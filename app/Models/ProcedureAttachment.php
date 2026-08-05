<?php

namespace App\Models;

use App\Enums\ProcedureAttachmentType;
use Database\Factories\ProcedureAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProcedureAttachment extends Model
{
    /** @use HasFactory<ProcedureAttachmentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_id',
        'type',
        'path',
        'original_name',
        'mime_type',
        'uploaded_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProcedureAttachmentType::class,
        ];
    }

    /**
     * Delete the private file when the attachment record is removed.
     */
    protected static function booted(): void
    {
        static::deleting(function (ProcedureAttachment $attachment): void {
            Storage::disk('local')->delete($attachment->path);
        });
    }

    /**
     * Get the procedure this attachment belongs to.
     *
     * @return BelongsTo<Procedure, $this>
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * Get the user who uploaded this attachment.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
