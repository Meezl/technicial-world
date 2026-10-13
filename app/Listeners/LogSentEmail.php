<?php

namespace App\Listeners;

use App\Models\EmailLog;
use App\Models\ServiceRequest;
use App\Models\PaymentRequest;
use App\Models\User;
use Symfony\Component\Mime\Email;
use Illuminate\Mail\Events\MessageSent;

class LogSentEmail
{
    /**
     * Persist a full copy of every email Laravel sends so the admin can
     * retrieve the exact content that hit the recipient's inbox (#5).
     *
     * Everything the application sends passes through here — Mailables and
     * notifications alike, queued or not, because the worker fires the same
     * event the web process does. What was missing was not the rows but what
     * they said about themselves: see the notes on the class name and the
     * subject below.
     */
    public function handle(MessageSent $event): void
    {
        try {
            $message = $event->message;
            $data = $event->data ?? [];

            $addressList = fn ($addresses) => collect($addresses)
                ->map(fn ($a) => ['address' => $a->getAddress(), 'name' => $a->getName()])
                ->all();

            $bodyHtml = $message->getHtmlBody() ?? '';
            $bodyText = $message->getTextBody();

            // Attachment metadata (filenames + sizes only)
            $attachments = [];
            foreach ($message->getAttachments() as $attachment) {
                $headers = $attachment->getPreparedHeaders();
                $disposition = $headers->get('Content-Disposition');
                $name = null;
                if ($disposition && method_exists($disposition, 'getParameter')) {
                    $name = $disposition->getParameter('filename');
                }
                $attachments[] = [
                    'name' => $name ?: 'attachment',
                    'size_bytes' => strlen($attachment->getBody()),
                    'mime'       => $headers->get('Content-Type')?->getBody(),
                ];
            }

            // Read the addresses off the message rather than the envelope.
            // $event->message is a Symfony Mime\Email, which has no
            // getEnvelope() — that lives on $event->sent. Calling it here threw
            // on every send, so nothing has reached the email archive since
            // this listener was written, and the failure was swallowed by the
            // catch below.
            //
            // The message's own accessors are the better source anyway: the
            // envelope merges to, cc and bcc into one recipient list, which
            // would have flattened the three columns this row keeps apart.
            $to = $addressList($message->getTo());
            $from = $message->getFrom()[0] ?? null;

            // Try to resolve a related model from $data (Mailables typically
            // share their context, including `serviceRequest`, `paymentRequest`, etc.)
            [$relatedType, $relatedId, $userId] = $this->resolveRelated($data, $to, $message);

            EmailLog::create([
                // Laravel calls these `__laravel_mailable` and
                // `__laravel_notification`; the keys looked for here were
                // `mailable_class` and `__mailable_class`, which it has never
                // set. Every row in the archive carried a null class, so there
                // was no way to ask it a question — "did the approval receipts
                // go out", "what did we send this client" — without reading
                // subjects by eye.
                'mailable_class' => $data['__laravel_mailable']
                    ?? $data['__laravel_notification']
                    ?? null,
                'subject'        => $message->getSubject() ?? '(no subject)',
                'to'             => $to,
                'cc'             => $addressList($message->getCc()),
                'bcc'            => $addressList($message->getBcc()),
                'from_address'   => $from?->getAddress(),
                'from_name'      => $from?->getName(),
                'body_html'      => $bodyHtml,
                'body_text'      => $bodyText,
                'attachments'    => $attachments,
                'related_type'   => $relatedType,
                'related_id'     => $relatedId,
                'user_id'        => $userId,
                'sent_at'        => now(),
                'status'         => 'sent',
            ]);
        } catch (\Throwable $e) {
            // Never let logging break delivery — but never let it lose the
            // email either. This catch is how a broken archive stays quiet:
            // the envelope bug swallowed every row for as long as it lived,
            // and a NOT NULL on related_type swallowed every email the
            // resolver could not place. Both times the send succeeded and the
            // record simply was not there.
            //
            // So a failure falls back to the least that is still useful — who
            // it went to, what it said on the tin, and when — with the reason
            // the full row could not be written kept beside it.
            $this->archiveWhatWeCan($event, $e);
        }
    }

    /**
     * The stub written when the full row cannot be.
     *
     * Deliberately touches as little as possible: no body, no attachments, no
     * resolved subject, because any of those may be what failed.
     */
    private function archiveWhatWeCan(MessageSent $event, \Throwable $original): void
    {
        try {
            $message = $event->message;

            EmailLog::create([
                'subject' => $message->getSubject() ?: '(no subject)',
                'to' => collect($message->getTo())
                    ->map(fn ($a) => ['address' => $a->getAddress(), 'name' => $a->getName()])
                    ->all(),
                'body_html' => '',
                'sent_at' => now(),
                // The email did go out. It is the archiving that degraded,
                // and saying otherwise would make the row lie about delivery.
                'status' => 'sent',
                'error_message' => 'Archived without detail: ' . $original->getMessage(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('EmailLog persistence failed entirely', [
                'original' => $original->getMessage(),
                'fallback' => $e->getMessage(),
            ]);
        }
    }

    /**
     * What this email was about.
     *
     * A Mailable shares its context, so the model is sitting in $data. A
     * notification does not: its view data is the rendered lines, and nothing
     * in it says which job the email concerns — so every notification in the
     * archive was filed against whoever received it, which answers "who did we
     * email" and not "what did we send about this job". Notifications that
     * know their subject now say so in a header; see RecordsEmailSubject.
     */
    private function resolveRelated(array $data, array $recipients, Email $message): array
    {
        $userId = null;
        if (!empty($recipients[0]['address'])) {
            $userId = User::where('email', $recipients[0]['address'])->value('id');
        }

        foreach ($data as $val) {
            if ($val instanceof ServiceRequest) {
                return [ServiceRequest::class, $val->id, $userId];
            }
            if ($val instanceof PaymentRequest) {
                return [PaymentRequest::class, $val->id, $val->user_id ?? $userId];
            }
        }

        if ($tagged = $this->taggedSubject($message)) {
            return [$tagged[0], $tagged[1], $userId];
        }

        // Nothing to point at. Left null rather than filed against User #0 —
        // a row that was neither about that user nor about any user, and a
        // foreign key to a record that does not exist.
        return [null, null, $userId];
    }

    /**
     * The subject a notification named for itself.
     *
     * @return array{0:string,1:int}|null
     */
    private function taggedSubject(Email $message): ?array
    {
        $header = $message->getHeaders()->get(EmailLog::SUBJECT_HEADER);

        if (!$header) {
            return null;
        }

        [$class, $id] = array_pad(explode(':', (string) $header->getBodyAsString(), 2), 2, null);

        if (!$class || !$id || !class_exists($class)) {
            return null;
        }

        return [$class, (int) $id];
    }
}
