<?php

declare(strict_types=1);

namespace Nvl\Media\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Support\MediaDiskResolver;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /**
     * @return array<model-property<Media>, mixed>
     */
    public function definition(): array
    {
        $generatedFilenameBase = $this->faker->unique()->slug(2);
        $filenameBase = $generatedFilenameBase;
        $generatedExtension = $this->faker->randomElement(['jpg', 'png', 'pdf', 'txt']);
        $extension = is_string($generatedExtension) ? $generatedExtension : 'txt';
        $mimeType = match ($extension) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            default => 'text/plain',
        };

        $type = str_starts_with($mimeType, 'image/')
            ? MediaType::IMAGE
            : (str_starts_with($mimeType, 'application/') ? MediaType::DOCUMENT : MediaType::OTHER);

        $filename = $filenameBase.'.'.$extension;
        $hashSeed = $this->faker->unique()->uuid();
        $digestSeed = $this->faker->uuid();

        return [
            'filename' => $filename,
            'hash' => hash('sha256', $filenameBase.$hashSeed).'.'.$extension,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => $this->faker->numberBetween(1024, 10 * 1024 * 1024),
            'disk' => MediaDiskResolver::resolve(),
            'folder' => 'factory/media',
            'is_public' => $this->faker->boolean(),
            'type' => $type,
            'digest' => sha1($filename.$digestSeed),
            'tags' => $this->faker->optional()->words(3),
            'metadata' => [
                'source' => 'factory',
            ],
            'uploaded_by' => null,
        ];
    }
}
