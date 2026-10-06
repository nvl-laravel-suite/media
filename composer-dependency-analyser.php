<?php

declare(strict_types=1);

use Nvl\Data\Services\TypeScriptSourceRegistry;
use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$configuration = (new Configuration)
    ->ignoreErrorsOnPackage('nvl/media', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackage(
        'orchestra/testbench-core',
        [ErrorType::SHADOW_DEPENDENCY],
    )
    ->addForceUsedSymbol(TypeScriptSourceRegistry::class);

$paths = [
    'aws/aws-sdk-php' => ['src/Services/S3MultipartUploadGateway.php', 'src/Services/MediaS3DependencyGuard.php'],
    'league/flysystem-aws-s3-v3' => ['src/Services/MediaDoctor.php', 'src/Services/MediaS3DependencyGuard.php'],
];
$paths['spatie/typescript-transformer'] = [
    'src/Data/Display/MediaDocument.php',
    'src/Data/Display/MediaFile.php',
    'src/Data/Display/MediaImage.php',
    'src/Data/Display/MediaImageBreakpoint.php',
    'src/Data/Display/MediaImageSize.php',
    'src/Data/Display/MediaImageVariationPayload.php',
    'src/Data/Display/MediaLibraryItem.php',
    'src/Data/Display/MediaManagementData.php',
    'src/Data/Display/MediaPayload.php',
    'src/Data/Display/MediaUsage.php',
    'src/Data/Display/OwnerMediaSlotData.php',
    'src/Data/Display/OwnerMediaSlotItemData.php',
    'src/Data/Display/OwnerMediaSlots.php',
    'src/Data/Display/PublicMedia.php',
    'src/Data/Display/PublicMediaFile.php',
    'src/Data/Display/PublicMediaImage.php',
    'src/Data/Display/PublicMediaImageSize.php',
    'src/Data/MediaActorData.php',
    'src/Data/MediaDoctorCheckData.php',
    'src/Data/MediaFilter.php',
    'src/Data/MediaScanResultData.php',
    'src/Data/Multipart/CompleteMultipartUploadData.php',
    'src/Data/Multipart/CompletedMultipartObjectData.php',
    'src/Data/Multipart/CompletedMultipartPartData.php',
    'src/Data/Multipart/InitiateMultipartUploadData.php',
    'src/Data/Multipart/MultipartUploadSessionData.php',
    'src/Data/Multipart/SignMultipartPartData.php',
    'src/Data/Multipart/SignedMultipartPartData.php',
    'src/Data/Mutations/AttachMediaPayload.php',
    'src/Data/Mutations/BulkMediaPayload.php',
    'src/Data/Mutations/DetachMediaPayload.php',
    'src/Data/Mutations/RenameMediaData.php',
    'src/Data/Mutations/ReorderMediaPayload.php',
    'src/Data/Mutations/ReplaceMediaData.php',
    'src/Data/Mutations/RequestMediaVariationsData.php',
    'src/Data/Mutations/StoreMediaPayload.php',
    'src/Data/Mutations/UpdateMediaPayload.php',
    'src/Enums/ImageCompression.php',
    'src/Enums/ImageFit.php',
    'src/Enums/ImageFormat.php',
    'src/Enums/ImagePreset.php',
    'src/Enums/MediaAbility.php',
    'src/Enums/MediaImageDriver.php',
    'src/Enums/MediaLifecycleStatus.php',
    'src/Enums/MediaMultipartStatus.php',
    'src/Enums/MediaOwnerSlotOperationStatus.php',
    'src/Enums/MediaOwnerSlotOperationType.php',
    'src/Enums/MediaType.php',
    'src/Enums/MediaVisibility.php',
    'src/Enums/MimeType.php',
];
foreach ($paths as $package => $files) {
    foreach ($files as $file) {
        $configuration->ignoreErrorsOnPackageAndPath($package, __DIR__.'/'.$file, [ErrorType::DEV_DEPENDENCY_IN_PROD]);
    }
}

return $configuration;
