<?php

namespace App\Models;

use Database\Factories\EmployeeTodoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTodo extends Model
{
    /** @use HasFactory<EmployeeTodoFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'title',
        'description',
        'due_date',
        'completed_at',
        'completed_by',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Scope the query to only pending todos.
     */
    public function scopePending($query)
    {
        return $query->whereNull('completed_at');
    }

    /**
     * Scope the query to only completed todos.
     */
    public function scopeCompleted($query)
    {
        return $query->whereNotNull('completed_at');
    }

    /**
     * Scope the query to overdue pending todos.
     */
    public function scopeOverdue($query)
    {
        return $query->pending()->where('due_date', '<=', now()->toDateString());
    }

    /**
     * The employee this todo belongs to.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The user who created this todo.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who marked this todo as done.
     *
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Determine whether this todo is completed.
     */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Determine whether this todo is overdue.
     */
    public function isOverdue(): bool
    {
        return ! $this->isCompleted() && $this->due_date->isPast();
    }

    /**
     * Mark the todo as completed by the given user.
     */
    public function markAsDone(User $user): void
    {
        $this->update([
            'completed_at' => now(),
            'completed_by' => $user->id,
        ]);
    }

    /**
     * Reopen a completed todo.
     */
    public function reopen(): void
    {
        $this->update([
            'completed_at' => null,
            'completed_by' => null,
        ]);
    }
}
