<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LeaveTypeResource\Pages;
use App\Models\LeaveType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/**
 * مورد إدارة أنواع الإجازات.
 * محمي بصلاحية manage_organization بدلاً من Policy مستقلة.
 */
class LeaveTypeResource extends Resource
{
    protected static ?string $model = LeaveType::class;

    protected static ?string $navigationGroup = 'الإعدادات';
    protected static ?string $navigationLabel = 'أنواع الإجازات';
    protected static ?string $navigationIcon  = 'heroicon-o-document-text';
    protected static ?string $modelLabel      = 'نوع إجازة';
    protected static ?string $pluralModelLabel = 'أنواع الإجازات';

    // -------------------------------------------------------------------------
    // Gate check
    // -------------------------------------------------------------------------

    public static function canViewAny(): bool
    {
        auth()->user()?->setOrganizationTeam();

        return Gate::allows('manage_organization');
    }

    public static function canCreate(): bool
    {
        auth()->user()?->setOrganizationTeam();

        return Gate::allows('manage_organization');
    }

    public static function canEdit($record): bool
    {
        auth()->user()?->setOrganizationTeam();

        return Gate::allows('manage_organization');
    }

    public static function canDelete($record): bool
    {
        auth()->user()?->setOrganizationTeam();

        return Gate::allows('manage_organization');
    }

    // -------------------------------------------------------------------------
    // Form
    // -------------------------------------------------------------------------

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')
                ->label('الكود')
                ->required()
                ->unique(LeaveType::class, 'code', ignoreRecord: true)
                ->maxLength(50),

            Forms\Components\TextInput::make('name')
                ->label('الاسم')
                ->required()
                ->maxLength(255),

            Forms\Components\Toggle::make('deducts_balance')
                ->label('يُخصم من الرصيد')
                ->default(true),

            Forms\Components\TextInput::make('yearly_entitlement')
                ->label('الاستحقاق السنوي (أيام)')
                ->numeric()
                ->minValue(0)
                ->nullable(),

            Forms\Components\TextInput::make('max_days_per_request')
                ->label('الحد الأقصى للطلب الواحد (أيام)')
                ->integer()
                ->minValue(1)
                ->nullable(),

            Forms\Components\Toggle::make('is_active')
                ->label('نشط')
                ->default(true),
        ]);
    }

    // -------------------------------------------------------------------------
    // Table
    // -------------------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('الكود')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('deducts_balance')
                    ->label('يُخصم من الرصيد')
                    ->boolean(),

                Tables\Columns\TextColumn::make('yearly_entitlement')
                    ->label('الاستحقاق السنوي')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('max_days_per_request')
                    ->label('الحد الأقصى / طلب')
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index'  => Pages\ListLeaveTypes::route('/'),
            'create' => Pages\CreateLeaveType::route('/create'),
            'edit'   => Pages\EditLeaveType::route('/{record}/edit'),
        ];
    }
}
