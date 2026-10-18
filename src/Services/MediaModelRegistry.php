<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Nvl\Media\Builders\MediaBuilder;
use Nvl\Media\Models\Media;
use Nvl\Support\Models\ConfiguredModelResolver;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipScope;
use ReflectionMethod;

/** Resolves the one configured Media extension through the shared native model identity. @api */
final class MediaModelRegistry
{
    /** @var class-string<Media>|null */
    private ?string $media = null;

    /** Retain deployment configuration and the shared identity registry. */
    public function __construct(private readonly Repository $configuration, private readonly OwnerRegistry $identities) {}

    /** Resolve and freeze the validated concrete Media class before resource compilation.
     * @return class-string<Media>
     */
    public function mediaClass(): string
    {
        if ($this->media !== null) {
            return $this->media;
        }
        $configured = $this->configuration->get('nvl-media.models.media', Media::class);
        if (! is_string($configured)) {
            throw new OwnershipConfigurationInvalid('Media model configuration must name a concrete Media subclass.');
        }
        $class = (new ConfiguredModelResolver)->resolve($configured, Media::class);
        if (! in_array($class::ownershipDefinition()->strategy, ['unscoped', 'column', 'pivot'], true)) {
            throw new OwnershipConfigurationInvalid('Media roots require a native column or concrete primary pivot.');
        }
        $model = new $class;
        $base = new Media;
        foreach ($base->getCasts() as $attribute => $cast) {
            if (($model->getCasts()[$attribute] ?? null) !== $cast) {
                throw new OwnershipConfigurationInvalid('Configured Media models must retain the required casts.');
            }
        }
        foreach (['save', 'delete', 'newQueryForRestoration', 'bootHasOwnership', 'newEloquentBuilder'] as $method) {
            if ((new ReflectionMethod($class, $method))->getDeclaringClass()->getName() !== Media::class) {
                throw new OwnershipConfigurationInvalid('Configured Media models must retain the canonical persistence hooks.');
            }
        }
        if (! $model->hasGlobalScope(OwnershipScope::class)
            || $model->getKeyType() !== 'string' || $model->getIncrementing()) {
            throw new OwnershipConfigurationInvalid('Configured Media models must retain the builder, UUID and ownership capabilities.');
        }
        $this->identities->registerReplacement('media.assets', Media::class, $class);
        $this->media = $class;

        return $class;
    }

    /** Resolve the immutable model from native framework entry points. @internal
     * @return class-string<Media>
     */
    public static function modelClass(): string
    {
        return Container::getInstance()->make(self::class)->mediaClass();
    }

    /** Construct the exact configured class without retaining a request-scoped model. @internal */
    public static function newInstance(): Media
    {
        $class = Container::getInstance()->make(self::class)->mediaClass();

        return new $class;
    }

    /** Persist a package-authored attribute map under the exact source assignment. @internal
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

    /** Preserve Media's public custom query builder. @internal */
    public static function query(): MediaBuilder
    {
        return self::newInstance()->newQuery();
    }
}
