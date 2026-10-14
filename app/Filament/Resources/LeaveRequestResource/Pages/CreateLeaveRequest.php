<?php

namespace App\Filament\Resources\LeaveRequestResource\Pages;

use App\Filament\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Services\LeaveRequestService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * صفحة إنشاء طلب إجازة جديد.
 * تستخدم LeaveRequestService::create() لتطبيق كل قواعد التحقق قبل الحفظ.
 */
class CreateLeaveRequest extends CreateRecord
{
    protected static string $resource = LeaveRequestResource::class;

    protected static ?string $title = 'إضافة طلب إجازة';
    protected static ?string $breadcrumb = 'طلب إجازة';

    /**
     * تجاوز عملية الإنشاء الافتراضية للاستفادة من LeaveRequestService.
     */
    protected function handleRecordCreation(array $data): LeaveRequest
    {
        $data['deduct_from_carried'] = false;

        try {
            $result = app(LeaveRequestService::class)->create($data, Auth::user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    Notification::make()
                        ->danger()
                        ->title($message)
                        ->persistent()
                        ->send();
                }
            }

            throw $e;
        }

        if (! empty($result->warnings)) {
            foreach ($result->warnings as $warning) {
                Notification::make()
                    ->warning()
                    ->title($warning)
                    ->persistent()
                    ->send();
            }
        }

        return $result->request;
    }
}
