<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Enums\TransactionType;
use App\Filament\Resources\EmployeeResource;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceTransaction;
use App\Models\LeaveType;
use App\Models\User;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected bool $createSystemUser = false;

    protected ?string $systemEmail = null;

    protected ?string $systemPassword = null;

    protected ?string $systemPhone = null;

    protected int $additionalBalanceDays = 0;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->createSystemUser = (bool) ($this->data['is_system_employee'] ?? $data['is_system_employee'] ?? false);
        $this->systemEmail = $this->data['system_email'] ?? $data['system_email'] ?? null;
        $this->systemPassword = $this->data['system_password'] ?? $data['system_password'] ?? null;
        $this->systemPhone = $this->data['system_phone'] ?? $data['system_phone'] ?? null;

        // Capture additional legacy balance days before stripping from form data
        $this->additionalBalanceDays = max(
            0,
            (int) ($this->data['initial_balance_days'] ?? $data['initial_balance_days'] ?? 0)
        );

        unset(
            $data['is_system_employee'],
            $data['system_email'],
            $data['system_password'],
            $data['system_phone'],
            $data['initial_balance_days'],
        );

        if (EmployeeResource::isSchoolActor()) {
            $data['organization_id'] = auth()->user()->organization_id;
            unset($data['user_id']);
        }

        if ($this->createSystemUser && blank($this->record->user_id)) {
            unset($data['user_id']);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // Sync phone to linked user account
        if ($this->record->user_id) {
            $phone = User::normalizePhone($this->record->phone);
            User::whereKey($this->record->user_id)->update(['phone' => $phone]);

            if ($this->record->wasChanged('approval_role')) {
                $user = User::find($this->record->user_id);
                if ($user) {
                    $organizationId = (int) $this->record->organization_id;
                    setPermissionsTeamId($organizationId);

                    $org = $this->record->organization ?? \App\Models\Organization::withoutGlobalScopes()->find($organizationId);
                    $isAdministration = $org?->isAdministration();
                    $isDirectorate    = $org?->isDirectorate();

                    $spatieRoleName = match($this->record->approval_role) {
                        \App\Enums\ApprovalRole::SCHOOL_PRINCIPAL => 'مدير مدرسة',
                        \App\Enums\ApprovalRole::LEAVES_OFFICER   => 'مسؤول الإجازات',
                        \App\Enums\ApprovalRole::HR_AFFAIRS       => 'شؤون عاملين',
                        \App\Enums\ApprovalRole::ADMIN_MANAGER    => 'مدير الإدارة',
                        default                                   => match(true) {
                            $isAdministration => 'كاتب الإدارة',
                            $isDirectorate    => 'منسق المديرية',
                            default           => 'موظف مدرسة',
                        },
                    };

                    $role = \Spatie\Permission\Models\Role::query()
                        ->where('name', $spatieRoleName)
                        ->where('organization_id', $organizationId)
                        ->first();

                    if ($role) {
                        $user->syncRoles([$role]);
                    }

                    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
                    setPermissionsTeamId(null);
                }
            }
        }

        if (! $this->record->user_id && $this->createSystemUser) {
            if ($this->systemEmail && $this->systemPassword && $this->record->organization_id) {
                EmployeeResource::provisionSystemUser(
                    employee: $this->record,
                    email: $this->systemEmail,
                    password: $this->systemPassword,
                    phone: $this->systemPhone ?: $this->record->phone,
                );
            }
        }

        // Apply additional legacy balance days to carried_over (additive)
        if ($this->additionalBalanceDays > 0) {
            $regularType = LeaveType::where('code', 'regular')->active()->first();

            if (! $regularType) {
                Log::warning('Additional balance not applied: no active LeaveType with code=regular', [
                    'employee_id'           => $this->record->id,
                    'additional_balance_days' => $this->additionalBalanceDays,
                ]);

                Notification::make()
                    ->warning()
                    ->title('تحذير: لم يُطبَّق الرصيد المُرحَّل')
                    ->body('لا يوجد نوع إجازة اعتيادية (regular) نشط في النظام. الرجاء إضافته ثم تعديل رصيد الموظف يدوياً.')
                    ->send();
            } else {
                $service = app(\App\Services\LeaveBalanceService::class);
                $balance = $service->getOrCreateBalance($this->record, $regularType, now()->year);

                DB::transaction(function () use ($balance) {
                    $balance = LeaveBalance::lockForUpdate()->findOrFail($balance->id);

                    LeaveBalanceTransaction::create([
                        'leave_balance_id' => $balance->id,
                        'type'             => TransactionType::CARRYOVER,
                        'days'             => $this->additionalBalanceDays,
                        'note'             => "رصيد مُرحَّل مُعدَّل يدوياً: {$this->additionalBalanceDays} يوم",
                        'created_by'       => auth()->id(),
                    ]);

                    $balance->increment('carried_over', $this->additionalBalanceDays);
                });
            }
        }
    }
}
