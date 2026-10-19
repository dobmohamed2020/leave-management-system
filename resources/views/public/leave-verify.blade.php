<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التحقق من طلب الإجازة - رقم {{ $request->number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, sans-serif;
            direction: rtl;
            max-width: 700px;
            margin: 40px auto;
            padding: 20px;
            background: #f9fafb;
            color: #111827;
        }
        h1 {
            font-size: 1.5rem;
            margin-bottom: 16px;
            color: #1e3a5f;
            border-bottom: 2px solid #1e3a5f;
            padding-bottom: 8px;
        }
        .verified-badge {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
            border-radius: 6px;
            margin-bottom: 20px;
            padding: 12px 16px;
            font-size: 0.95rem;
        }
        .card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 16px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 10px 12px;
            text-align: right;
            border-bottom: 1px solid #f3f4f6;
        }
        th {
            font-weight: 600;
            color: #6b7280;
            width: 35%;
            background: #f9fafb;
        }
        td {
            color: #111827;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .rejection-note {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #7f1d1d;
            border-radius: 6px;
            padding: 12px 16px;
            margin-top: 16px;
        }
        .footer-note {
            margin-top: 24px;
            text-align: center;
            font-size: 0.8rem;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <h1>التحقق من طلب الإجازة</h1>

    <div class="verified-badge">
        ✓ هذا الطلب تم التحقق منه إلكترونياً عبر نظام إدارة الإجازات
    </div>

    <div class="card">
        <table>
            <tr>
                <th>رقم الطلب</th>
                <td><strong>{{ $request->number }}</strong></td>
            </tr>
            <tr>
                <th>اسم الموظف</th>
                <td>{{ $request->employee?->full_name ?? '—' }}</td>
            </tr>
            <tr>
                <th>نوع الإجازة</th>
                <td>{{ $request->leaveType?->name ?? '—' }}</td>
            </tr>
            <tr>
                <th>من</th>
                <td>{{ $request->start_date?->toDateString() ?? '—' }}</td>
            </tr>
            <tr>
                <th>إلى</th>
                <td>{{ $request->end_date?->toDateString() ?? '—' }}</td>
            </tr>
            <tr>
                <th>عدد الأيام</th>
                <td>{{ (int) $request->days }}</td>
            </tr>
            <tr>
                <th>الحالة</th>
                <td>
                    <span class="status-badge">{{ $request->displayStatusLabel() }}</span>
                </td>
            </tr>
            <tr>
                <th>تاريخ القرار</th>
                <td>{{ $request->decided_at?->toDateString() ?? '—' }}</td>
            </tr>
        </table>
    </div>

    <p class="footer-note">
        نظام إدارة الإجازات — وزارة التربية والتعليم
    </p>
</body>
</html>
