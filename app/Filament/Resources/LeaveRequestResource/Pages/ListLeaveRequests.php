<?php

namespace App\Filament\Resources\LeaveRequestResource\Pages;

use App\Filament\Resources\LeaveRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

/**
 * صفحة قائمة طلبات الإجازات.
 */
class ListLeaveRequests extends ListRecords
{
    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('طلب إجازة اعتيادية')
                ->icon('heroicon-o-document-plus'),

            Actions\Action::make('createCarried')
                ->label('طلب إجازة من رصيد مرحل')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('warning')
                ->url(fn (): string => CreateCarriedLeaveRequest::getUrl())
                ->visible(fn (): bool => auth()->user()?->can('create', \App\Models\LeaveRequest::class) ?? false),

            Actions\Action::make('exportAll')
                ->label('تصدير Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->url(route('leave.export'))
                ->openUrlInNewTab(),
        ];
    }
}
