<?php
// analytics/lib/Jalali.php - Exact Gregorian <-> Jalali conversion and calendar helpers
// used by the sales analytics engine for bucketing (months, seasons, years).

final class Jalali {
    public const MONTHS = [1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
                           7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'];
    public const SEASONS = [1 => 'بهار', 2 => 'تابستان', 3 => 'پاییز', 4 => 'زمستان'];
    // PHP date('w'): 0=Sunday … 6=Saturday. Persian week starts on Saturday.
    public const WEEKDAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

    private static array $cache = [];

    /** @return int[] [jy, jm, jd] */
    public static function fromGregorian(int $gy, int $gm, int $gd): array {
        $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }

    /** @return int[] [gy, gm, gd] */
    public static function toGregorian(int $jy, int $jm, int $jd): array {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd
              + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) $days++;
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $leap = (($gy % 4 == 0) && ($gy % 100 != 0)) || ($gy % 400 == 0);
        $monthDays = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 0; $gm < 13 && $gd > $monthDays[$gm]; $gm++) {
            $gd -= $monthDays[$gm];
        }
        return [$gy, $gm, $gd];
    }

    /** Jalali parts for an ISO (Y-m-d) date, memoized. @return int[] [jy, jm, jd] */
    public static function parts(string $iso): array {
        if (isset(self::$cache[$iso])) return self::$cache[$iso];
        $y = (int)substr($iso, 0, 4); $m = (int)substr($iso, 5, 2); $d = (int)substr($iso, 8, 2);
        return self::$cache[$iso] = self::fromGregorian($y, $m, $d);
    }

    public static function iso(int $jy, int $jm, int $jd): string {
        [$gy, $gm, $gd] = self::toGregorian($jy, $jm, $jd);
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    public static function isLeap(int $jy): bool {
        // Esfand 30 exists only when it does not roll over into Farvardin 1 of the next year.
        [$y, $m] = self::parts(self::iso($jy, 12, 30));
        return $y === $jy && $m === 12;
    }

    public static function monthLength(int $jy, int $jm): int {
        if ($jm <= 6) return 31;
        if ($jm <= 11) return 30;
        return self::isLeap($jy) ? 30 : 29;
    }

    /** "1404/05/12" */
    public static function format(string $iso): string {
        if (!$iso || strlen($iso) < 10) return '';
        [$jy, $jm, $jd] = self::parts(substr($iso, 0, 10));
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }

    /** "12 مرداد 1404" */
    public static function longLabel(string $iso): string {
        [$jy, $jm, $jd] = self::parts($iso);
        return $jd . ' ' . self::MONTHS[$jm] . ' ' . $jy;
    }

    public static function monthLabel(int $jy, int $jm): string {
        return self::MONTHS[$jm] . ' ' . $jy;
    }

    /** Persian weekday index: 0=Saturday … 6=Friday */
    public static function weekdayIndex(string $iso): int {
        $w = (int)date('w', strtotime($iso));
        return ($w + 1) % 7;
    }

    /** Shift a Gregorian date by whole Jalali years, clamping Esfand 30 on non-leap years. */
    public static function shiftYears(string $iso, int $years): string {
        [$jy, $jm, $jd] = self::parts($iso);
        $ny = $jy + $years;
        $jd = min($jd, self::monthLength($ny, $jm));
        return self::iso($ny, $jm, $jd);
    }
}
