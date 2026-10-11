<?php

namespace App\Filament\Resources;

use App\Enums\OrganizationType;
use App\Filament\Resources\OrganizationResource\Pages;
use App\Models\Organization;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * مورد إدارة المؤسسات (مديرية / إدارة / مدرسة).
 */
class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $navigationGroup = 'المدارس';
    protected static ?string $navigationIcon  = 'heroicon-o-building-office-2';
    protected static ?string $navigationLabel = 'المدارس';
    protected static ?string $modelLabel      = 'مؤسسة';
    protected static ?string $pluralModelLabel = 'المدارس';

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        $user?->setOrganizationTeam();

        return (bool) $user?->can('viewAny', Organization::class);
    }

    // -------------------------------------------------------------------------
    // Form
    // -------------------------------------------------------------------------

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('اسم المؤسسة')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('code')
                ->label('الكود')
                ->required()
                ->maxLength(30)
                ->unique(Organization::class, 'code', ignoreRecord: true)
                ->validationMessages([
                    'unique' => 'هذا الكود مستخدم بالفعل.',
                ]),

            Forms\Components\Select::make('type')
                ->label('النوع')
                ->options(
                    collect(OrganizationType::cases())
                        ->mapWithKeys(fn(OrganizationType $t) => [$t->value => $t->label()])
                        ->toArray()
                )
                ->default(OrganizationType::SCHOOL->value)
                ->reactive()
                ->required(),

            Forms\Components\Select::make('parent_id')
                ->label('الجهة الأم')
                ->options(function (Forms\Get $get, ?Organization $record) {
                    $type = $get('type');
                    $query = Organization::withoutGlobalScopes()->orderBy('name');

                    if ($record) {
                        $subtreeIds = $record->subtreeIds();
                        $query->whereNotIn('id', $subtreeIds);
                    }

                    if ($type === OrganizationType::SCHOOL->value) {
                        $query->whereIn('type', [
                            OrganizationType::ADMINISTRATION->value,
                            OrganizationType::DIRECTORATE->value,
                        ]);
                    } elseif ($type === OrganizationType::ADMINISTRATION->value) {
                        $query->where('type', OrganizationType::DIRECTORATE->value);
                    }

                    return $query->pluck('name', 'id')->toArray();
                })
                ->searchable()
                ->required(fn (Forms\Get $get) => in_array($get('type'), [
                    OrganizationType::SCHOOL->value,
                    OrganizationType::ADMINISTRATION->value,
                ]))
                ->visible(fn (Forms\Get $get) => $get('type') !== OrganizationType::DIRECTORATE->value)
                ->placeholder('— اختر الجهة الأم —')
                ->helperText(fn (Forms\Get $get) => match ($get('type')) {
                    OrganizationType::SCHOOL->value => 'اختر الإدارة التعليمية التابعة لها المدرسة.',
                    OrganizationType::ADMINISTRATION->value => 'اختر المديرية التابعة لها الإدارة.',
                    default => null,
                }),

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
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('code')
                    ->label('الكود')
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('النوع')
                    ->formatStateUsing(fn(OrganizationType $state) => $state->label())
                    ->sortable(),

                Tables\Columns\TextColumn::make('parent.name')
                    ->label('الجهة الأم')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('النوع')
                    ->options(
                        collect(OrganizationType::cases())
                            ->mapWithKeys(fn(OrganizationType $t) => [$t->value => $t->label()])
                            ->toArray()
                    ),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('الحالة'),
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
            'index'  => Pages\ListOrganizations::route('/'),
            'create' => Pages\CreateOrganization::route('/create'),
            'edit'   => Pages\EditOrganization::route('/{record}/edit'),
        ];
    }
}
