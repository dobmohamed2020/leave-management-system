<?php

namespace App\Filament\Resources\OrganizationResource\Pages;

use App\Enums\ApprovalRule;
use App\Enums\OrganizationType;
use App\Filament\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\WorkflowConfiguration;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CreateOrganization extends CreateRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        /** @var Organization $record */
        $record = $this->getRecord();

        $this->provisionRolesForOrganization($record);

        if ($record->isSchool()) {
            $this->provisionWorkflowForSchool($record);
        }
    }

    private function provisionRolesForOrganization(Organization $organization): void
    {
        $roleDefinitions = [
            OrganizationType::SCHOOL->value => [
                'موظف مدرسة' => [
                    'create_leave_request',
                    'edit_leave_request',
                    'submit_leave_request',
                    'cancel_leave_request',
                    'delete_leave_request',
                    'view_leave_requests',
                    'view_employees',
                ],
                'مدير مدرسة' => [
                    'create_leave_request',
                    'edit_leave_request',
                    'submit_leave_request',
                    'approve_leave_request',
                    'return_leave_request',
                    'cancel_leave_request',
                    'delete_leave_request',
                    'view_leave_requests',
                    'view_all_leave_requests',
                    'create_employee',
                    'edit_employee',
                    'delete_employee',
                    'view_employees',
                ],
                'وكيل مدرسة' => [
                    'create_leave_request',
                    'edit_leave_request',
                    'submit_leave_request',
                    'cancel_leave_request',
                    'delete_leave_request',
                    'view_leave_requests',
                    'view_all_leave_requests',
                    'create_employee',
                    'edit_employee',
                    'view_employees',
                ],
                'أخصائي' => [
                    'create_leave_request',
                    'edit_leave_request',
                    'submit_leave_request',
                    'cancel_leave_request',
                    'delete_leave_request',
                    'view_leave_requests',
                    'view_all_leave_requests',
                    'create_employee',
                    'edit_employee',
                    'delete_employee',
                    'view_employees',
                ],
            ],
            OrganizationType::ADMINISTRATION->value => [
                'مسؤول الإجازات' => [
                    'approve_leave_request',
                    'reject_leave_request',
                    'return_leave_request',
                    'view_leave_requests',
                    'view_all_leave_requests',
                    'view_employees',
                    'view_reports',
                    'export_reports',
                ],
                'شؤون عاملين' => [
                    'approve_leave_request',
                    'reject_leave_request',
                    'return_leave_request',
                    'view_leave_requests',
                    'view_all_leave_requests',
                    'view_employees',
                    'view_reports',
                    'export_reports',
                ],
                'مدير الإدارة' => [
                    'approve_leave_request',
                    'reject_leave_request',
                    'return_leave_request',
                    'view_leave_requests',
                    'view_all_leave_requests',
                    'create_employee',
                    'edit_employee',
                    'delete_employee',
                    'view_employees',
                    'manage_users',
                    'manage_roles',
                    'manage_organization',
                    'view_reports',
                    'export_reports',
                ],
                'كاتب الإدارة' => [
                    'view_leave_requests',
                    'view_all_leave_requests',
                    'view_employees',
                    'view_reports',
                ],
            ],
            OrganizationType::DIRECTORATE->value => [
                'مدير المديرية' => [
                    'create_leave_request',
                    'edit_leave_request',
                    'submit_leave_request',
                    'cancel_leave_request',
                    'approve_leave_request',
                    'reject_leave_request',
                    'return_leave_request',
                    'view_leave_requests',
                    'view_all_leave_requests',
                    'delete_leave_request',
                    'create_employee',
                    'edit_employee',
                    'delete_employee',
                    'view_employees',
                    'manage_roles',
                    'manage_users',
                    'manage_organization',
                    'view_reports',
                    'export_reports',
                ],
                'وكيل المديرية' => [
                    'approve_leave_request',
                    'reject_leave_request',
                    'return_leave_request',
                    'view_leave_requests',
                    'view_all_leave_requests',
                    'view_employees',
                    'manage_users',
                    'manage_roles',
                    'view_reports',
                    'export_reports',
                ],
                'منسق' => [
                    'view_leave_requests',
                    'view_all_leave_requests',
                    'view_employees',
                    'view_reports',
                    'export_reports',
                ],
            ],
        ];

        $type = $organization->type instanceof OrganizationType
            ? $organization->type->value
            : (string) $organization->type;

        if (! isset($roleDefinitions[$type])) {
            return;
        }

        $teamKey = config('permission.column_names.team_foreign_key', 'organization_id');

        foreach ($roleDefinitions[$type] as $roleName => $permissions) {
            $role = Role::firstOrCreate(
                [
                    'name'       => $roleName,
                    'guard_name' => 'web',
                    $teamKey     => $organization->id,
                ],
                []
            );

            $validPerms = Permission::where('guard_name', 'web')
                ->whereIn('name', $permissions)
                ->pluck('name')
                ->all();

            $role->syncPermissions($validPerms);
        }
    }

    private function provisionWorkflowForSchool(Organization $organization): void
    {
        $stages = [
            [
                'stage_name'    => 'school_principal',
                'step_order'    => 1,
                'approval_rule' => ApprovalRule::ANY,
                'required_role' => 'مدير مدرسة',
                'label'         => 'اعتماد مدير المدرسة',
            ],
            [
                'stage_name'    => 'leaves_officer',
                'step_order'    => 2,
                'approval_rule' => ApprovalRule::ANY,
                'required_role' => 'مسؤول الإجازات',
                'label'         => 'رأي مسؤول الإجازات',
            ],
            [
                'stage_name'    => 'hr_affairs',
                'step_order'    => 3,
                'approval_rule' => ApprovalRule::ANY,
                'required_role' => 'شؤون عاملين',
                'label'         => 'رأي شؤون العاملين',
            ],
            [
                'stage_name'    => 'admin_manager',
                'step_order'    => 4,
                'approval_rule' => ApprovalRule::ANY,
                'required_role' => 'مدير الإدارة',
                'label'         => 'رأي مدير الإدارة',
            ],
        ];

        foreach ($stages as $stage) {
            WorkflowConfiguration::withoutGlobalScopes()->firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'step_order'      => $stage['step_order'],
                ],
                [
                    'stage_name'    => $stage['stage_name'],
                    'approval_rule' => $stage['approval_rule'],
                    'required_role' => $stage['required_role'],
                    'label'         => $stage['label'],
                    'is_active'     => true,
                ]
            );
        }
    }
}
