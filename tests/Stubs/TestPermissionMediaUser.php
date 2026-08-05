<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

/**
 * Test double for the role and permission methods supplied by Spatie Permission.
 */
final class TestPermissionMediaUser extends TestMediaUser
{
    /** @var list<string> */
    private array $mediaRoles = [];

    /** @var list<string> */
    private array $mediaPermissions = [];

    /**
     * @param  list<string>  $roles
     */
    public function withMediaRoles(array $roles): self
    {
        $this->mediaRoles = $roles;

        return $this;
    }

    /**
     * @param  list<string>  $permissions
     */
    public function withMediaPermissions(array $permissions): self
    {
        $this->mediaPermissions = $permissions;

        return $this;
    }

    /**
     * Match Spatie Permission's role-checking surface.
     *
     * @param  list<string>|string  ...$roles
     */
    public function hasAnyRole(array|string ...$roles): bool
    {
        $requested = [];

        foreach ($roles as $role) {
            $requested = array_merge($requested, is_array($role) ? $role : [$role]);
        }

        return array_intersect($this->mediaRoles, $requested) !== [];
    }

    /**
     * Match Spatie Permission's non-throwing permission-checking surface.
     */
    public function checkPermissionTo(string $permission): bool
    {
        return in_array($permission, $this->mediaPermissions, true);
    }
}
