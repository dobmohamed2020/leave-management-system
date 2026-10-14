<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->label('تعديل'),

            Actions\RestoreAction::make()
                ->label('استعادة من الحذف')
                ->visible(fn (): bool =>
                    $this->getRecord()->trashed() &&
                    ! EmployeeResource::isSchoolActor() &&
                    (bool) (
                        auth()->user()?->hasRole(['مدير الإدارة', 'مدير المديرية', 'مدير عام'])
                        || auth()->user()?->hasPermissionTo('manage_organization')
                        || (auth()->user()?->can('restore', $this->getRecord()) ?? false)
                    )
                )
                ->after(function (): void {
                    $record = $this->getRecord();
                    $record->update(['is_active' => true]);

                    \App\Models\DeletionRequest::where('model_type', 'employee')
                        ->where('model_id', $record->id)
                        ->where('status', 'approved')
                        ->update([
                            'status'      => 'restored',
                            'admin_notes' => 'تمت استعادة الموظف من الحذف العادي',
                        ]);
                }),

            Actions\Action::make('print_report')
                ->label('طباعة التقرير الشامل')
                ->icon('heroicon-o-printer')
                ->color('success')
                ->url(fn (): string => route('employee.report.print', ['id' => $this->record->id]))
                ->openUrlInNewTab(true),

            Actions\Action::make('promotions')
                ->label('سجل الترقيات')
                ->icon('heroicon-o-academic-cap')
                ->color('primary')
                ->modalHeading(fn (): string => "سجل الترقيات والدرجات — {$this->record->full_name}")
                ->modalWidth('4xl')
                ->modalContent(fn () => view('filament.modals.promotions-modal', ['employee' => $this->record]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('إغلاق'),

            Actions\Action::make('leave_history')
                ->label('التاريخ الكامل')
                ->icon('heroicon-o-calendar-days')
                ->color('info')
                ->url(fn (): string => EmployeeResource::getUrl('leave-history', ['record' => $this->record->id])),
        ];
    }
}
