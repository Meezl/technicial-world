<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Task-average progress rules: from when
    |--------------------------------------------------------------------------
    |
    | Jobs created on or after this moment (Africa/Nairobi) follow the rules
    | the product owner set on 2 November 2026: a whole-job report on a job
    | split into tasks carries the task average, and the lead can set any
    | task's bar with immediate effect.
    |
    | Jobs created before it keep the rules they were run under: a whole-job
    | report keeps the figure typed into it, and the lead moves only their own
    | bar, as a claim the office settles. Set PROGRESS_TASK_AVERAGE_FROM to the
    | exact deploy time if that should be the line instead.
    |
    */

    'task_average_from' => env('PROGRESS_TASK_AVERAGE_FROM', '2026-11-03 00:00:00'),

];
