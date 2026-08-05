<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

/**
 * Test double exposing only Spatie Permission's legacy permission-checking method.
 */
final class TestFallbackPermissionMediaUser extends TestMediaUser
{
    /**
     * Determine whether the test actor has the requested permission.
     */
    public function hasPermissionTo(string $permission): bool
    {
        return $permission === 'media.delete-any';
    }
}
