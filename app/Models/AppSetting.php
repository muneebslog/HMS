<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    public const NtfyAdminTopic = 'ntfy.admin_topic';

    public const NtfyReceptionTopic = 'ntfy.reception_topic';

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Read a stored setting, falling back when it has not been saved yet.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        return Cache::rememberForever(self::cacheKey($key), function () use ($key, $default): ?string {
            $value = self::query()->where('key', $key)->value('value');

            return $value ?? $default;
        });
    }

    /**
     * Persist a setting value.
     */
    public static function set(string $key, ?string $value): void
    {
        self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );

        Cache::forget(self::cacheKey($key));
    }

    /**
     * Build the cache key for a setting.
     */
    private static function cacheKey(string $key): string
    {
        return 'app_setting:'.$key;
    }
}
