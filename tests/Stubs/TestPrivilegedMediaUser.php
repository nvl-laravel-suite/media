<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

/** Test actor granted the configured global Media role. */
final class TestPrivilegedMediaUser extends TestMediaUser
{
    public function hasRole(string $role): bool
    {
        return $role === 'media-admin';
    }
}
