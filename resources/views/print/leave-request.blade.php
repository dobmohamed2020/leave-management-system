<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>طلب إجازة - {{ $leaveRequest->employee?->full_name ?? '' }} - {{ $leaveRequest->number }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        html { direction: rtl; }
        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, sans-serif;
            direction: rtl;
            text-align: right;
            unicode-bidi: embed;
            font-size: 13px;
            color: #111;
            margin: 0;
            padding: 0;
            background: #e8edf2;
        }
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            gap: 10px;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            padding: 12px 16px;
            background: #1e3a5f;
            color: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
            direction: rtl;
        }
        .toolbar a, .toolbar button {
            appearance: none;
            border: 0;
            border-radius: 8px;
            padding: 10px 18px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            color: #1e3a5f;
            background: #fff;
        }
        .toolbar a.secondary {
            background: transparent;
            color: #fff;
            border: 1px solid rgba(255,255,255,.55);
        }
        .sheet {
            max-width: 820px;
            margin: 18px auto;
            background: #fff;
            padding: 18px 22px;
            box-shadow: 0 8px 24px rgba(0,0,0,.08);
            direction: rtl;
            text-align: right;
        }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; direction: rtl; }
        .header-table td { vertical-align: top; }
        .org-line { font-size: 14px; font-weight: 700; color: #1e3a5f; line-height: 1.6; text-align: right; }
        .code-box { text-align: right; font-size: 12px; direction: rtl; }
        .barcode { margin-top: 4px; text-align: right; }
        .title {
            text-align: center;
            font-size: 20px;
            font-weight: 700;
            color: #1e3a5f;
            margin: 8px 0 12px;
            border-bottom: 2px solid #1e3a5f;
            padding-bottom: 8px;
            direction: rtl;
        }
        .section-title {
            background: #1e3a5f;
            color: #fff;
            font-weight: 700;
            padding: 6px 10px;
            font-size: 13px;
            margin: 12px 0 6px;
            text-align: right;
            direction: rtl;
        }
        table.data { width: 100%; border-collapse: collapse; font-size: 12.5px; direction: rtl; }
        table.data td, table.data th {
            border: 1px solid #999;
            padding: 7px 8px;
            vertical-align: middle;
            text-align: right;
            direction: rtl;
        }
        .label { background: #f0f3f7; font-weight: 700; width: 20%; }
        .value { width: 30%; }
        .statement {
            border: 1px solid #999;
            padding: 10px;
            margin: 8px 0;
            line-height: 1.9;
            font-size: 13px;
            text-align: right;
            direction: rtl;
        }
        .note {
            font-size: 11px;
            color: #444;
            margin: 6px 0;
            border: 1px dashed #aaa;
            padding: 6px 8px;
            text-align: right;
            direction: rtl;
        }
        .approval-box {
            border: 1px solid #1e3a5f;
            padding: 10px;
            text-align: center;
            margin-top: 10px;
            min-height: 50px;
            direction: rtl;
        }
        .footer {
            border-top: 1px solid #ccc;
            margin-top: 14px;
            padding-top: 8px;
            font-size: 11px;
            color: #555;
            direction: rtl;
        }
        .footer table { width: 100%; border-collapse: collapse; direction: rtl; }
        .muted { color: #666; }

        @media print {
            body { background: #fff; direction: rtl; }
            .toolbar { display: none !important; }
            .sheet {
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }
            @page { margin: 12mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <button type="button" onclick="window.print()">طباعة</button>
        <a href="{{ route('leave.pdf.download', ['number' => $leaveRequest->number]) }}">تحميل PDF</a>
        <a class="secondary" href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/admin/leave-requests/'.$leaveRequest->id) }}">رجوع</a>
    </div>

    <div class="sheet">
        <table class="header-table">
            <tr>
                <td style="width: 62%;">
                    <div class="org-line">{{ $directorate?->name ?? 'مديرية التربية والتعليم بالأقصر' }}</div>
                    <div class="org-line">{{ $administration?->name ?? '—' }}</div>
                    <div class="org-line">{{ $school?->name ?? $leaveRequest->organization?->name ?? '—' }}</div>
                </td>
                <td style="width: 38%;" class="code-box">
                    <div><strong>رقم الطلب:</strong> {{ $leaveRequest->number }}</div>
                    <div class="muted">كود المدرسة: {{ $school?->code ?? $leaveRequest->organization?->code ?? '—' }}</div>
                    @if(!empty($barcode))
                        <div class="barcode">
                            <img src="{{ $barcode }}" alt="barcode" style="height: 42px;">
                        </div>
                    @endif
                </td>
            </tr>
        </table>

        <div class="title">طلب إجازة ({{ $leaveTypeShort ?? 'اعتيادية' }})</div>

        <div class="section-title">أولاً: بيانات الموظف</div>
        <table class="data">
            <tr>
                <td class="label">اسم الموظف</td>
                <td class="value">{{ $leaveRequest->employee?->full_name ?? '—' }}</td>
                <td class="label">كود الموظف</td>
                <td class="value">{{ $leaveRequest->employee?->employee_code ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">المسمى الوظيفي</td>
                <td class="value">{{ $leaveRequest->employee?->job_title ?? '—' }}</td>
                <td class="label">الدرجة الوظيفية</td>
                <td class="value">{{ $employeeGrade ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">جهة العمل</td>
                <td class="value">{{ $school?->name ?? $leaveRequest->organization?->name ?? '—' }}</td>
                <td class="label">تاريخ التعيين</td>
                <td class="value">{{ $leaveRequest->employee?->hire_date?->format('Y/m/d') ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">تاريخ الميلاد</td>
                <td class="value">{{ $leaveRequest->employee?->birth_date?->format('Y/m/d') ?? '—' }}</td>
                <td class="label">تاريخ استلام العمل</td>
                <td class="value">{{ $leaveRequest->employee?->work_start_date?->format('Y/m/d') ?? '—' }}</td>
            </tr>
        </table>

        <div class="section-title">ثانياً: بيانات الإجازة</div>
        <div class="statement">
            أرجو الموافقة على منحي
            <strong>({{ $leaveTypeShort ?? 'إجازة' }})</strong>
            مدتها
            (<strong>{{ (int) $leaveRequest->days }}</strong>)
            يوم
            (<strong>{{ $daysInWords }}</strong>)
            اعتباراً من
            <strong>{{ $leaveRequest->start_date?->format('Y/m/d') ?? '—/—/—' }}</strong>
            إلى
            <strong>{{ $leaveRequest->end_date?->format('Y/m/d') ?? '—/—/—' }}</strong>.
            <br>
            تاريخ تحرير الطلب:
            <strong>{{ $leaveRequest->written_at?->format('Y/m/d') ?? $leaveRequest->created_at?->format('Y/m/d') ?? '—' }}</strong>
            &nbsp;&nbsp;|&nbsp;&nbsp;
            القائم بالعمل أثناء الإجازة:
            <strong>{{ $substituteName ?? '—' }}</strong>
            <br>
            السبب: {{ $leaveRequest->reason ?: '—' }}
        </div>

        @if(!empty($showCasualNote))
            <div class="note">
                ملحوظة: لا تُمنح الإجازة الاعتيادية لمدة يوم أو يومين إلا بعد استنفاد رصيد الإجازة العارضة.
            </div>
        @endif

        <div class="section-title">خاص بقسم الإجازات — الرصيد</div>
        <table class="data">
            <tr>
                <td class="label">الإجازة المستحقة</td>
                <td class="value">{{ $liveEntitled !== null ? (int) $liveEntitled : '—' }} يوم</td>
                <td class="label">السابق منحها</td>
                <td class="value">{{ $liveUsed !== null ? (int) $liveUsed : '—' }} يوم</td>
            </tr>
            <tr>
                <td class="label">الرصيد المتبقي</td>
                <td class="value">{{ $liveRemaining !== null ? (int) $liveRemaining : '—' }} يوم</td>
                <td class="label">حالة الطلب</td>
                <td class="value">{{ $leaveRequest->displayStatusLabel() }}</td>
            </tr>
        </table>

        <div class="section-title">ثالثاً: الإجراءات والآراء</div>
        <table class="data">
            <thead>
                <tr>
                    <th style="background:#e8edf2; width:28%;">الجهة</th>
                    <th style="background:#e8edf2; width:28%;">الاسم</th>
                    <th style="background:#e8edf2; width:18%;">التاريخ</th>
                    <th style="background:#e8edf2; width:26%;">التوقيع / الملاحظة</th>
                </tr>
            </thead>
            <tbody>
                @foreach($approvalRows as $row)
                    <tr>
                        <td style="font-weight:700; height:36px;">{{ $row['label'] }}</td>
                        <td>{{ $row['name'] ?: '' }}</td>
                        <td>{{ $row['date'] ?: '' }}</td>
                        <td style="text-align:center;">
                            @if(!empty($row['signed']))
                                <div style="font-weight:700; color:#1e3a5f; border-bottom:1px solid #1e3a5f; display:inline-block; padding:0 6px 2px;">
                                    {{ $row['signature'] }}
                                </div>
                                <div style="font-size:11px; color:#555; margin-top:2px;">توقيع إلكتروني معتمد</div>
                                @if(!empty($row['note']))
                                    <div style="font-size:11px; color:#444; margin-top:2px;">{{ $row['note'] }}</div>
                                @endif
                            @else
                                <div style="border-bottom:1px solid #777; height:22px; margin:6px 10px 2px;"></div>
                                <div style="font-size:11px; color:#555;">خانة التوقيع</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="approval-box">
            <div style="font-weight:700; margin-bottom:6px;">رأى مدير الإدارة</div>
            <div style="font-size:15px;">
                @if($leaveRequest->status === \App\Enums\LeaveStatus::APPROVED)
                    يعتمد
                @elseif($leaveRequest->status === \App\Enums\LeaveStatus::REJECTED)
                    يُرفض
                @else
                    ........................
                @endif
            </div>
        </div>

        @if($leaveRequest->status === \App\Enums\LeaveStatus::REJECTED && $leaveRequest->rejection_reason)
            <div style="margin-top:8px; border:1px solid #c0392b; padding:8px; background:#fef2f2;">
                <strong>سبب الرفض:</strong> {{ $leaveRequest->rejection_reason }}
            </div>
        @endif

        <div class="footer">
            <table>
                <tr>
                    <td style="width:70%; text-align:right;">
                        تاريخ الطباعة: {{ $printedAt->format('Y/m/d H:i') }}
                        &nbsp;|&nbsp;
                        {{ $administration?->name ?? '' }}
                        @if($school) — {{ $school->name }} @endif
                    </td>
                    <td style="width:30%; text-align:left;">
                        @if(!empty($qrCode))
                            {!! $qrCode !!}
                        @endif
                    </td>
                </tr>
            </table>
        </div>
    </div>

    @if(request()->boolean('autoprint'))
        <script>
            (function () {
                let printed = false;

                function doPrint() {
                    if (printed) return;
                    printed = true;

                    // 1. Focus the window (required by Chrome & Edge on Windows)
                    try {
                        window.focus();
                    } catch (e) {}

                    // 2. Wait for web fonts (e.g., Tajawal) to finish loading
                    const fontPromise = (document.fonts && document.fonts.ready)
                        ? document.fonts.ready
                        : Promise.resolve();

                    fontPromise.then(function () {
                        setTimeout(function () {
                            try {
                                window.focus();
                                window.print();
                            } catch (err) {
                                console.error('Print trigger failed:', err);
                            }
                        }, 350);
                    }).catch(function () {
                        setTimeout(function () {
                            try {
                                window.focus();
                                window.print();
                            } catch (err) {
                                console.error('Print trigger fallback failed:', err);
                            }
                        }, 400);
                    });
                }

                // If page is already loaded (from cache or fast local rendering)
                if (document.readyState === 'complete') {
                    doPrint();
                } else {
                    window.addEventListener('load', doPrint);
                    document.addEventListener('DOMContentLoaded', function () {
                        setTimeout(doPrint, 500);
                    });
                }
            })();
        </script>
    @endif
</body>
</html>
