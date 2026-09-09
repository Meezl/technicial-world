<?php

/**
 * The Property Management & Corporate module.
 *
 * Off everywhere until the module is finished. The work lands in phases (see
 * PROPERTY_MANAGEMENT_MODULE_PLAN.md) and each phase ships to main long before
 * the whole is usable, so the flag is what stops a half-built corporate journey
 * being reachable by a real client on a Railway deploy.
 *
 * It gates entry points only — routes, menu items, the segment picker. It must
 * never gate the reading of data that already exists: a corporate request that
 * was created while the flag was on stays out of the retail queues when it goes
 * off again, because leaking it back in is the failure this flag exists to
 * prevent.
 */
return [
    'enabled' => env('CORPORATE_MODULE_ENABLED', false),
];
