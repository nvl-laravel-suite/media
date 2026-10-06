<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Nvl\Media\Exceptions\MediaDependencyUnavailableException;

/** Checks optional built-in S3 dependencies before resolving a filesystem adapter. @internal */
final readonly class MediaS3DependencyGuard
{
    /** Determine whether both supported S3 runtime classes can be loaded. */
    public function available(): bool
    {
        return class_exists(S3Client::class) && class_exists(AwsS3V3Adapter::class);
    }

    /** Fail with an actionable package error before constructing the built-in S3 adapter. */
    public function assertAvailable(): void
    {
        if (! $this->available()) {
            throw new MediaDependencyUnavailableException("Built-in S3 requires composer require 'league/flysystem-aws-s3-v3:^3.0' 'aws/aws-sdk-php:^3.322.9' --with-all-dependencies.");
        }
    }
}
