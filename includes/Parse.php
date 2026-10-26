<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * Reading amounts and dates the way people type them, and the date helpers
 * every report shares.
 *
 * DATES ARE DAY FIRST. 03/04/2026 is 3 April. strtotime() would read it as
 * 4 March, and booking an entry into the wrong month is the worst mistake a
 * ledger can make, so day-first forms are matched before strtotime is allowed
 * near the text.
 */
final class Parse
{
    /**
     * An amount: 1200, 1,200, 1 200, K1,200.50, ZMW 1200.50, (450), -450,
     * 450-, 1.200,50. Null when nothing numeric is there, so "not given" and
     * "zero" stay different. The sign is kept.
     *
     * When both separators appear, the rightmost is the decimal point. When
     * only a comma appears it is read as grouping: "1,200" is one thousand two
     * hundred far more often than it is one point two.
     *
     * @param mixed $value
     */
    public static function amount($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 4);
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $raw, $m) === 1) {
            $negative = true;
            $raw = $m[1];
        }
        if (preg_match('/^(.*)-\s*$/', $raw, $m) === 1) {
            $negative = true;
            $raw = $m[1];
        }
        if (str_starts_with(ltrim($raw), '-')) {
            $negative = true;
        }
        $clean = preg_replace('/[^0-9.,]/', '', $raw) ?? '';
        if ($clean === '' || preg_match('/[0-9]/', $clean) !== 1) {
            return null;
        }
        $lastDot = strrpos($clean, '.');
        $lastComma = strrpos($clean, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $decimalAt = max($lastDot, $lastComma);
        } elseif ($lastDot !== false) {
            $decimalAt = substr_count($clean, '.') > 1 ? false : $lastDot;
        } else {
            $decimalAt = false;
        }
        if ($decimalAt === false) {
            $number = (float) preg_replace('/[.,]/', '', $clean);
        } else {
            $whole = preg_replace('/[.,]/', '', substr($clean, 0, $decimalAt)) ?? '';
            $frac = preg_replace('/[^0-9]/', '', substr($clean, $decimalAt + 1)) ?? '';
            $number = (float) (($whole === '' ? '0' : $whole) . '.' . ($frac === '' ? '0' : $frac));
        }
        $number = round($number, 4);
        return $negative ? -$number : $number;
    }

    /**
     * A date: Y-m-d, d/m/Y, d-m-Y, d.m.Y, d/m (this year), two-digit years
     * (2000-2099), today, yesterday, tomorrow, +3d, -2w, -1m, +1y, and month
     * names ("12 Jun 2026"). Null when it is not a date.
     */
    public static function date(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        $today = current_time('Y-m-d');
        $base = strtotime($today . ' 12:00:00 UTC') ?: time();
        if (in_array($value, ['today', 'now'], true)) {
            return $today;
        }
        if ($value === 'yesterday') {
            return gmdate('Y-m-d', $base - 86400);
        }
        if ($value === 'tomorrow') {
            return gmdate('Y-m-d', $base + 86400);
        }
        if (preg_match('/^([+-])\s*(\d{1,3})\s*([dwmy])$/', $value, $m) === 1) {
            $units = ['d' => 'day', 'w' => 'week', 'm' => 'month', 'y' => 'year'];
            $ts = strtotime($m[1] . $m[2] . ' ' . $units[$m[3]], $base);
            return $ts === false ? null : gmdate('Y-m-d', $ts);
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]) : null;
        }
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})(?:[/.\-](\d{2}|\d{4}))?$#', $value, $m) === 1) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) substr($today, 0, 4);
            if ($year < 100) {
                $year += 2000;
            }
            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
        }
        if (preg_match('/[a-z]{3}/', $value) !== 1) {
            return null;
        }
        $ts = strtotime($value . ' UTC', $base);
        if ($ts === false) {
            return null;
        }
        $year = (int) gmdate('Y', $ts);
        return $year < 2000 || $year > 2100 ? null : gmdate('Y-m-d', $ts);
    }

    /** A date or a fallback (today when not given). */
    public static function dateOr(string $value, ?string $fallback = null): string
    {
        return self::date($value) ?? ($fallback ?? current_time('Y-m-d'));
    }

    /**
     * The next occurrence of a recurring rule. The day is clamped to the
     * length of the target month, so rent due on the 31st stays at month end
     * instead of drifting to the 3rd.
     */
    public static function nextDue(string $from, string $frequency): string
    {
        $ts = strtotime($from . ' 12:00:00 UTC') ?: time();
        if ($frequency === 'weekly') {
            return gmdate('Y-m-d', $ts + 7 * 86400);
        }
        $months = match ($frequency) {
            'quarterly' => 3,
            'yearly'    => 12,
            default     => 1,
        };
        $day = (int) gmdate('j', $ts);
        $month = (int) gmdate('n', $ts) + $months;
        $year = (int) gmdate('Y', $ts);
        while ($month > 12) {
            $month -= 12;
            $year++;
        }
        $last = (int) gmdate('t', gmmktime(0, 0, 0, $month, 1, $year));
        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $last));
    }

    /** @return array{0:string,1:string} first and last day of the month containing the date, or of "YYYY-MM" */
    public static function monthBounds(string $anyDate = ''): array
    {
        $anyDate = trim($anyDate) !== '' ? trim($anyDate) : current_time('Y-m-d');
        if (preg_match('#^(\d{4})-(\d{2})$#', $anyDate, $m) === 1) {
            $first = $m[1] . '-' . $m[2] . '-01';
        } else {
            $first = substr($anyDate, 0, 7) . '-01';
        }
        $ts = strtotime($first . ' 12:00:00 UTC') ?: time();
        return [$first, gmdate('Y-m-t', $ts)];
    }

    /** @return array{0:string,1:string} the fiscal year containing the date */
    public static function fiscalBounds(string $anyDate = ''): array
    {
        $anyDate = $anyDate !== '' ? $anyDate : current_time('Y-m-d');
        $md = preg_match('#^(\d{2})-(\d{2})$#', trim((string) Settings::get('fiscal_start')), $m) === 1 ? $m[1] . '-' . $m[2] : '01-01';
        $year = (int) substr($anyDate, 0, 4);
        $start = $year . '-' . $md;
        if ($anyDate < $start) {
            $start = ($year - 1) . '-' . $md;
        }
        $end = gmdate('Y-m-d', (strtotime($start . ' +1 year 12:00:00 UTC') ?: time()) - 86400);
        return [$start, $end];
    }

    /** @param mixed $v */
    public static function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
    }

    /** An amount for display: currency code and grouped figure, unsigned. */
    public static function money($amount, string $currency = ''): string
    {
        $currency = $currency !== '' ? $currency : Settings::currency();
        return $currency . ' ' . number_format_i18n(abs((float) $amount), Settings::decimals());
    }

    /** The same, with a minus sign when negative. */
    public static function signed($amount, string $currency = ''): string
    {
        return ((float) $amount < -0.004 ? '-' : '') . self::money($amount, $currency);
    }

    /** A date as the site shows dates. */
    public static function show(string $ymd): string
    {
        if ($ymd === '' || str_starts_with($ymd, '1970')) {
            return '';
        }
        $ts = strtotime($ymd . ' 12:00:00 UTC');
        return $ts ? wp_date((string) get_option('date_format'), $ts, new \DateTimeZone('UTC')) : $ymd;
    }
}
