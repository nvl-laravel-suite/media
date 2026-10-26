<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$configuration = (new Configuration)
    ->ignoreErrorsOnPackage('nvl/media', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackage(
        'orchestra/testbench-core',
        [ErrorType::SHADOW_DEPENDENCY],
    );

$paths = [
    'aws/aws-sdk-php' => ['src/Services/S3MultipartUploadGateway.php', 'src/Services/MediaS3DependencyGuard.php'],
    'league/flysystem-aws-s3-v3' => ['src/Services/MediaDoctor.php', 'src/Services/MediaS3DependencyGuard.php'],
];
foreach ($paths as $package => $files) {
    foreach ($files as $file) {
        $configuration->ignoreErrorsOnPackageAndPath($package, __DIR__.'/'.$file, [ErrorType::DEV_DEPENDENCY_IN_PROD]);
    }
}

return $configuration;
