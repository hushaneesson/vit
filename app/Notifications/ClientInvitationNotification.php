<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a client when the admin adds them under a vendor.
 * Client can go directly to the portal and log in via OTP.
 */
class ClientInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('vendor.login');

        return (new MailMessage)
            ->subject('You have been added to the VIT Vendor Catalog Onboarding Portal')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('You have been added to the VIT Vendor Catalog Onboarding Portal on behalf of ' . $notifiable->vendor->name . '.')
            ->line('Use the button below to go to the portal and request your one-time login code. No password is required.')
            ->action('Go to Portal Login', $url)
            ->line('If you did not expect this email, you can safely ignore it.');
    }
}
