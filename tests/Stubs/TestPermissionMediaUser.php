<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

use Stringable;

/**
 * Test double for the role and permission methods supplied by Spatie Permission.
 *
 * @property list<string>|null $media_roles
 * @property list<string>|null $media_permissions
 */
final class TestPermissionMediaUser extends TestMediaUser
{
    /** Read role and permission state from the persisted test authority source.
     * @return array<string, string|Stringable>
     */
    protected function casts(): array
    {
        return [...parent::casts(), 'media_roles' => 'array', 'media_permissions' => 'array'];
    }

    /**
     * @param  list<string>  $roles
     */
    public function withMediaRoles(array $roles): self
    {
        $this->setAttribute('media_roles', $roles);
        if ($this->exists) {
            $this->saveQuietly();
        }

        return $this;
    }

    /**
     * @param  list<string>  $permissions
     */
    public function withMediaPermissions(array $permissions): self
    {
        $this->setAttribute('media_permissions', $permissions);
        if ($this->exists) {
            $this->saveQuietly();
        }

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

        return array_intersect(($this->media_roles ?? []), $requested) !== [];
    }

    /**
     * Match Spatie Permission's non-throwing permission-checking surface.
     */
    public function checkPermissionTo(string $permission): bool
    {
        return in_array($permission, ($this->media_permissions ?? []), true);
    }
}
