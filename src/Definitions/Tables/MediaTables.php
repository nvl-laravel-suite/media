<?php

declare(strict_types=1);

namespace Nvl\Media\Definitions\Tables;

use Nvl\Support\Config\PackageStorage;

/**
 * Defines the canonical table names owned by the Media package.
 */
final class MediaTables
{
    public const string Media = 'nvl_media_media';

    public const string Associations = 'nvl_media_associations';

    public const string ImageVariations = 'nvl_media_image_variations';

    public const string I18n = 'nvl_media_i18n';

    public const string MultipartUploads = 'nvl_media_multipart_uploads';

    public const string OwnerSlotOperations = 'nvl_media_owner_slot_operations';

    public const string TenantGrants = 'nvl_media_tenant_grants';

    public const string TenantGrantLocks = 'nvl_media_tenant_grant_locks';

    public const string TenantAdoptionCopies = 'nvl_media_tenant_adoption_copies';

    public const string MEDIA = self::Media;

    public const string MEDIA_ASSOCIATIONS = self::Associations;

    public const string MEDIA_IMAGE_VARIATIONS = self::ImageVariations;

    public const string MEDIA_I18N = self::I18n;

    public const string MEDIA_MULTIPART_UPLOADS = self::MultipartUploads;

    public const string MEDIA_OWNER_SLOT_OPERATIONS = self::OwnerSlotOperations;

    public const string MEDIA_TENANT_GRANTS = self::TenantGrants;

    public const string MEDIA_TENANT_GRANT_LOCKS = self::TenantGrantLocks;

    public const string MEDIA_TENANT_ADOPTION_COPIES = self::TenantAdoptionCopies;

    /** Return one configured logical or historical package table. */
    public static function get(string $key): string
    {
        return PackageStorage::resolveTable('media', $key);
    }

    private function __construct() {}
}
