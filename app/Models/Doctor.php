<?php

namespace App\Models;

use Database\Factories\DoctorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Doctor extends Model
{
    /** @use HasFactory<DoctorFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'specialization',
    ];

    /**
     * Get the prices associated with this doctor.
     */
    public function servicePrices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }

    /**
     * Get the queues associated with this doctor.
     */
    public function serviceQueues(): HasMany
    {
        return $this->hasMany(ServiceQueue::class);
    }
}
