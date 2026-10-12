<?php

namespace Database\Seeders;

use App\Enums\OrganizationType;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ArmantSchoolsSeeder extends Seeder
{
    private const ROLE_MAP = [
        'manager'    => 'مدير مدرسة',
        'specialist' => 'أخصائي',
    ];

    public function run(): void
    {
        $path = database_path('data/armant-schools.json');

        if (! File::exists($path)) {
            $this->command?->error("Missing data file: {$path}");

            return;
        }

        if (LeaveType::count() === 0) {
            $this->call(LeaveTypeSeeder::class);
        }

        if (\App\Models\EntitlementGrade::count() === 0) {
            $this->call(EntitlementGradeSeeder::class);
        }

        $payload = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $directorate = $this->ensureOrganization(
            type: OrganizationType::DIRECTORATE,
            name: $payload['directorate'],
            code: 'DIR-LXR',
            parent: null,
        );

        $administration = $this->ensureOrganization(
            type: OrganizationType::ADMINISTRATION,
            name: $payload['administration'],
            code: 'ADM-ARM',
            parent: $directorate,
        );

        $schools = [];
        foreach ($payload['schools'] as $schoolData) {
            $schools[] = $this->ensureOrganization(
                type: OrganizationType::SCHOOL,
                name: $schoolData['name'],
                code: strtoupper($schoolData['code']),
                parent: $administration,
            );
        }

        $this->command?->info('✓ Armant hierarchy ready: directorate, administration, '.count($schools).' schools.');

        // Create/refresh roles for any newly added organizations.
        $this->call(RoleAndPermissionSeeder::class);

        $allowedEmails = [];
        $userCount = 0;
        $defaultPassword = $payload['default_password'] ?? '123456789';

        // Administration oversight accounts (Director, HR affairs, Leaves officer)
        $adminAccounts = $payload['admin_accounts'] ?? [
            [
                'email' => 'armant.manager@armant-schools.edu',
                'name'  => 'مدير إدارة أرمنت التعليمية',
                'role'  => 'مدير الإدارة',
                'password' => $defaultPassword,
                'phone' => '01000000001',
                'must_change_password' => false,
            ],
            [
                'email' => 'armant.hr@armant-schools.edu',
                'name'  => 'مسؤول شؤون العاملين بإدارة أرمنت',
                'role'  => 'شؤون عاملين',
                'password' => $defaultPassword,
                'phone' => '01000000003',
                'must_change_password' => false,
            ],
            [
                'email' => 'armant.leaves@armant-schools.edu',
                'name'  => 'مسؤول إجازات إدارة أرمنت',
                'role'  => 'مسؤول الإجازات',
                'password' => $defaultPassword,
                'phone' => '01000000002',
                'must_change_password' => false,
            ],
        ];

        foreach ($adminAccounts as $account) {
            $allowedEmails[] = $account['email'];

            $user = User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name'                 => $account['name'],
                    'password'             => $account['password'] ?? $defaultPassword,
                    'phone'                => User::normalizePhone($account['phone'] ?? null),
                    'organization_id'      => $administration->id,
                    'is_active'            => true,
                    'must_change_password' => (bool) ($account['must_change_password'] ?? false),
                ]
            );

            $this->assignRole($user, $account['role'], $administration->id);
            $userCount++;
        }

        // Schools: Manager account ONLY for each school
        foreach ($payload['schools'] as $index => $schoolData) {
            $school = $schools[$index];
            $code = strtolower($schoolData['code']);

            $managerAccount = collect($schoolData['accounts'] ?? [])
                ->firstWhere('role', 'manager');

            if (! $managerAccount) {
                $this->command?->warn("Missing manager account for school {$schoolData['code']}");
                continue;
            }

            $allowedEmails[] = $managerAccount['email'];

            $legacyEmail = "{$code}.manager@armant-schools.example";
            $user = User::where('email', $managerAccount['email'])->first()
                ?? User::where('email', $legacyEmail)->first();

            $attrs = [
                'email'                => $managerAccount['email'],
                'name'                 => "مدير المدرسة — {$school->name}",
                'password'             => $managerAccount['password'] ?? $defaultPassword,
                'phone'                => User::normalizePhone($managerAccount['phone'] ?? null),
                'organization_id'      => $school->id,
                'is_active'            => true,
                'must_change_password' => (bool) ($managerAccount['must_change_password'] ?? false),
            ];

            if ($user) {
                $user->fill($attrs)->save();
            } else {
                $user = User::create($attrs);
            }

            $this->assignRole($user, 'مدير مدرسة', $school->id);
            $userCount++;
        }

        // Delete any accounts outside the allowed whitelist (removes specialist and test accounts)
        $deleted = User::query()
            ->where(function ($q) {
                $q->where('email', 'like', '%@armant-schools.%');
            })
            ->whereNotIn('email', $allowedEmails)
            ->get()
            ->each(function (User $user): void {
                setPermissionsTeamId($user->organization_id);
                $user->syncRoles([]);
                $user->tokens()->delete();
                $user->notifications()->delete();
                $user->delete();
            })
            ->count();

        setPermissionsTeamId(null);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Delete all previously entered/demo employees and their related records
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        \App\Models\LeaveRequestStep::query()->delete();
        \App\Models\LeaveRequestAttachment::query()->delete();
        \App\Models\LeaveRequest::withoutGlobalScopes()->forceDelete();
        \App\Models\LeaveBalanceTransaction::query()->delete();
        \App\Models\LeaveBalance::query()->delete();
        $deletedEmployees = Employee::withoutGlobalScopes()->forceDelete();
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

        // Ensure workflows exist for every school (including newly added ones).
        $this->call(WorkflowConfigurationSeeder::class);

        $domain = $payload['email_domain'] ?? 'armant-schools.edu';
        $this->command?->info("✓ {$userCount} Armant accounts seeded (@{$domain}): 3 administration + ".count($schools).' school managers.');
        $this->command?->info("✓ Removed {$deleted} users outside the whitelist (specialist and extra accounts removed).");
        $this->command?->info("✓ Cleaned employees table: removed {$deletedEmployees} records (0 demo employees seeded).");
        $this->command?->info('  Admin Manager : armant.manager@armant-schools.edu');
        $this->command?->info('  HR Affairs    : armant.hr@armant-schools.edu');
        $this->command?->info('  Leave Officer : armant.leaves@armant-schools.edu');
        $this->command?->info('  School sample : arm001.manager@armant-schools.edu');
    }

    private function ensureOrganization(
        OrganizationType $type,
        string $name,
        string $code,
        ?Organization $parent
    ): Organization {
        $org = Organization::withoutGlobalScopes()->updateOrCreate(
            ['code' => $code],
            [
                'parent_id' => $parent?->id,
                'type'      => $type,
                'name'      => $name,
                'depth'     => $type->depth(),
                'is_active' => true,
                'path'      => '/', // temporary until ID is known
            ]
        );

        $path = $parent ? $parent->path."{$org->id}/" : "/{$org->id}/";

        if ($org->path !== $path || $org->parent_id !== $parent?->id) {
            $org->updateQuietly([
                'path'      => $path,
                'parent_id' => $parent?->id,
                'depth'     => $type->depth(),
            ]);
        }

        return $org->fresh();
    }

    private function assignRole(User $user, string $roleName, int $organizationId): void
    {
        setPermissionsTeamId($organizationId);

        $role = Role::query()
            ->where('name', $roleName)
            ->where('organization_id', $organizationId)
            ->first();

        if ($role) {
            $user->syncRoles([$role]);
        } else {
            $this->command?->warn("Role [{$roleName}] missing for organization #{$organizationId}");
        }
    }
}
