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

        return $user->hasPermissionTo('view_employees');
    }

    /**
     * هل يمكن للمستخدم عرض موظف بعينه؟
     */
    public function view(User $user, Employee $employee): bool
    {
        $user->setOrganizationTeam();

        return $user->hasPermissionTo('view_employees');
    }

    /**
     * هل يمكن للمستخدم إنشاء موظف جديد؟
     */
    public function create(User $user): bool
    {
        $user->setOrganizationTeam();

        return $user->hasPermissionTo('create_employee');
    }

    /**
     * هل يمكن للمستخدم تعديل بيانات موظف؟
     */
    public function update(User $user, Employee $employee): bool
    {
        $user->setOrganizationTeam();

        return $user->hasPermissionTo('edit_employee');
    }

    /**
     * هل يمكن للمستخدم حذف موظف؟
     */
    public function delete(User $user, Employee $employee): bool
    {
        $user->setOrganizationTeam();

        return $user->hasPermissionTo('delete_employee');
    }

    /**
     * هل يمكن للمستخدم استعادة موظف محذوف؟
     */
    public function restore(User $user, Employee $employee): bool
    {
        $user->setOrganizationTeam();

        if ($user->hasRole(['مدير الإدارة', 'مدير المديرية', 'مدير عام']) || $user->hasPermissionTo('manage_organization')) {
            return true;
        }

        return $user->hasPermissionTo('delete_employee') && ! ($user->organization?->isSchool() ?? false);
    }

    /**
     * هل يمكن للمستخدم استعادة أي موظف محذوف؟
     */
    public function restoreAny(User $user): bool
    {
        $user->setOrganizationTeam();

        if ($user->hasRole(['مدير الإدارة', 'مدير المديرية', 'مدير عام']) || $user->hasPermissionTo('manage_organization')) {
            return true;
        }

        return $user->hasPermissionTo('delete_employee') && ! ($user->organization?->isSchool() ?? false);
    }
}
