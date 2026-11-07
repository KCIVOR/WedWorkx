<?php

namespace App\Support;

/**
 * The single question "is dev mode on?". Always false in production, whatever DEV_MODE says,
 * so a forgotten setting can never turn simulated payments on for real customers.
 */
final class DevMode
{
    public static function enabled(): bool
    {
        return (bool) config('dev.enabled') && ! app()->isProduction();
    }
}
