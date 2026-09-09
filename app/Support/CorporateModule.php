<?php

namespace App\Support;

/**
 * One place to ask whether the corporate module is switched on.
 *
 * A single reader of the config value, so the flag can later become a per-user
 * or per-organisation decision without hunting down `config('corporate.enabled')`
 * scattered through controllers.
 */
class CorporateModule
{
    public static function enabled(): bool
    {
        return (bool) config('corporate.enabled', false);
    }

    /**
     * Segment options an admin may pick from when raising a request.
     *
     * Retail is always offered. Corporate appears only once the module is on,
     * which is what keeps a half-built journey out of reach without a second
     * flag check at every form.
     */
    public static function availableSegments(): array
    {
        $segments = [\App\Models\ServiceRequest::SEGMENT_RETAIL];

        if (self::enabled()) {
            $segments[] = \App\Models\ServiceRequest::SEGMENT_CORPORATE;
        }

        return $segments;
    }
}
