@php
    $records = $employee->carryoverRecords()->with('leaveType')->get();
    $fiscal = app(\App\Services\FiscalYearService::class);
@endphp

<div class="space-y-4 text-sm">
    @if ($records->isEmpty())
        <p class="text-gray-500 dark:text-gray-400">لا توجد سجلات ترحيل مسجّلة لهذا الموظف.</p>
    @else
        <table class="w-full text-right border-collapse">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700">
                    <th class="py-2 px-2 font-semibold">السنة المالية المنتهية</th>
                    <th class="py-2 px-2 font-semibold">السنة المستهدفة</th>
                    <th class="py-2 px-2 font-semibold">نوع الإجازة</th>
                    <th class="py-2 px-2 font-semibold">الأيام المُرحَّلة</th>
                    <th class="py-2 px-2 font-semibold">درجة الاستحقاق</th>
                    <th class="py-2 px-2 font-semibold">المصدر</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($records as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="py-2 px-2">{{ $fiscal->label($row->from_fiscal_year) }}</td>
                        <td class="py-2 px-2">{{ $fiscal->label($row->to_fiscal_year) }}</td>
                        <td class="py-2 px-2">{{ $row->leaveType?->name ?? '—' }}</td>
                        <td class="py-2 px-2 font-medium">{{ $row->days_carried }}</td>
                        <td class="py-2 px-2">{{ $row->entitlement_grade ?? '—' }}</td>
                        <td class="py-2 px-2">
                            @switch($row->source)
                                @case('year_end') ترحيل 30/6 @break
                                @case('initial_balance') رصيد مبدئي @break
                                @case('manual') يدوي @break
                                @default {{ $row->source }}
                            @endswitch
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
