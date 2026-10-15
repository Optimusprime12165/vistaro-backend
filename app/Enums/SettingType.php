<?php

namespace App\Enums;

use App\Exceptions\InvalidSettingException;

/**
 * The deliberately small set of value types a business setting can have.
 * Every type has ONE canonical stored form (a string), so serialisation is deterministic:
 *   string  -> as given (max 5000 chars)            integer -> "-12" / "0" / "1999"
 *   decimal -> plain decimal, no exponent, trailing zeros stripped ("10.50" -> "10.5")
 *   boolean -> "1" / "0"                            email / url -> trimmed string, validated
 *   json    -> compact JSON of an array/object (invalid JSON, scalars and "null" are rejected)
 * Phone numbers are plain `string` settings, so leading zeros / "+" are never lost.
 */
enum SettingType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Email = 'email';
    case Url = 'url';
    case Json = 'json';

    public const MAX_STRING = 5000;
    public const MAX_JSON_BYTES = 65535;

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }

    /** Validates $value for this type and returns its canonical stored string. @throws InvalidSettingException */
    public function normalize(mixed $value): string
    {
        return match ($this) {
            self::String => $this->asString($value),
            self::Integer => $this->asInteger($value),
            self::Decimal => $this->asDecimal($value),
            self::Boolean => $this->asBoolean($value),
            self::Email => $this->asEmail($value),
            self::Url => $this->asUrl($value),
            self::Json => $this->asJson($value),
        };
    }

    /** Stored string -> typed PHP value. decimal stays a STRING (no float drift); json becomes an array. @throws InvalidSettingException when the stored text is corrupt */
    public function cast(string $stored): mixed
    {
        return match ($this) {
            self::String, self::Email, self::Url, self::Decimal => $stored,
            self::Integer => $this->castInteger($stored),
            self::Boolean => match ($stored) {
                '1' => true,
                '0' => false,
                default => throw $this->bad('Stored boolean is corrupt.'),
            },
            self::Json => $this->castJson($stored),
        };
    }

    private function asString(mixed $v): string
    {
        if (! is_string($v)) {
            throw $this->bad('The value must be a string.');
        }
        if (mb_strlen($v) > self::MAX_STRING) {
            throw $this->bad('The value may not be longer than ' . self::MAX_STRING . ' characters.');
        }

        return $v;
    }

    private function asInteger(mixed $v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_string($v) && preg_match('/^-?(0|[1-9][0-9]{0,17})$/', $v) === 1 && $v !== '-0') {
            return (string) (int) $v;
        }

        throw $this->bad('The value must be a whole number (e.g. 99).');
    }

    private function asDecimal(mixed $v): string
    {
        if (is_int($v)) {
            $v = (string) $v;
        } elseif (is_float($v)) {
            if (! is_finite($v)) {
                throw $this->bad('The value must be a finite decimal number.');
            }
            $v = rtrim(rtrim(number_format($v, 8, '.', ''), '0'), '.');
            $v = $v === '' || $v === '-' ? '0' : $v;
        }
        if (! is_string($v) || preg_match('/^(-?)(0|[1-9][0-9]{0,17})(?:\.([0-9]{1,8}))?$/', trim($v), $m) !== 1) {
            throw $this->bad('The value must be a plain decimal number (e.g. 10.50), up to 8 decimal places.');
        }
        $frac = rtrim($m[3] ?? '', '0');
        $sign = $m[1] === '-' && ($m[2] !== '0' || $frac !== '') ? '-' : '';

        return $sign . $m[2] . ($frac !== '' ? '.' . $frac : '');
    }

    private function asBoolean(mixed $v): string
    {
        return match (true) {
            $v === true, $v === 1, $v === '1', $v === 'true' => '1',
            $v === false, $v === 0, $v === '0', $v === 'false' => '0',
            default => throw $this->bad('The value must be true or false.'),
        };
    }

    private function asEmail(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : $v;
        if (! is_string($v) || strlen($v) > 254 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            throw $this->bad('The value must be a valid email address.');
        }

        return $v;
    }

    private function asUrl(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : $v;
        if (! is_string($v) || strlen($v) > 2000 || filter_var($v, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($v, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw $this->bad('The value must be a valid http(s) URL.');
        }

        return $v;
    }

    private function asJson(mixed $v): string
    {
        if (is_string($v)) {
            $decoded = json_decode($v, false, 32);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw $this->bad('The value is not valid JSON.');
            }
            $v = $decoded;
        } elseif (is_array($v)) {
            $v = json_decode((string) json_encode($v, JSON_PRESERVE_ZERO_FRACTION), false, 32);   // normalise nested arrays like decoded text
        }
        if (! is_array($v) && ! is_object($v)) {
            throw $this->bad('The value must be a JSON object or array.');
        }
        $json = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false || strlen($json) > self::MAX_JSON_BYTES) {
            throw $this->bad('The JSON value is invalid or larger than ' . self::MAX_JSON_BYTES . ' bytes.');
        }

        return $json;
    }

    private function castInteger(string $s): int
    {
        if (preg_match('/^-?(0|[1-9][0-9]{0,17})$/', $s) !== 1) {
            throw $this->bad('Stored integer is corrupt.');
        }

        return (int) $s;
    }

    private function castJson(string $s): mixed
    {
        $v = json_decode($s, true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($v)) {
            throw $this->bad('Stored JSON is corrupt.');
        }

        return $v;
    }

    private function bad(string $message): InvalidSettingException
    {
        return new InvalidSettingException($message);
    }
}
