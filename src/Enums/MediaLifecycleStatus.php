<?php

declare(strict_types=1);

namespace Nvl\Media\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Persisted lifecycle state for a media binary.
 */
#[TypeScript]
enum MediaLifecycleStatus: string
{
    case PendingUpload = 'pending_upload';
    case PendingScan = 'pending_scan';
    case Quarantined = 'quarantined';
    case Available = 'available';
    case ProcessingVariations = 'processing_variations';
    case Failed = 'failed';
    case Deleted = 'deleted';

    /**
     * Determine whether the binary may be associated or delivered.
     */
    public function isUsable(): bool
    {
        return in_array($this, [self::Available, self::ProcessingVariations], true);
    }
}
