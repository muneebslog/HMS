<?php

namespace App\Models;

use Database\Factories\ReceptionMemoReadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceptionMemoRead extends Model
{
    /** @use HasFactory<ReceptionMemoReadFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reception_memo_id',
        'user_id',
        'read_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /**
     * The memo this read receipt belongs to.
     *
     * @return BelongsTo<ReceptionMemo, $this>
     */
    public function memo(): BelongsTo
    {
        return $this->belongsTo(ReceptionMemo::class, 'reception_memo_id');
    }

    /**
     * The user who marked the memo as read.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
