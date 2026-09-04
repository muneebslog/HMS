<?php

namespace App\Models;

use App\Enums\BirthMultiplicity;
use App\Enums\LivingStatus;
use Database\Factories\ProcedureBirthCertificateDetailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcedureBirthCertificateDetail extends Model
{
    /** @use HasFactory<ProcedureBirthCertificateDetailFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => LivingStatus::Living->value,
        'multiplicity' => BirthMultiplicity::Single->value,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_id',
        'father_name',
        'mother_name',
        'grandfather_name',
        'maternal_grandfather_name',
        'father_age',
        'mother_age',
        'father_cnic',
        'mother_cnic',
        'home_address',
        'born_at',
        'sex',
        'status',
        'baby_name',
        'multiplicity',
        'child_order',
        'recorded_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'born_at' => 'datetime',
            'father_age' => 'integer',
            'mother_age' => 'integer',
            'child_order' => 'integer',
            'status' => LivingStatus::class,
            'multiplicity' => BirthMultiplicity::class,
        ];
    }

    /**
     * Get the procedure this birth certificate detail belongs to.
     *
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * Get the user who recorded this birth certificate detail.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Get a display label for the child's birth order among multiples.
     */
    public function childOrderLabel(): ?string
    {
        if ($this->child_order === null || $this->multiplicity === BirthMultiplicity::Single) {
            return null;
        }

        return match ($this->child_order) {
            1 => __('1st'),
            2 => __('2nd'),
            3 => __('3rd'),
            default => (string) $this->child_order,
        };
    }
}
