<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentStatusNotice extends Notification
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
        $mail = (new MailMessage)->subject('Ajo payment update')->line('Payment '.$this->data['reference'].' is '.$this->data['type'].'.');
        foreach ($this->data as $key => $value) {
            if (! in_array($key, ['type', 'reference'], true)) {
                $mail->line(ucwords(str_replace('_', ' ', $key)).': NGN '.$this->formatKobo($value));
            }
        }

        return $mail->action('View payment', route('deposits.show', $this->data['reference']));
    }

    private function formatKobo(string $kobo): string
    {
        return intdiv((int) $kobo, 100).'.'.str_pad((string) ((int) $kobo % 100), 2, '0', STR_PAD_LEFT);
    }
}
