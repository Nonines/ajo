<?php

use App\Contracts\Clock;
use App\Contracts\PaymentGateway;
use App\Jobs\DeliverPaymentNotice;
use App\Jobs\DispatchWithdrawal;
use App\Models\AuditEvent;
use App\Models\SavedDestination;
use App\Models\User;
use App\Support\Clock\FrozenClock;
use App\Support\Ledger\Wallets;
use App\Support\Withdrawals\Withdrawals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T10:00:00Z')));
    config(['inertia.ssr.enabled' => false, 'payments.secret_key' => 'sk_test_fixture']);
});

test('withdrawal pages expose exact own balances and masked destinations', function (): void {
    Http::preventStrayRequests();
    Http::fake(['api.paystack.co/bank*' => Http::response(['status' => true, 'data' => [
        ['code' => '058', 'name' => 'Fixture Bank', 'active' => true, 'currency' => 'NGN', 'type' => 'nuban'],
    ], 'meta' => ['next' => null]])]);
    $user = User::factory()->create();
    $destination = SavedDestination::factory()->for($user)->create();
    postWalletTestCredit($user, kobo: 9007199254740993);

    $this->actingAs($user)->get(route('withdrawals.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Withdrawals/Index')->where('balance', '9007199254740993')->where('debt', '0')
        ->where('destinations.0.accountNumber', '******6789')->missing('destinations.0.recipient_code'));
});

test('customer cannot read cancel or select another customers withdrawal records', function (): void {
    $withdrawal = requestedPayout();
    $other = User::factory()->create();
    postWalletTestCredit($other, 'other-funds', 101000);
    $this->actingAs($other);

    $this->get(route('withdrawals.show', $withdrawal))->assertNotFound();
    $this->delete(route('withdrawals.destroy', $withdrawal))->assertNotFound();
    $this->post(route('withdrawals.store'), ['amount' => '1000.00', 'destination_id' => $withdrawal->saved_destination_id,
        'request_key' => (string) Str::uuid()])->assertNotFound();

    expect((string) $withdrawal->fresh()->state)->toBe('requested');
});

test('withdrawal requests reject invalid and nonpositive decimal amounts', function (string $amount): void {
    $user = User::factory()->create();
    app(Wallets::class)->provision($user);
    $destination = SavedDestination::factory()->for($user)->create();

    $this->actingAs($user)->post(route('withdrawals.store'), ['amount' => $amount, 'destination_id' => $destination->id,
        'request_key' => (string) Str::uuid()])->assertInvalid('amount');

    $this->assertDatabaseCount('withdrawal_requests', 0);
})->with(['0', '-1', '0.001', '1e3', '99999999999999999999999999999', '92233720368547758.07']);

test('bank account resolution creates an encrypted destination for a third party holder', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.paystack.co/bank/resolve*' => Http::response(['status' => true, 'data' => ['account_number' => '0123456789', 'account_name' => 'Another Holder']]),
        'api.paystack.co/transferrecipient' => Http::response(['status' => true, 'data' => ['active' => true, 'type' => 'nuban', 'currency' => 'NGN',
            'recipient_code' => 'RCP_fixture', 'details' => ['account_number' => '0123456789', 'bank_code' => '058', 'bank_name' => 'Fixture Bank']]]),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('destinations.store'), ['label' => 'Family', 'account_number' => '0123456789', 'bank_code' => '058'])
        ->assertRedirect(route('withdrawals.index'));

    expect(SavedDestination::sole()->details['account_name'])->toBe('Another Holder');
    expect(DB::table('saved_destinations')->sole()->details)->not->toContain('0123456789');
    Http::assertSentCount(2);
});

test('unverified inactive and staff-only identities cannot request customer withdrawals', function (): void {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->get(route('withdrawals.index'))->assertRedirect(route('verification.notice'));
    $user->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
    $this->actingAs($user)->get(route('withdrawals.index'))->assertForbidden();
    auth('web')->logout();
    $this->actingAs(payoutStaff(), 'staff')->get(route('withdrawals.index'))->assertRedirect(route('login'));
});

test('server approval rejects bad credentials mismatched details and active recovery debt', function (): void {
    Queue::fake([DispatchWithdrawal::class, DeliverPaymentNotice::class]);
    $withdrawal = requestedPayout();
    app(Withdrawals::class)->review(payoutStaff(), $withdrawal, true, 'review');
    $withdrawal->forceFill(['submitted_at' => now()])->save();
    config(['withdrawals.approval_token' => 'test-only-approval-token-at-least-32-bytes']);
    $data = ['reference' => $withdrawal->reference, 'amount' => (string) $withdrawal->principal,
        'recipient' => $withdrawal->destination['recipient_code'], 'currency' => 'NGN', 'source' => 'balance'];

    $this->postJson(route('payments.approval', ['token' => 'invalid']), $data)->assertBadRequest();
    $this->postJson(route('payments.approval', ['token' => config('withdrawals.approval_token')]), [...$data, 'amount' => '1'])->assertBadRequest();
    $this->postJson(route('payments.approval', ['token' => config('withdrawals.approval_token')]), $data)->assertOk();

    expect($withdrawal->fresh()->provider_approved_at)->not->toBeNull();
});

test('bank selection loads every provider page and caches only a complete validated list', function (): void {
    Http::preventStrayRequests();
    Http::fake(['api.paystack.co/bank*' => function ($request) {
        $next = $request['next'] ?? null;

        return Http::response(['status' => true, 'data' => [
            ['code' => $next === null ? '058' : '044', 'name' => $next === null ? 'Zulu Bank' : 'Alpha Bank',
                'active' => true, 'currency' => 'NGN', 'type' => 'nuban'],
        ], 'meta' => ['next' => $next === null ? 'page-two' : null]]);
    }]);

    $banks = app(PaymentGateway::class)->banks();
    $again = app(PaymentGateway::class)->banks();

    expect($banks)->toBe([['code' => '044', 'name' => 'Alpha Bank'], ['code' => '058', 'name' => 'Zulu Bank']]);
    expect($again)->toBe($banks);
    Http::assertSentCount(2);
});

test('a customer action records the customer actor when both authentication guards have sessions', function (): void {
    $user = User::factory()->create();
    $destination = SavedDestination::factory()->for($user)->create();
    postWalletTestCredit($user, kobo: 101000);
    $this->actingAs(payoutStaff(), 'staff');
    $this->actingAs($user, 'web');

    $this->post(route('withdrawals.store'), ['amount' => '1000.00', 'destination_id' => $destination->id,
        'request_key' => (string) Str::uuid()])->assertRedirect();

    $event = AuditEvent::where('event', 'journal.posted')->orderByDesc('id')->firstOrFail();
    expect($event->causer_type)->toBe(User::class);
    expect($event->causer_id)->toBe($user->id);
    expect(AuditEvent::where('event', 'staff.financial_read')->exists())->toBeFalse();
});

test('bank details are excluded from flashed session input after validation fails', function (): void {
    Http::preventStrayRequests();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('destinations.store'), ['label' => 'Family', 'account_number' => '0123456789', 'bank_code' => 'invalid'])
        ->assertInvalid('bank_code')->assertSessionMissing('_old_input.account_number');

    $this->assertDatabaseCount('saved_destinations', 0);
    Http::assertNothingSent();
});
