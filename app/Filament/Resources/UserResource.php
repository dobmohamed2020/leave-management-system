<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Organization;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

/**
 * مورد إدارة حسابات المستخدمين وربطهم بالمؤسسات والأدوار.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationGroup = 'المستخدمون';
    protected static ?string $navigationLabel = 'المستخدمون';
    protected static ?string $navigationIcon  = 'heroicon-o-user-circle';
    protected static ?string $modelLabel      = 'مستخدم';
    protected static ?string $pluralModelLabel = 'المستخدمون';

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        $user?->setOrganizationTeam();

        return (bool) $user?->can('viewAny', User::class);
    }

    // -------------------------------------------------------------------------
    // Form
    // -------------------------------------------------------------------------

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('الاسم')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('email')
                ->label('البريد الإلكتروني')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(User::class, 'email', ignoreRecord: true),

            Forms\Components\TextInput::make('password')
                ->label('كلمة المرور')
                ->password()
                ->dehydrateStateUsing(fn(?string $state) => filled($state) ? bcrypt($state) : null)
                ->dehydrated(fn(?string $state) => filled($state))
                ->nullable()
                ->helperText('اتركها فارغة عند التعديل للإبقاء على كلمة المرور الحالية.'),

            Forms\Components\Select::make('organization_id')
                ->label('المؤسسة')
                ->options(
                    Organization::withoutGlobalScopes()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray()
                )
                ->searchable()
                ->required()
                ->reactive(),

            Forms\Components\Toggle::make('is_active')
                ->label('نشط')
                ->default(true),

            Forms\Components\Toggle::make('must_change_password')
                ->label('يجب تغيير كلمة المرور عند الدخول')
                ->default(false)
                ->helperText('عند التفعيل يُطلب من المستخدم تغيير كلمة المرور قبل استخدام النظام.'),

            Forms\Components\Select::make('roles')
                ->label('الأدوار')
                ->multiple()
                ->options(function (Get $get): array {
                    $orgId = $get('organization_id');

                    if (! $orgId) {
                        return [];
                    }

                    // Spatie teams column is organization_id (not team_id).
                    $teamKey = config('permission.column_names.team_foreign_key', 'organization_id');

                    return Role::query()
                        ->where($teamKey, $orgId)
                        ->orderBy('name')
                        ->pluck('name', 'name')
                        ->toArray();
                })
                ->live()
                ->helperText('يجب اختيار المؤسسة أولاً لعرض أدوارها.'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Mutate form data before save
    // -------------------------------------------------------------------------

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // password is handled via dehydrated logic above
        return $data;
    }

    // -------------------------------------------------------------------------
    // Table
    // -------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('البريد الإلكتروني')
                    ->searchable(),

                Tables\Columns\TextColumn::make('organization.name')
                    ->label('المؤسسة')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),

                Tables\Columns\IconColumn::make('must_change_password')
                    ->label('تغيير كلمة المرور')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('roles_list')
                    ->label('الأدوار')
                    ->getStateUsing(function (User $record): string {
                        $record->setOrganizationTeam();

                        return $record->getRoleNames()->implode('، ') ?: '—';
                    }),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('الحالة'),

                Tables\Filters\SelectFilter::make('organization_id')
                    ->label('المؤسسة')
                    ->options(
                        Organization::withoutGlobalScopes()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray()
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('force_delete_user')
                    ->label('حذف نهائي شامل')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => "تأكيد الحذف النهائي الشامل للمستخدم: {$record->name}")
                    ->modalDescription('تحذير شديد: سيتم حذف المستخدم نهائياً وحذف ملف الموظف التابع له وجميع طلبات إجازاته وأرصدته من قاعدة البيانات بلا رجعة. هل أنت متأكد؟')
                    ->modalSubmitActionLabel('نعم، احذف نهائياً')
                    ->modalCancelActionLabel('إلغاء')
                    ->visible(fn (): bool =>
                        (bool) (auth()->user()?->hasRole(['مدير المديرية', 'مدير عام', 'مدير الإدارة'])
                            || auth()->user()?->hasPermissionTo('manage_organization'))
                    )
                    ->action(function (User $record): void {
                        $name = $record->name;
                        app(\App\Services\CascadeDeletionService::class)->deleteUserPermanently($record, auth()->user());
                        Notification::make()
                            ->success()
                            ->title("تم حذف المستخدم [{$name}] نهائياً مع كافة متعلقاته")
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('name');
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
