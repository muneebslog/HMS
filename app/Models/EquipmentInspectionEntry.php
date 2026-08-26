<?php

namespace App\Models;

use App\Enums\EquipmentInspectionArea;
use App\Enums\EquipmentInspectionShift;
use Database\Factories\EquipmentInspectionEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $health_aide_id
 * @property EquipmentInspectionArea $area
 * @property Carbon $checklist_date
 * @property EquipmentInspectionShift $shift
 * @property string $checked_by_name
 * @property string|null $supervisor_name
 * @property array<string, mixed>|null $sign_off
 * @property Carbon $submitted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property User $user
 * @property HealthAide|null $healthAide
 */
class EquipmentInspectionEntry extends Model
{
    /** @use HasFactory<EquipmentInspectionEntryFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'health_aide_id',
        'area',
        'checklist_date',
        'shift',
        'checked_by_name',
        'supervisor_name',
        'sign_off',
        'submitted_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'area' => EquipmentInspectionArea::class,
            'checklist_date' => 'date',
            'shift' => EquipmentInspectionShift::class,
            'sign_off' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * Get the incharge nurse who submitted this entry.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the health aide who completed this checklist.
     *
     * @return BelongsTo<HealthAide, $this>
     */
    public function healthAide(): BelongsTo
    {
        return $this->belongsTo(HealthAide::class);
    }

    /**
     * Get the checklist answers for this entry.
     *
     * @return HasMany<EquipmentInspectionAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(EquipmentInspectionAnswer::class, 'entry_id');
    }

    /**
     * Get the maintenance register rows for this entry.
     *
     * @return HasMany<EquipmentInspectionRegisterRow, $this>
     */
    public function registerRows(): HasMany
    {
        return $this->hasMany(EquipmentInspectionRegisterRow::class, 'entry_id')->orderBy('sort_order');
    }

    /**
     * Determine whether this entry contains any fault signals.
     */
    public function hasFaults(): bool
    {
        $signOff = $this->sign_off ?? [];

        foreach (['equip_issues', 'equip_defect', 'faults_identified'] as $key) {
            if (($signOff[$key] ?? null) === 'yes') {
                return true;
            }
        }

        if ($this->relationLoaded('answers')) {
            $hasFaultAnswer = $this->answers->contains(
                fn (EquipmentInspectionAnswer $answer) => $answer->isFault()
            );
        } else {
            $hasFaultAnswer = $this->answers()
                ->where(function ($query): void {
                    $query->where('present', false)
                        ->orWhere('functional', false)
                        ->orWhere('maint_req', true)
                        ->orWhere('checked', false);
                })
                ->exists();
        }

        if ($hasFaultAnswer) {
            return true;
        }

        if ($this->relationLoaded('registerRows')) {
            return $this->registerRows->contains(
                fn (EquipmentInspectionRegisterRow $row) => filled($row->problem)
            );
        }

        return $this->registerRows()->whereNotNull('problem')->where('problem', '!=', '')->exists();
    }
}
