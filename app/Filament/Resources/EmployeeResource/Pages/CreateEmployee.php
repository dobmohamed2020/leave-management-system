<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Enums\TransactionType;
use App\Filament\Resources\EmployeeResource;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceTransaction;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveBalanceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    protected bool $createSystemUser = false;

    protected ?string $systemEmail = null;

    protected ?string $systemPassword = null;

    protected ?string $systemPhone = null;

    protected int $initialBalanceDays = 0;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->createSystemUser = (bool) ($this->data['is_system_employee'] ?? $data['is_system_employee'] ?? false);
        $this->systemEmail = $this->data['system_email'] ?? $data['system_email'] ?? null;
        $this->systemPassword = $this->data['system_password'] ?? $data['system_password'] ?? null;
        $this->systemPhone = $this->data['system_phone'] ?? $data['system_phone'] ?? null;

        // Capture initial legacy balance days before stripping from form data
        $this->initialBalanceDays = max(
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

        // Creating a new login account takes precedence over linking an existing user
        if ($this->createSystemUser) {
            unset($data['user_id']);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->createSystemUser) {
            if ($this->systemEmail && $this->systemPassword && $this->record->organization_id) {
                EmployeeResource::provisionSystemUser(
                    employee: $this->record,
                    email: $this->systemEmail,
                    password: $this->systemPassword,
                    phone: $this->systemPhone ?: $this->record->phone,
                );
            }
        }

        // Apply initial legacy balance days to carried_over
        if ($this->initialBalanceDays > 0) {
            $regularType = LeaveType::where('code', 'regular')->active()->first();

            if (! $regularType) {
                Log::warning('Initial balance not applied: no active LeaveType with code=regular', [
                    'employee_id'          => $this->record->id,
                    'initial_balance_days' => $this->initialBalanceDays,
                ]);

                Notification::make()
                    ->warning()
                    ->title('تحذير: لم يُطبَّق الرصيد المُرحَّل')
                    ->body('لا يوجد نوع إجازة اعتيادية (regular) نشط في النظام. الرجاء إضافته ثم تعديل رصيد الموظف يدوياً.')
                    ->send();
            } else {
                // The observer has already run accrueAnnual(), so the balance row
                // should exist. Use firstOrCreate as a safety net.
                $fiscalYear = app(\App\Services\FiscalYearService::class)->currentFiscalYear();
                $balance = LeaveBalance::firstOrCreate(
                    [
                        'employee_id'   => $this->record->id,
                        'leave_type_id' => $regularType->id,
                        'year'          => $fiscalYear,
                    ],
                    [
                        'entitled'     => $this->record->regularLeaveEntitlement(),
                        'carried_over' => 0,
                        'used'         => 0,
                    ]
                );

                DB::transaction(function () use ($balance, $regularType, $fiscalYear) {
                    $balance = LeaveBalance::lockForUpdate()->findOrFail($balance->id);

                    $transaction = LeaveBalanceTransaction::create([
                        'leave_balance_id' => $balance->id,
                        'type'             => TransactionType::CARRYOVER,
                        'days'             => $this->initialBalanceDays,
                        'note'             => "رصيد مبدئي قديم: {$this->initialBalanceDays} يوم",
                        'created_by'       => auth()->id(),
                    ]);

                    $balance->increment('carried_over', $this->initialBalanceDays);

                    $actor = auth()->user();
                    app(LeaveBalanceService::class)->recordCarryoverLedger(
                        $this->record,
                        $regularType,
                        $fiscalYear - 1,
                        $fiscalYear,
                        $this->initialBalanceDays,
                        'initial_balance',
                        $transaction->id,
                        $actor instanceof User ? $actor : null
                    );
                });
            }
        }
    }
}
