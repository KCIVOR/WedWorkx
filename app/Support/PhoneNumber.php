<?php

namespace App\Support;

final class PhoneNumber
{
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
