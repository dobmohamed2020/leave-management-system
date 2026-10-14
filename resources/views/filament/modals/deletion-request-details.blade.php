{{--
    تفاصيل طلب اعتماد الحذف
    $request: App\Models\DeletionRequest
--}}
<div class="space-y-4 text-right" dir="rtl">
    <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
        <h4 class="font-bold text-gray-900 dark:text-white text-base mb-1">{{ $request->title }}</h4>
        <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
            <span>المدرسة: <strong>{{ $request->organization?->name }}</strong></span>
            <span>•</span>
            <span>مقدم الطلب: <strong>{{ $request->requester?->name }}</strong></span>
            <span>•</span>
            <span>التاريخ: <strong>{{ $request->created_at?->format('d/m/Y - h:i A') }}</strong></span>
        </div>
    </div>

    {{-- تفاصيل العنصر المطلوب حذفه --}}
    @if (! empty($request->details))
        <div class="p-4 bg-blue-50/60 dark:bg-blue-900/20 rounded-xl border border-blue-200 dark:border-blue-800">
            <h5 class="font-semibold text-xs text-blue-800 dark:text-blue-300 mb-2">بيانات العنصر المستهدف:</h5>
            <div class="grid grid-cols-2 gap-2 text-xs">
                @foreach ($request->details as $key => $val)
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">{{ $key }}:</span>
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ is_array($val) ? json_encode($val) : $val }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- سبب الحذف --}}
    <div class="p-4 bg-amber-50/60 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-800">
        <span class="text-xs font-semibold text-amber-800 dark:text-amber-300 block mb-1">سبب طلب الحذف الوارد من المدرسة:</span>
        <p class="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-wrap">{{ $request->reason }}</p>
    </div>

    {{-- حالة القرار والملاحظات --}}
    @if ($request->status !== 'pending')
        @php
            $boxClass = match($request->status) {
                'approved' => 'bg-emerald-50/60 border-emerald-300 dark:bg-emerald-900/20 dark:border-emerald-800',
                'restored' => 'bg-blue-50/60 border-blue-300 dark:bg-blue-900/20 dark:border-blue-800',
                default    => 'bg-red-50/60 border-red-300 dark:bg-red-900/20 dark:border-red-800',
            };
            $textClass = match($request->status) {
                'approved' => 'text-emerald-800 dark:text-emerald-300',
                'restored' => 'text-blue-800 dark:text-blue-300',
                default    => 'text-red-800 dark:text-red-300',
            };
            $titleText = match($request->status) {
                'approved' => 'تم اعتماد وتنفيذ الحذف',
                'restored' => 'تمت استعادة العنصر من الحذف العادي وإعادته للعمل',
                default    => 'تم رفض طلب الحذف',
            };
        @endphp
        <div class="p-4 rounded-xl border {{ $boxClass }}">
            <div class="flex items-center justify-between mb-1.5">
                <span class="font-bold text-xs {{ $textClass }}">
                    قرار الإدارة: {{ $titleText }}
                </span>
                <span class="text-xs text-gray-500">
                    بواسطة: {{ $request->reviewer?->name ?? '—' }} ({{ $request->reviewed_at?->format('d/m/Y - h:i A') }})
                </span>
            </div>
            @if ($request->admin_notes)
                <p class="text-xs text-gray-700 dark:text-gray-300 mt-1 whitespace-pre-wrap">ملاحظات الإدارة: {{ $request->admin_notes }}</p>
            @endif
        </div>
    @endif
</div>
