<?php

declare(strict_types=1);

namespace Nvl\Media\Definitions\Tables;

/**
 * Defines the canonical table names owned by the Media package.
 */
final class MediaTables
{
    public const string Media = 'px_media';

    public const string Associations = 'px_media_associations';

    public const string ImageVariations = 'px_media_image_variations';

    public const string I18n = 'px_media_i18n';

    public const string MultipartUploads = 'px_media_multipart_uploads';

    public const string OwnerSlotOperations = 'px_media_owner_slot_operations';

    public const string MEDIA = self::Media;

    public const string MEDIA_ASSOCIATIONS = self::Associations;

    public const string MEDIA_IMAGE_VARIATIONS = self::ImageVariations;

    public const string MEDIA_I18N = self::I18n;

    public const string MEDIA_MULTIPART_UPLOADS = self::MultipartUploads;

    public const string MEDIA_OWNER_SLOT_OPERATIONS = self::OwnerSlotOperations;

    private function __construct() {}
}
