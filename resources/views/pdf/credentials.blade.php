<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>دليل بيانات تسجيل الدخول — إدارة أرمنت التعليمية</title>
<style>
  @page {
    margin: 15mm 12mm 15mm 12mm;
  }
  body {
    font-family: 'DejaVu Sans', sans-serif;
    direction: rtl;
    text-align: right;
    font-size: 10px;
    color: #1e293b;
    margin: 0;
    padding: 0;
  }
  .header {
    border-bottom: 2px solid #1e3a5f;
    padding-bottom: 8px;
    margin-bottom: 12px;
  }
  .header table {
    width: 100%;
  }
  .header td {
    vertical-align: middle;
  }
  .header .title {
    font-size: 15px;
    font-weight: bold;
    color: #1e3a5f;
    text-align: center;
  }
  .header .subtitle {
    font-size: 11px;
    color: #475569;
    text-align: center;
    margin-top: 3px;
  }
  .header .meta-right {
    font-size: 9px;
    color: #334155;
    text-align: right;
    width: 35%;
  }
  .header .meta-left {
    font-size: 9px;
    color: #334155;
    text-align: left;
    width: 35%;
  }
  .notice-box {
    background-color: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 4px;
    padding: 6px 10px;
    margin-bottom: 12px;
    font-size: 9px;
    color: #166534;
  }
  .section-title {
    font-size: 12px;
    font-weight: bold;
    color: #1e3a5f;
    background-color: #e2e8f0;
    padding: 5px 8px;
    border-radius: 3px;
    margin-top: 10px;
    margin-bottom: 6px;
  }
  table.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 14px;
  }
  table.data-table thead {
    display: table-header-group;
  }
  table.data-table tr {
    page-break-inside: avoid;
  }
  table.data-table th {
    background-color: #1e3a5f;
    color: #ffffff;
    font-size: 9px;
    font-weight: bold;
    padding: 5px 4px;
    text-align: center;
    border: 1px solid #1e3a5f;
  }
  table.data-table td {
    padding: 4px 5px;
    font-size: 8.5px;
    border: 1px solid #cbd5e1;
    text-align: right;
  }
  table.data-table tr:nth-child(even) {
    background-color: #f8fafc;
  }
  .center {
    text-align: center !important;
  }
  .ltr {
    direction: ltr !important;
    text-align: left !important;
    font-family: 'DejaVu Sans', monospace;
  }
  .badge {
    background-color: #dbeafe;
    color: #1e40af;
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 8px;
  }
  .footer {
    position: fixed;
    bottom: -10mm;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 8px;
    color: #94a3b8;
    border-top: 1px solid #e2e8f0;
    padding-top: 3px;
  }
</style>
</head>
<body>

<div class="header">
  <table>
    <tr>
      <td class="meta-right">
        <strong>وزارة التربية والتعليم والتعليم الفني</strong><br>
        مديرية التربية والتعليم بالأقصر<br>
        إدارة أرمنت التعليمية
      </td>
      <td>
        <div class="title">بيانات تسجيل الدخول لحسابات النظام</div>
        <div class="subtitle">نظام إدارة الإجازات الإلكتروني (Leave Management System)</div>
      </td>
      <td class="meta-left">
        <strong>تاريخ التصدير:</strong> {{ $generatedAt }}<br>
        <strong>إجمالي الحسابات:</strong> {{ count($adminAccounts) + count($schools) }} حساب<br>
        <strong>الرابط:</strong> {{ $appUrl }}
      </td>
    </tr>
  </table>
</div>

<div class="notice-box">
  <strong>تعليمات أمنية هامة:</strong>
  يمكن للمستخدمين تسجيل الدخول باستخدام <strong>البريد الإلكتروني</strong> أو <strong>رقم الهاتف</strong> وكلمة المرور المسجلة. يُرجى تغيير كلمة المرور عند أول تسجيل دخول للحفاظ على سرية البيانات.
</div>

{{-- 1. حسابات الإدارة والمديرية --}}
<div class="section-title">أولاً: حسابات المديرية وإدارة أرمنت التعليمية (الإشراف والاعتماد)</div>
<table class="data-table">
  <thead>
    <tr>
      <th style="width: 4%;">#</th>
      <th style="width: 20%;">المسمى والجهة</th>
      <th style="width: 14%;">الدور بالمنظومة</th>
      <th style="width: 28%;">البريد الإلكتروني</th>
      <th style="width: 14%;">رقم الهاتف</th>
      <th style="width: 20%;">كلمة المرور</th>
    </tr>
  </thead>
  <tbody>
    @foreach($adminAccounts as $idx => $admin)
    <tr>
      <td class="center">{{ $idx + 1 }}</td>
      <td><strong>{{ $admin['name'] }}</strong><br><span style="color:#64748b; font-size:7.5px;">{{ $admin['org'] }}</span></td>
      <td><span class="badge">{{ $admin['role'] }}</span></td>
      <td class="ltr">{{ $admin['email'] }}</td>
      <td class="center ltr">{{ $admin['phone'] }}</td>
      <td class="center ltr"><strong>{{ $admin['password'] }}</strong></td>
    </tr>
    @endforeach
  </tbody>
</table>

{{-- 2. حسابات مديري المدارس --}}
<div class="section-title">ثانياً: حسابات مديري المدارس التابعة لإدارة أرمنت التعليمية (63 مدرسة)</div>
<table class="data-table">
  <thead>
    <tr>
      <th style="width: 4%;">م</th>
      <th style="width: 7%;">الكود</th>
      <th style="width: 28%;">اسم المدرسة</th>
      <th style="width: 9%;">المرحلة</th>
      <th style="width: 26%;">البريد الإلكتروني (حساب المدير)</th>
      <th style="width: 13%;">رقم الهاتف</th>
      <th style="width: 13%;">كلمة المرور</th>
    </tr>
  </thead>
  <tbody>
    @foreach($schools as $idx => $school)
    <tr>
      <td class="center">{{ $idx + 1 }}</td>
      <td class="center ltr"><strong>{{ strtoupper($school['code']) }}</strong></td>
      <td>{{ $school['name'] }}</td>
      <td class="center">{{ $school['category'] }}</td>
      <td class="ltr">{{ $school['manager']['email'] }}</td>
      <td class="center ltr">{{ $school['manager']['phone'] }}</td>
      <td class="center ltr"><strong>{{ $school['manager']['password'] }}</strong></td>
    </tr>
    @endforeach
  </tbody>
</table>

</body>
</html>
