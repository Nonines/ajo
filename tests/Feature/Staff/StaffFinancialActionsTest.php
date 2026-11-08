<?php

use App\Contracts\Clock;
use App\Filament\Ops\Resources\WithdrawalRequests\Pages\ManageWithdrawalRequests;
use App\Filament\Resources\AuditEvents\Pages\ManageAuditEvents;
use App\Jobs\DispatchWithdrawal;
use App\Models\AuditEvent;
use App\Support\Clock\FrozenClock;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-11-07T10:00:00Z')));
});

test('native staff actions execute low threshold review and audit customer financial reads', function (): void {
    Queue::fake([DispatchWithdrawal::class]);
    $withdrawal = requestedPayout();
    $staff = payoutStaff();
    $this->actingAs($staff, 'staff');
    Filament::setCurrentPanel('ops');

    Livewire::test(ManageWithdrawalRequests::class)->callAction(TestAction::make('approve')->table($withdrawal),
        data: ['reason' => 'Destination and amount checked'])->assertHasNoFormErrors();

    expect((string) $withdrawal->fresh()->state)->toBe('queued_for_payout');
    expect(AuditEvent::where('event', 'staff.financial_read')->where('causer_id', $staff->id)->exists())->toBeTrue();
});

test('a manager cannot open the administrator audit viewer', function (): void {
    $this->actingAs(payoutStaff(), 'staff');
    Filament::setCurrentPanel('admin');

    Livewire::test(ManageAuditEvents::class)->assertForbidden();
});

test('staff table displays bank account suffixes while keeping full numbers encrypted', function (): void {
    $withdrawal = requestedPayout();
    $this->actingAs(payoutStaff(), 'staff');
    Filament::setCurrentPanel('ops');

    Livewire::test(ManageWithdrawalRequests::class)->assertCanSeeTableRecords([$withdrawal])
        ->assertSee('******6789')->assertDontSee('0123456789');
});

test('administrator audit reads are themselves recorded without recursive capture', function (): void {
    $this->actingAs(payoutStaff('admin'), 'staff');
    Filament::setCurrentPanel('admin');

    Livewire::test(ManageAuditEvents::class)->assertSuccessful();

    expect(AuditEvent::where('event', 'staff.audit_read')->exists())->toBeTrue();
    expect(AuditEvent::count())->toBeLessThan(20);
});
