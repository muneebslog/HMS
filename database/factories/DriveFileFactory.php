<?php

namespace Database\Factories;

use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;

/**
 * @extends Factory<DriveFile>
 */
class DriveFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->slug().'.pdf';
        $path = 'hms-drive/root/'.fake()->uuid().'.pdf';

        Storage::disk('local')->put($path, '%PDF-1.4 fake pdf content');

        return [
            'folder_id' => null,
            'name' => fake()->words(3, true),
            'original_filename' => $filename,
            'disk_path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'tags' => [],
            'created_by' => User::factory()->admin(),
        ];
    }

    /**
     * Place the file inside the given folder.
     */
    public function inFolder(DriveFolder $folder): static
    {
        return $this->state(fn (array $attributes) => [
            'folder_id' => $folder->id,
        ]);
    }

    /**
     * Mark the file as an image.
     */
    public function image(): static
    {
        return $this->state(function (array $attributes) {
            $filename = fake()->slug().'.png';
            $path = 'hms-drive/root/'.fake()->uuid().'.png';

            Storage::disk('local')->put($path, 'fake-image-content');

            return [
                'original_filename' => $filename,
                'disk_path' => $path,
                'mime_type' => 'image/png',
                'size' => 512,
            ];
        });
    }

    /**
     * Attach the given tags.
     *
     * @param  list<string>  $tags
     */
    public function withTags(array $tags): static
    {
        return $this->state(fn (array $attributes) => [
            'tags' => $tags,
        ]);
    }
}
