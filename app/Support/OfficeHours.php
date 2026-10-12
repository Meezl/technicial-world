<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The office clock.
 *
 * Promises and reminders were both measured in wall-clock hours, which is why
 * a client raising a low-urgency request at 23:00 was told it would be acted
 * on by 01:00, and why the team got seven reminders between midnight and
 * dawn. Time only passes here while somebody is at a desk.
 *
 * Deliberately a small, pure calculator: no database, no models, no clock of
 * its own beyond what it is handed. The rules it reads live in
 * config/service_levels.php so the office can move its own hours.
 */
class OfficeHours
{
    public function __construct(
        private readonly int $startHour,
        private readonly int $endHour,
        /** @var array<int,int> ISO weekday numbers, 1 = Monday. */
        private readonly array $workingDays,
        /** @var array<int,int> Working, but too thin to promise ordinary work on. */
        private readonly array $slowDays = [],
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (int) config('service_levels.working_hours.start', 8),
            (int) config('service_levels.working_hours.end', 17),
            (array) config('service_levels.working_days', [1, 2, 3, 4, 5, 6, 7]),
            (array) config('service_levels.slow_days', []),
        );
    }

    public function isWorkingDay(CarbonInterface $moment): bool
    {
        return in_array($moment->isoWeekday(), $this->workingDays, true);
    }

    public function isSlowDay(CarbonInterface $moment): bool
    {
        return in_array($moment->isoWeekday(), $this->slowDays, true);
    }

    public function isOpen(CarbonInterface $moment): bool
    {
        return $this->isWorkingDay($moment)
            && $moment->hour >= $this->startHour
            && $moment->hour < $this->endHour;
    }

    /**
     * The first instant the office is open at or after this one.
     *
     * A request raised at 23:00 is answered from 08:00, not from midnight.
     */
    public function nextOpening(CarbonInterface $moment): CarbonImmutable
    {
        $at = CarbonImmutable::instance($moment->toDateTime())->setTimezone($moment->getTimezone());

        // A week is further than any sane calendar needs; the bound is there
        // so a misconfigured working_days cannot spin forever.
        for ($i = 0; $i <= 14; $i++) {
            if ($this->isWorkingDay($at)) {
                $opens = $at->setTime($this->startHour, 0);
                $closes = $at->setTime($this->endHour, 0);

                if ($at->lt($opens)) {
                    return $opens;
                }

                if ($at->lt($closes)) {
                    return $at;
                }
            }

            $at = $at->addDay()->setTime($this->startHour, 0);
        }

        return $at;
    }

    /**
     * Move forward by a number of hours of office time.
     *
     * The clock stops at closing and restarts at opening, so "twelve hours"
     * from mid-afternoon is late the following morning rather than the middle
     * of the night.
     */
    public function addWorkingHours(CarbonInterface $from, float $hours): CarbonImmutable
    {
        $at = $this->nextOpening($from);
        $remaining = max(0.0, $hours);

        for ($i = 0; $i <= 400 && $remaining > 0.0001; $i++) {
            $closes = $at->setTime($this->endHour, 0);
            $availableToday = ($closes->getTimestamp() - $at->getTimestamp()) / 3600;

            if ($remaining <= $availableToday) {
                return $at->addSeconds((int) round($remaining * 3600));
            }

            $remaining -= $availableToday;
            $at = $this->nextOpening($closes->addSecond());
        }

        return $at;
    }

    /**
     * Where a promise is allowed to come to rest.
     *
     * A target landing on a day the office cannot staff properly is moved to
     * the next day it can, unless the urgency is one that is honoured anyway.
     * Moving it later is the honest direction: a promise that has to be
     * broken on a quiet Sunday was never worth making.
     */
    public function settle(CarbonImmutable $target, bool $exemptFromSlowDays = false): CarbonImmutable
    {
        if ($exemptFromSlowDays || $this->slowDays === []) {
            return $target;
        }

        for ($i = 0; $i <= 14 && $this->isSlowDay($target); $i++) {
            $target = $this->nextOpening($target->addDay()->setTime($this->startHour, 0));
        }

        return $target;
    }
}
