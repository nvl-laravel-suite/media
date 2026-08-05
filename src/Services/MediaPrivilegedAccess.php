<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Support\MediaConfiguration;
use Throwable;

/**
 * Adapts optional Spatie-compatible role and permission methods to Media abilities.
 */
final class MediaPrivilegedAccess
{
    /**
     * Determine whether an authenticated actor has a configured cross-owner grant.
     */
    public function allows(Authenticatable $actor, MediaAbility $ability): bool
    {
        if (! (bool) config('media.authorization.spatie_permission.enabled', true)) {
            return false;
        }

        if ($this->hasGlobalRole($actor)) {
            return true;
        }

        $globalPermission = config(
            'media.authorization.spatie_permission.global_permission',
        );

        if (is_string($globalPermission)
            && $globalPermission !== ''
            && $this->hasPermission($actor, $globalPermission)) {
            return true;
        }

        $permission = config(
            "media.authorization.spatie_permission.ability_permissions.{$ability->value}",
        );

        return is_string($permission)
            && $permission !== ''
            && $this->hasPermission($actor, $permission);
    }

    private function hasGlobalRole(Authenticatable $actor): bool
    {
        $roles = MediaConfiguration::stringList(
            'media.authorization.spatie_permission.global_roles',
        );

        if ($roles === [] || ! method_exists($actor, 'hasAnyRole')) {
            return false;
        }

        try {
            return $actor->hasAnyRole($roles) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasPermission(Authenticatable $actor, string $permission): bool
    {
        try {
            if (method_exists($actor, 'checkPermissionTo')) {
                return $actor->checkPermissionTo($permission) === true;
            }

            if (method_exists($actor, 'hasPermissionTo')) {
                return $actor->hasPermissionTo($permission) === true;
            }
        } catch (Throwable) {
            // Missing permissions, guard mismatches, and optional package
            // misconfiguration must fail closed without breaking ownership.
        }

        return false;
    }
}
