<?php

namespace App\Models;

use Database\Factories\DriveFolderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriveFolder extends Model
{
    /** @use HasFactory<DriveFolderFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'name',
        'created_by',
    ];

    /**
     * The parent folder.
     *
     * @return BelongsTo<DriveFolder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DriveFolder::class, 'parent_id');
    }

    /**
     * The child folders.
     *
     * @return HasMany<DriveFolder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(DriveFolder::class, 'parent_id');
    }

    /**
     * The files in this folder.
     *
     * @return HasMany<DriveFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(DriveFile::class, 'folder_id');
    }

    /**
     * The user who created this folder.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Build the breadcrumb trail from root to this folder.
     *
     * @return list<DriveFolder>
     */
    public function breadcrumb(): array
    {
        $trail = [];
        $folder = $this;

        while ($folder !== null) {
            array_unshift($trail, $folder);
            $folder = $folder->parent;
        }

        return $trail;
    }

    /**
     * Delete this folder, its nested folders/files, and stored file contents.
     */
    public function deleteWithContents(): void
    {
        foreach ($this->children as $child) {
            $child->deleteWithContents();
        }

        foreach ($this->files as $file) {
            $file->deleteWithStorage();
        }

        $this->delete();
    }
}
