<?php

namespace App\Support;

class Money
{
    /** Balances and rates: at least 2, at most 4 decimals (0.015 stays 0.015, 5 becomes 5.00). */
    public static function fmt(float|int|string|null $v): string
    {
        $s = number_format((float) $v, 4, '.', '');
        $s = rtrim($s, '0');
        $decimals = strlen(substr(strrchr($s, '.') ?: '.', 1));

        return $decimals < 2 ? number_format((float) $v, 2, '.', '') : $s;
    }

    /** Amount with the currency symbol, e.g. ৳12.50 (negative: -৳2.00). */
    public static function tk(float|int|string|null $v): string
    {
        $n = (float) $v;

        return ($n < 0 ? '-' : '').config('broadcast.currency', '৳').self::fmt(abs($n));
    }

    /** Human rate: "৳0.50 per 60 sec". */
    public static function rate(float|int|string|null $rate, int $seconds): string
    {
        return self::tk($rate).' per '.$seconds.' sec';
    }
}
