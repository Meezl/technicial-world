<?php

namespace App\Listeners;

use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Events\Verified;

class SendWelcomeEmail
{
    /**
     * Send the welcome email once the user has verified their address.
     *
     * This used to fire at registration alongside the "Verify Email Address"
     * notification. Both render through Laravel's stock template — same
     * masthead, same "Hello ...!", same single centred button — so arriving
     * seconds apart they read as one email delivered twice, which is what
     * clients complained about. Waiting for verification also means the
     * "Go to Dashboard" button actually works when it's clicked.
     */
    public function handle(Verified $event): void
    {
        $event->user->notify(new WelcomeNotification());
    }
}
