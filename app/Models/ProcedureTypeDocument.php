<?php

namespace App\Models;

use Database\Factories\ProcedureTypeDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProcedureTypeDocument extends Model
{
    /** @use HasFactory<ProcedureTypeDocumentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'procedure_type_id',
        'path',
        'original_name',
        'mime_type',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * Delete the private file when the document record is removed.
     */
    protected static function booted(): void
    {
        static::deleting(function (ProcedureTypeDocument $document): void {
            if (filled($document->path) && Storage::disk('local')->exists($document->path)) {
                Storage::disk('local')->delete($document->path);
            }
        });
    }

    /**
     * Get the procedure type this document belongs to.
     *
     * @return BelongsTo<ProcedureType, $this>
     */
    public function procedureType(): BelongsTo
    {
        return $this->belongsTo(ProcedureType::class);
    }

    /**
     * Determine whether this document is a PDF.
     */
    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf'
            || str_ends_with(strtolower($this->original_name), '.pdf');
    }

    /**
     * Determine whether this document is an image.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
