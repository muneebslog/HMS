<?php

namespace App\Models;

use App\Enums\StaffCategory;
use Database\Factories\HealthAideFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class HealthAide extends Model
{
    /** @use HasFactory<HealthAideFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'name',
        'pin',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'pin',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
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
            'pin' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope the query to only active health aides.
     *
     * @param  Builder<HealthAide>  $query
     * @return Builder<HealthAide>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Find an active health aide by plain PIN code.
     */
    public static function findByPin(string $plainPin): ?self
    {
        $plainPin = trim($plainPin);

        if ($plainPin === '') {
            return null;
        }

        foreach (static::query()->active()->get() as $aide) {
            if (Hash::check($plainPin, $aide->pin)) {
                return $aide;
            }
        }

        return null;
    }

    /**
     * Whether the plain PIN is already used by another active health aide.
     */
    public static function pinIsTaken(string $plainPin, ?int $ignoreId = null): bool
    {
        $query = static::query()->active();

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        foreach ($query->get() as $aide) {
            if (Hash::check($plainPin, $aide->pin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the HR profile behind this aide, creating a health aide profile when missing.
     */
    public function ensureEmployee(int $createdBy): Employee
    {
        if ($this->employee !== null) {
            return $this->employee;
        }

        $employee = Employee::query()->create([
            'name' => $this->name,
            'designation' => __('Health Aide'),
            'staff_category' => StaffCategory::HealthAide,
            'tracks_attendance' => true,
            'employment_type' => 'full_time',
            'status' => $this->is_active ? 'active' : 'inactive',
            'created_by' => $createdBy,
        ]);

        $this->update(['employee_id' => $employee->id]);

        return $this->setRelation('employee', $employee)->employee;
    }

    /**
     * The HR profile behind this kiosk identity.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
