<x-filament-panels::page>
<div class="space-y-6" dir="rtl">

    {{-- ============================
         A. بيانات الموظف
         ============================ --}}
    <x-filament::section heading="بيانات الموظف">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <span class="font-semibold text-gray-600 dark:text-gray-400">الاسم الكامل:</span>
                <span class="mr-1 text-gray-900 dark:text-white">{{ $employeeInfo['full_name'] }}</span>
            </div>
            <div>
                <span class="font-semibold text-gray-600 dark:text-gray-400">كود الموظف:</span>
                <span class="mr-1 text-gray-900 dark:text-white">{{ $employeeInfo['employee_code'] }}</span>
            </div>
            <div>
                <span class="font-semibold text-gray-600 dark:text-gray-400">المسمى الوظيفي:</span>
                <span class="mr-1 text-gray-900 dark:text-white">{{ $employeeInfo['job_title'] }}</span>
            </div>
            <div>
                <span class="font-semibold text-gray-600 dark:text-gray-400">الدرجة الوظيفية:</span>
                <span class="mr-1 text-gray-900 dark:text-white">
                    {{ $employeeInfo['grade_name'] }}
                    @if ($employeeInfo['yearly_days'] !== '—')
                        <span class="text-gray-500 dark:text-gray-400 text-xs">({{ $employeeInfo['yearly_days'] }} يوم/سنة)</span>
                    @endif
                </span>
            </div>
            <div>
                <span class="font-semibold text-gray-600 dark:text-gray-400">تاريخ التعيين:</span>
                <span class="mr-1 text-gray-900 dark:text-white">{{ $employeeInfo['hire_date'] }}</span>
            </div>
            <div>
                <span class="font-semibold text-gray-600 dark:text-gray-400">تاريخ بداية العمل:</span>
                <span class="mr-1 text-gray-900 dark:text-white">{{ $employeeInfo['work_start_date'] }}</span>
            </div>
            <div>
                <span class="font-semibold text-gray-600 dark:text-gray-400">سنوات الخدمة:</span>
                <span class="mr-1 text-gray-900 dark:text-white">{{ $employeeInfo['service_label'] }}</span>
            </div>
        </div>
    </x-filament::section>

    {{-- ============================
         B. فلترة بالسنوات
         ============================ --}}
    <x-filament::section heading="تصفية بالسنة">
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">من سنة</label>
                <input
                    type="number"
                    wire:model="fromYear"
                    placeholder="{{ now()->year - 5 }}"
                    class="w-28 rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm text-sm px-3 py-2 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">إلى سنة</label>
                <input
                    type="number"
                    wire:model="toYear"
                    placeholder="{{ now()->year }}"
                    class="w-28 rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm text-sm px-3 py-2 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                />
            </div>
            <x-filament::button
                wire:click="applyFilter"
                icon="heroicon-o-funnel"
                color="primary"
            >
                تطبيق
            </x-filament::button>
            @if ($fromYear !== null || $toYear !== null)
                <x-filament::button
                    wire:click="resetFilter"
                    color="gray"
                    icon="heroicon-o-x-mark"
                >
                    إلغاء التصفية
                </x-filament::button>
            @endif
        </div>
    </x-filament::section>

    {{-- ============================
         C. الإجماليات
         ============================ --}}
    <x-filament::section heading="الإجماليات">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="bg-blue-50 dark:bg-blue-900/30 rounded-xl p-4 text-center">
                <div class="text-2xl font-bold text-blue-700 dark:text-blue-300">{{ $summaryStats['service_label'] }}</div>
                <div class="text-xs text-blue-600 dark:text-blue-400 mt-1">سنوات الخدمة</div>
            </div>
            <div class="bg-green-50 dark:bg-green-900/30 rounded-xl p-4 text-center">
                <div class="text-2xl font-bold text-green-700 dark:text-green-300">{{ $summaryStats['total_entitled'] }}</div>
                <div class="text-xs text-green-600 dark:text-green-400 mt-1">إجمالي المستحق</div>
            </div>
            <div class="bg-orange-50 dark:bg-orange-900/30 rounded-xl p-4 text-center">
                <div class="text-2xl font-bold text-orange-700 dark:text-orange-300">{{ $summaryStats['total_used'] }}</div>
                <div class="text-xs text-orange-600 dark:text-orange-400 mt-1">إجمالي المستخدم</div>
            </div>
            <div class="bg-purple-50 dark:bg-purple-900/30 rounded-xl p-4 text-center">
                <div class="text-2xl font-bold text-purple-700 dark:text-purple-300">{{ $summaryStats['total_remaining'] }}</div>
                <div class="text-xs text-purple-600 dark:text-purple-400 mt-1">إجمالي المتبقي</div>
            </div>
            <div class="bg-teal-50 dark:bg-teal-900/30 rounded-xl p-4 text-center">
                <div class="text-2xl font-bold text-teal-700 dark:text-teal-300">{{ $summaryStats['approved_requests_count'] }}</div>
                <div class="text-xs text-teal-600 dark:text-teal-400 mt-1">إجازات معتمدة</div>
            </div>
        </div>
    </x-filament::section>

    {{-- ============================
         D. الفترات الوظيفية المستنتجة
         ============================ --}}
    <x-filament::section heading="الفترات الوظيفية المستنتجة">
        @if ($gradePeriods->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">لا توجد بيانات أرصدة لاستنتاج الفترات الوظيفية.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800">
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">الأيام المستحقة/سنة</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">من سنة</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">إلى سنة</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">عدد السنوات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($gradePeriods as $period)
                            <tr class="bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $period['entitled_days'] }} يوم</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $period['from_year'] }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $period['to_year'] }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $period['years_count'] }} سنة</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        {{-- Disclaimer shown unconditionally so users always know periods are inferred --}}
        <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">
            * مستنتج من بيانات أرصدة الإجازة الاعتيادية — لا يعكس تغييرات الدرجة الرسمية بالضرورة
        </p>
    </x-filament::section>

    {{-- ============================
         E. أرصدة الإجازات
         ============================ --}}
    <x-filament::section heading="أرصدة الإجازات">
        @if ($balanceRows->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">لا توجد أرصدة إجازات مسجلة
                @if ($fromYear !== null || $toYear !== null)
                    في النطاق الزمني المحدد.
                @else
                    لهذا الموظف.
                @endif
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800">
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">السنة</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">نوع الإجازة</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">المستحق</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">المُرحَّل</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">مستخدم من المُرحَّل</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">إجمالي المستخدم</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">المتبقي</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($balanceRows as $row)
                            <tr class="{{ $row['remaining'] <= 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-white dark:bg-gray-800' }} hover:opacity-90 transition-opacity">
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $row['year'] }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['leave_type_name'] }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['entitled'] }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['carried_over'] }}</td>
                                <td class="px-4 py-3 text-amber-600 dark:text-amber-400 font-medium">{{ $row['used_from_carried'] ?? 0 }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['used'] }}</td>
                                <td class="px-4 py-3 font-semibold {{ $row['remaining'] <= 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                                    {{ $row['remaining'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- ============================
         F. الإجازات المعتمدة
         ============================ --}}
    <x-filament::section heading="الإجازات المعتمدة">
        @if ($approvedRequests->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">لا توجد إجازات معتمدة لهذا الموظف.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800">
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">رقم الطلب</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">نوع الإجازة</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">من</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">إلى</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">الأيام</th>
                            <th class="px-4 py-3 text-right font-semibold text-gray-600 dark:text-gray-300">السنة</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($approvedRequests as $req)
                            <tr class="bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">{{ $req['number'] }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $req['leave_type_name'] }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $req['start_date'] }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $req['end_date'] }}</td>
                                <td class="px-4 py-3 font-semibold text-gray-900 dark:text-white">{{ $req['days'] }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $req['year'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

</div>
</x-filament-panels::page>
