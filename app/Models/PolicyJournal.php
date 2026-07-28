<?php

namespace App\Models;

use App\Enums\PolicyJournalStatus;
use Database\Factories\PolicyJournalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyJournal extends Model
{
    /** @use HasFactory<PolicyJournalFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'created_by',
        'title',
        'incident',
        'resolution',
        'policy',
        'category',
        'tags',
        'effective_date',
        'review_date',
        'status',
        'attachments',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PolicyJournalStatus::class,
            'tags' => 'array',
            'attachments' => 'array',
            'effective_date' => 'date',
            'review_date' => 'date',
        ];
    }

    /**
     * The user who created this policy journal entry.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope a query to only include entries matching the given search term.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\\%', $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('title', 'like', $like)
                ->orWhere('incident', 'like', $like)
                ->orWhere('resolution', 'like', $like)
                ->orWhere('policy', 'like', $like)
                ->orWhere('category', 'like', $like);
        });
    }

    /**
     * Scope a query to only include entries with the given category.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFilterByCategory(Builder $query, ?string $category): Builder
    {
        if (blank($category)) {
            return $query;
        }

        return $query->where('category', $category);
    }

    /**
     * Scope a query to only include entries with the given status.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFilterByStatus(Builder $query, ?PolicyJournalStatus $status): Builder
    {
        if ($status === null) {
            return $query;
        }

        return $query->where('status', $status);
    }
}
