<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware لتحويل كافة الأرقام العربية والمشرقية (٠-٩ و ۰-۹) المدخلة إلى أرقام إنجليزية (0-9)
 * في جميع الطلبات (Livewire / API / Form Requests) لضمان حفظ ومعالجة الأرقام بالإنجليزية فقط.
 */
class NormalizeArabicNumbers
{
    private static array $arabicToEnglish = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $input = $request->all();
        if (! empty($input)) {
            $normalized = $this->normalizeArray($input);
            $request->replace($normalized);
        }

        return $next($request);
    }

    /**
     * تحويل الأرقام التكراري داخل المصفوفات والنصوص.
     */
    private function normalizeArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->normalizeArray($value);
            } elseif (is_string($value)) {
                $data[$key] = strtr($value, self::$arabicToEnglish);
            }
        }

        return $data;
    }
}
