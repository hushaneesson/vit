<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a client when the admin creates/invites them under a vendor.
 * Client must follow the activation link before they can request an OTP.
 */
class ClientInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected string $invitationToken)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/vendor/activate/'.$this->invitationToken);

        return (new MailMessage)
            ->subject('You have been invited to the VIT Vendor Catalog Onboarding Portal')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('You have been invited to access the VIT Vendor Catalog Onboarding Portal on behalf of '.$notifiable->vendor->name.'.')
            ->line('Click below to activate your account. You will log in using a one-time code sent to your email — no password required.')
            ->action('Activate My Account', $url)
            ->line('If you did not expect this invitation, you can safely ignore this email.');
    }
}
