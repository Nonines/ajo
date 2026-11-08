<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a transactional payment notice snapshot and independent email delivery progress.
 *
 * @property string $id
 * @property int $user_id
 * @property int|null $deposit_id
 * @property string $recipient
 * @property array<string, string> $data
 * @property CarbonImmutable|null $mail_sent_at
 */
#[Hidden(['recipient', 'data'])]
class PaymentNotice extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recipient' => 'encrypted', 'data' => 'encrypted:array', 'mail_sent_at' => 'immutable_datetime'];
    }
}
