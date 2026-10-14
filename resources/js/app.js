import './bootstrap';

// تحويل الأرقام العربية والمشرقية (٠-٩) تلقائياً وفورياً إلى أرقام إنجليزية (0-9) في جميع النماذج
(function () {
    const arabicNumeralsMap = {
        '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
        '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
        '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
        '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9'
    };
    const arabicRegex = /[٠-٩۰-۹]/g;

    function convertToEnglish(str) {
        return str.replace(arabicRegex, char => arabicNumeralsMap[char] || char);
    }

    function sanitizeInput(el) {
        if (!el || typeof el.value !== 'string') return;
        if (arabicRegex.test(el.value)) {
            const oldStart = el.selectionStart;
            const oldEnd = el.selectionEnd;
            el.value = convertToEnglish(el.value);
            if (oldStart !== null && oldEnd !== null) {
                try {
                    el.setSelectionRange(oldStart, oldEnd);
                } catch (e) {}
            }
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    document.addEventListener('keydown', function (e) {
        if (e.key && arabicNumeralsMap[e.key]) {
            const target = e.target;
            if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') && !target.readOnly && !target.disabled) {
                e.preventDefault();
                const enDigit = arabicNumeralsMap[e.key];
                const start = target.selectionStart ?? target.value.length;
                const end = target.selectionEnd ?? target.value.length;
                const val = target.value;
                target.value = val.substring(0, start) + enDigit + val.substring(end);
                try {
                    target.setSelectionRange(start + 1, start + 1);
                } catch (err) {}
                target.dispatchEvent(new Event('input', { bubbles: true }));
                target.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }, true);

    document.addEventListener('input', function (e) {
        if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) {
            sanitizeInput(e.target);
        }
    }, true);

    document.addEventListener('paste', function (e) {
        if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) {
            setTimeout(() => sanitizeInput(e.target), 0);
        }
    }, true);
})();
