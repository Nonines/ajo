<?php

namespace App\Support;

use App\Contracts\Clock;
use App\Contracts\PaymentGateway;
use App\Jobs\DeliverPaymentNotice;
use App\Jobs\ProcessPaymentWebhook;
use App\Jobs\ScanPayments;
use App\Jobs\VerifyDeposit;
use App\Models\Deposit;
use App\Models\PaymentException;
use App\Models\PaymentNotice;
use App\Models\PaymentScan;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Rediscovers durable payment work and checkpoints bounded provider scans across scheduler runs.
 */
class PaymentMonitoring
{
    public function __construct(private Clock $clock, private PaymentGateway $gateway, private PaymentInbox $inbox) {}

    /**
     * Queues due receipts, payment checks, notices, and scans while surfacing overdue work.
     */
    public function recover(): void
    {
        $now = $this->clock->now();
        $limit = config('payments.batch_size');
        if (WebhookEvent::whereNull('processed_at')->where('received_at', '<=', $now->subMinutes(config('payments.inbox_overdue_minutes')))->exists()) {
            PaymentException::record('inbox_backlog', 'Payment inbox work is overdue; inspect queue workers and failed jobs.', $now, resourceType: 'inbox');
        } else {
            PaymentException::where('code', 'inbox_backlog')->whereNull('resolved_at')->update(['resolved_at' => $now]);
        }
        foreach (WebhookEvent::whereNull('processed_at')->where('next_attempt_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', $now->subSeconds(config('payments.claim_seconds'))))->orderBy('id')->limit($limit)->get() as $event) {
            $event->forceFill(['next_attempt_at' => $now->addMinute()])->save();
            $this->dispatch(fn () => ProcessPaymentWebhook::dispatch($event->id));
        }
        foreach (Deposit::whereNotNull('next_verify_at')->where('next_verify_at', '<=', $now)->orderBy('next_verify_at')->limit($limit)->get() as $deposit) {
            if ($deposit->recognized_at === null && $deposit->created_at->addHours(config('payments.escalation_hours'))->lte($now)) {
                PaymentException::record('unresolved_payment', 'Payment remains unrecognized after 24 hours; investigate without guessing its outcome.', $now, $deposit->id);
            }
            $deposit->forceFill(['next_verify_at' => $now->addMinutes(config('payments.verification_minutes'))])->save();
            $this->dispatch(fn () => VerifyDeposit::dispatch($deposit->id));
        }
        foreach (PaymentNotice::whereNull('mail_sent_at')->where('next_attempt_at', '<=', $now)->orderBy('next_attempt_at')->limit($limit)->get() as $notice) {
            $notice->forceFill(['next_attempt_at' => $now->addMinutes(config('payments.notification_retry_minutes'))])->save();
            $this->dispatch(fn () => DeliverPaymentNotice::dispatch($notice->id));
        }
        foreach (PaymentException::where('provider_open', true)->whereNotNull('resource_id')->where(fn ($query) => $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', $now->subMinutes(5)))->orderBy('last_checked_at')->limit($limit)->get() as $case) {
            $this->inbox->observe((string) $case->resource_type, ['id' => $case->resource_id, 'recheck_at' => $now->toIso8601String()]);
            $case->forceFill(['last_checked_at' => $now])->save();
        }
        foreach (['transaction', 'refund', 'dispute'] as $resource) {
            $scan = PaymentScan::where('resource', $resource)->first() ?? (new PaymentScan)->forceFill(['resource' => $resource]);
            if ($scan->scheduled_at === null) {
                $scan->forceFill(['scheduled_at' => $now])->save();
            }
            $due = $scan->completed_at?->addMinutes($resource === 'transaction' ? 1440 : 5) ?? $scan->scheduled_at ?? $now;
            if ($due->addSeconds(config('payments.claim_seconds'))->lte($now)) {
                PaymentException::record('scan_overdue', 'Provider discovery has not completed on schedule; inspect workers and retained scan progress.', $now, resourceType: $resource);
            }
            if ($scan->next_attempt_at === null || $scan->next_attempt_at->lte($now)) {
                if (! $this->dispatch(fn () => ScanPayments::dispatch($resource))) {
                    PaymentException::record('scan_dispatch_failed', 'Provider discovery could not be queued; persisted work remains due.', $now, resourceType: $resource);
                }
            }
        }
    }

    /**
     * Persists observations page by page within a fixed window and retains progress when the budget ends.
     */
    public function scan(string $resource): void
    {
        if (! in_array($resource, ['transaction', 'refund', 'dispute'], true)) {
            throw new \DomainException('Unsupported provider scan.');
        }
        $lock = Cache::lock('payment-scan:'.$resource, config('payments.claim_seconds'));
        if (! $lock->get()) {
            return;
        }
        try {
            $scan = PaymentScan::where('resource', $resource)->first() ?? (new PaymentScan)->forceFill(['resource' => $resource]);
            if ($scan->scheduled_at === null) {
                $scan->forceFill(['scheduled_at' => $this->clock->now()]);
            }
            if ($scan->next_attempt_at !== null && $scan->next_attempt_at->gt($this->clock->now())) {
                return;
            }
            if ($scan->window_from === null) {
                $from = $this->clock->now()->subYear();
                $checkpoint = $scan->observed_through?->subMinutes(config('payments.scan_overlap_minutes'));
                if ($checkpoint !== null && $checkpoint->lessThan($from)) {
                    $from = $checkpoint;
                }
                $scan->forceFill(['window_from' => $from, 'window_to' => $this->clock->now(), 'next_page' => 1])->save();
            }
            $deadline = microtime(true) + config('payments.scan_seconds');
            for ($page = 0; $page < config('payments.pages_per_run') && microtime(true) < $deadline; $page++) {
                $items = $this->gateway->list($resource, ['from' => $scan->window_from->toIso8601String(), 'to' => $scan->window_to->toIso8601String(), 'page' => $scan->next_page, 'perPage' => config('payments.batch_size')]);
                foreach ($items as $item) {
                    $this->inbox->observe($resource, $item);
                }
                if (count($items) < config('payments.batch_size')) {
                    $scan->forceFill(['next_page' => 1, 'observed_through' => $scan->window_to, 'window_from' => null, 'window_to' => null, 'completed_at' => $this->clock->now(),
                        'next_attempt_at' => $resource === 'transaction' ? $this->clock->now()->addDay() : $this->clock->now()->addMinutes(5)])->save();
                    PaymentException::where('resource_type', $resource)->whereNull('resource_id')->whereIn('code', ['scan_backlog', 'scan_unavailable', 'scan_overdue', 'scan_dispatch_failed'])->update(['resolved_at' => $this->clock->now()]);

                    return;
                }
                $scan->forceFill(['next_page' => $scan->next_page + 1, 'next_attempt_at' => $this->clock->now()->addMinute()])->save();
            }
            PaymentException::record('scan_backlog', 'Provider scan has more pages; durable progress is retained for the next run.', $this->clock->now(), resourceType: $resource);
        } catch (Throwable $exception) {
            if (isset($scan) && $scan->exists) {
                $scan->forceFill(['next_attempt_at' => $this->clock->now()->addSeconds($exception instanceof GatewayFailure ? max(60, $exception->retryAfter) : 300)])->save();
            }
            PaymentException::record('scan_unavailable', 'Provider discovery is incomplete; investigate scan availability and backlog.', $this->clock->now(), resourceType: $resource);
        } finally {
            $lock->release();
        }
    }

    private function dispatch(\Closure $dispatch): bool
    {
        try {
            $dispatch();

            return true;
        } catch (Throwable) {
            // Persisted due work remains recoverable on the next scheduler pass.
            return false;
        }
    }
}
