<?php

namespace App\Observers;

use App\Models\Client;
use App\Notifications\ClientInvitationNotification;

class ClientObserver
{
    /**
     * When the admin creates a new client record, set it active immediately
     * and send a portal access email. Clients can log in directly with OTP.
     */
    public function created(Client $client): void
    {
        if ($client->status === 'disabled') {
            return;
        }

        $client->forceFill([
            'status' => 'active',
            'activated_at' => $client->activated_at ?? now(),
            'invited_at' => $client->invited_at ?? now(),
        ])->save();

        $client->notify(new ClientInvitationNotification());
    }
}
