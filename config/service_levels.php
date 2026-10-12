<?php

/**
 * When the office opens, and what the client is told to expect.
 *
 * The confirmation email promised every client their request would be "acted
 * on within 2 hours" — the same words at every urgency and at every hour of
 * the night, so a low-urgency request raised at 23:00 promised action by
 * 01:00. The office reminder repeated the promise back to the team every two
 * hours round the clock, which overnight is seven emails nobody can act on
 * and a lesson that the channel can be ignored.
 *
 * Everything here is in the app's timezone (Africa/Nairobi).
 */
return [

    'working_hours' => [
        // The office day. A promise never falls outside it.
        'start' => (int) env('OFFICE_OPENS_HOUR', 8),
        'end' => (int) env('OFFICE_CLOSES_HOUR', 17),
    ],

    /**
     * Days the office works, as ISO weekday numbers (1 = Monday, 7 = Sunday).
     *
     * Sunday is open but slow, which is a different thing from closed. It
     * counts as working time — so an emergency raised on a Sunday morning is
     * still promised a Sunday answer — but see `slow_days` below for what it
     * does to the ordinary queue.
     */
    'working_days' => [1, 2, 3, 4, 5, 6, 7],

    /**
     * Days too thinly staffed to promise ordinary work on.
     *
     * A target that would land on one of these rolls to the next day for
     * everything except the urgency listed in `slow_day_exempt_urgencies`.
     * The hours still count — this only moves where the promise comes to
     * rest, so the office is never committed to a Sunday it cannot staff.
     */
    'slow_days' => [7],

    /** Urgencies that are honoured on a slow day anyway. */
    'slow_day_exempt_urgencies' => ['high'],

    /**
     * How long the client is told to expect, in working hours.
     *
     * Counted only while the office is open, so the clock stops at 17:00 and
     * restarts at 08:00. Twelve is the floor the business set for anything
     * that is not urgent; a working day is nine hours, so twelve lands mid
     * morning the following day.
     */
    'response_hours' => [
        'high' => (float) env('RESPONSE_HOURS_HIGH', 2),
        'medium' => (float) env('RESPONSE_HOURS_MEDIUM', 12),
        'low' => (float) env('RESPONSE_HOURS_LOW', 18),
    ],

    'default_response_hours' => (float) env('RESPONSE_HOURS_DEFAULT', 12),

    /**
     * How often the office is reminded about a request nobody has answered,
     * in working hours, by urgency.
     *
     * Reminders only go out during working hours. Repeating every two hours
     * through the night taught people to ignore the channel, which is the
     * opposite of what a reminder is for.
     */
    'reminder_hours' => [
        'high' => (float) env('REMINDER_HOURS_HIGH', 1),
        'medium' => (float) env('REMINDER_HOURS_MEDIUM', 4),
        'low' => (float) env('REMINDER_HOURS_LOW', 9),
    ],

    'default_reminder_hours' => (float) env('REMINDER_HOURS_DEFAULT', 4),

    /**
     * Reminders stop after this many.
     *
     * Repetition without escalation is noise. After the cap the request is
     * still on every queue and every dashboard that shows unactioned work —
     * it stops being emailed about, which is not the same as being forgotten.
     */
    'max_reminders' => (int) env('RFQ_MAX_REMINDERS', 6),

    /**
     * The reminder number at which the office is told plainly that this has
     * gone past the point of a nudge.
     */
    'escalate_after' => (int) env('RFQ_ESCALATE_AFTER', 3),
];
