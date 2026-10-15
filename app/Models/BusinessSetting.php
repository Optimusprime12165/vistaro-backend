<?php

namespace App\Models;

use App\Enums\SettingType;
use Illuminate\Database\Eloquent\Model;

/** Persistence only. All validation / casting / caching lives in App\Services\Settings\BusinessSettingsService. */
class BusinessSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'description', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'type' => SettingType::class];
    }

    /** Typed value, or the raw stored text if the row is corrupt (the service never uses this fallback for business rules). */
    public function typedValue(): mixed
    {
        try {
            return $this->type->cast((string) $this->value);
        } catch (\Throwable) {
            return $this->value;
        }
    }
}
