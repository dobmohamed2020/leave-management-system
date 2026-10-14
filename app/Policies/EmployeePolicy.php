<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class EmployeePolicy
{
    use HandlesAuthorization;

    /**
     * هل يمكن للمستخدم عرض قائمة الموظفين؟
     */
    public function viewAny(User $user): bool
    {
        $user->setOrganizationTeam();

        return $user->hasPermissionSafe('view_employees');
    }

    /**
     * هل يمكن للمستخدم عرض موظف بعينه؟
     */
    public function view(User $user, Employee $employee): bool
    {
        $user->setOrganizationTeam();

        return $user->hasPermissionSafe('view_employees');
    }

    /**
     * هل يمكن للمستخدم إنشاء موظف جديد؟
     */
    public function create(User $user): bool
    {
        $user->setOrganizationTeam();

        return $user->hasPermissionSafe('create_employee');
    }

    /**
     * هل يمكن للمستخدم تعديل بيانات موظف؟
     */
    public function update(User $user, Employee $employee): bool
    {
        $user->setOrganizationTeam();

        return $user->hasPermissionSafe('edit_employee');
    }

    /**
     * هل يمكن للمستخدم حذف موظف؟
     */
    public function delete(User $user, Employee $employee): bool
    {
        $user->setOrganizationTeam();

        return $user->hasPermissionSafe('delete_employee');
    }

    /**
     * هل يمكن للمستخدم استعادة موظف محذوف؟
     */
    public function restore(User $user, Employee $employee): bool
    {
        $user->setOrganizationTeam();

        if ($user->hasRole(['مدير الإدارة', 'مدير المديرية']) || $user->can('manage_organization')) {
            return true;
        }

        return $user->can('delete_employee') && ! ($user->organization?->isSchool() ?? false);
    }

    /**
     * هل يمكن للمستخدم استعادة أي موظف محذوف؟
     */
    public function restoreAny(User $user): bool
    {
        $user->setOrganizationTeam();

        if ($user->hasRole(['مدير الإدارة', 'مدير المديرية']) || $user->can('manage_organization')) {
            return true;
        }

        return $user->can('delete_employee') && ! ($user->organization?->isSchool() ?? false);
    }
}
