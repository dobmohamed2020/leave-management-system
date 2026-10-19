<?php

namespace App\Support;

use App\Models\User;

final class SystemAudit
{
    public static function canViewAuditLog(?User $user): bool
    {
        if (! $user || empty($user->email)) {
            return false;
        }

        $viewer = strtolower(trim((string) config('audit.viewer_email', 'admin@rtltec.com')));

        return strtolower(trim($user->email)) === $viewer;
    }
}
