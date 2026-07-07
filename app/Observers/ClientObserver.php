<?php

namespace App\Observers;

use App\Models\Client;
use App\Notifications\ClientInvitationNotification;

class ClientObserver
{
    /**
     * When the admin creates a new client record, automatically generate an
     * invitation token and email it. The client cannot log in (request an
     * OTP) until they've followed this link to activate.
     *
     * If the record is created already active (e.g. a data seed/import, or
     * a test simulating a pre-activated client), skip the invitation flow
     * entirely rather than overwriting the caller's intended status.
     */
    public function created(Client $client): void
    {
        if ($client->status === 'active') {
            return;
        }

        $token = $client->generateInvitationToken();
        $client->notify(new ClientInvitationNotification($token));
    }
}
