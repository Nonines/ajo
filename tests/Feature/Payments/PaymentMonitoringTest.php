<?php

use App\Contracts\Clock;
use App\Jobs\DeliverPaymentNotice;
use App\Jobs\ProcessPaymentWebhook;
use App\Jobs\ScanPayments;
use App\Jobs\VerifyDeposit;
use App\Models\PaymentException;
use App\Models\PaymentScan;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\Clock\FrozenClock;
use App\Support\Payments\Deposits;
use App\Support\Payments\PaymentInbox;
use App\Support\Payments\PaymentMonitoring;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('recovery sweeps include failed payment candidates and escalate unresolved attempts after 24 hours', function () {
    $clock = new FrozenClock(CarbonImmutable::parse('2026-11-04T12:00:00Z'));
    $this->app->instance(Clock::class, $clock);
    $deposit = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($deposit, ['status' => 'failed']);
    app(Deposits::class)->verify($deposit);
    $clock->set($clock->now()->addHours(25));

    app(PaymentMonitoring::class)->recover();

    Queue::assertPushed(VerifyDeposit::class, fn ($job) => $job->depositId === $deposit->id);
    expect(PaymentException::where('code', 'unresolved_payment')->where('deposit_id', $deposit->id)->exists())->toBeTrue();
    expect((string) $deposit->fresh()->state)->toBe('failed');
});

test('persisted unprocessed inbox work is queued again by the minute recovery job', function () {
    configurePaymentTest();
    $event = app(PaymentInbox::class)->observe('transaction', ['reference' => 'unknown']);
    Queue::fake([ProcessPaymentWebhook::class, VerifyDeposit::class, DeliverPaymentNotice::class, ScanPayments::class]);

    app(PaymentMonitoring::class)->recover();

    Queue::assertPushed(ProcessPaymentWebhook::class, fn ($job) => $job->eventId === $event->id);
});

test('provider scans retain page progress after a failed page and resume without losing observations', function () {
    $clock = new FrozenClock(CarbonImmutable::parse('2026-11-04T12:00:00Z'));
    $this->app->instance(Clock::class, $clock);
    configurePaymentTest();
    config(['payments.batch_size' => 2, 'payments.pages_per_run' => 2]);
    $pageTwoFails = true;
    Http::fake(['api.paystack.co/refund*' => function ($request) use (&$pageTwoFails) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        if ((int) $query['page'] === 1) {
            return Http::response(['status' => true, 'data' => [['id' => '3101'], ['id' => '3102']]]);
        }

        return $pageTwoFails ? Http::response([], 503) : Http::response(['status' => true, 'data' => [['id' => '3103']]]);
    }]);

    app(PaymentMonitoring::class)->scan('refund');
    expect(PaymentScan::sole()->next_page)->toBe(2);
    $this->assertDatabaseCount('webhook_events', 2);
    $pageTwoFails = false;
    $clock->set($clock->now()->addMinutes(6));
    app(PaymentMonitoring::class)->scan('refund');

    $this->assertDatabaseCount('webhook_events', 3);
    expect(PaymentScan::sole()->completed_at)->not->toBeNull();
    expect(PaymentScan::sole()->window_from)->toBeNull();
    Queue::assertPushed(ProcessPaymentWebhook::class);
});

test('known open cases beyond one year remain eligible for provider checks', function () {
    $clock = new FrozenClock(CarbonImmutable::parse('2026-11-04T12:00:00Z'));
    $this->app->instance(Clock::class, $clock);
    configurePaymentTest();
    $case = PaymentException::record('provider_dispute', 'Open dispute', $clock->now()->subYears(2), resourceType: 'dispute', resourceId: '3201', providerOpen: true);

    app(PaymentMonitoring::class)->recover();

    expect(WebhookEvent::whereNotNull('payload')->count())->toBe(1);
    expect($case->fresh()->provider_open)->toBeTrue();
    Queue::assertPushed(ProcessPaymentWebhook::class);
});

test('provider rate limiting pauses discovery and preserves the page for later recovery', function () {
    configurePaymentTest();
    Http::fake(['api.paystack.co/refund*' => Http::response([], 429, ['x-ratelimit-reset' => '120'])]);

    app(PaymentMonitoring::class)->scan('refund');

    expect(PaymentScan::sole()->next_page)->toBe(1);
    expect(PaymentScan::sole()->next_attempt_at)->not->toBeNull();
    expect(PaymentException::where('code', 'scan_unavailable')->exists())->toBeTrue();
    $this->assertDatabaseCount('webhook_events', 0);
});

test('a long discovery outage cannot skip the gap after the previous coverage checkpoint', function () {
    $clock = new FrozenClock(CarbonImmutable::parse('2026-11-04T12:00:00Z'));
    $this->app->instance(Clock::class, $clock);
    configurePaymentTest();
    $scan = new PaymentScan;
    $scan->forceFill(['resource' => 'refund', 'observed_through' => $clock->now()->subYears(2)])->save();
    Http::fake(['api.paystack.co/refund*' => Http::response(['status' => true, 'data' => []])]);

    app(PaymentMonitoring::class)->scan('refund');

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['from'] ?? null) === '2024-11-03T12:00:00+00:00';
    });
    expect($scan->refresh()->completed_at)->not->toBeNull();
});

test('scheduler detects overdue scans and inbox work without executing any queued jobs', function () {
    $clock = new FrozenClock(CarbonImmutable::parse('2026-11-04T12:00:00Z'));
    $this->app->instance(Clock::class, $clock);
    configurePaymentTest();
    $event = app(PaymentInbox::class)->observe('transaction', ['reference' => 'unknown']);
    app(PaymentMonitoring::class)->recover();
    $clock->set($clock->now()->addMinutes(8));

    app(PaymentMonitoring::class)->recover();
    app(PaymentMonitoring::class)->recover();

    expect(PaymentException::where('code', 'scan_overdue')->whereNull('resolved_at')->count())->toBe(3);
    expect(PaymentException::where('code', 'inbox_backlog')->whereNull('resolved_at')->count())->toBe(1);
    expect(PaymentScan::whereNotNull('completed_at')->count())->toBe(0);
    Queue::assertPushed(ScanPayments::class);
    Queue::assertPushed(ProcessPaymentWebhook::class);

    Http::fake(['api.paystack.co/refund*' => Http::response(['status' => true, 'data' => []])]);
    app(PaymentMonitoring::class)->scan('refund');
    $event->forceFill(['processed_at' => $clock->now()])->save();
    app(PaymentMonitoring::class)->recover();

    expect(PaymentException::where('code', 'scan_overdue')->where('resource_type', 'refund')->sole()->resolved_at)->not->toBeNull();
    expect(PaymentException::where('code', 'inbox_backlog')->sole()->resolved_at)->not->toBeNull();
    expect(PaymentException::where('code', 'scan_overdue')->where('resource_type', 'transaction')->sole()->resolved_at)->toBeNull();
});

test('stale scan completion and failed dispatch are visible until a complete scan recovers', function () {
    $clock = new FrozenClock(CarbonImmutable::parse('2026-11-04T12:00:00Z'));
    $this->app->instance(Clock::class, $clock);
    configurePaymentTest();
    foreach (['transaction', 'refund', 'dispute'] as $resource) {
        (new PaymentScan)->forceFill(['resource' => $resource, 'completed_at' => $clock->now()->subDays(2)])->save();
    }
    $dispatcher = app(Dispatcher::class);
    $broken = Mockery::mock(Dispatcher::class);
    $broken->shouldReceive('dispatch')->times(3)->andThrow(new RuntimeException('Injected dispatch failure'));
    $this->instance(Dispatcher::class, $broken);

    app(PaymentMonitoring::class)->recover();

    expect(PaymentException::where('code', 'scan_overdue')->whereNull('resolved_at')->count())->toBe(3);
    expect(PaymentException::where('code', 'scan_dispatch_failed')->whereNull('resolved_at')->count())->toBe(3);
    $this->instance(Dispatcher::class, $dispatcher);
    Http::fake(['api.paystack.co/transaction*' => Http::response(['status' => true, 'data' => []])]);
    app(PaymentMonitoring::class)->scan('transaction');

    expect(PaymentException::where('resource_type', 'transaction')->whereNull('resolved_at')->count())->toBe(0);
    expect(PaymentException::where('code', 'scan_dispatch_failed')->where('resource_type', 'refund')->sole()->resolved_at)->toBeNull();
});
