<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Nvl\Media\Builders\MediaBuilder;
use Nvl\Media\Models\Media;
use Nvl\Support\Models\ConfiguredModelRegistration;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/**
 * Resolves the one configured Media extension through the shared native model identity.
 *
 * @api
 */
final class MediaModelRegistry
{
    /** @var ConfiguredModelRegistration<Media> */
    private readonly ConfiguredModelRegistration $registration;

    /** Retain deployment configuration and the shared identity registry. */
    public function __construct(
        Repository $configuration,
        OwnerRegistry $identities,
    ) {
        $this->registration = new ConfiguredModelRegistration(
            $configuration,
            $identities,
            'nvl-media.models.media',
            Media::class,
            'media.assets',
            static function (string $class): void {
                if (! in_array($class::ownershipDefinition()->strategy, ['unscoped', 'column', 'pivot'], true)) {
                    throw new OwnershipConfigurationInvalid('Media roots require a native column or concrete primary pivot.');
                }
            },
        );
    }

    /** Resolve and freeze the concrete Media class before resource compilation.
     * @internal
     *
     * @return class-string<Media>
     */
    public function prepare(): string
    {
        return $this->registration->prepare();
    }

    /** Validate native capabilities after Laravel installs Eloquent's dispatcher.
     * @return class-string<Media>
     */
    public function mediaClass(): string
    {
        return $this->registration->modelClass();
    }

    /** Resolve the immutable model from native framework entry points.
     * @internal
     *
     * @return class-string<Media>
     */
    public static function modelClass(): string
    {
        return Container::getInstance()->make(self::class)->mediaClass();
    }

    /**
     * Construct the exact configured class without retaining a request-scoped model.
     *
     * @internal
     */
    public static function newInstance(): Media
    {
        $class = Container::getInstance()->make(self::class)->mediaClass();

        return new $class;
    }

    /** Persist a package-authored attribute map under the exact source assignment.
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes): Media
    {
        return app(OwnershipWriter::class)->create(self::modelClass(), static function (array $ownership) use ($attributes): Media {
            $model = self::newInstance();
            $model->forceFill([...$attributes, ...$ownership])->save();

            return $model;
        });
    }

    /**
     * Preserve Media's public custom query builder.
     *
     * @internal
     */
    public static function query(): MediaBuilder
    {
        return self::newInstance()->newQuery();
    }
}
