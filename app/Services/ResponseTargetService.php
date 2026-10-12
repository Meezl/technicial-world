<?php

namespace App\Services;

use App\Models\ServiceRequest;
use App\Support\OfficeHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When the office has said it will come back to a client.
 *
 * Every client was told the same thing — "acted on within 2 hours" — whatever
 * they had marked the job and whatever time they sent it. A duration also
 * decays into a lie: it is false three hours later, and the reminder built on
 * it had to open by accusing the office of breaking a promise it may never
 * have made.
 *
 * So the promise is a moment, not a countdown. A moment is still true at 3am,
 * the client can hold us to it, and it cannot land in the middle of the night
 * because it is measured on the office clock.
 */
class ResponseTargetService
{
    public function __construct(private readonly OfficeHours $hours)
    {
    }

    public static function make(): self
    {
        return new self(OfficeHours::fromConfig());
    }

    /** Working hours allowed for a first response at this urgency. */
    public function allowanceFor(?string $urgency): float
    {
        $map = (array) config('service_levels.response_hours', []);

        return (float) ($map[strtolower((string) $urgency)]
            ?? config('service_levels.default_response_hours', 12));
    }

    /** Working hours between reminders at this urgency. */
    public function reminderIntervalFor(?string $urgency): float
    {
        $map = (array) config('service_levels.reminder_hours', []);

        return (float) ($map[strtolower((string) $urgency)]
            ?? config('service_levels.default_reminder_hours', 4));
    }

    private function isExemptFromSlowDays(?string $urgency): bool
    {
        return in_array(
            strtolower((string) $urgency),
            array_map('strtolower', (array) config('service_levels.slow_day_exempt_urgencies', [])),
            true
        );
    }

    /**
     * The moment the office has committed to, for a request raised at $from.
     */
    public function dueAt(?string $urgency, CarbonInterface $from): CarbonImmutable
    {
        $target = $this->hours->addWorkingHours($from, $this->allowanceFor($urgency));

        return $this->hours->settle($target, $this->isExemptFromSlowDays($urgency));
    }

    public function dueAtFor(ServiceRequest $serviceRequest): CarbonImmutable
    {
        return $this->dueAt(
            $serviceRequest->urgency,
            $serviceRequest->created_at ?? CarbonImmutable::now()
        );
    }

    /**
     * The promise in the client's own words.
     *
     * Named days rather than dates where it reads better — "by 10:00 tomorrow
     * morning" is something somebody can hold in their head; "by 2026-10-13
     * 10:00" is something they have to decode.
     */
    public function phrase(CarbonInterface $due, ?CarbonInterface $now = null): string
    {
        $now = $now ? CarbonImmutable::instance($now->toDateTime()) : CarbonImmutable::now();
        $time = $due->format('g:ia');

        if ($due->isSameDay($now)) {
            return "by {$time} today";
        }

        if ($due->isSameDay($now->addDay())) {
            return "by {$time} tomorrow";
        }

        // Inside the coming week a weekday name is clearer than a date.
        if ($due->lt($now->addDays(7))) {
            return 'by ' . $due->format('l') . " at {$time}";
        }

        return 'by ' . $due->format('D j M') . " at {$time}";
    }

    /** Is the office late answering this one? */
    public function isOverdue(ServiceRequest $serviceRequest, ?CarbonInterface $now = null): bool
    {
        $due = $serviceRequest->response_due_at;

        if (!$due) {
            return false;
        }

        return CarbonImmutable::instance(($now ?? CarbonImmutable::now())->toDateTime())->gt($due);
    }
}
