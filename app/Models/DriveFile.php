<?php

namespace App\Models;

use Database\Factories\DriveFileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DriveFile extends Model
{
    /** @use HasFactory<DriveFileFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'folder_id',
        'name',
        'original_filename',
        'disk_path',
        'mime_type',
        'size',
        'tags',
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
            'tags' => 'array',
            'size' => 'integer',
        ];
    }

    /**
     * The folder containing this file.
     *
     * @return BelongsTo<DriveFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(DriveFolder::class, 'folder_id');
    }

    /**
     * The user who uploaded this file.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Determine whether this file is a PDF.
     */
    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf'
            || str_ends_with(strtolower($this->original_filename), '.pdf');
    }

    /**
     * Determine whether this file is an image.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Scope a query to files matching the given search term by name or tag.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like, $term) {
            $q->where('name', 'like', $like)
                ->orWhere('original_filename', 'like', $like)
                ->orWhere('tags', 'like', '%"'.$term.'"%')
                ->orWhere('tags', 'like', $like);
        });
    }

    /**
     * Delete the database record and remove the file from storage.
     */
    public function deleteWithStorage(): void
    {
        if (filled($this->disk_path) && Storage::disk('local')->exists($this->disk_path)) {
            Storage::disk('local')->delete($this->disk_path);
        }

        $this->delete();
    }
}
