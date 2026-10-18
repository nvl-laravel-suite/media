<?php

declare(strict_types=1);

namespace Nvl\Media\Database\Factories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaModelRegistry;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Support\MediaDiskResolver;

/**
 * Builds native package fixture rows and declared parents.
 *
 * @api
 *
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /** Resolve the configured consumer model for native factory persistence. */
    public function modelName(): string
    {
        return app(MediaModelRegistry::class)->mediaClass();
    }

    /**
     * Prepare native parent and owner facts after Laravel expands relationships.
     *
     * @internal
     */
    public function configure(): static
    {
        $expandRelationships = true;

        return $this->state(function () use (&$expandRelationships): array {
            $expandRelationships = $this->expandRelationships;

            return [];
        })->afterMaking(function (Media $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            FactoryGuard::root($model, 'media.assets');
            if (config('nvl-tenancy.enabled') === true && $model->getAttribute('storage_path') === null) {
                $model->forceFill([
                    'storage_path' => Container::getInstance()->make(MediaPathResolver::class)
                        ->mediaPathForFolder($model, $model->folder ?? ''),
                ]);
            }
        });
    }

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
