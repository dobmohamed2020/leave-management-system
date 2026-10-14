<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تقرير شامل للموظف - {{ $basicInfo['full_name'] }} (كود: {{ $basicInfo['employee_code'] }})</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        html { direction: rtl; }
        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, sans-serif;
            direction: rtl;
            text-align: right;
            unicode-bidi: embed;
            font-size: 13px;
            color: #1a202c;
            margin: 0;
            padding: 0;
            background: #f1f5f9;
        }

        /* Toolbar */
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            gap: 12px;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            padding: 12px 20px;
            background: #0f172a;
            color: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,.2);
            direction: rtl;
        }
        .toolbar button, .toolbar a {
            appearance: none;
            border: 0;
            border-radius: 8px;
            padding: 10px 20px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .btn-print {
            background: #2563eb;
            color: #fff;
        }
        .btn-print:hover { background: #1d4ed8; }
        .btn-secondary {
            background: #334155;
            color: #f8fafc;
        }
        .btn-secondary:hover { background: #475569; }

        /* Document Sheet */
        .sheet {
            max-width: 960px;
            margin: 24px auto;
            background: #fff;
            padding: 32px 40px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.08);
            border: 1px solid #e2e8f0;
        }

        /* Header block */
        .doc-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 16px;
            margin-bottom: 24px;
        }
        .doc-header-col {
            flex: 1;
        }
        .doc-header-col.left {
            text-align: left;
        }
        .gov-title {
            font-size: 13px;
            font-weight: 700;
            line-height: 1.5;
            color: #334155;
        }
        .main-title {
            text-align: center;
            flex: 2;
        }
        .main-title h1 {
            font-size: 20px;
            font-weight: 800;
            margin: 0;
            color: #0f172a;
        }
        .main-title h2 {
            font-size: 13px;
            font-weight: 600;
            margin: 6px 0 0;
            color: #64748b;
        }
        .emp-badge {
            display: inline-block;
            margin-top: 8px;
            background: #eff6ff;
            color: #1d4ed8;
            padding: 4px 14px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 12px;
            border: 1px solid #bfdbfe;
        }

        /* Section Headings */
        .section-box {
            margin-bottom: 26px;
            page-break-inside: avoid;
        }
        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-right: 4px solid #2563eb;
            padding: 8px 14px;
            border-radius: 6px;
            margin-bottom: 12px;
        }
        .section-header h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
        }

        /* Cards / Grids */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }
        .stat-card {
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .stat-card.blue {
            background: #eff6ff;
            border-color: #bfdbfe;
        }
        .stat-card.amber {
            background: #fffbeb;
            border-color: #fde68a;
        }
        .stat-card.green {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }
        .stat-card.purple {
            background: #faf5ff;
            border-color: #e9d5ff;
        }
        .stat-title {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 4px;
        }
        .stat-val {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
        }
        .stat-sub {
            font-size: 11px;
            color: #64748b;
            margin-top: 4px;
        }

        /* Tables */
        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-bottom: 8px;
        }
        .report-table th, .report-table td {
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            text-align: right;
        }
        .report-table th {
            background: #f1f5f9;
            color: #0f172a;
            font-weight: 700;
        }
        .report-table tr:nth-child(even) td {
            background: #f8fafc;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger  { background: #fee2e2; color: #991b1b; }
        .badge-info    { background: #e0f2fe; color: #075985; }
        .badge-gray    { background: #f1f5f9; color: #475569; }

        /* Signatures block */
        .signatures {
            margin-top: 40px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            text-align: center;
            page-break-inside: avoid;
            border-top: 2px dashed #cbd5e1;
            padding-top: 20px;
        }
        .sig-col {
            font-size: 12px;
        }
        .sig-title {
            font-weight: 700;
            color: #334155;
            margin-bottom: 45px;
        }
        .sig-dots {
            color: #94a3b8;
            font-size: 11px;
        }

        /* Footer */
        .doc-footer {
            margin-top: 24px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #64748b;
        }

        /* Print Media Styles */
        @media print {
            body {
                background: #fff;
                font-size: 11px;
            }
            .toolbar { display: none !important; }
            .sheet {
                box-shadow: none;
                border: none;
                max-width: 100%;
                margin: 0;
                padding: 10px;
            }
            .section-box {
                page-break-inside: avoid;
            }
            .report-table th, .report-table td {
                padding: 5px 6px;
                font-size: 10.5px;
            }
            .signatures {
                margin-top: 25px;
                padding-top: 15px;
            }
        }
    </style>
</head>
<body>

    {{-- شريط الإجراءات والطباعة العلوي --}}
    <div class="toolbar">
        <button type="button" class="btn-print" onclick="window.print()">
            <svg style="width:18px;height:18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            طباعة التقرير الشامل / تصدير PDF
        </button>

        <a href="/admin/employees/{{ $employee->id }}" class="btn-secondary">
            <svg style="width:18px;height:18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            العودة لصفحة الموظف
        </a>

        <a href="/admin/employees" class="btn-secondary">
            قائمة الموظفين
        </a>
    </div>

    {{-- ورقة التقرير الرسمية --}}
    <div class="sheet">

        {{-- ترويسة التقرير الرسمية --}}
        <div class="doc-header">
            <div class="doc-header-col">
                <div class="gov-title">
                    جمهورية مصر العربية<br>
                    وزارة التربية والتعليم والتعليم الفني<br>
                    مديرية التربية والتعليم بالأقصر<br>
                    إدارة أرمنت التعليمية
                </div>
            </div>

            <div class="main-title">
                <h1>بيان حالة وإجازات وترقيات موظف شامل</h1>
                <h2>تقرير الأرصدة والمُرحَّل وتاريخ التدرج الوظيفي وسجل العمليات</h2>
                <div class="emp-badge">
                    السنة المالية: {{ $fiscalYearLabel }}
                </div>
            </div>

            <div class="doc-header-col left">
                <div class="gov-title">
                    جهة العمل: <strong>{{ $basicInfo['organization_name'] }}</strong><br>
                    تاريخ الاستخراج: {{ $generatedAt->format('Y/m/d H:i') }}<br>
                    المُستخرِج: {{ $generatedBy }}
                </div>
            </div>
        </div>

        {{-- 1. البيانات الأساسية والوظيفية --}}
        <div class="section-box">
            <div class="section-header">
                <h3>أولاً: البيانات الأساسية والوظيفية</h3>
            </div>

            <table class="report-table">
                <tbody>
                    <tr>
                        <th style="width: 15%;">اسم الموظف</th>
                        <td style="width: 35%; font-weight: 700; font-size: 14px;">{{ $basicInfo['full_name'] }}</td>
                        <th style="width: 15%;">كود الموظف</th>
                        <td style="width: 35%; font-weight: 700; font-family: monospace; font-size: 14px;">{{ $basicInfo['employee_code'] }}</td>
                    </tr>
                    <tr>
                        <th>المسمى الوظيفي</th>
                        <td>{{ $basicInfo['job_title'] }}</td>
                        <th>جهة العمل / المؤسسة</th>
                        <td>{{ $basicInfo['organization_name'] }} ({{ $basicInfo['organization_type'] }})</td>
                    </tr>
                    <tr>
                        <th>الدرجة الحالية</th>
                        <td style="font-weight: 700; color: #1e3a5f;">{{ $promotionsData['current_grade'] }}</td>
                        <th>الاستحقاق السنوي الاعتيادي</th>
                        <td><span class="badge badge-info">{{ $promotionsData['yearly_entitlement'] }} يوم / سنة</span></td>
                    </tr>
                    <tr>
                        <th>تاريخ الميلاد والسن</th>
                        <td>{{ $basicInfo['birth_date'] ?? '—' }} ({{ $basicInfo['age'] }})</td>
                        <th>رقم الهاتف</th>
                        <td>{{ $basicInfo['phone'] }}</td>
                    </tr>
                    <tr>
                        <th>تاريخ التعيين</th>
                        <td>{{ $basicInfo['hire_date'] ?? '—' }}</td>
                        <th>تاريخ استلام العمل</th>
                        <td>{{ $basicInfo['work_start_date'] ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th>مدة الخدمة الإجمالية</th>
                        <td style="font-weight: 700; color: #047857;">{{ $basicInfo['service_duration_label'] }}</td>
                        <th>حالة الحساب والنظام</th>
                        <td>
                            @if ($basicInfo['is_active'])
                                <span class="badge badge-success">نشط</span>
                            @else
                                <span class="badge badge-danger">غير نشط</span>
                            @endif

                            @if ($basicInfo['has_system_user'])
                                <span class="badge badge-info" style="margin-right: 4px;">حساب دخول ({{ $basicInfo['system_user_email'] }})</span>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- 2. الدرجات وتاريخ الترقيات (ودرجاته وامتى ترقى) --}}
        <div class="section-box">
            <div class="section-header">
                <h3>ثانياً: الدرجات الوظيفية وسجل الترقيات (امتى ترقى والقرارات)</h3>
            </div>

            <div class="stats-grid">
                <div class="stat-card blue">
                    <div class="stat-title">الدرجة الوظيفية الحالية</div>
                    <div class="stat-val" style="font-size: 16px;">{{ $promotionsData['current_grade'] }}</div>
                    <div class="stat-sub">الاستحقاق: {{ $promotionsData['yearly_entitlement'] }} يوم سنوياً</div>
                </div>

                <div class="stat-card green">
                    <div class="stat-title">تاريخ آخر ترقية للدرجة الحالية</div>
                    <div class="stat-val" style="font-size: 16px;">{{ $promotionsData['promotion_date'] ?? '—' }}</div>
                    <div class="stat-sub">قرار رقم: {{ $promotionsData['decision_number'] }}</div>
                </div>

                <div class="stat-card purple">
                    <div class="stat-title">المدة المنقضية في الدرجة الحالية</div>
                    <div class="stat-val" style="font-size: 16px;">{{ $promotionsData['time_in_current_grade'] }}</div>
                    <div class="stat-sub">من تاريخ استحقاق الترقية</div>
                </div>
            </div>

            <table class="report-table">
                <thead>
                    <tr>
                        <th style="width: 5%;">م</th>
                        <th style="width: 25%;">الدرجة الوظيفية</th>
                        <th style="width: 20%;">تاريخ الترقية</th>
                        <th style="width: 20%;">رقم قرار الترقية</th>
                        <th style="width: 15%;">المدة في الدرجة</th>
                        <th style="width: 15%;">ملاحظات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($promotionsData['promotions_history'] as $idx => $p)
                        <tr>
                            <td style="text-align: center;">{{ $idx + 1 }}</td>
                            <td style="font-weight: 700;">{{ $p['grade'] }}</td>
                            <td>{{ $p['promotion_date'] }}</td>
                            <td>{{ $p['decision_number'] }}</td>
                            <td>{{ $p['duration_since_promotion'] }}</td>
                            <td>{{ $p['notes'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align: center; color: #64748b; padding: 12px;">
                                لا يوجد سجل ترقيات سابق مسجل — الدرجة الحالية المقيدة: <strong>{{ $promotionsData['current_grade'] }}</strong>
                                @if ($promotionsData['promotion_date'])
                                    (تاريخ الترقية: {{ $promotionsData['promotion_date'] }} - قرار: {{ $promotionsData['decision_number'] }})
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- 3. كشف أرصدة الإجازات والمُرحَّل (تقرير بإجازات ومرحل) --}}
        <div class="section-box">
            <div class="section-header">
                <h3>ثالثاً: كشف رصيد الإجازات والرصيد المُرحَّل (تفصيل كامل)</h3>
            </div>

            <div class="stats-grid">
                {{-- بطاقة الرصيد المرحل المنعزل --}}
                <div class="stat-card amber">
                    <div class="stat-title">الرصيد المُرحَّل (سنوات سابقة)</div>
                    <div class="stat-val" style="color: #b45309;">{{ $balancesData['regular']['carried_total'] }} <span style="font-size: 13px;">يوم</span></div>
                    <div class="stat-sub">
                        مستهلك منه: <strong>{{ $balancesData['regular']['used_carried'] }}</strong> يوم |
                        المتبقي: <strong>{{ $balancesData['regular']['remaining_carried'] }}</strong> يوم
                    </div>
                </div>

                {{-- بطاقة رصيد السنة الحالية --}}
                <div class="stat-card blue">
                    <div class="stat-title">رصيد السنة المالية الحالية ({{ $fiscalYearLabel }})</div>
                    <div class="stat-val" style="color: #1d4ed8;">{{ $balancesData['regular']['entitled_annual'] }} <span style="font-size: 13px;">يوم</span></div>
                    <div class="stat-sub">
                        مستهلك سنوي: <strong>{{ $balancesData['regular']['used_annual'] }}</strong> يوم |
                        متبقي سنوي: <strong>{{ $balancesData['regular']['remaining_annual'] }}</strong> يوم
                    </div>
                </div>

                {{-- إجمالي الرصيد الكلي المتاح --}}
                <div class="stat-card green">
                    <div class="stat-title">إجمالي الرصيد الكلي المتبقي للموظف</div>
                    <div class="stat-val" style="color: #047857;">{{ $balancesData['regular']['total_remaining'] }} <span style="font-size: 13px;">يوم</span></div>
                    <div class="stat-sub">متبقي السنة ({{ $balancesData['regular']['remaining_annual'] }}) + متبقي المُرحَّل ({{ $balancesData['regular']['remaining_carried'] }})</div>
                </div>
            </div>

            {{-- جدول الأرصدة عبر السنوات المالية --}}
            <table class="report-table">
                <thead>
                    <tr>
                        <th style="width: 12%;">السنة المالية</th>
                        <th style="width: 20%;">نوع الإجازة</th>
                        <th style="width: 12%;">المستحق السنوي</th>
                        <th style="width: 14%;">الرصيد المُرحَّل</th>
                        <th style="width: 14%;">مستهلك من المُرحَّل</th>
                        <th style="width: 14%;">إجمالي المستهلك</th>
                        <th style="width: 14%;">المتبقي الكلي</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($balancesData['allYearsBalances'] as $b)
                        <tr>
                            <td style="font-weight: 700;">{{ $b['year'] }}</td>
                            <td>{{ $b['type_name'] }}</td>
                            <td>{{ $b['entitled'] }} يوم</td>
                            <td style="color: #b45309; font-weight: 600;">{{ $b['carried_over'] }} يوم</td>
                            <td style="color: #d97706;">{{ $b['used_from_carried'] }} يوم</td>
                            <td style="color: #dc2626;">{{ $b['used'] }} يوم</td>
                            <td style="font-weight: 800; color: {{ $b['remaining'] > 0 ? '#16a34a' : '#dc2626' }};">
                                {{ $b['remaining'] }} يوم
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center; color: #64748b; padding: 10px;">
                                لا توجد أرصدة مسجلة في قاعدة البيانات لهذا الموظف
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if (! empty($carryoverFile))
                <div style="margin-top: 18px;">
                    <h4 style="font-size: 14px; font-weight: 700; color: #1e3a5f; margin-bottom: 8px;">
                        ملف الترحيلات (سنة مالية — أيام مُرحَّلة — درجة الاستحقاق وقت الترحيل)
                    </h4>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>من سنة (30/6)</th>
                                <th>إلى سنة (1/7)</th>
                                <th>نوع الإجازة</th>
                                <th>الأيام المُرحَّلة</th>
                                <th>درجة الاستحقاق</th>
                                <th>المصدر</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($carryoverFile as $row)
                                <tr>
                                    <td>{{ $row['from_label'] ?? $row['from_fiscal_year'] }}</td>
                                    <td>{{ $row['to_label'] ?? $row['to_fiscal_year'] }}</td>
                                    <td>{{ $row['leave_type'] ?? '—' }}</td>
                                    <td style="font-weight: 700; color: #b45309;">{{ $row['days_carried'] }} يوم</td>
                                    <td>{{ $row['entitlement_grade'] ?? '—' }}</td>
                                    <td>
                                        @switch($row['source'] ?? '')
                                            @case('year_end') ترحيل 30/6 @break
                                            @case('initial_balance') رصيد مبدئي @break
                                            @case('manual') يدوي @break
                                            @default {{ $row['source'] ?? '—' }}
                                        @endswitch
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- 4. سجل طلبات الإجازات المعتمدة والكلية --}}
        <div class="section-box">
            <div class="section-header">
                <h3>رابعاً: سجل الإجازات وطلبات الموظف (المعتمدة والمنفذة)</h3>
            </div>

            <div style="margin-bottom: 8px; font-size: 12px; color: #475569;">
                إجمالي الطلبات المقدمة: <strong>{{ $requestsData['total_count'] }}</strong> طلب |
                الطلبات المعتمدة: <strong>{{ $requestsData['approved_count'] }}</strong> طلب |
                إجمالي الأيام المعتمدة: <strong>{{ $requestsData['approved_days'] }}</strong> يوم
            </div>

            <table class="report-table">
                <thead>
                    <tr>
                        <th style="width: 15%;">رقم الطلب</th>
                        <th style="width: 18%;">نوع الإجازة</th>
                        <th style="width: 14%;">من تاريخ</th>
                        <th style="width: 14%;">إلى تاريخ</th>
                        <th style="width: 10%;">عدد الأيام</th>
                        <th style="width: 14%;">مصدر الخصم</th>
                        <th style="width: 15%;">حالة الطلب</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($requestsData['list'] as $req)
                        <tr>
                            <td style="font-family: monospace; font-size: 11px;">{{ $req['number'] }}</td>
                            <td style="font-weight: 600;">{{ $req['leave_type_name'] }}</td>
                            <td>{{ $req['start_date'] }}</td>
                            <td>{{ $req['end_date'] }}</td>
                            <td style="font-weight: 700; text-align: center;">{{ $req['days'] }}</td>
                            <td>
                                @if ($req['deduct_from_carried'])
                                    <span class="badge badge-warning">رصيد مُرحَّل</span>
                                @else
                                    <span class="badge badge-info">سنوي اعتيادي</span>
                                @endif
                            </td>
                            <td>
                                @if ($req['status'] === 'approved')
                                    <span class="badge badge-success">معتمد</span>
                                @elseif ($req['status'] === 'rejected')
                                    <span class="badge badge-danger">مرفوض</span>
                                @elseif ($req['status'] === 'pending_school' || $req['status'] === 'pending_admin')
                                    <span class="badge badge-warning">قيد المراجعة</span>
                                @else
                                    <span class="badge badge-gray">{{ $req['status_label'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center; color: #64748b; padding: 12px;">
                                لا توجد طلبات إجازة مسجلة لهذا الموظف
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- 5. سجل كافة العمليات والبيانات والتعديلات التي تمت على الموظف (اي داتا اتعملت للموظف) --}}
        <div class="section-box">
            <div class="section-header">
                <h3>خامساً: سجل التدقيق والعمليات (أي تعديل أو إجراء تم على الموظف)</h3>
            </div>

            <table class="report-table">
                <thead>
                    <tr>
                        <th style="width: 18%;">التاريخ والوقت</th>
                        <th style="width: 20%;">نوع الإجراء</th>
                        <th style="width: 20%;">القائم بالإجراء</th>
                        <th style="width: 42%;">التفاصيل والتغييرات المسجلة</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($auditTrail as $item)
                        <tr>
                            <td style="font-size: 11px; color: #475569;">{{ $item['date_label'] }}</td>
                            <td>
                                <span class="badge badge-{{ $item['badge_color'] }}">
                                    {{ $item['description'] }}
                                </span>
                            </td>
                            <td style="font-weight: 600;">{{ $item['causer_name'] }}</td>
                            <td style="font-size: 11px;">{{ $item['details'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" style="text-align: center; color: #64748b; padding: 10px;">
                                لا توجد حركات تدقيق مسجلة حتى الآن
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- 6. توقيعات الاعتماد الرسمية --}}
        <div class="signatures">
            <div class="sig-col">
                <div class="sig-title">مسؤول الإجازات بالجهة</div>
                <div class="sig-dots">الاسم: ....................</div>
                <div class="sig-dots">التوقيع: ..................</div>
            </div>

            <div class="sig-col">
                <div class="sig-title">شؤون العاملين</div>
                <div class="sig-dots">الاسم: ....................</div>
                <div class="sig-dots">التوقيع: ..................</div>
            </div>

            <div class="sig-col">
                <div class="sig-title">مدير المدرسة / جهة العمل</div>
                <div class="sig-dots">الاسم: ....................</div>
                <div class="sig-dots">التوقيع: ..................</div>
            </div>

            <div class="sig-col">
                <div class="sig-title">يعتمد، مدير الإدارة التعليمية</div>
                <div class="sig-dots">الاسم: ....................</div>
                <div class="sig-dots">خاتم الشعار: ...............</div>
            </div>
        </div>

        {{-- تذييل الصفحة --}}
        <div class="doc-footer">
            <div>نظام إدارة الإجازات الإلكتروني — إدارة أرمنت التعليمية</div>
            <div>تم استخراج التقرير في: {{ $generatedAt->format('Y-m-d H:i:s') }}</div>
            <div>صفحة التقرير الرسمي الشامل</div>
        </div>

    </div>

</body>
</html>
