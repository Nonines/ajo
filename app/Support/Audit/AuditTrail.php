<?php

namespace App\Support\Audit;

use App\Contracts\Clock;
use App\Models\AuditEvent;
use App\Models\Staff;
use App\Models\User;
use App\Support\Ledger\FinancialTransaction;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Appends redacted events to an HMAC chain within serialized financial transactions.
 */
class AuditTrail
{
    private bool $writing = false;

    public function __construct(private FinancialTransaction $transactions, private Clock $clock) {}

    /**
     * Allows the audit model to distinguish the chain writer from ordinary model saves.
     */
    public function isWriting(): bool
    {
        return $this->writing;
    }

    /**
     * Builds an event with an explicit actor or one inferred from the current request context.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $changes
     */
    public function record(string $event, ?Model $subject = null, array $properties = [], ?Model $actor = null, array $changes = []): AuditEvent
    {
        if ($actor === null) {
            $http = app()->bound('request') && request()->route() !== null;
            $candidate = $this->staffContext() ? Auth::guard('staff')->user() : ($http ? Auth::guard('web')->user() : null);
            $actor = $candidate instanceof Model ? $candidate : null;
        }

        $activity = new AuditEvent;
        $activity->forceFill(['log_name' => 'audit', 'description' => $event, 'event' => $event,
            'subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject?->getKey(),
            'causer_type' => $actor?->getMorphClass(), 'causer_id' => $actor?->getKey(),
            'properties' => collect($properties), 'attribute_changes' => collect($changes)]);

        return $this->append($activity);
    }

    /**
     * Redacts and seals an event, advancing the chain head in the same transaction.
     */
    public function append(AuditEvent $activity): AuditEvent
    {
        return $this->transactions->run([], function () use ($activity): AuditEvent {
            $head = DB::table('audit_head')->where('id', 1)->sole();
            $version = config('audit.key_version');
            $now = $this->clock->now()->utc()->format('Y-m-d H:i:s.u');
            $properties = $activity->properties?->all() ?? [];
            $http = app()->bound('request') && request()->route() !== null;
            $correlation = $properties['operation_key'] ?? (string) Str::uuid();

            if ($http) {
                $correlation = request()->attributes->get('audit_correlation', $correlation);
                request()->attributes->set('audit_correlation', $correlation);
            }

            // Bind actor, origin, and correlation context into the sealed event evidence.
            $properties['context'] = ['actor' => match ($activity->causer_type) {
                Staff::class => 'staff', User::class => 'customer', default => 'system',
            }, 'origin' => $http ? (request()->segment(1) ?: 'web') : 'console',
                'panel' => $this->staffContext() ? Filament::getCurrentPanel()?->getId() : null,
                'ip' => $http ? request()->ip() : null, 'correlation' => $correlation];

            // Redact sensitive values before hashing the persisted envelope.
            $activity->forceFill(['id' => $head->sequence + 1, 'previous_hash' => $head->hash,
                'key_version' => $version, 'created_at' => $now, 'updated_at' => $now,
                'properties' => collect($this->redact($properties)),
                'attribute_changes' => collect($this->redact($activity->attribute_changes?->all() ?? []))]);
            $activity->hash = hash_hmac('sha256', $head->hash.$this->canonical($this->envelope($activity)), $this->key($version));

            // Permit model writes only for the duration of the chain writer's save.
            $this->writing = true;

            try {
                $activity->save();
            } finally {
                $this->writing = false;
            }

            DB::table('audit_head')->where('id', 1)->update(['sequence' => $activity->id, 'hash' => $activity->hash]);

            // Retain customer references even after online events move into archives.
            $customers = [$activity->subject_type === User::class ? $activity->subject_id : null,
                $activity->causer_type === User::class ? $activity->causer_id : null, $properties['customer_id'] ?? null];

            foreach (array_unique(array_filter($customers, is_int(...))) as $customerId) {
                DB::table('audit_customer_references')->upsert(['user_id' => $customerId, 'last_sequence' => $activity->id], ['user_id'], ['last_sequence']);
            }

            return $activity;
        });
    }

    /**
     * Recognizes staff panel and Livewire contexts when selecting the audit actor.
     */
    public function staffContext(): bool
    {
        return (app()->bound('request') && (request()->routeIs('filament.*') || request()->is('livewire/*')))
            || (Filament::getCurrentPanel() !== null && Auth::getDefaultDriver() === 'staff');
    }

    /**
     * Selects the persisted fields covered by the event hash, including raw timestamp values.
     *
     * @return array<string, mixed>
     */
    public function envelope(AuditEvent $event): array
    {
        return ['id' => (int) $event->id, 'log_name' => $event->log_name, 'description' => $event->description,
            'event' => $event->event, 'subject_type' => $event->subject_type, 'subject_id' => $event->subject_id,
            'causer_type' => $event->causer_type, 'causer_id' => $event->causer_id,
            'properties' => $event->properties?->all() ?? [], 'attribute_changes' => $event->attribute_changes?->all() ?? [],
            'key_version' => $event->key_version, 'created_at' => $event->getAttributes()['created_at'],
            'updated_at' => $event->getAttributes()['updated_at']];
    }

    /**
     * Sorts associative keys recursively while preserving list order for reproducible signatures.
     *
     * @param  array<string, mixed>  $value
     */
    public function canonical(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }

            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Requires a dedicated signing key for the requested historical key version.
     */
    public function key(string $version): string
    {
        $key = config('audit.keys.'.$version);

        if (! is_string($key) || strlen($key) < 32) {
            throw new DomainException('A dedicated audit key of at least 32 bytes is required.');
        }

        return $key;
    }

    /**
     * Verifies archive continuity, retained online events, and the head; returns the final sequence.
     */
    public function verify(): int
    {
        return $this->transactions->run([], function (): int {
            $head = DB::table('audit_head')->where('id', 1)->sole();
            app(AuditArchives::class)->verify();
            $sequence = $head->floor_sequence;
            $hash = $head->floor_hash;

            foreach (AuditEvent::orderBy('id')->cursor() as $event) {
                if ($event->id !== ++$sequence || ! hash_equals($hash, $event->previous_hash)
                    || ! hash_equals($event->hash, hash_hmac('sha256', $hash.$this->canonical($this->envelope($event)), $this->key($event->key_version)))) {
                    throw new DomainException('Audit chain verification failed at event '.$event->id.'.');
                }

                $hash = $event->hash;
            }

            if ($head->sequence !== $sequence || ! hash_equals($head->hash, $hash)) {
                throw new DomainException('Audit chain head does not match retained evidence.');
            }

            return $sequence;
        });
    }

    /**
     * Checks one expected sequence and predecessor hash, returning the authenticated event hash.
     */
    public function verifyEvent(AuditEvent $event, int $sequence, string $hash): string
    {
        if ($event->id !== $sequence || ! hash_equals($hash, $event->previous_hash)
            || ! hash_equals($event->hash, hash_hmac('sha256', $hash.$this->canonical($this->envelope($event)), $this->key($event->key_version)))) {
            throw new DomainException('Audit archive verification failed at event '.$event->id.'.');
        }

        return $event->hash;
    }

    /**
     * Recursively removes recognized credential fields and masks bank account details before sealing.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $name => $value) {
            if (is_string($name) && preg_match('/password|secret|token|authorization|credential|recovery_code|access_code|recipient_code/i', $name)) {
                $values[$name] = '[redacted]';
            } elseif (is_string($name) && preg_match('/account_number|bank_details/i', $name)) {
                $values[$name] = is_string($value) ? '******'.substr($value, -4) : '[redacted]';
            } elseif (is_array($value)) {
                $values[$name] = $this->redact($value);
            }
        }

        return $values;
    }
}
