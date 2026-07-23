<?php

namespace App\Models;

use Database\Factories\KanbanItemCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KanbanItemComment extends Model
{
    /** @use HasFactory<KanbanItemCommentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'kanban_item_id',
        'user_id',
        'content',
    ];

    /**
     * The kanban item this comment belongs to.
     *
     * @return BelongsTo<KanbanItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(KanbanItem::class, 'kanban_item_id');
    }

    /**
     * The user who wrote this comment.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
