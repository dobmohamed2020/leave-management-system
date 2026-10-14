<?php

namespace App\Services;

use Carbon\Carbon;

class FiscalYearService
{
    /**
     * Get the fiscal year key (start year) for a given date or now.
     * Year runs from July 1st (01-07) to June 30th (30-06).
     * e.g., October 2026 -> 2026 (for 2026/2027)
     * e.g., March 2026   -> 2025 (for 2025/2026)
     */
    public function fiscalYearFor(?Carbon $date = null): int
    {
        $date = $date ? $date->copy() : now();
        return $date->month >= 7 ? $date->year : $date->year - 1;
    }

    public function currentFiscalYear(): int
    {
        return $this->fiscalYearFor();
    }

    /**
     * Human-readable label for a fiscal year.
     * e.g. 2026 -> "2026 / 2027"
     */
    public function label(int $fiscalYear): string
    {
        $next = $fiscalYear + 1;
        return "{$fiscalYear} / {$next}";
    }

    public function startDate(int $fiscalYear): Carbon
    {
        return Carbon::create($fiscalYear, 7, 1, 0, 0, 0);
    }

    public function endDate(int $fiscalYear): Carbon
    {
        return Carbon::create($fiscalYear + 1, 6, 30, 23, 59, 59);
    }

    public function isLastDayOfFiscalYear(?Carbon $date = null): bool
    {
        $date = $date ? $date->copy() : now();
        return $date->month === 6 && $date->day === 30;
    }
}
