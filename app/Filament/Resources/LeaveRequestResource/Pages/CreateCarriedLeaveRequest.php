<?php

namespace App\Filament\Resources\LeaveRequestResource\Pages;

use App\Filament\Resources\LeaveRequestResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveRequestService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * صفحة مخصصة لإنشاء طلب إجازة من الرصيد المُرحَّل (سنوات سابقة).
 */
class CreateCarriedLeaveRequest extends CreateRecord
{
    protected static string $resource = LeaveRequestResource::class;

    protected static ?string $title = 'إضافة طلب إجازة من رصيد مُرحّل';
    protected static ?string $breadcrumb = 'طلب إجازة من رصيد مُرحّل';

    public function form(Form $form): Form
    {
        $regularType = LeaveType::where('code', 'regular')->first();

        return $form->schema([
            Forms\Components\Section::make('إرشاد وتنبيه')
                ->schema([
                    Forms\Components\Placeholder::make('notice')
                        ->hiddenLabel()
                        ->content(new HtmlString('
                            <div class="flex items-center gap-3 p-3 bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-700/60 rounded-xl text-amber-900 dark:text-amber-200">
                                <span class="text-xl">ℹ️</span>
                                <div class="text-sm">
                                    <strong>طلب إجازة من الرصيد المُرحَّل:</strong>
                                    هذا النموذج مخصص لخصم أيام الإجازة الاعتيادية حصراً ومباشرةً من <strong>الرصيد المُرحَّل</strong> للموظف (من السنوات السابقة)، دون المساس برصيد سنته الحالية.
                                </div>
                            </div>
                        ')),
                ]),

            Forms\Components\Section::make('بيانات الطلب')
                ->schema([
                    Forms\Components\Select::make('employee_id')
                        ->label('الموظف')
                        ->options(fn (): array => LeaveRequestResource::carriedEmployeeOptions())
                        ->searchable()
                        ->required()
                        ->reactive()
                        ->helperText('يظهر موظفو مؤسستك مع بيان رصيدهم المُرحَّل المتاح.'),

                    Forms\Components\Hidden::make('leave_type_id')
                        ->default($regularType?->id)
                        ->required(),

                    Forms\Components\Hidden::make('deduct_from_carried')
                        ->default(1)
                        ->required(),

                    Forms\Components\TextInput::make('leave_type_display')
                        ->label('نوع الإجازة')
                        ->default('إجازة اعتيادية (خصم من الرصيد المُرحَّل)')
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\Placeholder::make('balance_info')
                        ->label('حالة الرصيد المُرحَّل المتاح')
                        ->content(function (Forms\Get $get) use ($regularType): HtmlString {
                            $employeeId = $get('employee_id');
                            if (! $employeeId) {
                                return new HtmlString('<span class="text-gray-400 text-sm">اختر الموظف لعرض تفاصيل الرصيد المُرحَّل</span>');
                            }

                            $emp = Employee::withoutGlobalScopes()->find($employeeId);
                            if (! $emp || ! $regularType) {
                                return new HtmlString('—');
                            }

                            $fiscalYear = app(\App\Services\FiscalYearService::class)->currentFiscalYear();
                            $bal = $emp->balanceFor($regularType->id, $fiscalYear);
                            $carried = $bal ? $bal->carried_over : 0;
                            $usedCarried = $bal ? $bal->used_carried : 0;
                            $remCarried = $bal ? $bal->remaining_carried : 0;
                            $color = $remCarried > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-danger-600 dark:text-danger-400';

                            return new HtmlString("
                                <div class='flex flex-wrap gap-4 text-sm font-medium p-2.5 rounded-lg bg-amber-50/50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/40'>
                                    <div>الرصيد المُرحَّل الإجمالي: <span class='font-bold'>{$carried}</span> يوم</div>
                                    <div>المُستخدم من المُرحَّل: <span class='font-bold'>{$usedCarried}</span> يوم</div>
                                    <div>المتبقي من المُرحَّل: <span class='font-bold {$color}'>{$remCarried} يوم</span></div>
                                </div>
                            ");
                        })
                        ->columnSpanFull(),

                    Forms\Components\DatePicker::make('start_date')
                        ->label('تاريخ البداية')
                        ->required()
                        ->minDate(now()->startOfDay())
                        ->rules(['after_or_equal:today'])
                        ->validationMessages([
                            'after_or_equal' => 'لا يمكن تقديم طلب إجازة بتاريخ قديم في الماضي.',
                        ])
                        ->reactive()
                        ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set) {
                            LeaveRequestResource::recalculateDays($get, $set);
                        }),

                    Forms\Components\DatePicker::make('end_date')
                        ->label('تاريخ النهاية')
                        ->required()
                        ->minDate(fn (Forms\Get $get) => $get('start_date') ?: now()->startOfDay())
                        ->rules(['after_or_equal:start_date'])
                        ->validationMessages([
                            'after_or_equal' => 'تاريخ النهاية يجب أن يكون في نفس تاريخ البداية أو بعده.',
                        ])
                        ->reactive()
                        ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set) {
                            LeaveRequestResource::recalculateDays($get, $set);
                        }),

                    Forms\Components\TextInput::make('days')
                        ->label('عدد الأيام (أيام العمل)')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->disabled()
                        ->dehydrated()
                        ->required()
                        ->rules([
                            'min:1',
                            fn (Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get, $regularType) {
                                $days = (int) $value;
                                if ($days < 1) {
                                    $fail('عدد أيام الإجازة لا يمكن أن يقل عن يوم واحد.');
                                    return;
                                }

                                $employeeId = $get('employee_id');
                                if (! $employeeId || ! $regularType) {
                                    return;
                                }

                                $employee = Employee::withoutGlobalScopes()->find($employeeId);
                                if (! $employee) {
                                    return;
                                }

                                $fiscalYear = app(\App\Services\FiscalYearService::class)->fiscalYearFor(
                                    $get('start_date') ? \Carbon\Carbon::parse($get('start_date')) : null
                                );
                                $balance = $employee->balanceFor($regularType->id, $fiscalYear);
                                $remaining = $balance ? (float) $balance->remaining_carried : 0.0;

                                if ($days > $remaining) {
                                    $fail("عدد الأيام المطلوب ({$days} يوم) يتجاوز الرصيد المُرحَّل المتبقي للموظف ({$remaining} يوم).");
                                }
                            },
                        ])
                        ->validationMessages([
                            'min' => 'عدد أيام الإجازة لا يمكن أن يقل عن يوم واحد.',
                        ])
                        ->helperText('يُحسب تلقائياً من التواريخ (يجب ألا يقل عن يوم واحد ولا يتعدى الرصيد المُرحَّل المتبقي).'),

                    Forms\Components\DatePicker::make('written_at')
                        ->label('تاريخ كتابة الطلب')
                        ->nullable(),

                    Forms\Components\Select::make('substitute_employee_id')
                        ->label('الموظف البديل (القائم بالعمل أثناء الإجازة)')
                        ->options(function (Forms\Get $get): array {
                            $selectedId = $get('employee_id');
                            return LeaveRequestResource::employeeOptions(
                                excludeId: $selectedId ? (int) $selectedId : null
                            );
                        })
                        ->searchable()
                        ->nullable()
                        ->helperText('اختياري — الموظف الذي سيقوم بالعمل أثناء فترة الإجازة إن وُجد.'),

                    Forms\Components\Textarea::make('reason')
                        ->label('السبب')
                        ->nullable()
                        ->maxLength(500)
                        ->columnSpanFull()
                        ->helperText('اختياري — اكتب سبب طلب الإجازة إن وُجد.'),
                ])
                ->columns(2),
        ]);
    }

    protected function handleRecordCreation(array $data): LeaveRequest
    {
        $data['deduct_from_carried'] = true;
        if (empty($data['leave_type_id'])) {
            $data['leave_type_id'] = LeaveType::where('code', 'regular')->value('id');
        }

        try {
            $result = app(LeaveRequestService::class)->create($data, Auth::user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    Notification::make()
                        ->danger()
                        ->title($message)
                        ->persistent()
                        ->send();
                }
            }

            throw $e;
        }

        if (! empty($result->warnings)) {
            foreach ($result->warnings as $warning) {
                Notification::make()
                    ->warning()
                    ->title($warning)
                    ->persistent()
                    ->send();
            }
        }

        return $result->request;
    }
}
