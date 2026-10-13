<?php

namespace App\Notifications\Concerns;

use App\Models\EmailLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;

/**
 * Lets a notification say which record its email is about.
 *
 * A Mailable hands its models to the view, so the email archive can read the
 * job straight out of them. A notification hands over rendered lines and
 * nothing else, so every notification ever sent was filed in the archive
 * against whoever received it. That answers "who did we email" and not "what
 * have we sent this client about this job", which is the question somebody
 * actually opens the archive to ask.
 *
 * One line in toMail() fixes it for a notification that knows its subject.
 */
trait RecordsEmailSubject
{
    protected function recordSubject(MailMessage $message, ?Model $subject): MailMessage
    {
        if (!$subject || $subject->getKey() === null) {
            return $message;
        }

        return $message->withSymfonyMessage(function (Email $email) use ($subject) {
            $email->getHeaders()->addTextHeader(
                EmailLog::SUBJECT_HEADER,
                $subject::class . ':' . $subject->getKey()
            );
        });
    }
}
