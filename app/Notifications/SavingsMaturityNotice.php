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
        $outcome = match ($this->data['type']) {
            'savings_maturity' => 'matured', 'savings_rolled_over' => 'rolled over',
            'savings_early_termination' => 'closed early', 'savings_admin_closed' => 'administratively closed',
            default => str_replace('_', ' ', substr($this->data['type'], 8)),
        };
        $mail = (new MailMessage)->subject('Ajo savings: '.$outcome)->line('Your savings update: '.$outcome.'.');
        foreach (['principal', 'gross_interest', 'capitalized_interest', 'forfeiture', 'principal_penalty', 'waived_forfeiture', 'waived_penalty',
            'net_interest', 'withholding', 'debt_repayment', 'remaining_debt', 'wallet_credit', 'interest_cap', 'principal_cap'] as $key) {
            if (! isset($this->data[$key])) {
                continue;
            }
            $kobo = (int) $this->data[$key];
            $mail->line(ucwords(str_replace('_', ' ', $key)).': NGN '.intdiv($kobo, 100).'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT));
        }

        if (isset($this->data['expires_at'])) {
            $mail->line('Waiver expires at '.$this->data['expires_at'].'.');
        }

        return $mail->action('View savings plan', route('savings.show', $this->data['plan_id']));
    }
}
