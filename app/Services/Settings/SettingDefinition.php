<?php

namespace App\Services\Settings;

use App\Enums\SettingType;

/**
 * A setting that existing business logic actually READS. Only these keys change behaviour; creating any other key does not
 * create new logic. Known keys have a forced type, a valid range, a fallback (the existing config / .env default,
 * then a hard-coded last resort) and cannot be deleted.
 */
final class SettingDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly SettingType $type,
        public readonly string $group,
        public readonly string $description,
        public readonly string $configPath,   // existing default, e.g. vistaro.shipping.flat_fee (overridable via .env)
        public readonly int $hardDefault,     // last resort when both the row and the config value are missing/invalid
        public readonly int $min,
        public readonly int $max,
        public readonly bool $isPublic = true,
    ) {
    }

    public function accepts(int $v): bool
    {
        return $v >= $this->min && $v <= $this->max;
    }

    /** The value used when the stored row is absent or invalid: config default if valid, else the hard-coded default. */
    public function fallback(): int
    {
        $c = config($this->configPath);

        return is_numeric($c) && (int) $c == $c && $this->accepts((int) $c) ? (int) $c : $this->hardDefault;
    }
}
