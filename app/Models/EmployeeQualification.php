<?php

namespace App\Models;

use Database\Factories\EmployeeQualificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EmployeeQualification extends Model
{
    /** @use HasFactory<EmployeeQualificationFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'course',
        'passing_year',
        'institution',
        'document_path',
        'original_name',
        'created_by',
    ];

    /**
     * The employee this qualification belongs to.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The user who created this qualification record.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Determine whether a document file is attached.
     */
    public function hasDocument(): bool
    {
        return filled($this->document_path);
    }

    /**
     * Determine whether the stored document file exists.
     */
    public function documentExists(): bool
    {
        return $this->hasDocument() && Storage::disk('local')->exists($this->document_path);
    }
}
