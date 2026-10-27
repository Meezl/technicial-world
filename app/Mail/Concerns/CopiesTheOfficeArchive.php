<?php

namespace App\Mail\Concerns;

/**
 * BCCs the shared office inbox on a ticket email.
 *
 * Ticket mail is addressed to people — the admins and PMs who must act on it,
 * or the person who filed it — and people leave, go on leave, or filter their
 * inbox. The archive address is the one recipient that outlives all of that,
 * so the office can answer "what did we tell them, and when?" from a single
 * mailbox instead of asking whoever happened to be on the thread.
 *
 * BCC rather than CC deliberately: the client must not learn the office's
 * internal archive address from a reply-all.
 */
trait CopiesTheOfficeArchive
{
    /**
     * The archive address, or nothing when it is unset or already a named
     * recipient of this message — a shared inbox that also doubles as a staff
     * account should not get the same mail twice.
     *
     * Note the hand-rolled recipient check. Mailable::hasTo() cannot be used
     * from inside envelope(): it consults the envelope to answer, so asking it
     * here would call this method again, for ever. The to/cc arrays are already
     * filled by Mail::to() before the envelope is read, so reading them
     * directly is both correct and the only way out of that loop.
     *
     * @return array<int, string>
     */
    protected function officeArchive(): array
    {
        $archive = trim((string) config('mail.office_archive'));

        if ($archive === '') {
            return [];
        }

        foreach (array_merge($this->to, $this->cc) as $recipient) {
            if (strcasecmp((string) ($recipient['address'] ?? ''), $archive) === 0) {
                return [];
            }
        }

        return [$archive];
    }
}
