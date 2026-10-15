<?php

namespace App\Support;

/**
 * Defence in depth for business_settings, which is NOT a place for secrets (DB credentials, app keys, gateway secrets,
 * access tokens, passwords belong in the environment / a secrets manager). Refuses keys that are named like secrets and values
 * that look like well-known credential formats. This is a safety net, not a guarantee: the rule is still "never put secrets here".
 */
final class SecretDetector
{
    private const KEY = '/(password|passwd|passphrase|pwd|secret|token|credential|api_?key|private_?key|access_?key|signing_?key|encryption_?key|app_?key|bearer|auth_?header)/i';

    private const VALUE = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{10,}/',
        '/^base64:[A-Za-z0-9+\/=]{40,}$/',
        '/^eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}$/',
        '/^[a-z][a-z0-9+.-]*:\/\/[^\/\s:@]+:[^\/\s@]+@/i',
        '/\b(?:ghp|gho|ghs|github_pat)_[A-Za-z0-9_]{20,}/',
        '/\bxox[baprs]-[A-Za-z0-9-]{10,}/',
        '/^Bearer\s+[A-Za-z0-9._~+\/-]{16,}=*$/i',
    ];

    public static function keyLooksSecret(string $key): bool
    {
        return preg_match(self::KEY, $key) === 1;
    }

    /** Scans strings, and every nested key and string of arrays/objects. */
    public static function valueLooksSecret(mixed $value): bool
    {
        if (is_string($value)) {
            foreach (self::VALUE as $pattern) {
                if (preg_match($pattern, trim($value)) === 1) {
                    return true;
                }
            }

            return false;
        }
        if (is_array($value) || is_object($value)) {
            foreach ((array) $value as $k => $v) {
                if ((is_string($k) && self::keyLooksSecret($k)) || self::valueLooksSecret($v)) {
                    return true;
                }
            }
        }

        return false;
    }
}
