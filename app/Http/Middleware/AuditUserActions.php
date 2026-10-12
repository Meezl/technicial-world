<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The floor under the audit trail.
 *
 * Every write that matters is supposed to log itself with a named action and a
 * diff, and those entries are far more useful than anything a middleware can
 * infer. But the named calls are sprinkled across controllers and services by
 * hand, so coverage was whatever each author remembered: the whole client side
 * of the pipeline — raising a request, declining a quote, confirming arrival,
 * paying — wrote nothing at all, which made the busier half of the platform
 * the half with no history.
 *
 * So this runs after every authenticated state-changing request and, if
 * nothing logged during it, writes one catch-all entry: the actor, the route,
 * the address, the agent, and the input with secrets stripped. It is the
 * weaker record on purpose. A route that deserves a real action name should
 * still call AuditLog::log() and this will then stay out of its way.
 *
 * Deliberately not an Eloquent observer. Model events would catch the writes
 * but not the actor's intent, would double up on the explicit entries, and
 * would log nothing for an action whose effect is a file, a mail or an
 * external call rather than a column.
 */
class AuditUserActions
{
    /** Reads leave no trace worth keeping; the log would be nothing else. */
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * The reads that are an exception, by route name.
     *
     * A download is not really a read: a technician's ID, a client revenue
     * export, an invoice PDF — the data leaves the platform, and whether it
     * did is a question somebody eventually asks. Matched on the route's name
     * rather than a list of routes, so the next export added is audited the
     * day it ships instead of the day somebody remembers this file.
     */
    private const SENSITIVE_READ_PATTERN = '/\.(download|export|pdf)$|documents?\.show$/';

    /**
     * Routes whose own records are better than anything written here.
     *
     * Safaricom's webhooks are unauthenticated anyway, and every one of them
     * already lands in mpesa_transactions with the payload intact. Logging out
     * is covered by its own listener, which fires after the session is gone
     * and so after this middleware can still see who it was.
     */
    private const IGNORED_ROUTES = [
        'logout',
    ];

    private const IGNORED_PATHS = [
        'api/mpesa/*',
        'api/transactions/*',
    ];

    /**
     * Never persisted, whatever the field is called.
     *
     * The catch-all records input precisely because nobody chose what it
     * should record, which means a password or an M-Pesa PIN would go in with
     * everything else. An audit table is widely readable by design — that is
     * the point of it — so a secret in here is a secret published.
     */
    private const REDACTED = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        '_token',
        'api_key',
        'secret',
        'pin',
        'otp',
        'cvv',
        'card_number',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        AuditLog::resetRequestCounter();

        $response = $next($request);

        try {
            $this->record($request, $response);
        } catch (\Throwable $e) {
            // A gap in the trail is not worth failing a client's payment over.
            \Illuminate\Support\Facades\Log::warning('Audit middleware failed', [
                'path' => $request->path(),
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        if ($this->isIgnored($request)) {
            return;
        }

        $user = $request->user();
        $status = $response->getStatusCode();
        $isRead = in_array($request->method(), self::READ_METHODS, true);

        if ($isRead) {
            // Only the copies that leave the platform, and only the ones that
            // succeeded — a download that 404s disclosed nothing.
            if ($status >= 400 || !$this->isSensitiveRead($request)) {
                return;
            }

            $this->write(AuditLog::ACTION_DOWNLOAD, $request, $user);

            return;
        }

        // Refused. Nothing changed, so it is recorded as an attempt rather
        // than an action — a separate verb, so it can be filtered out of a
        // view of what actually happened and found on its own when the
        // question is who tried.
        if ($status === 401 || $status === 403) {
            $this->write(AuditLog::ACTION_DENIED, $request, $user, ['status' => $status]);

            return;
        }

        // Everything else that failed changed nothing either, and validation
        // failures are the bulk of what a portal receives. Recording them
        // would bury the actions that did take effect.
        if ($status >= 400) {
            return;
        }

        if (AuditLog::writesThisRequest() > 0) {
            return;
        }

        $this->write(AuditLog::ACTION_REQUEST, $request, $user);
    }

    /**
     * One writer for all three, so they cannot describe the same request
     * differently.
     *
     * The actor may be absent. A guest submitting the contact form or an
     * enquiry is doing something worth keeping, and refusing to record it
     * because nobody was signed in would leave the public edge of the
     * platform as the part with no history.
     */
    private function write(string $action, Request $request, ?User $user, array $extra = []): void
    {
        [$type, $id] = $this->subjectOf($request, $user);

        AuditLog::record(
            $action,
            $type,
            $id,
            null,
            array_filter(array_merge([
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => '/' . ltrim($request->path(), '/'),
                'role' => $user?->role ?? 'guest',
                'input' => $this->safeInput($request) ?: null,
            ], $extra), fn ($value) => $value !== null),
            $user?->id
        );
    }

    private function isSensitiveRead(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name !== null && preg_match(self::SENSITIVE_READ_PATTERN, $name) === 1;
    }

    private function isIgnored(Request $request): bool
    {
        if (in_array($request->route()?->getName(), self::IGNORED_ROUTES, true)) {
            return true;
        }

        return $request->is(...self::IGNORED_PATHS);
    }

    /**
     * What the request was about.
     *
     * The first model Laravel resolved out of the URL is almost always the
     * subject — the job, the payment, the document — which makes the entry
     * show up alongside that record's own history rather than in a flat list
     * of paths. With no model bound, the actor is the only honest subject.
     */
    private function subjectOf(Request $request, ?User $user): array
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model && $parameter->getKey() !== null) {
                return [get_class($parameter), (int) $parameter->getKey()];
            }
        }

        if ($user instanceof User) {
            return [User::class, (int) $user->id];
        }

        // A guest's submission has no subject the middleware can see — the
        // record it creates is made after this runs. Left null rather than
        // pointed at a sentinel.
        return [null, null];
    }

    private function safeInput(Request $request): array
    {
        $input = $request->except(self::REDACTED);

        // Uploads are recorded by name and size; the file itself belongs on
        // disk, and serialising it into a JSON column would be a copy nobody
        // asked for.
        foreach ($request->allFiles() as $field => $files) {
            $files = is_array($files) ? $files : [$files];

            $input[$field] = array_map(fn ($file) => [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ], $files);
        }

        return $this->truncate($input);
    }

    /**
     * Keep one entry from swallowing a long description or a base64 blob.
     */
    private function truncate(array $input, int $limit = 500): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->truncate($value, $limit);
                continue;
            }

            if (is_string($value) && mb_strlen($value) > $limit) {
                $input[$key] = mb_substr($value, 0, $limit) . '… [truncated]';
            }
        }

        return $input;
    }
}
