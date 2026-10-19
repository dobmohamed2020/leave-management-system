# إعداد Cloudflare لـ leave.rtltec.com

## SSL/TLS

- **Encryption mode:** Full (strict)
- **Always Use HTTPS:** On
- **Minimum TLS Version:** 1.2
- **HSTS:** Enable بعد التأكد من عمل HTTPS (اختياري: Preload بحذر)

## DNS

- تفعيل **DNSSEC** عند مسجل النطاق إن أمكن
- مراقبة تنبيهات تغيير سجلات DNS في Cloudflare

## WAF

- **Managed rules:** OWASP Core Ruleset (أو Cloudflare Managed)
- **Custom rule — rate limit تسجيل الدخول:**
  - URI Path contains `/api/auth/login` OR `/admin/login`
  - Rate: 10 requests / 1 minute / IP → Block أو Challenge
- **Custom rule — التحقق العام:**
  - URI Path starts with `/verify/`
  - Rate: 60 requests / 1 minute / IP

## Cache

- **Bypass cache** لـ:
  - `/admin/*`
  - `/api/*`
  - `/livewire/*`
- لا تخزّن مؤقتاً استجابات تحتوي cookies/session

## Bot / DDoS

- **Bot Fight Mode** أو **Super Bot Fight** حسب الخطة
- **Under Attack Mode** فقط أثناء هجوم نشط

## اختياري

- **Turnstile** على `/verify/*` إذا زاد المسح الآلي
- **Page Rules / Cache Rules** لرفع `Cache-Control: no-store` على المسارات الحساسة

## بعد النشر

تحقق من الرؤوس عبر [securityheaders.com](https://securityheaders.com) وأن `Strict-Transport-Security` يظهر على HTTPS.
