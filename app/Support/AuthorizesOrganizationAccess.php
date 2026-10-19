<?php

namespace App\Support;

use App\Models\User;

/**
 * Ensures a user may only act on records belonging to their organization subtree.
 */
final class AuthorizesOrganizationAccess
{
    public static function userCanAccessOrganization(User $user, int $organizationId): bool
    {
        if (! $user->organization_id) {
            return true;
        }

        $organization = $user->organization;

        if (! $organization) {
            return false;
        }

        return in_array($organizationId, $organization->subtreeIds(), true);
    }
}
