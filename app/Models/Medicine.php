<?php

namespace App\Models;

use App\Concerns\IdentifiesSyrupMedicine;
use App\Enums\MedicineDose;
use App\Models\Concerns\HasStockBalances;
use Database\Factories\MedicineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Medicine extends Model
{
    /** @use HasFactory<MedicineFactory> */
    use HasFactory, HasStockBalances, IdentifiesSyrupMedicine;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'short_form',
        'unit',
        'default_dose',
        'default_days',
        'is_active',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'default_dose' => '1-0-0',
        'default_days' => 3,
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_dose' => MedicineDose::class,
            'default_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope the query to only active medicines.
     *
     * @param  Builder<Medicine>  $query
     * @return Builder<Medicine>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return BelongsToMany<Symptom, $this>
     */
    public function symptoms(): BelongsToMany
    {
        return $this->belongsToMany(Symptom::class)->withTimestamps();
    }

    /**
     * Label for catalog pickers; omits unit when it is a placeholder dot.
     */
    public function catalogLabel(): string
    {
        if (filled($this->unit) && $this->unit !== '.') {
            return $this->name.' ('.$this->unit.')';
        }

        return $this->name;
    }
}
