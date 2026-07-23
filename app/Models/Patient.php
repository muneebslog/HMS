<?php

namespace App\Models;

use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'mrn',
        'phone',
        'age',
        'gender',
    ];

    /**
     * Boot the model and generate an MRN for new patients.
     */
    protected static function booted(): void
    {
        static::created(function (Patient $patient) {
            if (blank($patient->mrn)) {
                $patient->update(['mrn' => 'MRN'.str_pad((string) $patient->id, 6, '0', STR_PAD_LEFT)]);
            }
        });
    }

    /**
     * Get the queue tokens associated with this patient.
     *
     * @return HasMany<QueueToken, $this>
     */
    public function queueTokens(): HasMany
    {
        return $this->hasMany(QueueToken::class);
    }
}
