<?php

use App\Contracts\Clock;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\PaymentException;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\Clock\FrozenClock;
use App\Support\Payments\Deposits;
use App\Support\Payments\PaymentInbox;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

test('webhooks authenticate exact raw bytes before durable encrypted receipt', function () {
    configurePaymentTest();
    $raw = "{ \"event\": \"ping\", \"data\": {} }\n";
    $signature = hash_hmac('sha512', $raw, 'sk_test_fixture');

    $this->call('POST', route('payments.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature], $raw)->assertOk();

    $event = WebhookEvent::sole();
    expect($event->payload)->toBe($raw);
    expect(DB::table('webhook_events')->value('payload'))->not->toContain('ping');
    Queue::assertPushed(ProcessPaymentWebhook::class, fn ($job) => $job->eventId === $event->id);
});

test('invalid or reserialized webhook signatures return 401 without persistence', function () {
    configurePaymentTest();
    $raw = '{ "event": "ping", "data": {} }';
    $signature = hash_hmac('sha512', '{"event":"ping","data":{}}', 'sk_test_fixture');

    $this->call('POST', route('payments.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature], $raw)->assertUnauthorized();

    $this->assertDatabaseCount('webhook_events', 0);
    Queue::assertNothingPushed();
});

test('duplicate changed and reordered webhook payloads share one verified financial recognition', function () {
    $deposit = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($deposit);
    $inbox = app(PaymentInbox::class);
    $first = json_encode(['event' => 'charge.success', 'data' => ['reference' => $deposit->reference, 'amount' => 1]], JSON_THROW_ON_ERROR);
    $changed = json_encode(['data' => ['amount' => 900000000, 'reference' => $deposit->reference], 'event' => 'charge.success'], JSON_THROW_ON_ERROR);

    $one = $inbox->receive($first, hash_hmac('sha512', $first, 'sk_test_fixture'));
    expect($inbox->receive($first, hash_hmac('sha512', $first, 'sk_test_fixture'))->id)->toBe($one->id);
    $two = $inbox->receive($changed, hash_hmac('sha512', $changed, 'sk_test_fixture'));
    $inbox->process($two->id);
    $inbox->process($one->id);
    app(Deposits::class)->verify($deposit);

    $this->assertDatabaseCount('webhook_events', 2);
    $this->assertDatabaseCount('journals', 2);
    expect($deposit->fresh()->wallet_credit)->toBe(500000);
    expect($one->fresh()->processed_at)->not->toBeNull();
});

test('acknowledged receipt survives dispatch failure and can be processed later', function () {
    $deposit = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($deposit);
    $original = app(Dispatcher::class);
    $broken = Mockery::mock(Dispatcher::class);
    $broken->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Injected dispatch failure'));
    $this->instance(Dispatcher::class, $broken);
    $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $deposit->reference]], JSON_THROW_ON_ERROR);

    $this->call('POST', route('payments.webhook'), [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, 'sk_test_fixture')], $raw)->assertOk();
    $this->instance(Dispatcher::class, $original);
    app(PaymentInbox::class)->process(WebhookEvent::sole()->id);

    expect($deposit->fresh()->wallet_credit)->toBe(500000);
    $this->assertDatabaseCount('journals', 2);
});

test('abandoned worker claims recover without rechecking ingress timestamp', function () {
    $deposit = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($deposit);
    $clock = new FrozenClock(CarbonImmutable::parse('2026-11-04T12:00:00Z'));
    $this->app->instance(Clock::class, $clock);
    $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $deposit->reference]], JSON_THROW_ON_ERROR);
    $event = app(PaymentInbox::class)->receive($raw, hash_hmac('sha512', $raw, 'sk_test_fixture'));
    $event->forceFill(['claim_token' => 'abandoned', 'claimed_at' => $clock->now()])->save();
    $clock->set($clock->now()->addMinutes(10));

    app(PaymentInbox::class)->process($event->id);

    expect($event->fresh()->processed_at)->not->toBeNull();
    expect($deposit->fresh()->wallet_credit)->toBe(500000);
});

test('authenticated unknown payment creates an ops exception without money', function () {
    configurePaymentTest();
    $raw = '{"event":"charge.success","data":{"reference":"unknown"}}';
    $event = app(PaymentInbox::class)->receive($raw, hash_hmac('sha512', $raw, 'sk_test_fixture'));

    app(PaymentInbox::class)->process($event->id);

    expect(PaymentException::sole()->code)->toBe('orphan_payment');
    $this->assertDatabaseCount('journals', 0);
});

test('timestamped webhook mode validates current signatures and rejects downgrade or stale delivery', function () {
    configurePaymentTest();
    $clock = new FrozenClock(CarbonImmutable::parse('2026-11-04T12:00:00Z'));
    $this->app->instance(Clock::class, $clock);
    config(['payments.webhook_mode' => 'timestamped', 'payments.webhook_signing_secret' => 'whsec_fixture']);
    $raw = '{"event":"ping","data":{}}';
    $timestamp = $clock->now()->timestamp;
    $signature = hash_hmac('sha512', $timestamp.'.'.$raw, 'whsec_fixture');
    $header = 't='.$timestamp.',v1=whk_old:'.str_repeat('0', 128).',v1=whk_new:'.$signature;

    $this->call('POST', route('payments.webhook'), [], [], [], ['HTTP_PAYSTACK_SIGNATURE' => $header], $raw)->assertOk();
    $clock->set($clock->now()->addSeconds(301));
    $this->call('POST', route('payments.webhook'), [], [], [], ['HTTP_PAYSTACK_SIGNATURE' => $header, 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, 'sk_test_fixture')], $raw)->assertUnauthorized();

    $this->assertDatabaseCount('webhook_events', 1);
});
