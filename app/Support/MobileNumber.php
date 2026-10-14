<?php

namespace App\Support;

/**
 * One canonical form for a mobile number (E.164, e.g. +919876543210). OTP limits, user lookup and the
 * unique index all use this form, so "9876543210", "+91 98765-43210" and "09876543210" are the SAME person
 * and cannot be used to dodge the daily OTP limit.
 */
final class MobileNumber
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $s = preg_replace('/[\s\-().]/', '', trim($raw));
        if ($s === '' || $s === null) {
            return null;
        }

        $cc = (string) config('vistaro.auth.default_country_code', '91');

        if (str_starts_with($s, '+')) {
            $digits = substr($s, 1);
        } elseif (str_starts_with($s, '00')) {
            $digits = substr($s, 2);
        } else {
            $digits = $s;
            if (ctype_digit($digits)) {
                if (strlen($digits) === 10) {
                    $digits = $cc . $digits;                       // national number
                } elseif (strlen($digits) === 11 && $digits[0] === '0') {
                    $digits = $cc . substr($digits, 1);            // trunk prefix 0
                }
            }
        }

        if ($digits === '' || ! ctype_digit($digits) || $digits[0] === '0' || strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        // Indian numbers: exactly 10 subscriber digits, starting 6-9.
        if (str_starts_with($digits, '91') && ! preg_match('/^91[6-9]\d{9}$/', $digits)) {
            return null;
        }

        return '+' . $digits;
    }

    /** +91******3210 : safe for logs and API responses. */
    public static function mask(string $mobile): string
    {
        $len = strlen($mobile);

        return $len <= 7 ? str_repeat('*', $len) : substr($mobile, 0, 3) . str_repeat('*', $len - 7) . substr($mobile, -4);
    }
}
