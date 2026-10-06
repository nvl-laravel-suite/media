<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

use Nvl\Support\Contracts\ResponseCode;

/**
 * Stable Media mutation and failure discriminators, preserving existing Inertia flash values.
 *
 * @api
 */
enum MediaResponseCode: string implements ResponseCode
{
    case FileUnacceptableForCollection = 'file_unacceptable_for_collection';
    case ConversionFailed = 'conversion_failed';
    case DiskNotDefined = 'disk_not_defined';
    case MediaUploadFailed = 'media_upload_failed';
    case MediaInUse = 'media_in_use';
    case StorageUnavailable = 'storage_unavailable';

    case Updated = 'updated';
    case Deleted = 'deleted';

    case BatchReadUnavailable = 'batch_read_unavailable';
    case MediaNotReusable = 'media_not_reusable';
    case S3DependencyUnavailable = 's3_dependency_unavailable';
    case OperationFailed = 'operation_failed';
}
