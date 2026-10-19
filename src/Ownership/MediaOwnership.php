<?php

declare(strict_types=1);

namespace Nvl\Media\Ownership;

use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaImageVariation;
use Nvl\Support\Ownership\Compatibility\LegacyOwnershipSupport;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/** Current package classification, without retaining an actor or a model. @internal */
final class MediaOwnership
{
    /** A declared consumer root also stays guarded when its runtime is unavailable. */
    public static function active(): bool
    {
        return self::declared() || app(LegacyOwnershipSupport::class)->enabled();
    }

    /** Read immutable ownership metadata even when the enforcing runtime is unavailable. */
    public static function declared(): bool
    {
        return app(OwnershipResourceRegistry::class)->get('media.assets')->ownership->strategy !== 'unscoped';
    }

    /** Obtain the full stable physical partition only after fresh resource admission. */
    public static function storagePartition(): ?string
    {
        if (! self::active()) {
            return null;
        }
        $attributes = app(OwnershipBoundary::class)->attributes('media.assets');
        $scope = $attributes['owner_scope_key'] ?? null;
        if ($scope === 'central') {
            return 'central';
        }
        if (! is_string($scope) || preg_match('/^owner:[a-f0-9]{64}$/D', $scope) !== 1) {
            throw new OwnershipViolation('Media storage requires its complete admitted owner partition.');
        }

        return 'owners/'.substr($scope, 6);
    }

    /** Guard a native static delivery entry point using the current admission. */
    public static function assertAsset(Media $media): void
    {
        app(MediaDeliveryGuard::class)->assertAsset($media);
    }

    /** Guard a carried variation before reading its parent relationship. */
    public static function assertVariationOwner(MediaImageVariation $variation): void
    {
        if (self::declared()) {
            app(OwnershipBoundary::class)->assertRecord($variation, 'media.variations');
        }
    }

    /** Guard the exact native variation and its chosen canonical asset. */
    public static function assertVariation(MediaImageVariation $variation, Media $media): void
    {
        app(MediaDeliveryGuard::class)->assertVariation($variation, $media);
    }

    /** Derive a signed capability identity without admitting a recipient. */
    public static function privateCapabilityKey(): string
    {
        return app(OwnershipBoundary::class)->key('media.assets', 'private-url');
    }

    private function __construct() {}
}
