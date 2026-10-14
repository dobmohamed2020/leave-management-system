<?php

namespace App\Filament\Resources\LeaveRequestResource\Pages;

use App\Enums\LeaveStatus;
use App\Enums\StepStatus;
use App\Exceptions\LeaveRequestException;
use App\Filament\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * صفحة عرض تفاصيل طلب الإجازة مع إجراءات مسار الاعتماد.
 */
class ViewLeaveRequest extends ViewRecord
{
    protected static string $resource = LeaveRequestResource::class;

    // -------------------------------------------------------------------------
    // Header Actions
    // -------------------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            // Edit
            \Filament\Actions\EditAction::make()
                ->visible(fn (): bool => Auth::user()?->can('update', $this->getRecord()) ?? false),

            \Filament\Actions\DeleteAction::make()
                ->label('حذف')
                ->requiresConfirmation()
                ->modalHeading('تأكيد حذف طلب الإجازة')
                ->modalDescription('سيتم تعليم الطلب كـ «تم حذفه».')
                ->modalSubmitActionLabel('نعم، احذف')
                ->modalCancelActionLabel('إلغاء')
                ->successRedirectUrl(LeaveRequestResource::getUrl('index'))
                ->visible(fn (): bool => Auth::user()?->can('delete', $this->getRecord()) ?? false)
                ->before(function (): void {
                    $record = $this->getRecord();
                    if ($record->status !== LeaveStatus::CANCELLED && $record->status->canBeCancelled()) {
                        try {
                            app(\App\Services\LeaveRequestService::class)->cancel($record, Auth::user());
                        } catch (\Throwable) {
                            $record->forceFill([
                                'status' => LeaveStatus::CANCELLED,
                                'current_stage' => null,
                            ])->save();
                        }
                    }
                }),

            \Filament\Actions\RestoreAction::make()
                ->label('استعادة من الحذف')
                ->visible(fn (): bool =>
                    $this->getRecord()->trashed() &&
                    ! (Auth::user()?->organization?->isSchool() ?? false) &&
                    (bool) (
                        Auth::user()?->hasRole(['مدير الإدارة', 'مدير المديرية', 'مدير عام'])
                        || Auth::user()?->hasPermissionTo('manage_organization')
                        || (Auth::user()?->can('restore', $this->getRecord()) ?? false)
                    )
                )
                ->after(function (): void {
                    app(\App\Services\LeaveRequestService::class)->restore($this->getRecord(), Auth::user());
                }),

            // Submit
            Action::make('submit')
                ->label('تقديم الطلب')
                ->icon('heroicon-o-paper-airplane')
                ->color('info')
                ->requiresConfirmation()
                ->visible(fn(): bool =>
                    in_array($this->getRecord()->status, [LeaveStatus::DRAFT, LeaveStatus::RETURNED])
                    && Auth::user()?->can('submit', $this->getRecord())
                )
                ->action(function (): void {
                    $record = $this->getRecord();
                    try {
                        app(\App\Services\LeaveRequestService::class)->submit($record, Auth::user());
                        $this->refreshFormData([]);
                        Notification::make()->success()->title('تم تقديم الطلب بنجاح')->send();
                    } catch (LeaveRequestException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),

            // Approve
            Action::make('approve')
                ->label(fn (): string => $this->getRecord()->current_stage === 'school_principal'
                    ? 'اعتماد وتوقيع إلكتروني'
                    : 'اعتماد')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn(): bool =>
                    in_array($this->getRecord()->status, [LeaveStatus::SUBMITTED, LeaveStatus::IN_REVIEW])
                    && Auth::user()?->can('approve', $this->getRecord())
                )
                ->form([
                    Forms\Components\Textarea::make('note')
                        ->label('ملاحظة (اختيارية)')
                        ->nullable(),
                ])
                ->action(function (array $data): void {
                    $record = $this->getRecord();
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

                        $this->refreshFormData([]);
                        Notification::make()->success()->title('تمت الموافقة على الطلب')->send();
                    } catch (LeaveRequestException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),

            // Reject
            Action::make('reject')
                ->label('رفض')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn(): bool =>
                    in_array($this->getRecord()->status, [LeaveStatus::SUBMITTED, LeaveStatus::IN_REVIEW])
                    && Auth::user()?->can('reject', $this->getRecord())
                )
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('سبب الرفض')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $record = $this->getRecord();
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

                        $this->refreshFormData([]);
                        Notification::make()->success()->title('تم رفض الطلب')->send();
                    } catch (LeaveRequestException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),

            // Return for edits
            Action::make('return')
                ->label('إعادة للتعديل')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn(): bool =>
                    in_array($this->getRecord()->status, [LeaveStatus::SUBMITTED, LeaveStatus::IN_REVIEW])
                    && Auth::user()?->can('return', $this->getRecord())
                )
                ->form([
                    Forms\Components\Textarea::make('note')
                        ->label('الملاحظة')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $record = $this->getRecord();
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

                        $this->refreshFormData([]);
                        Notification::make()->success()->title('تمت إعادة الطلب للتعديل')->send();
                    } catch (LeaveRequestException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),

            // Cancel
            Action::make('cancel')
                ->label('حذف الطلب')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('تأكيد حذف الطلب')
                ->modalDescription('سيتم تعليم الطلب كـ «تم حذفه».')
                ->visible(fn(): bool =>
                    ! $this->getRecord()->trashed()
                    && $this->getRecord()->status->canBeCancelled()
                    && Auth::user()?->can('cancel', $this->getRecord())
                )
                ->action(function (): void {
                    $record = $this->getRecord();
                    try {
                        app(\App\Services\LeaveRequestService::class)->cancel($record, Auth::user());
                        $this->refreshFormData([]);
                        Notification::make()->success()->title('تم حذفه')->send();
                    } catch (LeaveRequestException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),

            // Print (opens printable Arabic HTML page)
            Action::make('print')
                ->label('طباعة')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn(): string => route('leave.pdf.print', [
                    'number' => $this->getRecord()->number,
                    'autoprint' => 1,
                ]))
                ->openUrlInNewTab()
                ->visible(fn(): bool => $this->getRecord()->status === LeaveStatus::APPROVED),

            // Download PDF
            Action::make('downloadPdf')
                ->label('تحميل PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(fn(): string => route('leave.pdf.download', ['number' => $this->getRecord()->number]))
                ->openUrlInNewTab()
                ->visible(fn(): bool => $this->getRecord()->status === LeaveStatus::APPROVED),
        ];
    }
}
