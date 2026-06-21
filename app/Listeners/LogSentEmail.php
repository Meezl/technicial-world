<?php

namespace App\Listeners;

use App\Models\EmailLog;
use App\Models\ServiceRequest;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;

class LogSentEmail
{
    /**
     * Persist a full copy of every email Laravel sends so the admin can
     * retrieve the exact content that hit the recipient's inbox (#5).
     */
    public function handle(MessageSent $event): void
    {
        try {
            $message = $event->message;
            $envelope = $message->getEnvelope();
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

            $from = $envelope->getSender();

            // Try to resolve a related model from $data (Mailables typically
            // share their context, including `serviceRequest`, `paymentRequest`, etc.)
            [$relatedType, $relatedId, $userId] = $this->resolveRelated($data, $addressList($envelope->getRecipients()));

            EmailLog::create([
                'mailable_class' => isset($data['mailable_class']) ? $data['mailable_class'] : (isset($data['__mailable_class']) ? $data['__mailable_class'] : null),
                'subject'        => $message->getSubject() ?? '(no subject)',
                'to'             => $addressList($envelope->getRecipients()),
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
            // Never let logging break delivery
            \Illuminate\Support\Facades\Log::warning('EmailLog persistence failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveRelated(array $data, array $recipients): array
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
        return [User::class, $userId ?? 0, $userId];
    }
}
