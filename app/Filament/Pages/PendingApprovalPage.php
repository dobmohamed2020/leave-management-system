<?php

namespace App\Filament\Pages;

use App\Enums\LeaveStatus;
use App\Enums\StepStatus;
use App\Exceptions\LeaveRequestException;
use App\Filament\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PendingApprovalPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'طلبات الإجازات';
    protected static ?string $navigationLabel = 'طلبات الاعتماد';
    protected static ?string $navigationIcon  = 'heroicon-o-hand-raised';
    protected static ?int    $navigationSort  = 2;
    protected static string  $view = 'filament.pages.pending-approval-page';

    public static function canAccess(): bool
    {
        $user = Auth::user();
        $user?->setOrganizationTeam();

        return (bool) $user?->hasPermissionTo('approve_leave_request');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public static function getNavigationBadge(): ?string
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }
        $user->setOrganizationTeam();

        $count = static::buildPendingQuery($user)->count();

        return $count > 0 ? (string) $count : null;
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /**
     * Build the pending-approval query for the given user.
     * Called both from getTableQuery() and getNavigationBadge().
     */
    public static function buildPendingQuery(\App\Models\User $user): Builder
    {
        $user->setOrganizationTeam();

        $schoolOrgId = null;
        $adminStages = [];
        $adminOrgIds = [];

        // School principal: sees requests for their own school
        if (($user->organization?->isSchool() ?? false) && $user->hasRole('مدير مدرسة')) {
            $schoolOrgId = $user->organization_id;
        }

        // Administration / Directorate roles: see requests across subtree
        if ($user->organization?->isAdministration() || $user->organization?->isDirectorate()) {
            if ($user->hasRole('مسؤول الإجازات')) {
                $adminStages[] = 'leaves_officer';
            }
            if ($user->hasRole('شؤون عاملين')) {
                $adminStages[] = 'hr_affairs';
            }
            if ($user->hasRole('مدير الإدارة')) {
                $adminStages[] = 'admin_manager';
            }
            if (! empty($adminStages)) {
                $adminOrgIds = $user->organization?->subtreeIds() ?? [$user->organization_id];
            }
        }

        // Fix #4: oldest first (submitted_at asc) so approvers see longest-waiting requests first
        $base = LeaveRequest::withoutGlobalScopes()
            ->with(['employee', 'leaveType', 'steps', 'organization'])
            ->whereIn('status', [LeaveStatus::SUBMITTED->value, LeaveStatus::IN_REVIEW->value])
            ->oldest('submitted_at');

        // No qualifying stages — return empty result
        if (! $schoolOrgId && empty($adminStages)) {
            return $base->whereRaw('1 = 0');
        }

        return $base->where(function (Builder $q) use ($schoolOrgId, $adminStages, $adminOrgIds) {
            if ($schoolOrgId) {
                $q->orWhere(fn (Builder $s) => $s
                    ->where('current_stage', 'school_principal')
                    ->where('organization_id', $schoolOrgId));
            }
            if (! empty($adminStages)) {
                $q->orWhere(fn (Builder $s) => $s
                    ->whereIn('current_stage', $adminStages)
                    ->whereIn('organization_id', $adminOrgIds));
            }
        });
    }

    protected function getTableQuery(): Builder
    {
        $user = Auth::user();

        return static::buildPendingQuery($user);
    }

    // -------------------------------------------------------------------------
    // Table
    // -------------------------------------------------------------------------

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->getTableQuery())
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('الرقم')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('employee.full_name')
                    ->label('الموظف')
                    ->searchable()
                    ->sortable(),

                // Fix #2: add organization.name column
                Tables\Columns\TextColumn::make('organization.name')
                    ->label('الجهة'),

                Tables\Columns\TextColumn::make('leaveType.name')
                    ->label('نوع الإجازة'),

                // Fix #6: date format d F Y
                Tables\Columns\TextColumn::make('start_date')
                    ->label('من')
                    ->date('d F Y'),

                Tables\Columns\TextColumn::make('end_date')
                    ->label('إلى')
                    ->date('d F Y'),

                Tables\Columns\TextColumn::make('days')
                    ->label('الأيام'),

                // Fix #3: add status badge column
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->formatStateUsing(fn (LeaveRequest $record): string => $record->displayStatusLabel())
                    ->color(fn (LeaveRequest $record): string => $record->displayStatusColor())
                    ->badge(),

                // Fix #1: use pendingApprovalLabel instead of stageLabel
                Tables\Columns\TextColumn::make('current_stage')
                    ->label('المرحلة')
                    ->formatStateUsing(fn (?string $state) => LeaveRequest::pendingApprovalLabel($state))
                    ->badge(),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('تاريخ التقديم')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->recordUrl(fn (LeaveRequest $record): string => LeaveRequestResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('لا توجد طلبات بانتظار اعتمادك')
            ->emptyStateDescription('لا توجد طلبات في انتظار اعتمادك حالياً')
            ->actions([
                // --- عرض ---
                Tables\Actions\ViewAction::make()
                    ->label('عرض')
                    ->url(fn (LeaveRequest $record): string => LeaveRequestResource::getUrl('view', ['record' => $record])),

                // --- اعتماد ---
                Tables\Actions\Action::make('approve')
                    ->label('اعتماد')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (LeaveRequest $record): bool =>
                        in_array($record->status, [LeaveStatus::SUBMITTED, LeaveStatus::IN_REVIEW])
                        && (bool) Auth::user()?->can('approve', $record)
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

                            Notification::make()->success()->title('تم الاعتماد')->send();
                        } catch (LeaveRequestException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                // --- رفض ---
                Tables\Actions\Action::make('reject')
                    ->label('رفض')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (LeaveRequest $record): bool =>
                        in_array($record->status, [LeaveStatus::SUBMITTED, LeaveStatus::IN_REVIEW])
                        && (bool) Auth::user()?->can('reject', $record)
                    )
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('سبب الرفض')
                            ->required()
                            ->minLength(5),
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

                            Notification::make()->success()->title('تم الرفض')->send();
                        } catch (LeaveRequestException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                // --- إعادة للتعديل (Fix #7: updated label) ---
                Tables\Actions\Action::make('return')
                    ->label('إعادة للتعديل')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (LeaveRequest $record): bool =>
                        in_array($record->status, [LeaveStatus::SUBMITTED, LeaveStatus::IN_REVIEW])
                        && (bool) Auth::user()?->can('return', $record)
                    )
                    ->form([
                        Forms\Components\Textarea::make('note')
                            ->label('ملاحظة الإعادة')
                            ->required()
                            ->minLength(5),
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

                            Notification::make()->success()->title('تمت الإعادة')->send();
                        } catch (LeaveRequestException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }
}
