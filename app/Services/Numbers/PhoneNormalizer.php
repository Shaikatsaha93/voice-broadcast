<?php

namespace App\Services\Numbers;

class PhoneNormalizer
{
    /** Returns digits-only international number (no +) or null when invalid. */
    public static function normalize(string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', trim($raw));
        if ($d === '' || $d === null) {
            return null;
        }
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        } elseif (str_starts_with($d, '0')) {
            $d = config('broadcast.default_country_prefix').substr($d, 1);
        }

        return preg_match('/^[1-9]\d{7,14}$/', $d) ? $d : null;
    }
}
