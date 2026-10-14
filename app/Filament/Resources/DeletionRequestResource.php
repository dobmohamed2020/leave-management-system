<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeletionRequestResource\Pages;
use App\Models\DeletionRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\CascadeDeletionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class DeletionRequestResource extends Resource
{
    protected static ?string $model = DeletionRequest::class;

    protected static ?string $navigationGroup = 'طلبات الإجازات';
    protected static ?string $navigationLabel = 'طلبات اعتماد الحذف';
    protected static ?string $navigationIcon  = 'heroicon-o-trash';
    protected static ?string $modelLabel      = 'طلب حذف';
    protected static ?string $pluralModelLabel = 'طلبات اعتماد الحذف';
    protected static ?int    $navigationSort  = 3;

    public static function canViewAny(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        $user->setOrganizationTeam();

        // Available to Admin Manager, Directorate roles, and School Principals/Officers
        return $user->hasRole(['مدير الإدارة', 'مدير المديرية', 'مدير مدرسة', 'موظف مدرسة'])
            || $user->hasPermissionTo('manage_organization')
            || $user->hasPermissionTo('approve_leave_request');
    }

    public static function getNavigationBadge(): ?string
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        $query = DeletionRequest::query()->where('status', 'pending');

        if ($user->organization?->isSchool()) {
            $query->where('organization_id', $user->organization_id);
        } elseif ($user->organization?->isAdministration() || $user->organization?->isDirectorate()) {
            $subtreeIds = $user->organization?->subtreeIds() ?? [$user->organization_id];
            $query->whereIn('organization_id', $subtreeIds);
        }

        $count = $query->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('موضوع الطلب')
                ->disabled(),

            Forms\Components\Textarea::make('reason')
                ->label('سبب طلب الحذف')
                ->disabled(),

            Forms\Components\Textarea::make('admin_notes')
                ->label('ملاحظات مدير الإدارة')
                ->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $user = Auth::user();
                if (! $user) {
                    return $query;
                }

                if ($user->organization?->isSchool()) {
                    return $query->where('organization_id', $user->organization_id);
                }

                if ($user->organization?->isAdministration() || $user->organization?->isDirectorate()) {
                    $subtreeIds = $user->organization?->subtreeIds() ?? [$user->organization_id];
                    return $query->whereIn('organization_id', $subtreeIds);
                }

                return $query;
            })
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الطلب')
                    ->dateTime('d/m/Y - h:i A')
                    ->sortable(),

                Tables\Columns\TextColumn::make('organization.name')
                    ->label('المدرسة الطالبة')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('الموضوع')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('model_type')
                    ->label('نوع العنصر')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'employee'      => 'موظف',
                        'leave_request' => 'طلب إجازة',
                        default         => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'employee'      => 'primary',
                        'leave_request' => 'warning',
                        default         => 'gray',
                    }),

                Tables\Columns\TextColumn::make('reason')
                    ->label('سبب طلب الحذف')
                    ->limit(50)
                    ->tooltip(fn (DeletionRequest $record) => $record->reason)
                    ->wrap(),

                Tables\Columns\TextColumn::make('requester.name')
                    ->label('مقدم الطلب')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'pending'  => 'بانتظار الاعتماد',
                        'approved' => 'معتمد ومحذوف',
                        'rejected' => 'مرفوض',
                        'restored' => 'تمت الاستعادة من الحذف',
                        default    => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'pending'  => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'restored' => 'info',
                        default    => 'gray',
                    }),

                Tables\Columns\TextColumn::make('admin_notes')
                    ->label('ملاحظات الإدارة')
                    ->limit(40)
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'pending'  => 'بانتظار الاعتماد',
                        'approved' => 'معتمد ومحذوف',
                        'rejected' => 'مرفوض',
                        'restored' => 'تمت الاستعادة من الحذف',
                    ]),
                Tables\Filters\SelectFilter::make('model_type')
                    ->label('نوع العنصر')
                    ->options([
                        'employee'      => 'موظف',
                        'leave_request' => 'طلب إجازة',
                    ]),
            ])
            ->actions([
                // معاينة تفاصيل الطلب
                Tables\Actions\Action::make('view_details')
                    ->label('معاينة')
                    ->icon('heroicon-o-eye')
                    ->color('info')
                    ->modalHeading(fn (DeletionRequest $record) => $record->title)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق')
                    ->modalContent(function (DeletionRequest $record) {
                        return view('filament.modals.deletion-request-details', ['request' => $record]);
                    }),

                // اعتماد الحذف (خاص بمدير الإدارة والآدمن)
                Tables\Actions\Action::make('approve_deletion')
                    ->label('اعتماد الحذف')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DeletionRequest $record): bool =>
                        $record->isPending() &&
                        (bool) (Auth::user()?->hasRole(['مدير الإدارة', 'مدير المديرية'])
                            || Auth::user()?->hasPermissionTo('manage_organization'))
                    )
                    ->form([
                        Forms\Components\Select::make('action_type')
                            ->label('نوع الحذف المراد تنفيذه')
                            ->options([
                                'soft_delete'  => 'حذف عادي (Soft Delete)',
                                'force_delete' => 'حذف نهائي شامل لكافة المتعلقات (Cascade Hard Delete)',
                            ])
                            ->default('soft_delete')
                            ->required(),

                        Forms\Components\Textarea::make('admin_notes')
                            ->label('ملاحظات قرار الاعتماد')
                            ->nullable()
                            ->placeholder('اكتب أي ملاحظات موجهة للمدرسة...'),
                    ])
                    ->action(function (DeletionRequest $record, array $data): void {
                        $target = $record->getTargetRecord();
                        $actionType = $data['action_type'] ?? 'soft_delete';
                        $cascade = app(CascadeDeletionService::class);
                        $admin = Auth::user();

                        if ($target) {
                            if ($record->model_type === 'employee') {
                                /** @var Employee $target */
                                if ($actionType === 'force_delete') {
                                    $cascade->deleteEmployeePermanently($target, $admin);
                                } else {
                                    $target->delete();
                                }
                            } elseif ($record->model_type === 'leave_request') {
                                /** @var LeaveRequest $target */
                                if ($actionType === 'force_delete') {
                                    $cascade->deleteLeaveRequestPermanently($target, $admin);
                                } else {
                                    $target->forceFill([
                                        'status' => \App\Enums\LeaveStatus::CANCELLED,
                                        'current_stage' => null,
                                    ])->save();
                                    $target->delete();
                                }
                            }
                        }

                        $record->update([
                            'status'      => 'approved',
                            'action_type' => $actionType,
                            'admin_notes' => $data['admin_notes'] ?? null,
                            'reviewed_by' => $admin->id,
                            'reviewed_at' => now(),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('تم اعتماد وتنفيذ طلب الحذف بنجاح')
                            ->send();
                    }),

                // رفض طلب الحذف (خاص بمدير الإدارة والآدمن)
                Tables\Actions\Action::make('reject_deletion')
                    ->label('رفض الحذف')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (DeletionRequest $record): bool =>
                        $record->isPending() &&
                        (bool) (Auth::user()?->hasRole(['مدير الإدارة', 'مدير المديرية'])
                            || Auth::user()?->hasPermissionTo('manage_organization'))
                    )
                    ->form([
                        Forms\Components\Textarea::make('admin_notes')
                            ->label('سبب رفض طلب الحذف')
                            ->required()
                            ->placeholder('اكتب سبب الرفض لتوجيهه للمدرسة...'),
                    ])
                    ->action(function (DeletionRequest $record, array $data): void {
                        $record->update([
                            'status'      => 'rejected',
                            'admin_notes' => $data['admin_notes'],
                            'reviewed_by' => Auth::id(),
                            'reviewed_at' => now(),
                        ]);

                        Notification::make()
                            ->warning()
                            ->title('تم رفض طلب الحذف وإشعار المدرسة بالسبب')
                            ->send();
                    }),

                // استعادة من الحذف العادي (خاص بمدير الإدارة والآدمن)
                Tables\Actions\Action::make('restore_deletion')
                    ->label('استعادة من الحذف')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (DeletionRequest $record): string => "تأكيد استعادة العنصر من الحذف: {$record->title}")
                    ->modalDescription('سيتم إلغاء الحذف العادي واستعادة الموظف أو طلب الإجازة وإعادته للعمل كنشط في النظام.')
                    ->modalSubmitActionLabel('نعم، استعادة العنصر')
                    ->modalCancelActionLabel('إلغاء')
                    ->visible(function (DeletionRequest $record): bool {
                        if ($record->status !== 'approved' || $record->action_type !== 'soft_delete') {
                            return false;
                        }

                        $user = Auth::user();
                        $hasRole = (bool) (
                            $user?->hasRole(['مدير الإدارة', 'مدير المديرية'])
                            || $user?->hasPermissionTo('manage_organization')
                        );

                        if (! $hasRole) {
                            return false;
                        }

                        $target = $record->getTargetRecord();
                        return $target && method_exists($target, 'trashed') && $target->trashed();
                    })
                    ->form([
                        Forms\Components\Textarea::make('restore_notes')
                            ->label('سبب أو ملاحظات الاستعادة')
                            ->placeholder('اكتب سبب استعادة العنصر من الحذف...')
                            ->nullable(),
                    ])
                    ->action(function (DeletionRequest $record, array $data): void {
                        $target = $record->getTargetRecord();
                        $admin = Auth::user();

                        if ($target && method_exists($target, 'restore')) {
                            if ($record->model_type === 'employee') {
                                /** @var Employee $target */
                                $target->restore();
                                if (method_exists($target, 'update')) {
                                    $target->update(['is_active' => true]);
                                }
                            } elseif ($record->model_type === 'leave_request') {
                                /** @var LeaveRequest $target */
                                app(\App\Services\LeaveRequestService::class)->restore($target, $admin);
                            }
                        }

                        $note = ! empty($data['restore_notes'])
                            ? $data['restore_notes']
                            : 'تمت استعادة السجل من الحذف العادي بواسطة مدير الإدارة';

                        $record->update([
                            'status'      => 'restored',
                            'admin_notes' => $note,
                            'reviewed_by' => $admin?->id,
                            'reviewed_at' => now(),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('تمت استعادة العنصر من الحذف العادي بنجاح وإعادته للعمل')
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeletionRequests::route('/'),
        ];
    }
}
