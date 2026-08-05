<?php

namespace App\Models;

use App\Enums\ProcedureNoteStyle;
use Database\Factories\ProcedureTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class ProcedureType extends Model
{
    /** @use HasFactory<ProcedureTypeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_active',
        'requires_birth_certificate',
        'requires_fetal_heart',
        'note_style',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'requires_birth_certificate' => false,
        'requires_fetal_heart' => false,
        'note_style' => 'operation',
    ];

    /**
     * Delete private document files before the type is removed.
     */
    protected static function booted(): void
    {
        static::deleting(function (ProcedureType $procedureType): void {
            foreach ($procedureType->documents()->get() as $document) {
                $document->delete();
            }

            Storage::disk('local')->deleteDirectory("procedure-types/{$procedureType->id}");
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'requires_birth_certificate' => 'boolean',
            'requires_fetal_heart' => 'boolean',
            'note_style' => ProcedureNoteStyle::class,
        ];
    }

    /**
     * Scope the query to only active procedure types.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the procedures associated with this type.
     *
     * @return HasMany<Procedure, $this>
     */
    public function procedures(): HasMany
    {
        return $this->hasMany(Procedure::class);
    }

    /**
     * Get the print documents associated with this type.
     *
     * @return HasMany<ProcedureTypeDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ProcedureTypeDocument::class)->orderBy('sort_order')->orderBy('id');
    }
}
