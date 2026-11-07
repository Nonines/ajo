<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SavingsMaturityNotice extends Notification
{
    /** @param array<string, string> $data */
    public function __construct(public array $data) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Your Ajo savings matured')->line('Your fixed-term savings have settled.');
        foreach (['principal', 'gross_interest', 'net_interest', 'withholding', 'debt_repayment', 'remaining_debt', 'wallet_credit'] as $key) {
            $kobo = (int) $this->data[$key];
            $mail->line(ucwords(str_replace('_', ' ', $key)).': NGN '.intdiv($kobo, 100).'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT));
        }

        return $mail->action('View maturity statement', route('savings.show', $this->data['plan_id']));
    }
}
