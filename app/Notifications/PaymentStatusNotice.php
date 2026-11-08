<?php

namespace App\Notifications;

use App\Support\Money\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Formats saved payment evidence for customer email delivery.
 */
class PaymentStatusNotice extends Notification
{
    /** @param array<string, string> $data */
    public function __construct(public array $data) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Displays the saved payment amounts and links to the customer's payment evidence.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Ajo payment update')->line('Payment '.$this->data['reference'].' is '.$this->data['type'].'.');

        foreach ($this->data as $key => $value) {
            if (! in_array($key, ['type', 'reference'], true)) {
                $mail->line(ucwords(str_replace('_', ' ', $key)).': NGN '.(new Money($value))->toNaira());
            }
        }

        return $mail->action('View payment', route('deposits.show', $this->data['reference']));
    }
}
