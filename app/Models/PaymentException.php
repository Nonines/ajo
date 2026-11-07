<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int|null $deposit_id
 * @property int|null $webhook_event_id
 * @property string $code
 * @property string $message
 * @property string|null $resource_type
 * @property string|null $resource_id
 * @property bool $provider_open
 * @property int $next_refund_page
 * @property int $refund_page_offset
 * @property CarbonImmutable|null $refund_scan_to
 * @property CarbonImmutable|null $resolved_at
 * @property Deposit|null $deposit
 */
class PaymentException extends Model
{
    public static function record(string $code, string $message, CarbonImmutable $now, ?int $depositId = null, ?int $eventId = null, ?string $resourceType = null, ?string $resourceId = null, bool $providerOpen = false): self
    {
        return DB::transaction(function () use ($code, $message, $now, $depositId, $eventId, $resourceType, $resourceId, $providerOpen): self {
            $key = $code.':'.hash('sha256', json_encode([$depositId, $eventId, $resourceType, $resourceId], JSON_THROW_ON_ERROR));
            $exception = self::where('key', $key)->first() ?? new self;
            $exception->forceFill(['key' => $key, 'deposit_id' => $depositId, 'webhook_event_id' => $eventId, 'code' => $code, 'message' => $message,
                'resource_type' => $resourceType, 'resource_id' => $resourceId, 'provider_open' => $providerOpen,
                'last_checked_at' => $now, 'resolved_at' => null])->save();

            return $exception;
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['provider_open' => 'boolean', 'resolved_at' => 'immutable_datetime', 'response_due_at' => 'immutable_datetime', 'last_checked_at' => 'immutable_datetime',
            'next_refund_page' => 'integer', 'refund_page_offset' => 'integer', 'refund_scan_to' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Deposit, $this> */
    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }
}
