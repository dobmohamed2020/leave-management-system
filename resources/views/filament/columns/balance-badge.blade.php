{{--
    عمود رصيد الإجازات في جدول الموظفين — فصل تام بين رصيد السنة والرصيد المُرحَّل
--}}
@php
    /** @var \App\Models\Employee $employee */
    $employee = $getRecord();
    $balances = $employee->leaveBalances()
        ->with('leaveType')
        ->currentYear()
        ->get();
@endphp

@if ($balances->isEmpty())
    <span class="text-xs text-gray-400">—</span>
@else
    <div class="flex flex-wrap items-center gap-1.5">
        @foreach ($balances as $balance)
            @php
                $type = $balance->leaveType;
                $isRegular = $type?->code === 'regular';
                $remAnnual = (int) $balance->remaining_entitled;
                $entitled  = (int) $balance->entitled;
                $remCarried = (int) $balance->remaining_carried;
                $carriedTotal = (int) $balance->carried_over;
            @endphp

            @if ($isRegular)
                {{-- رصيد السنة الحالية --}}
                <span
                    class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200"
                    title="الإجازة الاعتيادية (رصيد السنة): متبقي {{ $remAnnual }} من أصل {{ $entitled }} يوم"
                >
                    الاعتيادي: {{ $remAnnual }}/{{ $entitled }}
                </span>

                {{-- رصيد المرحل المنفصل --}}
                @if ($carriedTotal > 0 || $balance->used_carried > 0)
                    <span
                        class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-amber-50 text-amber-800 border border-amber-300"
                        title="الرصيد المُرحَّل (سنوات سابقة): متبقي {{ $remCarried }} من أصل {{ $carriedTotal }} يوم"
                    >
                        المُرحَّل: {{ $remCarried }}/{{ $carriedTotal }}
                    </span>
                @endif
            @else
                <span
                    class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-800 border border-gray-200"
                    title="{{ $type?->name }}: متبقي {{ $remAnnual }} من {{ $entitled }}"
                >
                    {{ $type?->name }}: {{ $remAnnual }}/{{ $entitled }}
                </span>
            @endif
        @endforeach
    </div>
@endif
