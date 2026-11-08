<?php

use App\Filament\Ops\Resources\PaymentExceptions\Pages\ManagePaymentExceptions;
use App\Filament\Ops\Resources\PaymentExceptions\PaymentExceptionResource;
use App\Jobs\DeliverPaymentNotice;
use App\Jobs\ProcessPaymentWebhook;
use App\Jobs\ScanPayments;
use App\Jobs\VerifyDeposit;
use App\Models\PaymentException;
use App\Models\PaymentNotice;
use App\Models\Staff;
use App\Models\User;
use App\Notifications\PaymentStatusNotice;
use App\Support\Payments\Deposits;
use App\Support\Payments\PaymentInbox;
use App\Support\Payments\PaymentNotices;
use Database\Seeders\StaffPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

test('payment email preserves exact saved monetary amounts', function () {
    $notice = new PaymentStatusNotice(['type' => 'confirmed', 'reference' => 'ajo-test',
        'net' => '9007199254740993', 'fee' => '1', 'remaining_debt' => '0']);

    $mail = $notice->toMail(new stdClass);

    expect($mail->introLines)->toContain('Net: NGN 90071992547409.93', 'Fee: NGN 0.01', 'Remaining Debt: NGN 0.00');
});

test('receipt intents survive before dispatch and create an owned unread inbox record once', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $deposit = initializePaymentTest($user);
    fakeVerifiedPayment($deposit);
    app(Deposits::class)->verify($deposit);
    Notification::fake();
    $notice = PaymentNotice::sole();

    app(PaymentNotices::class)->deliver($notice->id);
    app(PaymentNotices::class)->deliver($notice->id);

    $this->assertDatabaseCount('notifications', 1);
    Notification::assertSentOnDemandTimes(PaymentStatusNotice::class, 1);
    expect($notice->fresh()->mail_sent_at)->not->toBeNull();
    $this->actingAs($user)->get(route('notifications.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Notifications')->has('notifications.data', 1)->where('notifications.data.0.readAt', null));
    $this->actingAs($other)->patch(route('notifications.update', $notice->id))->assertNotFound();
    $this->actingAs($user)->patch(route('notifications.update', $notice->id))->assertRedirect();
    expect($user->notifications()->sole()->read_at)->not->toBeNull();
    $this->assertDatabaseCount('journals', 2);
});

test('failed notice intent persistence rolls back financial recognition and projections', function () {
    $deposit = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($deposit);
    DB::unprepared("CREATE TRIGGER reject_notice BEFORE INSERT ON payment_notices BEGIN SELECT RAISE(ABORT, 'Injected notice intent failure'); END");

    expect(fn () => app(Deposits::class)->verify($deposit))->toThrow(QueryException::class);

    $this->assertDatabaseCount('journals', 0);
    $this->assertDatabaseCount('payment_notices', 0);
    expect($deposit->fresh()->recognition_journal_id)->toBeNull();
});

test('email failure leaves a retryable notice and never repeats its financial effect', function () {
    $deposit = initializePaymentTest(User::factory()->create());
    fakeVerifiedPayment($deposit);
    app(Deposits::class)->verify($deposit);
    $notice = PaymentNotice::sole();
    Mail::shouldReceive('mailer')->andThrow(new RuntimeException('Injected mail failure'));

    expect(fn () => app(PaymentNotices::class)->deliver($notice->id))->toThrow(RuntimeException::class);
    expect($notice->fresh()->mail_sent_at)->toBeNull();
    Notification::fake();
    app(PaymentNotices::class)->deliver($notice->id);

    Notification::assertSentOnDemand(PaymentStatusNotice::class);
    $this->assertDatabaseCount('notifications', 1);
    $this->assertDatabaseCount('journals', 2);
});

test('ops exception permission matrix excludes inactive and unprivileged staff', function (string $role, bool $active, bool $allowed) {
    $this->seed(StaffPermissionSeeder::class);
    $staff = Staff::factory()->create(['is_active' => $active]);
    if ($role !== 'none') {
        $staff->assignRole($role);
    }

    expect(Gate::forUser($staff)->allows('viewAny', PaymentException::class))->toBe($allowed);
    expect(Gate::forUser($staff)->allows('create', PaymentException::class))->toBeFalse();
})->with([['manager', true, true], ['admin', true, true], ['none', true, false], ['manager', false, false]]);

test('authorized ops retry queues verification without exposing financial editing', function () {
    $this->seed(StaffPermissionSeeder::class);
    $staff = Staff::factory()->withTwoFactor()->create();
    $staff->assignRole('manager');
    $this->actingAs($staff, 'staff');
    Filament::setCurrentPanel('ops');
    configurePaymentTest();
    $event = app(PaymentInbox::class)->observe('transaction', ['reference' => 'unknown']);
    $exception = PaymentException::record('orphan_payment', 'Unknown checkout', now()->toImmutable(), eventId: $event->id);
    Queue::fake([ProcessPaymentWebhook::class, VerifyDeposit::class, DeliverPaymentNotice::class, ScanPayments::class]);

    Livewire::test(ManagePaymentExceptions::class)->assertCanSeeTableRecords([$exception])->callAction(TestAction::make('retry')->table($exception));

    Queue::assertPushed(ProcessPaymentWebhook::class, fn ($job) => $job->eventId === $event->id);
    $this->assertDatabaseCount('journals', 0);
    expect(PaymentExceptionResource::canCreate())->toBeFalse();
});
