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

        // Administration oversight accounts (from JSON when present).
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

        foreach ($payload['schools'] as $index => $schoolData) {
            $school = $schools[$index];
            $code = strtolower($schoolData['code']);

            foreach ($schoolData['accounts'] as $account) {
                $roleName = self::ROLE_MAP[$account['role']] ?? null;

                if (! $roleName) {
                    $this->command?->warn("Unknown account role [{$account['role']}] for {$schoolData['code']}");

                    continue;
                }

                $allowedEmails[] = $account['email'];

                // Migrate legacy @armant-schools.example accounts to the new domain.
                $legacyEmail = "{$code}.{$account['role']}@armant-schools.example";
                $user = User::where('email', $account['email'])->first()
                    ?? User::where('email', $legacyEmail)->first();

                $attrs = [
                    'email'                => $account['email'],
                    'name'                 => "{$account['title']} — {$school->name}",
                    'password'             => $account['password'] ?? $defaultPassword,
                    'phone'                => User::normalizePhone($account['phone'] ?? null),
                    'organization_id'      => $school->id,
                    'is_active'            => true,
                    'must_change_password' => (bool) ($account['must_change_password'] ?? false),
                ];

                if ($user) {
                    $user->fill($attrs)->save();
                } else {
                    $user = User::create($attrs);
                }

                $this->assignRole($user, $roleName, $school->id);
                $userCount++;
            }
        }

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
                $user->delete();
            })
            ->count();

        setPermissionsTeamId(null);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $employeeCount = $this->seedEmployeesForSchools($schools);

        // Ensure workflows exist for every school (including newly added ones).
        $this->call(WorkflowConfigurationSeeder::class);

        $domain = $payload['email_domain'] ?? 'armant-schools.edu';
        $this->command?->info("✓ {$userCount} Armant school accounts seeded (@{$domain}).");
        $this->command?->info("✓ Removed {$deleted} users outside the Armant schools list.");
        $this->command?->info("✓ {$employeeCount} employees seeded for Armant schools.");
        $this->command?->info('  School login : arm001.manager@armant-schools.edu');
        $this->command?->info('  Admin login  : armant.manager@armant-schools.edu');
    }

    /**
     * Ensure each Armant school has demo employees + leave balances.
     *
     * @param  list<Organization>  $schools
     */
    private function seedEmployeesForSchools(array $schools): int
    {
        $leaveTypes = LeaveType::all();
        $year = now()->year;
        $created = 0;
        $gradesCodes = \App\Models\EntitlementGrade::where('is_active', true)->pluck('code')->toArray();
        $defaultGrade = $gradesCodes[0] ?? null;

        $jobTitles = ['معلم', 'معلم أول', 'وكيل', 'أمين مكتبة', 'أخصائي اجتماعي'];

        foreach ($schools as $school) {
            $existing = Employee::withoutGlobalScopes()
                ->where('organization_id', $school->id)
                ->count();

            $needed = max(0, 3 - $existing);

            for ($i = 0; $i < $needed; $i++) {
                $employee = Employee::create([
                    'organization_id'   => $school->id,
                    'employee_code'     => 'EMP-' . strtoupper(substr(md5($school->id . $i . microtime()), 0, 6)),
                    'full_name'         => 'موظف ' . ($existing + $i + 1) . ' — ' . $school->name,
                    'job_title'         => $jobTitles[$i % count($jobTitles)],
                    'entitlement_grade' => $defaultGrade,
                    'hire_date'         => now()->subYears(rand(1, 15))->toDateString(),
                    'work_start_date'   => now()->subYears(rand(1, 15))->toDateString(),
                    'is_active'         => true,
                ]);

                foreach ($leaveTypes as $leaveType) {
                    $entitled = match ($leaveType->code) {
                        'regular' => $employee->regularLeaveEntitlement(),
                        default   => $leaveType->yearly_entitlement,
                    };

                    if ($entitled == 0 && ! $leaveType->deducts_balance) {
                        continue;
                    }

                    LeaveBalance::firstOrCreate(
                        [
                            'employee_id'   => $employee->id,
                            'leave_type_id' => $leaveType->id,
                            'year'          => $year,
                        ],
                        [
                            'entitled'     => $entitled,
                            'carried_over' => 0,
                            'used'         => 0,
                        ]
                    );
                }

                $created++;
            }
        }

        return $created;
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
