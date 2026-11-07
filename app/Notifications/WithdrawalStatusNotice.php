<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WithdrawalStatusNotice extends Notification
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
        $mail = (new MailMessage)->subject('Ajo withdrawal update')
            ->line('Withdrawal '.$this->data['reference'].' is '.str_replace('_', ' ', substr($this->data['type'], 11)).'.');
        foreach (['principal', 'reserved_fee', 'actual_fee', 'remaining_debt'] as $field) {
            $value = $this->data[$field];
            $display = $value === 'unverified' ? 'awaiting verification' : 'NGN '.intdiv((int) $value, 100).'.'.str_pad((string) ((int) $value % 100), 2, '0', STR_PAD_LEFT);
            $mail->line(ucwords(str_replace('_', ' ', $field)).': '.$display);
        }

        return $mail->line('Actual incurred processing fees remain customer-paid. Confirmed returns repay recovery debt before becoming available in your wallet.')
            ->action('View withdrawal', route('withdrawals.show', $this->data['withdrawal_id']));
    }
}
