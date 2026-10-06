<?php

namespace App\Models;

use Database\Factories\StationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A physical PC placed at a location that can open station pages without a user login.
 *
 * @property string $name
 * @property string|null $location
 * @property list<string> $allowed_pages
 * @property bool $is_active
 * @property string|null $device_token_hash
 * @property Carbon|null $registered_at
 * @property Carbon|null $last_seen_at
 * @property string|null $last_page
 * @property string|null $last_ip
 * @property string|null $last_user_agent
 */
class Station extends Model
{
    /** @use HasFactory<StationFactory> */
    use HasFactory;

    /**
     * A station counts as online when it was seen within this many seconds.
     */
    public const ONLINE_THRESHOLD_SECONDS = 90;

    /**
     * Station pages a PC can be allowed to open, keyed by route name.
     *
     * @var array<string, string>
     */
    public const PAGES = [
        'display.er' => 'ER Station',
        'display.drips' => 'Drip Delivery',
        'display.er_drips' => 'ER + Drips',
    ];

    /**
     * Routes that a page grant implicitly covers (aliases and iframe children).
     *
     * @var array<string, list<string>>
     */
    private const PAGE_INCLUDES = [
        'display.er' => ['display.er', 'display.medication'],
        'display.drips' => ['display.drips'],
        'display.er_drips' => ['display.er_drips', 'display.er', 'display.medication', 'display.drips'],
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'location',
        'allowed_pages',
        'is_active',
        'device_token_hash',
        'registered_at',
        'last_seen_at',
        'last_page',
        'last_ip',
        'last_user_agent',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'device_token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_pages' => 'array',
            'is_active' => 'boolean',
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Route names that are gated to registered station PCs.
     *
     * @return list<string>
     */
    public static function gatedRouteNames(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::PAGE_INCLUDES))));
    }

    /**
     * @param  Builder<Station>  $query
     */
    public function scopeOnline(Builder $query): void
    {
        $query->where('last_seen_at', '>=', now()->subSeconds(self::ONLINE_THRESHOLD_SECONDS));
    }

    /**
     * Whether a PC has been registered to this station.
     */
    public function isRegistered(): bool
    {
        return $this->device_token_hash !== null;
    }

    /**
     * Whether the station PC has checked in recently.
     */
    public function isOnline(): bool
    {
        return $this->is_active
            && $this->last_seen_at !== null
            && $this->last_seen_at->greaterThanOrEqualTo(now()->subSeconds(self::ONLINE_THRESHOLD_SECONDS));
    }

    /**
     * Whether this station may open the given route.
     */
    public function canOpen(string $routeName): bool
    {
        foreach ($this->allowed_pages ?? [] as $page) {
            if (in_array($routeName, self::PAGE_INCLUDES[$page] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Route to send the station PC to when it opens the station landing page.
     */
    public function homeRouteName(): ?string
    {
        foreach (['display.er_drips', 'display.er', 'display.drips'] as $page) {
            if (in_array($page, $this->allowed_pages ?? [], true)) {
                return $page;
            }
        }

        return null;
    }
}
