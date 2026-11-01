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

// These fixtures belong only to the retired Tenancy suite excluded by phpunit.xml.dist.
// The active Ownership suites and every production path remain analysed.
$configuration->addPathsToExclude(array_map(
    static fn (string $path): string => __DIR__.'/'.$path,
    [
        'tests/Fixtures/RetiredTenancy',
        'tests/Fixtures/tenancy-consumer',
        'tests/MediaTenancyTestCase.php',
        'tests/MediaBatchTenancyTestCase.php',
        'tests/MediaCatalogTenancyTestCase.php',
        'tests/MediaPlatformTenancyTestCase.php',
        'tests/MediaTenantAssetTestCase.php',
        'tests/Fixtures/BatchMediaTenancyFixtureServiceProvider.php',
        'tests/Fixtures/MediaTenancyFixtureServiceProvider.php',
        'tests/Fixtures/MediaTenancyConsumerServiceProvider.php',
        'tests/Fixtures/MediaTenancyConsumerSetupCommand.php',
        'tests/Fixtures/MediaTenancyConsumerMaintenanceMode.php',
        'tests/Fixtures/MediaTenancyDirectory.php',
        'tests/Fixtures/MediaTenancyMaintenanceMode.php',
        'tests/Fixtures/MediaTenancyPlatformAccess.php',
        'tests/Fixtures/MediaTenancyScenario.php',
        'tests/Fixtures/TenancyOwnerAdoptionAdapter.php',
    ],
));

// Optional integrations are guarded by the provider or the S3 dependency guard.
// DistributionTest boots and publishes without either optional integration.
$paths = [
    'nvl/ownership' => ['src/Providers/MediaServiceProvider.php', 'src/Ownership/MediaAdoptionAdapter.php', 'src/Ownership/MediaAdoptionVerifier.php'],
    'aws/aws-sdk-php' => ['src/Services/S3MultipartUploadGateway.php', 'src/Services/MediaS3DependencyGuard.php'],
    'league/flysystem-aws-s3-v3' => ['src/Services/MediaDoctor.php', 'src/Services/MediaS3DependencyGuard.php'],
];
foreach ($paths as $package => $files) {
    foreach ($files as $file) {
        $configuration->ignoreErrorsOnPackageAndPath($package, __DIR__.'/'.$file, [ErrorType::DEV_DEPENDENCY_IN_PROD]);
    }
}

return $configuration;
