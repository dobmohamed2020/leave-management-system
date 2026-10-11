<?php

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkflowConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

describe('Organization Creation & Hierarchy', function () {
    it('creates directorate without manual path or depth', function () {
        $directorate = Organization::create([
            'name'      => 'مديرية الأقصر التعليمية الجديدة',
            'code'      => 'DIR-TEST-NEW',
            'type'      => OrganizationType::DIRECTORATE,
            'is_active' => true,
        ]);

        expect($directorate->fresh()->path)->toBe("/{$directorate->id}/")
            ->and($directorate->fresh()->depth)->toBe(0)
            ->and($directorate->isDirectorate())->toBeTrue();
    });

    it('creates administration under directorate with correct path and depth', function () {
        $directorate = Organization::create([
            'name'      => 'مديرية قنا التعليمية',
            'code'      => 'DIR-QNA',
            'type'      => OrganizationType::DIRECTORATE,
            'is_active' => true,
        ]);

        $admin = Organization::create([
            'name'      => 'إدارة قوص التعليمية',
            'code'      => 'ADM-QUS',
            'type'      => OrganizationType::ADMINISTRATION,
            'parent_id' => $directorate->id,
            'is_active' => true,
        ]);

        expect($admin->fresh()->path)->toBe("/{$directorate->id}/{$admin->id}/")
            ->and($admin->fresh()->depth)->toBe(1)
            ->and($admin->isAdministration())->toBeTrue();
    });

    it('creates school under administration with correct path and depth', function () {
        $directorate = Organization::create([
            'name'      => 'مديرية سوهاج التعليمية',
            'code'      => 'DIR-SHG',
            'type'      => OrganizationType::DIRECTORATE,
            'is_active' => true,
        ]);

        $admin = Organization::create([
            'name'      => 'إدارة طهطا التعليمية',
            'code'      => 'ADM-THT',
            'type'      => OrganizationType::ADMINISTRATION,
            'parent_id' => $directorate->id,
            'is_active' => true,
        ]);

        $school = Organization::create([
            'name'      => 'مدرسة النور الابتدائية',
            'code'      => 'SCH-NUR',
            'type'      => OrganizationType::SCHOOL,
            'parent_id' => $admin->id,
            'is_active' => true,
        ]);

        expect($school->fresh()->path)->toBe("/{$directorate->id}/{$admin->id}/{$school->id}/")
            ->and($school->fresh()->depth)->toBe(2)
            ->and($school->isSchool())->toBeTrue();
    });

    it('cascades path updates when organization moves to new parent', function () {
        $dir1 = Organization::create([
            'name' => 'مديرية 1',
            'code' => 'DIR-1',
            'type' => OrganizationType::DIRECTORATE,
        ]);

        $dir2 = Organization::create([
            'name' => 'مديرية 2',
            'code' => 'DIR-2',
            'type' => OrganizationType::DIRECTORATE,
        ]);

        $admin = Organization::create([
            'name'      => 'إدارة منقولة',
            'code'      => 'ADM-MOV',
            'type'      => OrganizationType::ADMINISTRATION,
            'parent_id' => $dir1->id,
        ]);

        $school = Organization::create([
            'name'      => 'مدرسة تابعة',
            'code'      => 'SCH-SUB',
            'type'      => OrganizationType::SCHOOL,
            'parent_id' => $admin->id,
        ]);

        expect($school->fresh()->path)->toBe("/{$dir1->id}/{$admin->id}/{$school->id}/");

        // Move admin to dir2
        $admin->parent_id = $dir2->id;
        $admin->save();

        expect($admin->fresh()->path)->toBe("/{$dir2->id}/{$admin->id}/")
            ->and($school->fresh()->path)->toBe("/{$dir2->id}/{$admin->id}/{$school->id}/");
    });

    it('can create school via Filament CreateOrganization page and provisions roles and workflow', function () {
        $dir = Organization::create([
            'name' => 'مديرية الأقصر',
            'code' => 'DIR-LXR',
            'type' => OrganizationType::DIRECTORATE,
        ]);

        $admin = Organization::create([
            'name'      => 'إدارة أرمنت',
            'code'      => 'ADM-ARM',
            'type'      => OrganizationType::ADMINISTRATION,
            'parent_id' => $dir->id,
        ]);

        $user = User::factory()->create([
            'organization_id' => $dir->id,
            'is_active'       => true,
        ]);
        // Give manage_organization permission
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'manage_organization', 'guard_name' => 'web']);
        setPermissionsTeamId($dir->id);
        $user->givePermissionTo('manage_organization');

        Livewire::actingAs($user)
            ->test(\App\Filament\Resources\OrganizationResource\Pages\CreateOrganization::class)
            ->fillForm([
                'name'      => 'مدرسة النيل الابتدائية',
                'code'      => 'SCH-NIL',
                'type'      => OrganizationType::SCHOOL->value,
                'parent_id' => $admin->id,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $school = Organization::where('code', 'SCH-NIL')->first();
        expect($school)->not->toBeNull()
            ->and($school->path)->toBe("/{$dir->id}/{$admin->id}/{$school->id}/")
            ->and($school->depth)->toBe(2);

        // Roles provisioned
        $roles = Role::where('organization_id', $school->id)->pluck('name')->all();
        expect($roles)->toContain('مدير مدرسة')
            ->and($roles)->toContain('وكيل مدرسة')
            ->and($roles)->toContain('موظف مدرسة');

        // Workflows provisioned
        $stages = WorkflowConfiguration::withoutGlobalScopes()
            ->where('organization_id', $school->id)
            ->orderBy('step_order')
            ->get();

        expect($stages)->toHaveCount(4);
    });
});
