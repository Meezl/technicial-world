<?php

namespace Tests\Feature;

use App\Services\ResponseTargetService;
use App\Support\OfficeHours;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The office clock, on its own.
 *
 * Everything the client is promised and everything the office is reminded
 * about rests on this, so it is worth testing away from the models. 8am to
 * 5pm, seven days, Sunday open but too thin to promise ordinary work on.
 */
class OfficeHoursTest extends TestCase
{
    private function at(string $when): CarbonImmutable
    {
        return CarbonImmutable::parse($when, config('app.timezone'));
    }

    private function hours(): OfficeHours
    {
        return OfficeHours::fromConfig();
    }

    public function test_the_clock_only_runs_while_the_office_is_open(): void
    {
        $hours = $this->hours();

        $this->assertFalse($hours->isOpen($this->at('2026-10-14 07:59')));
        $this->assertTrue($hours->isOpen($this->at('2026-10-14 08:00')));
        $this->assertTrue($hours->isOpen($this->at('2026-10-14 16:59')));
        $this->assertFalse($hours->isOpen($this->at('2026-10-14 17:00')));
        $this->assertFalse($hours->isOpen($this->at('2026-10-14 23:30')));
    }

    public function test_time_raised_before_opening_starts_counting_at_eight(): void
    {
        // 06:00 plus two working hours is 10:00, not 08:00.
        $this->assertSame(
            '2026-10-14 10:00',
            $this->hours()->addWorkingHours($this->at('2026-10-14 06:00'), 2)->format('Y-m-d H:i')
        );
    }

    public function test_the_clock_stops_overnight(): void
    {
        // 16:00 plus two working hours: one before close, one after opening.
        $this->assertSame(
            '2026-10-15 09:00',
            $this->hours()->addWorkingHours($this->at('2026-10-14 16:00'), 2)->format('Y-m-d H:i')
        );
    }

    public function test_a_long_allowance_spans_several_days(): void
    {
        // A working day is nine hours. Twelve from 09:00 Wednesday is eight
        // hours to close, then four more from Thursday's opening.
        $this->assertSame(
            '2026-10-15 12:00',
            $this->hours()->addWorkingHours($this->at('2026-10-14 09:00'), 12)->format('Y-m-d H:i')
        );
    }

    public function test_an_ordinary_promise_does_not_come_to_rest_on_the_slow_day(): void
    {
        $targets = ResponseTargetService::make();

        // Saturday afternoon, low urgency. Landing on Sunday is a promise the
        // office cannot staff, so it moves to Monday rather than being made.
        $due = $targets->dueAt('low', $this->at('2026-10-17 15:00'));

        $this->assertNotSame('Sunday', $due->format('l'), 'An ordinary promise landed on the slow day.');
        $this->assertSame('Monday', $due->format('l'));
    }

    public function test_an_urgent_promise_is_honoured_on_the_slow_day(): void
    {
        $targets = ResponseTargetService::make();

        // An emergency on a Sunday morning is still an emergency. The office
        // is open; it is only thin.
        $due = $targets->dueAt('high', $this->at('2026-10-18 09:00'));

        $this->assertSame('Sunday', $due->format('l'));
        $this->assertSame('2026-10-18 11:00', $due->format('Y-m-d H:i'));
    }

    public function test_the_promise_is_phrased_so_somebody_can_hold_us_to_it(): void
    {
        $targets = ResponseTargetService::make();
        $now = $this->at('2026-10-14 09:00');

        $this->assertSame('by 11:00am today', $targets->phrase($this->at('2026-10-14 11:00'), $now));
        $this->assertSame('by 10:00am tomorrow', $targets->phrase($this->at('2026-10-15 10:00'), $now));
        $this->assertSame('by Friday at 4:00pm', $targets->phrase($this->at('2026-10-16 16:00'), $now));
    }

    public function test_an_unknown_urgency_still_gets_a_promise(): void
    {
        $targets = ResponseTargetService::make();

        // Nothing may fall through without one — a request with no promise is
        // the state this whole thing exists to remove.
        $this->assertSame(
            (float) config('service_levels.default_response_hours'),
            $targets->allowanceFor('whatever-this-is')
        );
    }
}
