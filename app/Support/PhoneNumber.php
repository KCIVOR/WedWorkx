<?php

namespace App\Support;

final class PhoneNumber
{
    /** Public business contacts may be mobile, landline, or international. Never fill missing digits. */
    public static function normalizePublic(?string $value): ?string
    {
        $v = preg_replace('/[\s\-().]/', '', (string) $value);
        if (preg_match('/^0[1-9]\d{8,9}$/', $v)) $v = '+63'.substr($v, 1);
        if (! preg_match('/^\+[1-9]\d{7,14}$/', $v)) return null;
        if (str_starts_with($v, '+63') && ! preg_match('/^\+63(?:9\d{9}|[2-8]\d{8,9})$/', $v)) return null;
        return $v;
    }

    /**
     * Accepts 09XXXXXXXXX and +639XXXXXXXXX (spaces, dashes and brackets are ignored).
     * Returns the one stored form, +639XXXXXXXXX, or null when the number is not valid.
     */
    public static function normalize(?string $value): ?string
    {
        $v = preg_replace('/[\s\-().]/', '', (string) $value);

        if (preg_match('/^09(\d{9})$/', $v, $m) || preg_match('/^\+639(\d{9})$/', $v, $m)) {
            return '+639'.$m[1];
        }

        return null;
    }
}
