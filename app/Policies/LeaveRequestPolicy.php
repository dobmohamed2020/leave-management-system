<?php

namespace App\Policies;

use App\Enums\OrganizationType;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class LeaveRequestPolicy
{
    use HandlesAuthorization;

    /**
     * هل يمكن للمستخدم عرض قائمة طلبات الإجازات؟
     */
    public function viewAny(User $user): bool
    {
        $user->setOrganizationTeam();

        return $user->hasPermissionSafe('view_leave_requests');
    }

    /**
     * هل يمكن للمستخدم عرض طلب إجازة بعينه؟
     */
    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        $user->setOrganizationTeam();

        return $user->hasPermissionSafe('view_leave_requests')
            || $user->hasPermissionSafe('approve_leave_request');
    }

    /**
     * هل يمكن للمستخدم إنشاء طلب إجازة جديد؟
     */
    public function create(User $user): bool
    {
        $user->setOrganizationTeam();

        return $user->hasPermissionSafe('create_leave_request');
    }

    /**
     * هل يمكن للمستخدم تعديل طلب إجازة؟
     * المدرسة: قبل اعتماد/رفض الإدارة. غير ذلك: حالات المسودة/المعاد فقط.
     */
    public function update(User $user, LeaveRequest $leaveRequest): bool
    {
        $user->setOrganizationTeam();

        if (! $user->hasPermissionSafe('edit_leave_request')) {
            return false;
        }

        if ($user->hasPermissionSafe('manage_organization') || $user->hasPermissionSafe('view_reports')) {
            return $leaveRequest->status->canBeEdited()
                || $leaveRequest->status->canBeModifiedBySchool();
        }

        // School users: edit until administration makes a final decision.
        return $leaveRequest->status->canBeModifiedBySchool();
    }

    /**
     * هل يمكن للمستخدم حذف طلب إجازة؟
     * المدرسة قبل قرار الإدارة، أو صلاحية manage_organization.
     */
    public function delete(User $user, LeaveRequest $leaveRequest): bool
    {
        $user->setOrganizationTeam();

        if ($user->hasPermissionSafe('manage_organization')) {
            return true;
        }

        return $user->hasPermissionSafe('delete_leave_request')
            && $leaveRequest->status->canBeModifiedBySchool();
    }

    /**
     * هل يمكن للمستخدم استعادة طلب إجازة محذوف؟
     */
    public function restore(User $user, LeaveRequest $leaveRequest): bool
    {
        $user->setOrganizationTeam();

        if ($user->hasRole(['مدير الإدارة', 'مدير المديرية']) || $user->can('manage_organization')) {
            return true;
        }

        return $user->can('delete_leave_request') && ! ($user->organization?->isSchool() ?? false);
    }

    /**
     * هل يمكن للمستخدم استعادة أي طلب إجازة محذوف؟
     */
    public function restoreAny(User $user): bool
    {
        $user->setOrganizationTeam();

        if ($user->hasRole(['مدير الإدارة', 'مدير المديرية']) || $user->can('manage_organization')) {
            return true;
        }

        return $user->can('delete_leave_request') && ! ($user->organization?->isSchool() ?? false);
    }

    /**
     * هل يمكن للمستخدم تقديم طلب إجازة؟
     */
    public function submit(User $user, LeaveRequest $leaveRequest): bool
    {
        $user->setOrganizationTeam();

        return $user->hasPermissionSafe('submit_leave_request');
    }

    /**
     * هل يمكن للمستخدم اعتماد طلب الإجازة؟
     * - مدير المدرسة: مرحلة school_principal فقط
     * - الإدارة/المديرية: مراحل الإدارة
     */
    public function approve(User $user, LeaveRequest $leaveRequest): bool
    {
        $user->setOrganizationTeam();

        if (! $user->hasPermissionSafe('approve_leave_request')) {
            return false;
        }

        if ($leaveRequest->current_stage === 'school_principal') {
            return $this->isSchoolPrincipalActor($user, $leaveRequest);
        }

        $allowedStages = ['leaves_officer', 'hr_affairs', 'admin_manager'];

        if (! $this->isAdministrationActor($user)) {
            return false;
        }

        if (! in_array($leaveRequest->current_stage, $allowedStages, true)) {
            return false;
        }

        $requiredRole = $this->resolveStageRole($leaveRequest->current_stage);

        return $requiredRole !== false && $user->hasRole($requiredRole);
    }

    /**
     * هل يمكن للمستخدم رفض طلب الإجازة؟
     * الرفض النهائي من الإدارة فقط.
     */
    public function reject(User $user, LeaveRequest $leaveRequest): bool
    {
        $user->setOrganizationTeam();

        if (! $user->hasPermissionSafe('reject_leave_request')) {
            return false;
        }

        $allowedStages = ['leaves_officer', 'hr_affairs', 'admin_manager'];

        if (! $this->isAdministrationActor($user)) {
            return false;
        }

        if (! in_array($leaveRequest->current_stage, $allowedStages, true)) {
            return false;
        }

        $requiredRole = $this->resolveStageRole($leaveRequest->current_stage);

        return $requiredRole !== false && $user->hasRole($requiredRole);
    }

    /**
     * هل يمكن للمستخدم إعادة طلب الإجازة للتعديل؟
     */
    public function return(User $user, LeaveRequest $leaveRequest): bool
    {
        $user->setOrganizationTeam();

        if (! $user->hasPermissionSafe('return_leave_request')) {
            return false;
        }

        if ($leaveRequest->current_stage === 'school_principal') {
            return $this->isSchoolPrincipalActor($user, $leaveRequest);
        }

        $allowedStages = ['leaves_officer', 'hr_affairs', 'admin_manager'];

        if (! $this->isAdministrationActor($user)) {
            return false;
        }

        if (! in_array($leaveRequest->current_stage, $allowedStages, true)) {
            return false;
        }

        $requiredRole = $this->resolveStageRole($leaveRequest->current_stage);

        return $requiredRole !== false && $user->hasRole($requiredRole);
    }

    /**
     * Map stage name to the required Arabic role name for that stage.
     * Returns false for any unknown stage — fail-closed: unknown stages are denied.
     *
     * @return string|false  role name to check, or false to deny access
     */
    private function resolveStageRole(string $stage): string|false
    {
        return match ($stage) {
            'leaves_officer' => 'مسؤول الإجازات',
            'hr_affairs'     => 'شؤون عاملين',
            'admin_manager'  => 'مدير الإدارة',
            default          => false,
        };
    }

    private function isAdministrationActor(User $user): bool
    {
        $type = $user->organization?->type;

        return in_array($type, [
            OrganizationType::ADMINISTRATION,
            OrganizationType::DIRECTORATE,
        ], true);
    }

    private function isSchoolPrincipalActor(User $user, LeaveRequest $leaveRequest): bool
    {
        return ($user->organization?->isSchool() ?? false)
            && $leaveRequest->organization_id === $user->organization_id
            && $user->hasRole('مدير مدرسة');
    }

    /**
     * هل يمكن للمستخدم إلغاء طلب الإجازة؟
     * يشترط أن تكون حالة الطلب قابلة للإلغاء.
     */
    public function cancel(User $user, LeaveRequest $leaveRequest): bool
    {
        $user->setOrganizationTeam();

        return $user->hasPermissionSafe('cancel_leave_request')
            && $leaveRequest->status->canBeCancelled();
    }
}
