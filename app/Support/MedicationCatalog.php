<?php

namespace App\Support;

use App\Models\DripBase;
use App\Models\Injection;
use App\Models\Medicine;
use App\Models\Service;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Active medicine, injection, drip base and drip service lists for the doctor's medication page,
 * cached as one entry and cleared whenever one of those models is saved or deleted.
 */
class MedicationCatalog
{
    public const CacheKey = 'medication-catalog';

    /**
     * @return Collection<int, Medicine>
     */
    public static function medicines(): Collection
    {
        return Medicine::hydrate(self::rows()['medicines']);
    }

    /**
     * @return Collection<int, Injection>
     */
    public static function injections(): Collection
    {
        return Injection::hydrate(self::rows()['injections']);
    }

    /**
     * @return Collection<int, DripBase>
     */
    public static function dripBases(): Collection
    {
        return DripBase::hydrate(self::rows()['drip_bases']);
    }

    /**
     * @return Collection<int, Service>
     */
    public static function dripServices(): Collection
    {
        return Service::hydrate(self::rows()['drip_services']);
    }

    /**
     * Drop the cached catalog once the current transaction (if any) commits.
     */
    public static function forget(): void
    {
        DB::afterCommit(fn () => Cache::memo()->forget(self::CacheKey));
    }

    /**
     * Raw attributes are cached instead of models because the cache does not unserialize objects.
     *
     * @return array{
     *     medicines: list<array<string, mixed>>,
     *     injections: list<array<string, mixed>>,
     *     drip_bases: list<array<string, mixed>>,
     *     drip_services: list<array<string, mixed>>
     * }
     */
    private static function rows(): array
    {
        return Cache::memo()->rememberForever(self::CacheKey, fn (): array => [
            'medicines' => self::attributes(Medicine::query()->active()->orderByRaw('lower(name)')->orderBy('name')->get()),
            'injections' => self::attributes(Injection::query()->active()->orderByRaw('lower(name)')->orderBy('name')->get()),
            'drip_bases' => self::attributes(DripBase::query()->active()->orderBy('name')->get()),
            'drip_services' => self::attributes(Service::query()->active()->where('is_drip', true)->orderBy('name')->get()),
        ]);
    }

    /**
     * @param  Collection<int, Model>  $models
     * @return list<array<string, mixed>>
     */
    private static function attributes(Collection $models): array
    {
        return $models->map(fn ($model): array => $model->getAttributes())->values()->all();
    }
}
