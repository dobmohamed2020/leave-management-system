<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id'            => ['required', 'integer', 'exists:employees,id'],
            'leave_type_id'          => ['required', 'integer', 'exists:leave_types,id'],
            'start_date'             => ['required', 'date', 'after_or_equal:today'],
            'end_date'               => ['required', 'date', 'after_or_equal:start_date'],
            'days'                   => ['required', 'integer', 'min:1', 'max:365'],
            'written_at'             => ['nullable', 'date'],
            'reason'                 => ['nullable', 'string', 'max:500'],
            'substitute_employee_id' => ['nullable', 'integer', 'exists:employees,id', 'different:employee_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.required'            => 'الموظف مطلوب.',
            'employee_id.exists'              => 'الموظف المحدد غير موجود.',
            'leave_type_id.required'          => 'نوع الإجازة مطلوب.',
            'leave_type_id.exists'            => 'نوع الإجازة المحدد غير موجود.',
            'start_date.required'             => 'تاريخ البداية مطلوب.',
            'start_date.date'                 => 'تاريخ البداية غير صحيح.',
            'start_date.after_or_equal'       => 'تاريخ البداية يجب ألا يكون في الماضي.',
            'end_date.required'               => 'تاريخ النهاية مطلوب.',
            'end_date.date'                   => 'تاريخ النهاية غير صحيح.',
            'end_date.after_or_equal'         => 'تاريخ النهاية يجب أن يكون بعد أو مساوياً لتاريخ البداية.',
            'days.required'                   => 'عدد الأيام مطلوب.',
            'days.integer'                    => 'عدد الأيام يجب أن يكون رقماً صحيحاً.',
            'days.min'                        => 'الحد الأدنى لعدد الأيام هو يوم واحد.',
            'days.max'                        => 'الحد الأقصى لعدد الأيام هو 365 يوماً.',
            'written_at.date'                 => 'تاريخ التحرير غير صحيح.',
            'reason.max'                      => 'السبب يجب ألا يتجاوز 500 حرف.',
            'substitute_employee_id.exists'   => 'الموظف البديل المحدد غير موجود.',
            'substitute_employee_id.different'=> 'لا يمكن أن يكون الموظف البديل هو نفس طالب الإجازة.',
        ];
    }
}
