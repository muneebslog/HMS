<?php

namespace App\Models;

use App\Enums\KanbanStatus;
use Database\Factories\KanbanItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KanbanItem extends Model
{
    /** @use HasFactory<KanbanItemFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'status',
        'position',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => KanbanStatus::class,
        ];
    }

    /**
     * The default ordering for the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('ordered', function ($query) {
            $query->orderBy('position');
        });
    }

    /**
     * The user who created this kanban item.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The comments on this kanban item.
     *
     * @return HasMany<KanbanItemComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(KanbanItemComment::class, 'kanban_item_id')->orderBy('created_at');
    }
}
