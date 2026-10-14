{{--
    نافذة سجل الترقيات والتدرج الوظيفي للموظف
    $employee: App\Models\Employee
--}}
@php
    $promotions = $employee->promotions()->with(['entitlementGrade', 'creator'])->get();
    $currentGrade = $employee->entitlementGrade?->name ?? ($employee->grade ?? 'غير محددة');
    $lastPromoDate = $employee->promotion_date?->format('Y/m/d');
    $timeInGrade = '—';
    if ($employee->promotion_date) {
        $diff = $employee->promotion_date->diff(now());
        $timeInGrade = "{$diff->y} سنة و {$diff->m} شهر";
    }
@endphp

<div class="space-y-5 text-right" dir="rtl">
    {{-- بيانات الموظف والدرجة الحالية --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-4 bg-gray-50 dark:bg-gray-800/60 rounded-xl border border-gray-200 dark:border-gray-700">
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400 block">الموظف والكود</span>
            <span class="font-bold text-gray-900 dark:text-white">{{ $employee->full_name }}</span>
            <span class="text-xs text-gray-500 mr-1">({{ $employee->employee_code }})</span>
        </div>
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400 block">الدرجة الحالية والاستحقاق</span>
            <span class="font-bold text-primary-600 dark:text-primary-400">
                {{ $currentGrade }}
            </span>
            <span class="text-xs text-gray-500 mr-1">({{ $employee->regularLeaveEntitlement() }} يوم اعتيادي/سنة)</span>
        </div>
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400 block">تاريخ آخر ترقية وقرارها</span>
            <span class="font-semibold text-gray-800 dark:text-gray-200">
                {{ $lastPromoDate ?? 'لم يُحدد' }}
            </span>
            @if ($employee->promotion_decision_number)
                <span class="text-xs text-gray-500 mr-1">({{ $employee->promotion_decision_number }})</span>
            @endif
            <div class="text-xs text-emerald-600 dark:text-emerald-400 mt-0.5">المدة في الدرجة: {{ $timeInGrade }}</div>
        </div>
    </div>

    {{-- جدول سجل الترقيات والتدرج الوظيفي --}}
    <div class="space-y-2">
        <h4 class="font-bold text-gray-900 dark:text-white text-sm flex items-center gap-1.5">
            <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/></svg>
            سجل التدرج الوظيفي وتواريخ الترقيات وقراراتها
        </h4>

        @if ($promotions->isEmpty())
            <div class="p-6 text-center text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/40 rounded-xl border border-gray-200 dark:border-gray-700">
                لا توجد ترقيات سابقة مسجلة في السجل.
                <p class="text-xs text-gray-400 mt-1">الدرجة المقيدة الحالية: {{ $currentGrade }} @if($lastPromoDate) — تاريخ الترقية: {{ $lastPromoDate }} @endif</p>
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm text-right">
                    <thead class="bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-xs font-semibold">
                        <tr>
                            <th class="px-3 py-2.5">م</th>
                            <th class="px-3 py-2.5">الدرجة الوظيفية</th>
                            <th class="px-3 py-2.5">تاريخ الترقية</th>
                            <th class="px-3 py-2.5">رقم قرار الترقية</th>
                            <th class="px-3 py-2.5">المدة المنقضية</th>
                            <th class="px-3 py-2.5">ملاحظات</th>
                            <th class="px-3 py-2.5">المُسجِّل</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($promotions as $index => $promo)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                <td class="px-3 py-2.5 text-gray-500">{{ $index + 1 }}</td>
                                <td class="px-3 py-2.5 font-bold text-gray-900 dark:text-white">
                                    {{ $promo->grade }}
                                    @if ($promo->entitlementGrade)
                                        <span class="text-xs font-normal text-gray-500 mr-1">({{ $promo->entitlementGrade->yearly_days }} يوم)</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-gray-800 dark:text-gray-200 font-medium">
                                    {{ $promo->promotion_date?->format('Y/m/d') ?? '—' }}
                                </td>
                                <td class="px-3 py-2.5 text-gray-700 dark:text-gray-300">
                                    {{ $promo->decision_number ?? '—' }}
                                </td>
                                <td class="px-3 py-2.5 text-emerald-600 dark:text-emerald-400 text-xs">
                                    {{ $promo->duration_since_promotion_label }}
                                </td>
                                <td class="px-3 py-2.5 text-gray-500 text-xs">
                                    {{ $promo->notes ?? '—' }}
                                </td>
                                <td class="px-3 py-2.5 text-gray-500 text-xs">
                                    {{ $promo->creator?->name ?? 'النظام' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
