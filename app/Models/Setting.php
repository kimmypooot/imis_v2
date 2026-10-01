<?php

namespace App\Models;

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use App\Support\Settings\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One application-level setting. Which settings exist — and their type,
 * group, label, and whether they're editable — is defined in code by
 * App\Support\Settings\SettingRegistry and synced into this table; only
 * `value` is ever changed through the admin UI, and the controller sets it
 * explicitly. Nothing is mass-assignable, so a request payload can't flip
 * `editable` or change a type.
 *
 * Read values through App\Support\Settings\Settings (cached), not by
 * querying this model directly.
 */
class Setting extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        // After commit, so a concurrent request can't re-cache the old value
        // between the flush and the commit. Runs immediately outside a
        // transaction.
        $flush = fn () => DB::afterCommit(fn () => Settings::flush());

        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'group' => SettingGroup::class,
            'editable' => 'boolean',
            'constraints' => 'array',
        ];
    }

    public function typedValue(): string|int|bool|null
    {
        return $this->type->cast($this->value);
    }

    /**
     * @return array<int, string>
     */
    public function rules(): array
    {
        return $this->type->rules($this->constraints ?? []);
    }
}
