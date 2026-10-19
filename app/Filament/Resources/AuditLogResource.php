<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Support\SystemAudit;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class AuditLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationGroup = 'النظام';

    protected static ?string $navigationLabel = 'سجل التدقيق الكامل';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $modelLabel = 'سجل';

    protected static ?string $pluralModelLabel = 'سجل التدقيق';

    protected static ?int $navigationSort = 99;

    public static function canViewAny(): bool
    {
        return SystemAudit::canViewAuditLog(auth()->user());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('التاريخ والوقت')
                    ->dateTime('Y/m/d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('causer.name')
                    ->label('المستخدم')
                    ->default('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('الإجراء')
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('log_name')
                    ->label('نوع السجل')
                    ->badge(),

                Tables\Columns\TextColumn::make('channel')
                    ->label('القناة')
                    ->state(fn (Activity $record): string => (string) ($record->properties['channel'] ?? '—')),

                Tables\Columns\TextColumn::make('ip')
                    ->label('IP')
                    ->state(fn (Activity $record): string => (string) ($record->properties['ip'] ?? '—'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('subject_label')
                    ->label('السجل المرتبط')
                    ->state(function (Activity $record): string {
                        if (! $record->subject_type) {
                            return '—';
                        }

                        return class_basename($record->subject_type).' #'.$record->subject_id;
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('log_name')
                    ->label('نوع السجل')
                    ->options([
                        'default' => 'تغييرات النماذج',
                        'audit' => 'تدقيق النظام',
                    ]),

                Tables\Filters\Filter::make('channel')
                    ->label('القناة')
                    ->form([
                        Select::make('value')
                            ->label('القناة')
                            ->options([
                                'web' => 'ويب',
                                'api' => 'API',
                                'auth' => 'مصادقة',
                                'workflow' => 'سير العمل',
                            ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        return $query->where('properties->channel', $data['value']);
                    }),

                Tables\Filters\Filter::make('created_at')
                    ->label('التاريخ')
                    ->form([
                        DatePicker::make('from')->label('من'),
                        DatePicker::make('until')->label('إلى'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
