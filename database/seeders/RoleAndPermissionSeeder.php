<?php

namespace Database\Seeders;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * All system permissions.
     * Guard is 'web' throughout — adjust if API guard is added later.
     */
    private const PERMISSIONS = [
        // إجازات
        'create_leave_request',
        'edit_leave_request',
        'submit_leave_request',
        'cancel_leave_request',
        'approve_leave_request',
        'reject_leave_request',
        'return_leave_request',
        'view_leave_requests',
        'view_all_leave_requests',   // مستوى الإدارة والمديرية
        'delete_leave_request',      // حذف قبل قرار الإدارة
        // موظفون
        'create_employee',
        'edit_employee',
        'delete_employee',
        'view_employees',
        // مستخدمون وأدوار
        'manage_roles',
        'manage_users',
        // مؤسسات
        'manage_organization',
        // تقارير
        'view_reports',
        'export_reports',
    ];

    /**
     * Default role definitions per organization type.
     * Each role gets a label (name) and a list of permission keys.
     */
    private const ROLES = [
        // ========= مدرسة =========
        // المدرسة: موظفين + طلبات إجازة فقط (بدون مستخدمين/مؤسسات/تقارير)
        'school_employee' => [
            'label'       => 'موظف مدرسة',
            'org_types'   => [OrganizationType::SCHOOL],
            'permissions' => [
                'create_leave_request',
                'edit_leave_request',
                'submit_leave_request',
                'cancel_leave_request',
                'delete_leave_request',
                'view_leave_requests',
                'view_employees',
            ],
        ],
        'school_manager' => [
            'label'       => 'مدير مدرسة',
            'org_types'   => [OrganizationType::SCHOOL],
            // يعتمد مرحلة المدرسة (توقيع إلكتروني) ثم يرفع للإدارة
            'permissions' => [
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
        ],
        'school_assistant' => [
            'label'       => 'وكيل مدرسة',
            'org_types'   => [OrganizationType::SCHOOL],
            'permissions' => [
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
        ],
        'school_specialist' => [
            'label'       => 'أخصائي',
            'org_types'   => [OrganizationType::SCHOOL],
            'permissions' => [
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
        // ========= إدارة تعليمية =========
        // الإدارة: إشراف واعتماد + تقارير + مستخدمين/مؤسسات
        'leaves_officer' => [
            'label'       => 'مسؤول الإجازات',
            'org_types'   => [OrganizationType::ADMINISTRATION],
            'permissions' => [
                'approve_leave_request',
                'reject_leave_request',
                'return_leave_request',
                'view_leave_requests',
                'view_all_leave_requests',
                'view_employees',
                'view_reports',
                'export_reports',
            ],
        ],
        'hr_affairs' => [
            'label'       => 'شؤون عاملين',
            'org_types'   => [OrganizationType::ADMINISTRATION],
            'permissions' => [
                'approve_leave_request',
                'reject_leave_request',
                'return_leave_request',
                'view_leave_requests',
                'view_all_leave_requests',
                'view_employees',
                'view_reports',
                'export_reports',
            ],
        ],
        'admin_manager' => [
            'label'       => 'مدير الإدارة',
            'org_types'   => [OrganizationType::ADMINISTRATION],
            'permissions' => [
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
        ],
        'admin_clerk' => [
            'label'       => 'كاتب الإدارة',
            'org_types'   => [OrganizationType::ADMINISTRATION],
            'permissions' => [
                'view_leave_requests',
                'view_all_leave_requests',
                'view_employees',
                'view_reports',
            ],
        ],
        // ========= مديرية =========
        'director' => [
            'label'       => 'مدير المديرية',
            'org_types'   => [OrganizationType::DIRECTORATE],
            'permissions' => self::PERMISSIONS, // كل الصلاحيات
        ],
        'deputy_director' => [
            'label'       => 'وكيل المديرية',
            'org_types'   => [OrganizationType::DIRECTORATE],
            'permissions' => [
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
        ],
        'coordinator' => [
            'label'       => 'منسق',
            'org_types'   => [OrganizationType::DIRECTORATE],
            'permissions' => [
                'view_leave_requests',
                'view_all_leave_requests',
                'view_employees',
                'view_reports',
                'export_reports',
            ],
        ],
    ];

    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Create all permissions (guard: web)
        foreach (self::PERMISSIONS as $perm) {
            Permission::updateOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->command?->info('✓ ' . count(self::PERMISSIONS) . ' permissions created.');

        // 2. Create roles scoped to each real organization
        //    Spatie teams: organization_id is the "team_id"
        $organizations = Organization::all();

        foreach ($organizations as $org) {
            self::seedForOrganization($org);
        }

        $this->command?->info("✓ Roles created for {$organizations->count()} organizations.");

        // Reset team context
        setPermissionsTeamId(null);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public static function seedForOrganization(Organization $org): void
    {
        // Ensure permissions exist
        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        setPermissionsTeamId($org->id);

        foreach (self::ROLES as $key => $definition) {
            if (! in_array($org->type, $definition['org_types'])) {
                continue;
            }

            $role = Role::updateOrCreate(
                [
                    'name'            => $definition['label'],
                    'guard_name'      => 'web',
                    'organization_id' => $org->id,
                ],
                []
            );

            $permissions = array_filter(
                $definition['permissions'],
                fn($p) => in_array($p, self::PERMISSIONS)
            );

            $role->syncPermissions($permissions);
        }

        setPermissionsTeamId(null);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
