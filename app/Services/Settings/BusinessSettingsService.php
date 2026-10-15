<?php

namespace App\Services\Settings;

use App\Enums\SettingType;
use App\Exceptions\ApiException;
use App\Exceptions\InvalidSettingException;
use App\Models\BusinessSetting;
use App\Support\SecretDetector;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single reusable settings service. Reads come from ONE cached snapshot of the whole (small) table, so any number of
 * lookups in a request costs at most one query, and none once the cache is warm. Every write validates key / type / value,
 * stores the canonical form and then drops the snapshot, so the next read sees the change without a deploy.
 *
 * Business rules never trust a row blindly: shippingFlatFee() / freeShippingThreshold() / returnWindowDays() use the stored value
 * only if it is the right type and inside the allowed range, otherwise the existing config/.env default (then a hard-coded one).
 */
final class BusinessSettingsService
{
    private const CACHE_KEY = 'vistaro:business_settings:v1';
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){0,4}$/';
    private const GROUP_PATTERN = '/^[a-z][a-z0-9_]{0,59}$/';

    /** @var array<string,array{value:string,type:string,group:string,description:?string,is_public:bool}>|null per-request memo */
    private ?array $memo = null;

    // ------------------------------------------------------------------ reads

    /** Typed value of one setting, or $default when absent / corrupt. */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->snapshot()[$key] ?? null;

        return $row === null ? $default : $this->castRow($key, $row, $default);
    }

    public function has(string $key): bool
    {
        return isset($this->snapshot()[$key]);
    }

    /** @return array<string,mixed> key => typed value for one group (all settings, public or not; for server-side use only) */
    public function group(string $group): array
    {
        $out = [];
        foreach ($this->snapshot() as $key => $row) {
            if ($row['group'] === $group) {
                $out[$key] = $this->castRow($key, $row, null);
            }
        }

        return $out;
    }

    /**
     * What the public API may show: ONLY is_public = true rows, grouped. Private rows are never present, not even as a key name.
     *
     * @return array<string,array<string,mixed>> group => [key => typed value]
     */
    public function publicGrouped(?string $group = null): array
    {
        $out = [];
        foreach ($this->snapshot() as $key => $row) {
            if (! $row['is_public'] || ($group !== null && $row['group'] !== $group)) {
                continue;
            }
            $out[$row['group']][$key] = $this->castRow($key, $row, null);
        }
        ksort($out);

        return $out;
    }

    /** @return array{key:string,value:mixed,type:string,group:string,description:?string}|null null when absent OR private */
    public function publicOne(string $key): ?array
    {
        $row = $this->snapshot()[$key] ?? null;
        if ($row === null || ! $row['is_public']) {
            return null;
        }

        return ['key' => $key, 'value' => $this->castRow($key, $row, null), 'type' => $row['type'], 'group' => $row['group'], 'description' => $row['description']];
    }

    // --------------------------------------------- business rules (live settings)

    public function shippingFlatFee(): int
    {
        return $this->knownInteger(SettingDefinitions::SHIPPING_FLAT_FEE);
    }

    public function freeShippingThreshold(): int
    {
        return $this->knownInteger(SettingDefinitions::SHIPPING_FREE_ABOVE);
    }

    public function returnWindowDays(): int
    {
        return $this->knownInteger(SettingDefinitions::RETURN_WINDOW_DAYS);
    }

    private function knownInteger(string $key): int
    {
        $def = SettingDefinitions::find($key) ?? throw new \LogicException("Unknown business rule setting {$key}.");
        $row = $this->snapshot()[$key] ?? null;
        if ($row === null) {
            return $def->fallback();                         // never configured: the existing default
        }
        try {
            $v = SettingType::Integer->cast($row['value']);
            if ($row['type'] === SettingType::Integer->value && $def->accepts($v)) {
                return $v;
            }
        } catch (Throwable) {
            // fall through to the safe fallback
        }
        Log::warning("business_settings: invalid stored value for {$key}; using the safe default.");

        return $def->fallback();
    }

    // ------------------------------------------------------------------ admin

    public function paginate(array $filters, int $perPage, ?int $page = null): LengthAwarePaginator
    {
        return BusinessSetting::query()
            ->when($filters['group'] ?? null, fn ($q, $g) => $q->where('group', $g))
            ->when(array_key_exists('is_public', $filters), fn ($q) => $q->where('is_public', (bool) $filters['is_public']))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where('key', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $s) . '%'))
            ->orderBy('key')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function find(string $key): BusinessSetting
    {
        return BusinessSetting::query()->where('key', $key)->firstOrFail();
    }

    /**
     * @param array{key:string,value:mixed,type:string,group?:string,description?:?string,is_public?:bool} $data
     * @throws InvalidSettingException
     */
    public function create(array $data): BusinessSetting
    {
        $key = (string) ($data['key'] ?? '');
        $this->assertKey($key);
        $def = SettingDefinitions::find($key);
        $type = SettingType::tryFrom((string) ($data['type'] ?? $def?->type->value ?? '')) ?? throw new InvalidSettingException('Unsupported setting type.', 'type');
        if ($def && $type !== $def->type) {
            throw new InvalidSettingException("{$key} must be of type {$def->type->value}.", 'type');
        }
        $group = (string) ($data['group'] ?? $def?->group ?? '');
        $this->assertGroup($group);
        $stored = $this->validatedValue($key, $type, $data['value'] ?? null);

        try {
            $row = DB::transaction(fn () => BusinessSetting::query()->create([
                'key' => $key, 'value' => $stored, 'type' => $type, 'group' => $group,
                'description' => $this->description($data['description'] ?? $def?->description ?? null),
                'is_public' => (bool) ($data['is_public'] ?? $def?->isPublic ?? false),
            ]));
        } finally {
            $this->flush();
        }

        return $row;
    }

    /**
     * Update value / description / is_public / group. The type and key never change (create a new setting instead).
     *
     * @param array{value?:mixed,group?:string,description?:?string,is_public?:bool} $data
     */
    public function update(string $key, array $data): BusinessSetting
    {
        try {
            $row = DB::transaction(function () use ($key, $data) {
                $row = BusinessSetting::query()->where('key', $key)->lockForUpdate()->firstOrFail();
                if (array_key_exists('value', $data)) {
                    $row->value = $this->validatedValue($key, $row->type, $data['value']);
                }
                if (array_key_exists('group', $data)) {
                    $this->assertGroup((string) $data['group']);
                    $row->group = (string) $data['group'];
                }
                if (array_key_exists('description', $data)) {
                    $row->description = $this->description($data['description']);
                }
                if (array_key_exists('is_public', $data)) {
                    $row->is_public = (bool) $data['is_public'];
                }
                $row->save();

                return $row;
            });
        } finally {
            $this->flush();
        }

        return $row->refresh();
    }

    /** Settings the application logic reads (shipping fee, free-shipping threshold, return window) cannot be deleted: update them instead. */
    public function delete(string $key): void
    {
        if (SettingDefinitions::find($key) !== null) {
            throw new ApiException('This setting is used by business rules and cannot be deleted. Update its value instead.', 422, ['key' => ['Protected setting.']]);
        }
        try {
            BusinessSetting::query()->where('key', $key)->firstOrFail()->delete();
        } finally {
            $this->flush();
        }
    }

    /** Drops the cached snapshot. Called after every write (also on failure, which only costs one extra query). */
    public function flush(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    // ---------------------------------------------------------------- internals

    /** @return array<string,array{value:string,type:string,group:string,description:?string,is_public:bool}> */
    private function snapshot(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        return $this->memo = Cache::remember(self::CACHE_KEY, (int) config('vistaro.settings.cache_ttl', 300), function () {
            $out = [];
            foreach (BusinessSetting::query()->orderBy('key')->get(['key', 'value', 'type', 'group', 'description', 'is_public']) as $r) {
                $out[$r->key] = ['value' => (string) $r->value, 'type' => $r->type->value, 'group' => $r->group, 'description' => $r->description, 'is_public' => (bool) $r->is_public];
            }

            return $out;
        });
    }

    private function castRow(string $key, array $row, mixed $default): mixed
    {
        try {
            return SettingType::from($row['type'])->cast($row['value']);
        } catch (Throwable) {
            Log::warning("business_settings: stored value of {$key} is corrupt for its type {$row['type']}.");

            return $default;
        }
    }

    private function assertKey(string $key): void
    {
        if (strlen($key) > 100 || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidSettingException('The key must be lowercase letters, digits and underscores, with optional dot-separated parts (e.g. store.support_email), max 100 characters.', 'key');
        }
        if (SecretDetector::keyLooksSecret($key)) {
            throw new InvalidSettingException('Secrets (passwords, tokens, API keys, credentials) must not be stored in business settings.', 'key');
        }
    }

    private function assertGroup(string $group): void
    {
        if (preg_match(self::GROUP_PATTERN, $group) !== 1) {
            throw new InvalidSettingException('The group must be lowercase letters, digits and underscores (max 60).', 'group');
        }
        if (SecretDetector::keyLooksSecret($group)) {
            throw new InvalidSettingException('Secrets must not be stored in business settings.', 'group');
        }
    }

    private function description(mixed $d): ?string
    {
        if ($d === null || $d === '') {
            return null;
        }
        if (! is_string($d) || mb_strlen($d) > 255) {
            throw new InvalidSettingException('The description must be text of at most 255 characters.', 'description');
        }

        return $d;
    }

    /** Type check + range check for known keys + secret scan; returns the canonical stored string. */
    private function validatedValue(string $key, SettingType $type, mixed $value): string
    {
        $stored = $type->normalize($value);
        if (SecretDetector::valueLooksSecret($type === SettingType::Json ? json_decode($stored) : $stored)) {
            throw new InvalidSettingException('The value looks like a secret or credential. Secrets must not be stored in business settings.');
        }
        $def = SettingDefinitions::find($key);
        if ($def && ! $def->accepts((int) $stored)) {
            throw new InvalidSettingException("The value for {$key} must be between {$def->min} and {$def->max}.");
        }

        return $stored;
    }
}
