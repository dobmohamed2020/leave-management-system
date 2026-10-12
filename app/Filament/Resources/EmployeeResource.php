<?php

namespace App\Filament\Resources;

use App\Enums\LeaveStatus;
use App\Enums\OrganizationType;
use App\Filament\Resources\EmployeeResource\Pages;
use App\Models\Employee;
use App\Models\EntitlementGrade;
use App\Models\Organization;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Unique;

/**
 * مورد إدارة بيانات الموظفين مع عرض رصيد الإجازات.
 */
class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static ?string $navigationGroup = 'الموظفون';
    protected static ?string $navigationLabel = 'الموظفون';
    protected static ?string $navigationIcon  = 'heroicon-o-identification';
    protected static ?string $modelLabel      = 'موظف';
    protected static ?string $pluralModelLabel = 'الموظفون';

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        $user?->setOrganizationTeam();

        return (bool) $user?->can('viewAny', Employee::class);
    }

    public static function isSchoolActor(): bool
    {
        return auth()->user()?->organization?->isSchool() ?? false;
    }

    // -------------------------------------------------------------------------
    // Form
    // -------------------------------------------------------------------------

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('employee_code')
                ->label('كود الموظف')
                ->required()
                ->maxLength(50)
                ->extraInputAttributes(['dir' => 'ltr', 'style' => 'unicode-bidi: plaintext;'])
                ->dehydrateStateUsing(fn (?string $state): ?string => $state === null
                    ? null
                    : trim((string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{200B}-\x{200D}\x{FEFF}]/u', '', $state)))
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule) => $rule->whereNull('deleted_at'),
                )
                ->validationMessages([
                    'unique' => 'كود الموظف مستخدم بالفعل، اختر كوداً آخر.',
                    'required' => 'كود الموظف مطلوب.',
                ]),

            Forms\Components\TextInput::make('full_name')
                ->label('الاسم الكامل')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('job_title')
                ->label('المسمى الوظيفي')
                ->maxLength(255),

            Forms\Components\Select::make('approval_role')
                ->label('دور الاعتماد')
                ->options(function (callable $get): array {
                    $orgId = $get('organization_id') ?: auth()->user()?->organization_id;
                    if (! $orgId) return [];

                    $org = \App\Models\Organization::withoutGlobalScopes()->find($orgId);
                    if (! $org) return [];

                    return match(true) {
                        $org->type === \App\Enums\OrganizationType::SCHOOL => [
                            \App\Enums\ApprovalRole::SCHOOL_PRINCIPAL->value => \App\Enums\ApprovalRole::SCHOOL_PRINCIPAL->label(),
                        ],
                        $org->type === \App\Enums\OrganizationType::ADMINISTRATION => [
                            \App\Enums\ApprovalRole::LEAVES_OFFICER->value => \App\Enums\ApprovalRole::LEAVES_OFFICER->label(),
                            \App\Enums\ApprovalRole::HR_AFFAIRS->value     => \App\Enums\ApprovalRole::HR_AFFAIRS->label(),
                            \App\Enums\ApprovalRole::ADMIN_MANAGER->value  => \App\Enums\ApprovalRole::ADMIN_MANAGER->label(),
                        ],
                        default => [],
                    };
                })
                ->live()
                ->hidden(function (callable $get): bool {
                    $orgId = $get('organization_id') ?: auth()->user()?->organization_id;
                    if (! $orgId) return true;
                    $org = \App\Models\Organization::withoutGlobalScopes()->find($orgId);
                    return ! $org || ! in_array($org->type, [
                        \App\Enums\OrganizationType::SCHOOL,
                        \App\Enums\OrganizationType::ADMINISTRATION,
                    ]);
                })
                ->nullable()
                ->placeholder('اختر دور الاعتماد'),

            Forms\Components\Select::make('organization_id')
                ->label('المؤسسة')
                ->options(fn (): array => static::organizationOptions())
                ->searchable()
                ->preload()
                ->live()
                ->required(fn (): bool => ! static::isSchoolActor())
                ->visible(fn (): bool => ! static::isSchoolActor())
                ->default(fn () => auth()->user()?->organization_id),

            Forms\Components\Select::make('entitlement_grade')
                ->label('الدرجة الوظيفية')
                ->options(function (?Employee $record): array {
                    $options = EntitlementGrade::options(activeOnly: true);

                    if ($record?->entitlement_grade && ! isset($options[$record->entitlement_grade])) {
                        $current = EntitlementGrade::findByCode($record->entitlement_grade);
                        if ($current) {
                            $options[$current->code] = "{$current->name} — {$current->yearly_days} يوم (غير نشط)";
                        }
                    }

                    return $options;
                })
                ->searchable()
                ->helperText('تُدار الدرجات من الإعدادات ← الدرجات الوظيفية'),

            Forms\Components\TextInput::make('initial_balance_days')
                ->label('رصيد إجازات اعتيادية مُرحَّل (أيام)')
                ->helperText(fn (?Employee $record): string => $record === null
                    ? 'أدخل عدد الأيام التي يمتلكها الموظف من سنوات سابقة. سيُضاف إلى رصيد الترحيل للسنة الحالية.'
                    : 'اتركها فارغة إذا لم ترد تعديل الرصيد المُرحَّل. أي قيمة مُدخلة ستُضاف للرصيد الحالي.'
                )
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(365)
                ->nullable()
                ->default(null)
                ->dehydrated(false)
                ->placeholder('0'),

            // الإدارة: ربط بحساب موجود (بدل إنشاء جديد)
            Forms\Components\Select::make('user_id')
                ->label('ربط بحساب موجود')
                ->options(
                    User::orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray()
                )
                ->searchable()
                ->nullable()
                ->placeholder('— لا يوجد —')
                ->visible(fn (Get $get): bool => ! static::isSchoolActor() && ! (bool) $get('is_system_employee'))
                ->helperText('أو فعّل «إنشاء حساب دخول» بالأسفل بدل الربط.'),

            // مدرسة أو إدارة: إنشاء حساب دخول جديد مرتبط بالموظف
            Forms\Components\Section::make('حساب دخول للنظام')
                ->description(function (Get $get): string {
                    $approvalRole = $get('approval_role');
                    if ($approvalRole) {
                        $roleEnum = \App\Enums\ApprovalRole::tryFrom($approvalRole);
                        if ($roleEnum) {
                            return "فعّل الخيار إذا كان الموظف يحتاج الدخول للنظام. الدور الذي سيُمنح للحساب: {$roleEnum->label()}.";
                        }
                    }

                    $orgId = $get('organization_id') ?: auth()->user()?->organization_id;
                    if ($orgId) {
                        $org = \App\Models\Organization::withoutGlobalScopes()->find($orgId);
                        if ($org?->isAdministration()) {
                            return 'فعّل الخيار إذا كان الموظف يحتاج الدخول للنظام. الدور: موظف إدارة.';
                        }
                    }

                    return 'فعّل الخيار إذا كان الموظف يحتاج الدخول للنظام. الدور: موظف مدرسة.';
                })
                ->schema([
                    Forms\Components\Toggle::make('is_system_employee')
                        ->label('إنشاء حساب دخول')
                        ->live()
                        ->dehydrated(false)
                        ->default(fn (?Employee $record): bool => filled($record?->user_id))
                        ->disabled(fn (?Employee $record): bool => filled($record?->user_id)),

                    Forms\Components\Placeholder::make('linked_user_info')
                        ->label('الحساب المرتبط')
                        ->content(function (?Employee $record): string {
                            if (! $record?->user) {
                                return '—';
                            }

                            $parts = array_filter([
                                $record->user->email,
                                $record->user->phone ? 'تليفون: '.$record->user->phone : null,
                            ]);

                            return implode(' | ', $parts) ?: '—';
                        })
                        ->visible(fn (?Employee $record): bool => filled($record?->user_id)),

                    Forms\Components\TextInput::make('system_email')
                        ->label('البريد الإلكتروني')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(table: User::class, column: 'email')
                        ->visible(fn (Get $get, ?Employee $record): bool => (bool) $get('is_system_employee') && blank($record?->user_id))
                        ->dehydrated(false)
                        ->validationMessages([
                            'unique' => 'البريد الإلكتروني مستخدم بالفعل.',
                            'required' => 'البريد الإلكتروني مطلوب لحساب الدخول.',
                        ]),

                    Forms\Components\TextInput::make('system_phone')
                        ->label('رقم التليفون (للدخول)')
                        ->tel()
                        ->maxLength(20)
                        ->nullable()
                        ->default(fn (Get $get): ?string => $get('phone'))
                        ->dehydrateStateUsing(fn (?string $state): ?string => User::normalizePhone($state))
                        ->unique(table: User::class, column: 'phone')
                        ->visible(fn (Get $get, ?Employee $record): bool => (bool) $get('is_system_employee') && blank($record?->user_id))
                        ->dehydrated(false)
                        ->helperText('يمكن الدخول بهذا الرقم بدل البريد.')
                        ->validationMessages([
                            'unique' => 'رقم التليفون مستخدم بالفعل.',
                        ]),

                    Forms\Components\TextInput::make('system_password')
                        ->label('كلمة المرور')
                        ->password()
                        ->revealable()
                        ->required()
                        ->minLength(8)
                        ->visible(fn (Get $get, ?Employee $record): bool => (bool) $get('is_system_employee') && blank($record?->user_id))
                        ->dehydrated(false)
                        ->validationMessages([
                            'required' => 'كلمة المرور مطلوبة لحساب الدخول.',
                            'min' => 'كلمة المرور يجب ألا تقل عن 8 أحرف.',
                        ]),
                ])
                ->columns(1),

            Forms\Components\DatePicker::make('birth_date')
                ->label('تاريخ الميلاد')
                ->nullable(),

            Forms\Components\DatePicker::make('hire_date')
                ->label('تاريخ التعيين')
                ->nullable(),

            Forms\Components\DatePicker::make('work_start_date')
                ->label('تاريخ بداية العمل')
                ->nullable(),

            Forms\Components\TextInput::make('phone')
                ->label('رقم الهاتف')
                ->tel()
                ->maxLength(20)
                ->live(onBlur: true)
                ->dehydrateStateUsing(fn (?string $state): ?string => User::normalizePhone($state)),

            Forms\Components\Toggle::make('is_active')
                ->label('نشط')
                ->default(true),
        ]);
    }

    /**
     * Create a system login user for an employee and assign appropriate role.
     */
    public static function provisionSystemUser(
        Employee $employee,
        string $email,
        string $password,
        ?string $phone = null,
    ): User {
        $organizationId = (int) $employee->organization_id;
        $phone = User::normalizePhone($phone ?: $employee->phone);

        $user = User::create([
            'name'                 => $employee->full_name,
            'email'                => $email,
            'phone'                => $phone,
            'password'             => $password,
            'organization_id'      => $organizationId,
            'is_active'            => true,
            'must_change_password' => false,
        ]);

        setPermissionsTeamId($organizationId);

        $org = $employee->organization ?? Organization::withoutGlobalScopes()->find($organizationId);
        $isAdministration = $org?->isAdministration();
        $isDirectorate    = $org?->isDirectorate();

        $spatieRoleName = match($employee->approval_role) {
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

        if (! $role && $org) {
            \Database\Seeders\RoleAndPermissionSeeder::seedForOrganization($org);
            $role = \Spatie\Permission\Models\Role::query()
                ->where('name', $spatieRoleName)
                ->where('organization_id', $organizationId)
                ->first();
        }

        if ($role) {
            $user->assignRole($role);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId(null);

        $employee->update(['user_id' => $user->id]);

        return $user;
    }

    /**
     * @return array<int|string, string>
     */
    public static function organizationOptions(): array
    {
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        $query = Organization::withoutGlobalScopes()->orderBy('type')->orderBy('name');

        if ($user->organization?->isAdministration()) {
            $query->where(function ($q) use ($user) {
                $q->where('id', $user->organization_id)
                    ->orWhere('parent_id', $user->organization_id);
            });
        } elseif ($user->organization?->isDirectorate()) {
            $query->whereIn('id', $user->organization->subtreeIds());
        } elseif ($user->organization?->isSchool()) {
            $query->where('id', $user->organization_id);
        }

        return $query->get()->mapWithKeys(function (Organization $org) use ($user) {
            $label = $org->name;
            if ($org->id === $user->organization_id && $org->isAdministration()) {
                $label .= ' (الإدارة)';
            }
            return [$org->id => $label];
        })->toArray();
    }

    // -------------------------------------------------------------------------
    // Table
    // -------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee_code')
                    ->label('الكود')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('الاسم الكامل')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('job_title')
                    ->label('المسمى الوظيفي')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('entitlementGrade.name')
                    ->label('الدرجة')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('organization.name')
                    ->label('المؤسسة')
                    ->placeholder('—')
                    ->sortable()
                    ->visible(fn (): bool => ! static::isSchoolActor()),

                Tables\Columns\IconColumn::make('user_id')
                    ->label('حساب دخول')
                    ->boolean()
                    ->getStateUsing(fn (Employee $record): bool => filled($record->user_id)),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),

                Tables\Columns\TextColumn::make('approval_role')
                    ->label('دور الاعتماد')
                    ->formatStateUsing(fn($state) => $state?->label() ?? '—')
                    ->badge()
                    ->color(fn($state) => match($state) {
                        \App\Enums\ApprovalRole::SCHOOL_PRINCIPAL => 'success',
                        \App\Enums\ApprovalRole::ADMIN_MANAGER    => 'danger',
                        \App\Enums\ApprovalRole::LEAVES_OFFICER   => 'warning',
                        \App\Enums\ApprovalRole::HR_AFFAIRS       => 'info',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\ViewColumn::make('balance_badge')
                    ->label('رصيد الإجازات')
                    ->view('filament.columns.balance-badge'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('organization_id')
                    ->label('المؤسسة')
                    ->options(fn (): array => static::organizationOptions())
                    ->visible(fn (): bool => ! static::isSchoolActor()),

                Tables\Filters\SelectFilter::make('entitlement_grade')
                    ->label('الدرجة الوظيفية')
                    ->options(fn (): array => EntitlementGrade::query()
                        ->ordered()
                        ->pluck('name', 'code')
                        ->all()),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('الحالة'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('leave_history')
                    ->label('تاريخ الإجازات')
                    ->icon('heroicon-o-calendar-days')
                    ->color('info')
                    ->url(fn (Employee $record): string => EmployeeResource::getUrl('leave-history', ['record' => $record->id]))
                    ->openUrlInNewTab(false),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('renewBalances')
                    ->label('تجديد الأرصدة السنوية')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('تجديد الأرصدة السنوية')
                    ->modalDescription('سيتم تجديد أرصدة الإجازات للموظفين المحددين للسنة الحالية. هل أنت متأكد؟')
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                        $service = app(\App\Services\LeaveBalanceService::class);
                        $year = now()->year;
                        $count = 0;
                        foreach ($records as $employee) {
                            try {
                                DB::transaction(function () use ($service, $employee, $year) {
                                    $service->carryOver($employee, $year - 1, $year);
                                    $service->accrueAnnual($employee, $year);
                                });
                                $count++;
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::warning('Balance renewal failed', [
                                    'employee_id' => $employee->id,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                        Notification::make()
                            ->success()
                            ->title("تم تجديد أرصدة {$count} موظف بنجاح")
                            ->send();
                    }),
            ])
            ->defaultSort('full_name')
            ->recordUrl(fn (Employee $record): string => EmployeeResource::getUrl('view', ['record' => $record]));
    }

    // -------------------------------------------------------------------------
    // Infolist (View page)
    // -------------------------------------------------------------------------

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Tabs::make('employee_tabs')
                    ->tabs([

                        // =====================================================
                        // Tab 1: البيانات الأساسية
                        // =====================================================
                        Infolists\Components\Tabs\Tab::make('البيانات الأساسية')
                            ->icon('heroicon-o-user')
                            ->schema([
                                Infolists\Components\Section::make('بيانات الموظف')
                                    ->columns(3)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('full_name')
                                            ->label('الاسم الكامل')
                                            ->weight(\Filament\Support\Enums\FontWeight::Bold)
                                            ->size(\Filament\Infolists\Components\TextEntry\TextEntrySize::Large),

                                        Infolists\Components\TextEntry::make('employee_code')
                                            ->label('كود الموظف')
                                            ->copyable()
                                            ->placeholder('—'),

                                        Infolists\Components\IconEntry::make('is_active')
                                            ->label('الحالة')
                                            ->boolean()
                                            ->trueColor('success')
                                            ->falseColor('danger')
                                            ->trueIcon('heroicon-o-check-circle')
                                            ->falseIcon('heroicon-o-x-circle'),

                                        Infolists\Components\TextEntry::make('job_title')
                                            ->label('المسمى الوظيفي')
                                            ->placeholder('—'),

                                        Infolists\Components\TextEntry::make('grade')
                                            ->label('الدرجة (نصية)')
                                            ->placeholder('—'),

                                        Infolists\Components\TextEntry::make('entitlementGrade.name')
                                            ->label('درجة الاستحقاق')
                                            ->placeholder('لا يوجد')
                                            ->badge()
                                            ->color('primary'),

                                        Infolists\Components\TextEntry::make('entitlement_yearly_days')
                                            ->label('الإجازة الاعتيادية المستحقة')
                                            ->state(fn (Employee $record): string =>
                                                $record->regularLeaveEntitlement() . ' يوم / سنة'
                                            ),

                                        Infolists\Components\TextEntry::make('organization.name')
                                            ->label('الجهة التعليمية')
                                            ->placeholder('—')
                                            ->badge()
                                            ->color('gray'),

                                        Infolists\Components\TextEntry::make('phone')
                                            ->label('رقم الهاتف')
                                            ->placeholder('—')
                                            ->copyable(),
                                    ]),

                                Infolists\Components\Section::make('التواريخ')
                                    ->columns(3)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('birth_date')
                                            ->label('تاريخ الميلاد')
                                            ->date('d F Y')
                                            ->placeholder('—'),

                                        Infolists\Components\TextEntry::make('hire_date')
                                            ->label('تاريخ التعيين')
                                            ->date('d F Y')
                                            ->placeholder('—'),

                                        Infolists\Components\TextEntry::make('work_start_date')
                                            ->label('بداية العمل الفعلي')
                                            ->date('d F Y')
                                            ->placeholder('—'),

                                        Infolists\Components\TextEntry::make('years_of_service')
                                            ->label('سنوات الخدمة')
                                            ->state(fn (Employee $record): string => (function () use ($record) {
                                                $start = $record->work_start_date ?? $record->hire_date;
                                                if (! $start) return '—';
                                                return \Carbon\Carbon::parse($start)->diffInYears(now()) . ' سنة';
                                            })()),
                                    ]),
                            ]),

                        // =====================================================
                        // Tab 2: رصيد الإجازات
                        // =====================================================
                        Infolists\Components\Tabs\Tab::make('رصيد الإجازات')
                            ->icon('heroicon-o-calculator')
                            ->schema([
                                Infolists\Components\Section::make('رصيد السنة الحالية — ' . now()->year)
                                    ->schema([
                                        Infolists\Components\RepeatableEntry::make('currentYearBalances')
                                            ->label('')
                                            ->state(fn (Employee $record) =>
                                                $record->leaveBalances()
                                                    ->with('leaveType')
                                                    ->currentYear()
                                                    ->get()
                                                    ->map(fn ($b) => [
                                                        'leave_type' => $b->leaveType?->name ?? '—',
                                                        'entitled'   => (int) $b->entitled,
                                                        'carried'    => (int) $b->carried_over,
                                                        'used'       => (int) $b->used,
                                                        'remaining'  => max(0, (int) $b->entitled + (int) $b->carried_over - (int) $b->used),
                                                    ])
                                                    ->toArray()
                                            )
                                            ->columns(5)
                                            ->schema([
                                                Infolists\Components\TextEntry::make('leave_type')
                                                    ->label('نوع الإجازة')
                                                    ->weight(\Filament\Support\Enums\FontWeight::Bold),

                                                Infolists\Components\TextEntry::make('entitled')
                                                    ->label('المستحق')
                                                    ->suffix(' يوم')
                                                    ->color('primary'),

                                                Infolists\Components\TextEntry::make('carried')
                                                    ->label('المُرحَّل')
                                                    ->suffix(' يوم')
                                                    ->color('info'),

                                                Infolists\Components\TextEntry::make('used')
                                                    ->label('المستخدم')
                                                    ->suffix(' يوم')
                                                    ->color('warning'),

                                                Infolists\Components\TextEntry::make('remaining')
                                                    ->label('المتبقي')
                                                    ->suffix(' يوم')
                                                    ->color(fn ($state): string => ((int) $state) > 0 ? 'success' : 'danger')
                                                    ->weight(\Filament\Support\Enums\FontWeight::Bold),
                                            ]),
                                    ]),

                                Infolists\Components\Section::make('الإجازة الاعتيادية — ملخص الاستحقاق')
                                    ->columns(2)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('regular_entitlement')
                                            ->label('المستحق النظامي هذه السنة')
                                            ->state(fn (Employee $record): string =>
                                                $record->regularLeaveEntitlement() . ' يوم'
                                            )
                                            ->badge()
                                            ->color('success'),

                                        Infolists\Components\TextEntry::make('regular_balance_remaining')
                                            ->label('المتبقي من الإجازة الاعتيادية')
                                            ->state(function (Employee $record): string {
                                                $balance = $record->leaveBalances()
                                                    ->whereHas('leaveType', fn ($q) => $q->where('code', 'regular'))
                                                    ->currentYear()
                                                    ->first();
                                                if (! $balance) return 'لا يوجد رصيد مسجل';
                                                $rem = max(0, (int)$balance->entitled + (int)$balance->carried_over - (int)$balance->used);
                                                return $rem . ' يوم';
                                            })
                                            ->badge()
                                            ->color(fn (string $state): string =>
                                                str_contains($state, 'لا') ? 'gray' :
                                                ((int) $state > 0 ? 'success' : 'danger')
                                            ),
                                    ]),
                            ]),

                        // =====================================================
                        // Tab 3: طلبات الإجازة
                        // =====================================================
                        Infolists\Components\Tabs\Tab::make('طلبات الإجازة')
                            ->icon('heroicon-o-clipboard-document-list')
                            ->schema([
                                Infolists\Components\RepeatableEntry::make('leaveRequestsList')
                                    ->label('')
                                    ->state(fn (Employee $record) =>
                                        $record->leaveRequests()
                                            ->with('leaveType')
                                            ->latest()
                                            ->get()
                                            ->map(fn ($req) => [
                                                'number'     => $req->number,
                                                'leave_type' => $req->leaveType?->name ?? '—',
                                                'start_date' => $req->start_date?->format('d/m/Y') ?? '—',
                                                'end_date'   => $req->end_date?->format('d/m/Y') ?? '—',
                                                'days'       => (int) $req->days,
                                                'status_label' => $req->status->label(),
                                                'status_color' => $req->status->color(),
                                            ])
                                            ->toArray()
                                    )
                                    ->columns(6)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('number')
                                            ->label('رقم الطلب')
                                            ->fontFamily(\Filament\Support\Enums\FontFamily::Mono)
                                            ->size(\Filament\Infolists\Components\TextEntry\TextEntrySize::Small),

                                        Infolists\Components\TextEntry::make('leave_type')
                                            ->label('نوع الإجازة'),

                                        Infolists\Components\TextEntry::make('start_date')
                                            ->label('من'),

                                        Infolists\Components\TextEntry::make('end_date')
                                            ->label('إلى'),

                                        Infolists\Components\TextEntry::make('days')
                                            ->label('الأيام')
                                            ->suffix(' يوم'),

                                        Infolists\Components\TextEntry::make('status_label')
                                            ->label('الحالة')
                                            ->badge()
                                            ->color(fn ($state): string => match ($state) {
                                                'معتمد' => 'success',
                                                'مرفوض', 'تم حذفه' => 'danger',
                                                'قيد المراجعة', 'مُعاد للتعديل' => 'warning',
                                                'مقدّم' => 'info',
                                                default => 'gray',
                                            }),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    public static function getPages(): array
    {
        return [
            'index'         => Pages\ListEmployees::route('/'),
            'create'        => Pages\CreateEmployee::route('/create'),
            'view'          => Pages\ViewEmployee::route('/{record}'),
            'edit'          => Pages\EditEmployee::route('/{record}/edit'),
            'leave-history' => Pages\ViewEmployeeLeaveHistory::route('/{record}/leave-history'),
        ];
    }
}
