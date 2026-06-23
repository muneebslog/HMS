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
        'phone',
        'age',
        'gender',
    ];

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
