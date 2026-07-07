<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One-time login code emailed to a client. Clients have no password — this
 * code is the entire authentication mechanism.
 */
class ClientOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected string $code)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your VIT Vendor Portal login code')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your one-time login code is:')
            ->line(new \Illuminate\Support\HtmlString('<h2 style="letter-spacing:4px;">'.$this->code.'</h2>'))
            ->line('This code expires in 10 minutes. If you did not request this, you can ignore this email.');
    }
}
