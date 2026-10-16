<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    public const NtfyAdminTopic = 'ntfy.admin_topic';

    public const NtfyReceptionTopic = 'ntfy.reception_topic';

    public const AllowHaveNoNumber = 'reception.allow_have_no_number';

    public const FinanceOwnerPassword = 'finance.owner_password';

    public const LateGraceMinutes = 'attendance.late_grace_minutes';

    public const DuplicatePunchMinutes = 'attendance.duplicate_punch_minutes';

    public const BreakMergeMinutes = 'attendance.break_merge_minutes';

    public const MaxSessionHours = 'attendance.max_session_hours';

    public const LongSessionHours = 'attendance.long_session_hours';

    /**
     * Attendance pairing rules and their defaults.
     *
     * @var array<string, array{label: string, default: int, min: int, max: int}>
     */
    public const AttendanceRules = [
        self::LateGraceMinutes => ['label' => 'Late grace (minutes)', 'default' => 15, 'min' => 0, 'max' => 120],
        self::DuplicatePunchMinutes => ['label' => 'Ignore repeat punches within (minutes)', 'default' => 10, 'min' => 0, 'max' => 120],
        self::BreakMergeMinutes => ['label' => 'Join sessions split by a break up to (minutes)', 'default' => 90, 'min' => 0, 'max' => 480],
        self::MaxSessionHours => ['label' => 'Longest possible session (hours)', 'default' => 36, 'min' => 4, 'max' => 72],
        self::LongSessionHours => ['label' => 'Flag sessions longer than (hours)', 'default' => 14, 'min' => 4, 'max' => 72],
    ];

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
     * Read one of the attendance pairing rules as a whole number.
     */
    public static function attendanceRule(string $key): int
    {
        $rule = self::AttendanceRules[$key];

        return (int) self::get($key, (string) $rule['default']);
    }

    /**
     * Whether reception may register patients without a phone number.
     */
    public static function allowsHaveNoNumber(): bool
    {
        return self::get(self::AllowHaveNoNumber, '1') === '1';
    }

    /**
     * The hashed password for the public owner finance page, or null when the page is switched off.
     */
    public static function financeOwnerPasswordHash(): ?string
    {
        $hash = self::get(self::FinanceOwnerPassword);

        return filled($hash) ? $hash : null;
    }

    /**
     * Build the cache key for a setting.
     */
    private static function cacheKey(string $key): string
    {
        return 'app_setting:'.$key;
    }
}
