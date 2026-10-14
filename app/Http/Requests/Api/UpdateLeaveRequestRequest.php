<?php

namespace App\Http\Requests\Api;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'leave_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'written_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
            'substitute_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'deduct_from_carried' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $request = $this->resolveLeaveRequest();

            if (! $request) {
                $v->errors()->add('id', 'طلب الإجازة غير موجود.');

                return;
            }

            if (! $request->status->canBeEdited()) {
                $v->errors()->add('status',
                    "لا يمكن تعديل الطلب في حالته الحالية ({$request->status->label()})."
                );
            }

            $employeeId = $this->input('employee_id', $request->employee_id);
            $substituteId = $this->input('substitute_employee_id');
            if ($substituteId !== null && (int) $substituteId === (int) $employeeId) {
                $v->errors()->add(
                    'substitute_employee_id',
                    'لا يمكن أن يكون الموظف البديل هو نفس طالب الإجازة.'
                );
            }
        });
    }

    private function resolveLeaveRequest(): ?LeaveRequest
    {
        $bound = $this->route('leave_request');

        if ($bound instanceof LeaveRequest) {
            return $bound;
        }

        if (is_numeric($bound)) {
            return LeaveRequest::withoutGlobalScopes()->find((int) $bound);
        }

        return null;
    }

    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'تاريخ النهاية يجب أن يكون بعد أو مساوياً لتاريخ البداية.',
            'days.min' => 'الحد الأدنى لعدد الأيام هو يوم واحد.',
            'days.max' => 'الحد الأقصى لعدد الأيام هو 365 يوماً.',
            'days.integer' => 'عدد الأيام يجب أن يكون رقماً صحيحاً.',
            'reason.required' => 'سبب طلب الإجازة مطلوب.',
            'reason.min' => 'سبب طلب الإجازة يجب ألا يقل عن 5 أحرف.',
            'reason.max' => 'السبب يجب ألا يتجاوز 500 حرف.',
            'substitute_employee_id.required' => 'يجب تحديد الموظف البديل (القائم بالعمل أثناء الإجازة).',
            'substitute_employee_id.exists' => 'الموظف البديل المحدد غير موجود.',
        ];
    }
}
