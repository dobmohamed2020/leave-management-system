<?php

namespace App\Filament\Resources;

use App\Enums\LeaveStatus;
use App\Enums\StepStatus;
use App\Exceptions\LeaveRequestException;
use App\Filament\Resources\LeaveRequestResource\Pages;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Organization;
use App\Rules\Leave\WorkingDaysRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * مورد إدارة طلبات الإجازات مع مسار الاعتماد الكامل.
 */
class LeaveRequestResource extends Resource
{
    protected static ?string $model = LeaveRequest::class;

    protected static ?string $navigationGroup = 'طلبات الإجازات';
    protected static ?string $navigationLabel = 'طلبات الإجازة';
    protected static ?string $navigationIcon  = 'heroicon-o-clipboard-document-list';
    protected static ?string $modelLabel      = 'طلب إجازة';
    protected static ?string $pluralModelLabel = 'طلبات الإجازات';

    public static function canViewAny(): bool
    {
        $user = Auth::user();
        $user?->setOrganizationTeam();

        return (bool) $user?->can('viewAny', LeaveRequest::class);
    }

    // -------------------------------------------------------------------------
    // Navigation Badge (pending requests count)
    // -------------------------------------------------------------------------

    public static function getNavigationBadge(): ?string
    {
        // OrganizationScope applies automatically — shows only the logged-in user's org
        return (string) LeaveRequest::pending()->count() ?: null;
    }

    // -------------------------------------------------------------------------
    // Form (shared between Create and Edit pages)
    // -------------------------------------------------------------------------

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('بيانات الطلب')
                ->schema([
                    Forms\Components\Select::make('employee_id')
                        ->label('الموظف')
                        ->options(fn (): array => self::employeeOptions())
                        ->searchable()
                        ->required()
                        ->reactive()
                        ->helperText('يظهر فقط موظفو مؤسستك (ونطاقها).'),

                    Forms\Components\Select::make('leave_type_id')
                        ->label('نوع الإجازة')
                        ->options(
                            LeaveType::active()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray()
                        )
                        ->required()
                        ->reactive(),

                    Forms\Components\Hidden::make('deduct_from_carried')
                        ->default(0)
                        ->dehydrated(),

                    Forms\Components\Placeholder::make('balance_info')
                        ->label('حالة الرصيد المتاح')
                        ->content(function (Forms\Get $get): \Illuminate\Support\HtmlString {
                            $employeeId = $get('employee_id');
                            $leaveTypeId = $get('leave_type_id');
                            if (! $employeeId) {
                                return new \Illuminate\Support\HtmlString('<span class="text-gray-400 text-sm">اختر الموظف ونوع الإجازة لعرض الرصيد</span>');
                            }

                            $emp = Employee::withoutGlobalScopes()->find($employeeId);
                            $type = $leaveTypeId ? LeaveType::find($leaveTypeId) : null;
                            if (! $emp || ! $type) {
                                return new \Illuminate\Support\HtmlString('—');
                            }

                            if (! $type->deducts_balance) {
                                return new \Illuminate\Support\HtmlString('<span class="text-info-600 dark:text-info-400 text-sm font-medium">هذا النوع من الإجازات لا يخصم من الرصيد.</span>');
                            }

                            $fiscalYear = app(\App\Services\FiscalYearService::class)->currentFiscalYear();
                            $bal = $emp->balanceFor($type->id, $fiscalYear);
                            $entitled = $bal ? (int) $bal->entitled : 0;
                            $used = $bal ? (int) $bal->used : 0;
                            $rem = $bal ? (int) $bal->remaining_entitled : 0;
                            $color = $rem > 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400';

                            return new \Illuminate\Support\HtmlString("
                                <div class='flex flex-wrap gap-4 text-sm font-medium p-2.5 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700'>
                                    <div>رصيد السنة الحالية المستحق: <span class='font-bold'>{$entitled}</span> يوم</div>
                                    <div>المُستخدم: <span class='font-bold'>{$used}</span> يوم</div>
                                    <div>المتبقي: <span class='font-bold {$color}'>{$rem} يوم</span></div>
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
                            self::recalculateDays($get, $set);
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
                            self::recalculateDays($get, $set);
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
                            fn (Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                $days = (int) $value;
                                if ($days < 1) {
                                    $fail('عدد أيام الإجازة لا يمكن أن يقل عن يوم واحد.');
                                    return;
                                }

                                $employeeId = $get('employee_id');
                                $leaveTypeId = $get('leave_type_id');
                                if (! $employeeId || ! $leaveTypeId) {
                                    return;
                                }

                                $employee = Employee::withoutGlobalScopes()->find($employeeId);
                                $leaveType = LeaveType::find($leaveTypeId);
                                if (! $employee || ! $leaveType || ! $leaveType->deducts_balance) {
                                    return;
                                }

                                $fiscalYear = app(\App\Services\FiscalYearService::class)->fiscalYearFor(
                                    $get('start_date') ? \Carbon\Carbon::parse($get('start_date')) : null
                                );
                                $balance = $employee->balanceFor($leaveType->id, $fiscalYear);
                                $remaining = $balance ? (float) $balance->remaining_entitled : 0.0;

                                if ($days > $remaining) {
                                    $fail("عدد الأيام المطلوب ({$days} يوم) يتجاوز رصيد السنة الحالية المتبقي للموظف ({$remaining} يوم).");
                                }
                            },
                        ])
                        ->validationMessages([
                            'min' => 'عدد أيام الإجازة لا يمكن أن يقل عن يوم واحد.',
                        ])
                        ->helperText('يُحسب تلقائياً من التواريخ (يجب ألا يقل عن يوم واحد ولا يتعدى الرصيد المتبقي).'),

                    Forms\Components\DatePicker::make('written_at')
                        ->label('تاريخ كتابة الطلب')
                        ->nullable(),

                    Forms\Components\Select::make('substitute_employee_id')
                        ->label('الموظف البديل (القائم بالعمل أثناء الإجازة)')
                        ->options(function (Forms\Get $get): array {
                            $selectedId = $get('employee_id');

                            return self::employeeOptions(
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

            Forms\Components\Section::make('معلومات الرصيد')
                ->schema([
                    Forms\Components\TextInput::make('balance_entitled')
                        ->label('الرصيد المستحق')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('balance_used')
                        ->label('المستخدم')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false),

                    Forms\Components\TextInput::make('balance_remaining')
                        ->label('المتبقي')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false),
                ])
                ->columns(3)
                ->disabled()
                ->hiddenOn('create'),
        ]);
    }

    /**
     * إعادة حساب أيام العمل بين التاريخين.
     */
    private static function recalculateDays(Forms\Get $get, Forms\Set $set): void
    {
        $start = $get('start_date');
        $end   = $get('end_date');

        if (! $start || ! $end) {
            return;
        }

        $rule = new WorkingDaysRule();
        $rule->setData(['start_date' => $start, 'end_date' => $end]);
        $rule->validate('days', 0, fn($msg) => null);

        $set('days', $rule->calculatedDays);
    }

    // -------------------------------------------------------------------------
    // Infolist (View page)
    // -------------------------------------------------------------------------

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('بيانات الطلب')
                ->schema([
                    Infolists\Components\TextEntry::make('number')
                        ->label('رقم الطلب')
                        ->copyable(),

                    Infolists\Components\TextEntry::make('employee.full_name')
                        ->label('الموظف'),

                    Infolists\Components\TextEntry::make('leaveType.name')
                        ->label('نوع الإجازة'),

                    Infolists\Components\TextEntry::make('organization.name')
                        ->label('الجهة'),

                    Infolists\Components\TextEntry::make('start_date')
                        ->label('من')
                        ->date('d F Y'),

                    Infolists\Components\TextEntry::make('end_date')
                        ->label('إلى')
                        ->date('d F Y'),

                    Infolists\Components\TextEntry::make('days')
                        ->label('عدد الأيام'),

                    Infolists\Components\TextEntry::make('written_at')
                        ->label('تاريخ الكتابة')
                        ->date('d F Y'),

                    Infolists\Components\TextEntry::make('status')
                        ->label('الحالة')
                        ->badge()
                        ->formatStateUsing(fn ($state, LeaveRequest $record): string => $record->displayStatusLabel())
                        ->color(fn ($state, LeaveRequest $record): string => $record->displayStatusColor()),

                    Infolists\Components\TextEntry::make('substituteEmployee.full_name')
                        ->label('الموظف البديل (القائم بالعمل)')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('current_stage')
                        ->label('المرحلة الحالية')
                        ->formatStateUsing(fn (?string $state): string => LeaveRequest::pendingApprovalLabel($state))
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('reason')
                        ->label('السبب')
                        ->columnSpanFull()
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('rejection_reason')
                        ->label('سبب الرفض')
                        ->columnSpanFull()
                        ->placeholder('—')
                        ->visible(fn(LeaveRequest $record) => $record->rejection_reason !== null),
                ])
                ->columns(2),

            Infolists\Components\Section::make('معلومات الرصيد')
                ->schema([
                    Infolists\Components\TextEntry::make('balance_entitled')
                        ->label('المستحق')
                        ->suffix(' يوم'),

                    Infolists\Components\TextEntry::make('balance_used')
                        ->label(fn (LeaveRequest $record): string =>
                            $record->status === LeaveStatus::APPROVED ? 'المستخدم (بعد الاعتماد)' : 'المستخدم'
                        )
                        ->suffix(' يوم'),

                    Infolists\Components\TextEntry::make('balance_remaining')
                        ->label(fn (LeaveRequest $record): string =>
                            $record->status === LeaveStatus::APPROVED ? 'المتبقي (بعد الاعتماد)' : 'المتبقي'
                        )
                        ->suffix(' يوم')
                        ->weight(\Filament\Support\Enums\FontWeight::Bold)
                        ->color('success'),
                ])
                ->columns(3)
                ->visible(fn(LeaveRequest $record) => $record->balance_entitled !== null),

            Infolists\Components\Section::make('مسار الاعتماد')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('steps')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('stage')
                                ->label('المرحلة')
                                ->formatStateUsing(fn (?string $state): string => LeaveRequest::stageLabel($state)),

                            Infolists\Components\TextEntry::make('status')
                                ->label('الحالة')
                                ->badge()
                                ->formatStateUsing(fn(StepStatus $state): string => $state->label())
                                ->color(fn(StepStatus $state): string => $state->color()),

                            Infolists\Components\TextEntry::make('actedBy.name')
                                ->label('بواسطة')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('acted_at')
                                ->label('التاريخ')
                                ->dateTime('d F Y H:i')
                                ->placeholder('—'),

                            Infolists\Components\TextEntry::make('note')
                                ->label('الملاحظة')
                                ->placeholder('—')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                ]),
        ]);
    }

    // -------------------------------------------------------------------------
    // Table
    // -------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('لا توجد طلبات إجازة')
            ->emptyStateDescription('أضف طلب إجازة للبدء.')
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('رقم الطلب')
                    ->copyable()
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('employee.full_name')
                    ->label('الموظف')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('leaveType.name')
                    ->label('نوع الإجازة')
                    ->formatStateUsing(fn ($state, LeaveRequest $record): string =>
                        $record->deduct_from_carried ? "{$state} (مُرحَّل)" : (string) $state
                    )
                    ->color(fn (LeaveRequest $record): ?string => $record->deduct_from_carried ? 'warning' : null)
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('من')
                    ->date('d F Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('end_date')
                    ->label('إلى')
                    ->date('d F Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('days')
                    ->label('الأيام')
                    ->numeric(0)
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state, LeaveRequest $record): string => $record->displayStatusLabel())
                    ->color(fn ($state, LeaveRequest $record): string => $record->displayStatusColor())
                    ->sortable(),

                Tables\Columns\TextColumn::make('current_stage')
                    ->label('المرحلة الحالية')
                    ->formatStateUsing(fn (?string $state): string => LeaveRequest::pendingApprovalLabel($state))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('organization.name')
                    ->label('الجهة')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'active' => 'قائم',
                        'modified' => 'تم تعديله وقائم',
                        'cancelled' => 'تم حذفه',
                        'draft' => 'مسودة',
                        'returned' => 'مُعاد للتعديل',
                        'rejected' => 'مرفوض',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'active' => $query->whereNull('deleted_at')
                                ->where('was_modified', false)
                                ->whereIn('status', [
                                    LeaveStatus::SUBMITTED->value,
                                    LeaveStatus::IN_REVIEW->value,
                                    LeaveStatus::APPROVED->value,
                                ]),
                            'modified' => $query->whereNull('deleted_at')
                                ->where('was_modified', true)
                                ->whereNotIn('status', [
                                    LeaveStatus::CANCELLED->value,
                                    LeaveStatus::REJECTED->value,
                                ]),
                            'cancelled' => $query->where(function (Builder $q) {
                                $q->where('status', LeaveStatus::CANCELLED->value)
                                    ->orWhereNotNull('deleted_at');
                            }),
                            'draft' => $query->whereNull('deleted_at')
                                ->where('status', LeaveStatus::DRAFT->value),
                            'returned' => $query->whereNull('deleted_at')
                                ->where('status', LeaveStatus::RETURNED->value)
                                ->where('was_modified', false),
                            'rejected' => $query->whereNull('deleted_at')
                                ->where('status', LeaveStatus::REJECTED->value),
                            default => $query,
                        };
                    }),

                Tables\Filters\SelectFilter::make('leave_type_id')
                    ->label('نوع الإجازة')
                    ->options(
                        LeaveType::orderBy('name')->pluck('name', 'id')->toArray()
                    ),

                Tables\Filters\SelectFilter::make('organization_id')
                    ->label('الجهة')
                    ->options(
                        Organization::withoutGlobalScopes()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray()
                    ),

                Tables\Filters\Filter::make('start_date')
                    ->label('نطاق التاريخ')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('من'),
                        Forms\Components\DatePicker::make('to')->label('إلى'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn($q, $date) => $q->whereDate('start_date', '>=', $date))
                            ->when($data['to'],   fn($q, $date) => $q->whereDate('start_date', '<=', $date));
                    }),

                Tables\Filters\SelectFilter::make('deduct_from_carried')
                    ->label('جهة الخصم')
                    ->options([
                        '0' => 'رصيد السنة الحالية',
                        '1' => 'الرصيد المُرحَّل (سنوات سابقة)',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (($data['value'] ?? null) === '0') {
                            return $query->where('deduct_from_carried', false);
                        }
                        if (($data['value'] ?? null) === '1') {
                            return $query->where('deduct_from_carried', true);
                        }
                        return $query;
                    }),

                Tables\Filters\TrashedFilter::make()
                    ->label('المحذوفات')
                    ->visible(fn (): bool => ! (Auth::user()?->organization?->isSchool() ?? false)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (LeaveRequest $record): bool => Auth::user()?->can('update', $record) ?? false),

                // للمدارس: إرسال طلب اعتماد حذف لمدير الإدارة
                Tables\Actions\Action::make('request_deletion')
                    ->label('طلب حذف')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (LeaveRequest $record): bool =>
                        (Auth::user()?->organization?->isSchool() ?? false)
                        && ! in_array($record->status, [LeaveStatus::CANCELLED])
                    )
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('سبب طلب حذف الإجازة')
                            ->required()
                            ->maxLength(500)
                            ->placeholder('اكتب سبب طلب الحذف لإرساله إلى مدير الإدارة للاعتماد...'),
                    ])
                    ->action(function (LeaveRequest $record, array $data): void {
                        \App\Models\DeletionRequest::create([
                            'organization_id' => Auth::user()->organization_id,
                            'model_type'      => 'leave_request',
                            'model_id'        => $record->id,
                            'title'           => "طلب حذف إجازة: {$record->number} ({$record->employee?->full_name})",
                            'details'         => [
                                'leave_number'  => $record->number,
                                'employee_name' => $record->employee?->full_name,
                                'leave_type'    => $record->leaveType?->name,
                                'start_date'    => $record->start_date?->format('Y-m-d'),
                                'end_date'      => $record->end_date?->format('Y-m-d'),
                                'days'          => $record->days,
                            ],
                            'reason'          => $data['reason'],
                            'requested_by'    => Auth::id(),
                            'status'          => 'pending',
                        ]);

                        Notification::make()
                            ->success()
                            ->title('تم إرسال طلب الحذف لمدير الإدارة للاعتماد')
                            ->send();
                    }),

                // لمدير الإدارة والآدمن: حذف نهائي شامل Cascade
                Tables\Actions\Action::make('force_delete_request')
                    ->label('حذف نهائي شامل')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد الحذف النهائي الشامل لطلب الإجازة')
                    ->modalDescription('تحذير شديد: سيتم حذف طلب الإجازة وجميع المرفقات وحركات الرصيد المرتبطة به نهائياً من قاعدة البيانات بلا رجعة. هل أنت متأكد؟')
                    ->modalSubmitActionLabel('نعم، احذف نهائياً')
                    ->modalCancelActionLabel('إلغاء')
                    ->visible(fn (LeaveRequest $record): bool =>
                        (bool) (Auth::user()?->hasRole(['مدير الإدارة', 'مدير المديرية', 'مدير عام'])
                            || Auth::user()?->hasPermissionTo('manage_organization'))
                    )
                    ->action(function (LeaveRequest $record): void {
                        app(\App\Services\CascadeDeletionService::class)->deleteLeaveRequestPermanently($record, Auth::user());
                        Notification::make()
                            ->success()
                            ->title("تم حذف طلب الإجازة {$record->number} نهائياً مع كافة متعلقاته")
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد حذف طلب الإجازة')
                    ->modalDescription('سيتم تعليم الطلب كـ «تم حذفه».')
                    ->modalSubmitActionLabel('نعم، احذف')
                    ->modalCancelActionLabel('إلغاء')
                    ->visible(fn (LeaveRequest $record): bool =>
                        ! (Auth::user()?->organization?->isSchool() ?? false)
                        && (Auth::user()?->can('delete', $record) ?? false)
                    )
                    ->before(function (LeaveRequest $record): void {
                        if ($record->status !== LeaveStatus::CANCELLED && $record->status->canBeCancelled()) {
                            try {
                                app(\App\Services\LeaveRequestService::class)->cancel($record, Auth::user());
                            } catch (\Throwable) {
                                $record->forceFill([
                                    'status' => LeaveStatus::CANCELLED,
                                    'current_stage' => null,
                                ])->save();
                            }
                        } elseif ($record->status !== LeaveStatus::CANCELLED) {
                            $record->forceFill([
                                'status' => LeaveStatus::CANCELLED,
                                'current_stage' => null,
                            ])->save();
                        }
                    }),

                // استعادة طلب الإجازة من الحذف العادي
                Tables\Actions\RestoreAction::make()
                    ->label('استعادة من الحذف')
                    ->color('success')
                    ->modalHeading(fn (LeaveRequest $record): string => "تأكيد استعادة طلب الإجازة رقم {$record->number}")
                    ->modalDescription('سيتم استعادة طلب الإجازة وإعادته بحالة «مسودة» بحيث يمكن تعديله أو إعادة تقديمه.')
                    ->modalSubmitActionLabel('نعم، استعادة طلب الإجازة')
                    ->modalCancelActionLabel('إلغاء')
                    ->visible(fn (LeaveRequest $record): bool =>
                        $record->trashed() &&
                        ! (Auth::user()?->organization?->isSchool() ?? false) &&
                        (bool) (
                            Auth::user()?->hasRole(['مدير الإدارة', 'مدير المديرية', 'مدير عام'])
                            || Auth::user()?->hasPermissionTo('manage_organization')
                            || (Auth::user()?->can('restore', $record) ?? false)
                        )
                    )
                    ->after(function (LeaveRequest $record): void {
                        app(\App\Services\LeaveRequestService::class)->restore($record, Auth::user());

                        Notification::make()
                            ->success()
                            ->title("تمت استعادة طلب الإجازة [{$record->number}] بنجاح بحالة مسودة")
                            ->send();
                    }),

                Tables\Actions\Action::make('submit')
                    ->label('تقديم الطلب')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn(LeaveRequest $record): bool =>
                        in_array($record->status, [LeaveStatus::DRAFT, LeaveStatus::RETURNED])
                        && Auth::user()?->can('submit', $record)
                    )
                    ->action(function (LeaveRequest $record): void {
                        try {
                            app(\App\Services\LeaveRequestService::class)->submit($record, Auth::user());
                            Notification::make()
                                ->success()
                                ->title('تم تقديم الطلب بنجاح')
                                ->send();
                        } catch (LeaveRequestException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('approve')
                    ->label(fn (LeaveRequest $record): string => $record->current_stage === 'school_principal'
                        ? 'اعتماد وتوقيع إلكتروني'
                        : 'اعتماد')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(LeaveRequest $record): bool =>
                        in_array($record->status, [LeaveStatus::SUBMITTED, LeaveStatus::IN_REVIEW])
                        && Auth::user()?->can('approve', $record)
                    )
                    ->form([
                        Forms\Components\Textarea::make('note')
                            ->label('ملاحظة (اختيارية)')
                            ->nullable(),
                    ])
                    ->action(function (LeaveRequest $record, array $data): void {
                        try {
                            $step = $record->steps()
                                ->where('status', StepStatus::PENDING->value)
                                ->where('stage', $record->current_stage)
                                ->first();

                            if (! $step) {
                                Notification::make()->danger()->title('لا توجد مرحلة نشطة للاعتماد.')->send();
                                return;
                            }

                            app(\App\Services\LeaveRequestService::class)->approve(
                                $record, $step, Auth::user(), $data['note'] ?? null
                            );

                            Notification::make()->success()->title('تمت الموافقة على الطلب')->send();
                        } catch (LeaveRequestException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('رفض')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn(LeaveRequest $record): bool =>
                        in_array($record->status, [LeaveStatus::SUBMITTED, LeaveStatus::IN_REVIEW])
                        && Auth::user()?->can('reject', $record)
                    )
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('سبب الرفض')
                            ->required(),
                    ])
                    ->action(function (LeaveRequest $record, array $data): void {
                        try {
                            $step = $record->steps()
                                ->where('status', StepStatus::PENDING->value)
                                ->where('stage', $record->current_stage)
                                ->first();

                            if (! $step) {
                                Notification::make()->danger()->title('لا توجد مرحلة نشطة للرفض.')->send();
                                return;
                            }

                            app(\App\Services\LeaveRequestService::class)->reject(
                                $record, $step, Auth::user(), $data['reason']
                            );

                            Notification::make()->success()->title('تم رفض الطلب')->send();
                        } catch (LeaveRequestException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('return')
                    ->label('إعادة للتعديل')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn(LeaveRequest $record): bool =>
                        in_array($record->status, [LeaveStatus::SUBMITTED, LeaveStatus::IN_REVIEW])
                        && Auth::user()?->can('return', $record)
                    )
                    ->form([
                        Forms\Components\Textarea::make('note')
                            ->label('الملاحظة')
                            ->required(),
                    ])
                    ->action(function (LeaveRequest $record, array $data): void {
                        try {
                            $step = $record->steps()
                                ->where('status', StepStatus::PENDING->value)
                                ->where('stage', $record->current_stage)
                                ->first();

                            if (! $step) {
                                Notification::make()->danger()->title('لا توجد مرحلة نشطة للإعادة.')->send();
                                return;
                            }

                            app(\App\Services\LeaveRequestService::class)->returnRequest(
                                $record, $step, Auth::user(), $data['note']
                            );

                            Notification::make()->success()->title('تمت إعادة الطلب للتعديل')->send();
                        } catch (LeaveRequestException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label('حذف')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد حذف الطلب')
                    ->modalDescription('سيتم تعليم الطلب كـ «تم حذفه».')
                    ->visible(fn(LeaveRequest $record): bool =>
                        ! $record->trashed()
                        && $record->status->canBeCancelled()
                        && Auth::user()?->can('cancel', $record)
                    )
                    ->action(function (LeaveRequest $record): void {
                        try {
                            app(\App\Services\LeaveRequestService::class)->cancel($record, Auth::user());
                            Notification::make()->success()->title('تم حذفه')->send();
                        } catch (LeaveRequestException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('export')
                    ->label('تصدير')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records): \Symfony\Component\HttpFoundation\BinaryFileResponse {
                        $ids = $records->pluck('id')->toArray();
                        return \Maatwebsite\Excel\Facades\Excel::download(
                            new \App\Exports\LeaveRequestsExport(['ids' => $ids]),
                            'leave-requests.xlsx'
                        );
                    }),

                Tables\Actions\RestoreBulkAction::make()
                    ->label('استعادة المحدد من الحذف')
                    ->color('success')
                    ->modalHeading('تأكيد استعادة طلبات الإجازة المحددة')
                    ->modalDescription('سيتم استعادة الطلبات المحددة وإعادتها لحالة «مسودة».')
                    ->modalSubmitActionLabel('نعم، استعادة')
                    ->modalCancelActionLabel('إلغاء')
                    ->visible(fn (): bool =>
                        ! (Auth::user()?->organization?->isSchool() ?? false) &&
                        (bool) (
                            Auth::user()?->hasRole(['مدير الإدارة', 'مدير المديرية', 'مدير عام'])
                            || Auth::user()?->hasPermissionTo('manage_organization')
                            || Auth::user()?->hasPermissionTo('delete_leave_request')
                        )
                    )
                    ->after(function (\Illuminate\Database\Eloquent\Collection $records): void {
                        $service = app(\App\Services\LeaveRequestService::class);
                        $user = Auth::user();
                        foreach ($records as $req) {
                            $service->restore($req, $user);
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Employees visible to the current user (own org subtree).
     *
     * @return array<int|string, string>
     */
    public static function employeeOptions(?int $excludeId = null): array
    {
        $user = Auth::user();

        if (! $user?->organization_id) {
            return [];
        }

        $orgIds = $user->organization?->subtreeIds() ?? [$user->organization_id];

        // withoutGlobalScopes() also removes SoftDeletingScope — filter deleted rows explicitly.
        return Employee::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->active()
            ->whereIn('organization_id', $orgIds)
            ->when($excludeId, fn (Builder $q) => $q->where('id', '!=', $excludeId))
            ->orderBy('full_name')
            ->pluck('full_name', 'id')
            ->all();
    }

    /**
     * Employees with their available carried-over balance.
     *
     * @return array<int|string, string>
     */
    public static function carriedEmployeeOptions(?int $excludeId = null): array
    {
        $user = Auth::user();

        if (! $user?->organization_id) {
            return [];
        }

        $orgIds = $user->organization?->subtreeIds() ?? [$user->organization_id];
        $regularType = LeaveType::where('code', 'regular')->first();
        $fiscalYear = app(\App\Services\FiscalYearService::class)->currentFiscalYear();

        $employees = Employee::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->active()
            ->whereIn('organization_id', $orgIds)
            ->when($excludeId, fn (Builder $q) => $q->where('id', '!=', $excludeId))
            ->orderBy('full_name')
            ->get();

        $options = [];
        foreach ($employees as $emp) {
            $bal = $regularType ? $emp->balanceFor($regularType->id, $fiscalYear) : null;
            $remCarried = $bal ? (int) $bal->remaining_carried : 0;
            $options[$emp->id] = "{$emp->full_name} — (الرصيد المُرحَّل المتاح: {$remCarried} يوم)";
        }

        return $options;
    }

    // -------------------------------------------------------------------------
    // Modify query for permission-aware scoping
    // -------------------------------------------------------------------------

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([
                \App\Models\Scopes\OrganizationScope::class,
                SoftDeletingScope::class,
            ])
            ->with(['employee', 'leaveType', 'organization', 'steps.actedBy']);

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $user->setOrganizationTeam();

        if ($user->organization?->isSchool()) {
            $query->withoutTrashed();
        }

        if ($user->hasPermissionTo('view_all_leave_requests')) {
            $org = $user->organization;

            if ($org) {
                return $query->whereIn('organization_id', $org->subtreeIds());
            }

            return $query;
        }

        // Scoped to own organization only
        return $query->where('organization_id', $user->organization_id);
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    public static function getPages(): array
    {
        return [
            'index'          => Pages\ListLeaveRequests::route('/'),
            'create'         => Pages\CreateLeaveRequest::route('/create'),
            'create-carried' => Pages\CreateCarriedLeaveRequest::route('/create-carried'),
            'edit'           => Pages\EditLeaveRequest::route('/{record}/edit'),
            'view'           => Pages\ViewLeaveRequest::route('/{record}'),
        ];
    }
}
