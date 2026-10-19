<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Support\AuthorizesOrganizationAccess;

trait ChecksOrganizationSubtree
{
    protected function userCanAccessOrganization(User $user, int $organizationId): bool
    {
        return AuthorizesOrganizationAccess::userCanAccessOrganization($user, $organizationId);
    }
}
