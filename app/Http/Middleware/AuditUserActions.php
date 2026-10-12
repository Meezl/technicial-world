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
    private const IGNORED_METHODS = ['GET', 'HEAD', 'OPTIONS'];

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
        if (in_array($request->method(), self::IGNORED_METHODS, true)) {
            return;
        }

        $user = $request->user();

        if (!$user instanceof User) {
            return;
        }

        // A rejected request changed nothing. Validation failures and 403s are
        // the bulk of what a portal receives, and recording them would bury
        // the actions that did take effect. Authentication failures are the
        // exception worth having, but they belong with the login listener,
        // which sees the attempt this middleware never does.
        if ($response->getStatusCode() >= 400) {
            return;
        }

        if (AuditLog::writesThisRequest() > 0) {
            return;
        }

        if ($this->isIgnored($request)) {
            return;
        }

        [$type, $id] = $this->subjectOf($request, $user);

        AuditLog::record(
            AuditLog::ACTION_REQUEST,
            $type,
            $id,
            null,
            array_filter([
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => '/' . ltrim($request->path(), '/'),
                'role' => $user->role,
                'input' => $this->safeInput($request) ?: null,
            ], fn ($value) => $value !== null)
        );
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
    private function subjectOf(Request $request, User $user): array
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model && $parameter->getKey() !== null) {
                return [get_class($parameter), (int) $parameter->getKey()];
            }
        }

        return [User::class, (int) $user->id];
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
