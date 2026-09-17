<?php

declare(strict_types=1);

namespace Nvl\Media\Tenancy;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Nvl\Tenancy\Contracts\TenantParentResolver;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;

/** Resolves the package's explicit canonical Media owner ownership allowlist. */
final readonly class MediaTenantParentResolver implements TenantParentResolver
{
    /** Create the configuration-backed parent resolver. */
    public function __construct(private Repository $configuration) {}

    /** @return array<string, class-string<Model>> */
    public function types(): array
    {
        $configured = $this->configuration->get('media.tenancy.owner_types', []);
        if (! is_array($configured)) {
            throw new TenantConfigurationInvalid('media.tenancy.owner_types must be a class-string list.');
        }
        $types = [];
        foreach ($configured as $class) {
            if (! is_string($class) || ! is_a($class, Model::class, true)) {
                throw new TenantConfigurationInvalid('A Media tenant owner must be a concrete Eloquent model.');
            }
            $model = new $class;
            $types[$model->getMorphClass()] = $class;
        }

        return $types;
    }
}
