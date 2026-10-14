{{--
    نافذة كشف حساب الرصيد المُرحَّل للموظف
    $employee: App\Models\Employee
--}}
@php
    $regType = \App\Models\LeaveType::where('code', 'regular')->first();
    $balance = $regType ? $employee->balanceFor($regType->id) : null;
    $carriedTotal = $balance ? (int) $balance->carried_over : 0;
    $usedCarried  = $balance ? (int) $balance->used_carried : 0;
    $remCarried   = $balance ? (int) $balance->remaining_carried : 0;

    $transactions = $balance ? $balance->transactions()
        ->with(['leaveRequest', 'createdBy'])
        ->get()
        ->filter(function ($t) {
            // تصفية الحركات المؤثرة على الرصيد المرحل:
            // 1. ترحيل CARRYOVER
            // 2. خصم طلب إجازة مؤشر عليه deduct_from_carried
            // 3. تعديل يدوي مذكور فيه مرحل
            if ($t->type === \App\Enums\TransactionType::CARRYOVER) return true;
            if ($t->leaveRequest && $t->leaveRequest->deduct_from_carried) return true;
            if ($t->note && (str_contains($t->note, 'مُرحَّل') || str_contains($t->note, 'مرحل') || str_contains($t->note, '30-6'))) return true;
            return false;
        }) : collect();
@endphp

<div class="space-y-5 text-right" dir="rtl">
    {{-- بيانات الموظف والدرجة --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-4 bg-gray-50 dark:bg-gray-800/60 rounded-xl border border-gray-200 dark:border-gray-700">
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400 block">الموظف والكود</span>
            <span class="font-bold text-gray-900 dark:text-white">{{ $employee->full_name }}</span>
            <span class="text-xs text-gray-500 mr-1">({{ $employee->employee_code }})</span>
        </div>
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400 block">الدرجة الوظيفية</span>
            <span class="font-semibold text-primary-600 dark:text-primary-400">
                {{ $employee->entitlementGrade?->name ?? 'غير محددة' }}
            </span>
            <span class="text-xs text-gray-500 mr-1">({{ $employee->regularLeaveEntitlement() }} يوم اعتيادي/سنة)</span>
        </div>
        <div>
            <span class="text-xs text-gray-500 dark:text-gray-400 block">الجهة / المدرسة</span>
            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $employee->organization?->name ?? '—' }}</span>
        </div>
    </div>

    {{-- بطاقات إحصائيات الرصيد المرحل المنعزل --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        {{-- إجمالي المرحل --}}
        <div class="p-4 bg-blue-50/80 dark:bg-blue-900/20 rounded-xl border border-blue-200 dark:border-blue-800">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-blue-700 dark:text-blue-300">إجمالي الرصيد المُرحَّل</span>
                <span class="p-1.5 rounded-lg bg-blue-100 dark:bg-blue-800 text-blue-700 dark:text-blue-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                </span>
            </div>
            <div class="mt-2 text-2xl font-extrabold text-blue-900 dark:text-blue-100">
                {{ $carriedTotal }} <span class="text-sm font-medium">يوم</span>
            </div>
            <p class="text-xs text-blue-600 dark:text-blue-400 mt-1">تراكم سنوات سابقة + ترحيلات 30-6</p>
        </div>

        {{-- المستهلك من المرحل --}}
        <div class="p-4 bg-amber-50/80 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-800">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-amber-700 dark:text-amber-300">المستهلك من المُرحَّل</span>
                <span class="p-1.5 rounded-lg bg-amber-100 dark:bg-amber-800 text-amber-700 dark:text-amber-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg>
                </span>
            </div>
            <div class="mt-2 text-2xl font-extrabold text-amber-900 dark:text-amber-100">
                {{ $usedCarried }} <span class="text-sm font-medium">يوم</span>
            </div>
            <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">إجازات خُصمت خصيصاً من المرحل</p>
        </div>

        {{-- المتبقي الصافي --}}
        <div class="p-4 bg-emerald-50/80 dark:bg-emerald-900/20 rounded-xl border border-emerald-200 dark:border-emerald-800">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">المتبقي من المُرحَّل</span>
                <span class="p-1.5 rounded-lg bg-emerald-100 dark:bg-emerald-800 text-emerald-700 dark:text-emerald-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </span>
            </div>
            <div class="mt-2 text-2xl font-extrabold text-emerald-900 dark:text-emerald-100">
                {{ $remCarried }} <span class="text-sm font-medium">يوم</span>
            </div>
            <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1">الرصيد المتاح للاستهلاك الفعلي</p>
        </div>
    </div>

    {{-- جدول كشف حركات الرصيد المرحل --}}
    <div class="space-y-2">
        <h4 class="font-bold text-gray-900 dark:text-white text-sm flex items-center gap-1.5">
            <svg class="w-4 h-4 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            سجل حركات واستهلاك الرصيد المُرحَّل بتواريخها
        </h4>

        @if ($transactions->isEmpty())
            <div class="p-6 text-center bg-gray-50 dark:bg-gray-800/40 rounded-xl border border-dashed border-gray-300 dark:border-gray-700 text-sm text-gray-500">
                لا توجد حركات مسجلة على الرصيد المُرحَّل حتى الآن.
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-2.5 text-right font-semibold text-gray-600 dark:text-gray-300">التاريخ</th>
                            <th class="px-4 py-2.5 text-right font-semibold text-gray-600 dark:text-gray-300">نوع الحركة</th>
                            <th class="px-4 py-2.5 text-right font-semibold text-gray-600 dark:text-gray-300">الأيام</th>
                            <th class="px-4 py-2.5 text-right font-semibold text-gray-600 dark:text-gray-300">رقم الطلب / البيان</th>
                            <th class="px-4 py-2.5 text-right font-semibold text-gray-600 dark:text-gray-300">المسؤول</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700 bg-white dark:bg-gray-900">
                        @foreach ($transactions as $tx)
                            @php
                                $isAddition = in_array($tx->type, [\App\Enums\TransactionType::CARRYOVER, \App\Enums\TransactionType::REFUND]) || ($tx->days > 0 && $tx->type === \App\Enums\TransactionType::ADJUSTMENT);
                            @endphp
                            <tr class="hover:bg-gray-50/60 dark:hover:bg-gray-800/50">
                                <td class="px-4 py-2.5 whitespace-nowrap text-gray-600 dark:text-gray-300">
                                    {{ $tx->created_at?->format('d/m/Y - h:i A') ?? '—' }}
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    @if ($tx->type === \App\Enums\TransactionType::CARRYOVER)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300">
                                            ترحيل رصيد 30-6
                                        </span>
                                    @elseif ($tx->type === \App\Enums\TransactionType::DEDUCTION)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">
                                            استهلاك إجازة من المرحل
                                        </span>
                                    @elseif ($tx->type === \App\Enums\TransactionType::REFUND)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">
                                            استرداد إجازة ملغاة
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300">
                                            تعديل رصيد
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap font-bold {{ $isAddition ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                                    {{ $isAddition ? '+' : '-' }}{{ abs($tx->days) }} يوم
                                </td>
                                <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                                    @if ($tx->leaveRequest)
                                        <span class="font-medium">طلب: {{ $tx->leaveRequest->number }}</span>
                                        <span class="text-xs text-gray-500 block">({{ $tx->leaveRequest->start_date?->format('Y-m-d') }} إلى {{ $tx->leaveRequest->end_date?->format('Y-m-d') }})</span>
                                    @else
                                        <span>{{ $tx->note ?? '—' }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                    {{ $tx->createdBy?->name ?? 'النظام الآلي' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
