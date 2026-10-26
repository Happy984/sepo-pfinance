<?php

declare(strict_types=1);

namespace SepoFinance\Views;

use SepoFinance\Admin\Menu;
use SepoFinance\Parse;

defined('ABSPATH') || exit;

/** Pieces every Finance screen shares. */
final class Ui
{
    public static function e($v): string
    {
        return esc_html((string) $v);
    }

    /** A paperclip, for entries with files. */
    public static function clip(): string
    {
        return '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21.4 11.1l-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/></svg>';
    }

    public static function money($amount, string $currency = ''): string
    {
        return esc_html(Parse::money($amount, $currency));
    }

    public static function signed($amount, string $currency = ''): string
    {
        $neg = (float) $amount < -0.004;
        return '<span class="' . ($neg ? 'sfin-neg' : '') . '">' . esc_html(Parse::signed($amount, $currency)) . '</span>';
    }

    /** The screen heading, a line under it, and buttons on the right. */
    public static function head(string $title, string $intro = '', string $actions = ''): string
    {
        $nav = '';
        $items = ['' => __('Dashboard', 'sepo-finance'), 'ledger' => __('Ledger', 'sepo-finance'), 'invoices' => __('Invoices', 'sepo-finance'), 'bills' => __('Bills', 'sepo-finance'), 'recurring' => __('Recurring', 'sepo-finance'), 'reports' => __('Reports', 'sepo-finance')];
        $cur = Menu::current();
        foreach ($items as $slug => $label) {
            $nav .= '<a class="sfin-nav__item' . ($cur === $slug ? ' is-on' : '') . '" href="' . esc_url(Menu::url($slug)) . '"' . ($cur === $slug ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        return '<nav class="sfin-nav" aria-label="' . esc_attr__('Finance', 'sepo-finance') . '">' . $nav . '</nav>'
            . '<header class="sfin-head"><div><h2 class="sfin-title">' . esc_html($title) . '</h2>' . ($intro !== '' ? '<p class="sfin-muted">' . $intro . '</p>' : '') . '</div>'
            . ($actions !== '' ? '<div class="sfin-head__actions">' . $actions . '</div>' : '') . '</header>';
    }

    /** A number tile. $mod: live, alert, good. */
    public static function tile(string $label, string $valueHtml, string $mod = '', string $href = ''): string
    {
        $tag = $href !== '' ? 'a' : 'div';
        return '<' . $tag . ' class="sfin-tile' . ($mod !== '' ? ' sfin-tile--' . esc_attr($mod) : '') . '"' . ($href !== '' ? ' href="' . esc_url($href) . '"' : '') . '><b>' . $valueHtml . '</b><span>' . esc_html($label) . '</span></' . $tag . '>';
    }

    public static function empty(string $html): string
    {
        return '<div class="sfin-empty">' . $html . '</div>';
    }

    public static function notice(string $html, string $kind = 'info'): string
    {
        return '<div class="sfin-note sfin-note--' . esc_attr($kind) . '">' . $html . '</div>';
    }

    /** A record's JSON for a table row, so edit and copy work before any request. */
    public static function data(array $r): string
    {
        $json = wp_json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return esc_attr(is_string($json) ? $json : '{}');
    }

    public static function query(string $key, string $fallback = ''): string
    {
        $v = $_GET[$key] ?? null; // phpcs:ignore WordPress.Security.NonceVerification -- read-only filters
        return is_scalar($v) ? trim(sanitize_text_field(wp_unslash((string) $v))) : $fallback;
    }

    /** Hidden inputs that keep a GET form on this admin page. */
    public static function pageField(): string
    {
        return '<input type="hidden" name="page" value="' . esc_attr(self::query('page')) . '">';
    }

    public static function statusPill(string $status, string $label = ''): string
    {
        $map = ['paid' => 'good', 'balanced' => 'good', 'reconciled' => 'good', 'cleared' => '', 'sent' => 'info', 'draft' => '', 'cancelled' => 'muted', 'pending' => 'warn', 'overdue' => 'bad'];
        return '<span class="sfin-pill sfin-pill--' . esc_attr($map[$status] ?? '') . '">' . esc_html($label !== '' ? $label : self::statusLabel($status)) . '</span>';
    }

    public static function statusLabel(string $s): string
    {
        return [
            'pending' => __('Pending', 'sepo-finance'), 'cleared' => __('Cleared', 'sepo-finance'), 'reconciled' => __('Reconciled', 'sepo-finance'),
            'draft' => __('Draft', 'sepo-finance'), 'sent' => __('Sent', 'sepo-finance'), 'paid' => __('Paid', 'sepo-finance'),
            'overdue' => __('Overdue', 'sepo-finance'), 'cancelled' => __('Cancelled', 'sepo-finance'), 'balanced' => __('Balanced', 'sepo-finance'),
        ][$s] ?? ucfirst($s);
    }

    public static function typeLabel(string $t): string
    {
        return ['income' => __('Income', 'sepo-finance'), 'expense' => __('Expense', 'sepo-finance'), 'transfer' => __('Transfer', 'sepo-finance')][$t] ?? ucfirst($t);
    }

    public static function accountType(string $t): string
    {
        return ['bank' => __('Bank', 'sepo-finance'), 'mobile' => __('Mobile money', 'sepo-finance'), 'cash' => __('Cash', 'sepo-finance'), 'card' => __('Card', 'sepo-finance'), 'other' => __('Other', 'sepo-finance')][$t] ?? ucfirst($t);
    }

    /**
     * A grouped column chart of income and expenses per period, drawn as SVG
     * with a table behind it for screen readers.
     *
     * @param list<array{period:string,income:float,expenses:float}> $periods
     */
    public static function columns(array $periods, string $title): string
    {
        if ($periods === []) {
            return '';
        }
        $max = 0.0;
        foreach ($periods as $p) {
            $max = max($max, $p['income'], $p['expenses']);
        }
        $max = $max > 0 ? $max : 1;
        $nice = self::niceMax($max);
        $w = 720;
        $h = 240;
        $left = 64;
        $bottom = 28;
        $top = 12;
        $plotW = $w - $left - 8;
        $plotH = $h - $bottom - $top;
        $n = count($periods);
        $slot = $plotW / $n;
        $bar = max(3, min(22, ($slot - 8) / 2));
        $svg = '<svg class="sfin-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr($title) . '" preserveAspectRatio="xMidYMid meet">';
        for ($g = 0; $g <= 4; $g++) {
            $y = $top + $plotH - $plotH * $g / 4;
            $svg .= '<line class="sfin-chart__grid" x1="' . $left . '" x2="' . ($w - 8) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '"/>'
                . '<text class="sfin-chart__axis" x="' . ($left - 8) . '" y="' . round($y + 4, 1) . '" text-anchor="end">' . esc_html(self::short($nice * $g / 4)) . '</text>';
        }
        foreach ($periods as $i => $p) {
            $x = $left + $slot * $i + ($slot - 2 * $bar - 3) / 2;
            $hi = $plotH * $p['income'] / $nice;
            $he = $plotH * $p['expenses'] / $nice;
            $svg .= '<rect class="sfin-chart__in" x="' . round($x, 1) . '" y="' . round($top + $plotH - $hi, 1) . '" width="' . round($bar, 1) . '" height="' . round(max(0, $hi), 1) . '" rx="2"><title>' . esc_html($p['period'] . ' · ' . __('Income', 'sepo-finance') . ' ' . Parse::money($p['income'])) . '</title></rect>'
                . '<rect class="sfin-chart__out" x="' . round($x + $bar + 3, 1) . '" y="' . round($top + $plotH - $he, 1) . '" width="' . round($bar, 1) . '" height="' . round(max(0, $he), 1) . '" rx="2"><title>' . esc_html($p['period'] . ' · ' . __('Expenses', 'sepo-finance') . ' ' . Parse::money($p['expenses'])) . '</title></rect>';
            if ($n <= 14 || $i % 2 === 0) {
                $label = strlen($p['period']) === 7 ? wp_date('M', strtotime($p['period'] . '-15 12:00:00 UTC') ?: time(), new \DateTimeZone('UTC')) : $p['period'];
                $svg .= '<text class="sfin-chart__axis" x="' . round($left + $slot * $i + $slot / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle">' . esc_html($label) . '</text>';
            }
        }
        $svg .= '</svg>';
        $table = '<table class="screen-reader-text"><caption>' . esc_html($title) . '</caption><thead><tr><th>' . esc_html__('Period', 'sepo-finance') . '</th><th>' . esc_html__('Income', 'sepo-finance') . '</th><th>' . esc_html__('Expenses', 'sepo-finance') . '</th></tr></thead><tbody>';
        foreach ($periods as $p) {
            $table .= '<tr><td>' . esc_html($p['period']) . '</td><td>' . self::money($p['income']) . '</td><td>' . self::money($p['expenses']) . '</td></tr>';
        }
        $table .= '</tbody></table>';
        return '<figure class="sfin-figure"><figcaption class="sfin-figure__head"><span>' . esc_html($title) . '</span><span class="sfin-legend"><i class="sfin-legend__in"></i>' . esc_html__('Income', 'sepo-finance') . '<i class="sfin-legend__out"></i>' . esc_html__('Expenses', 'sepo-finance') . '</span></figcaption>' . $svg . $table . '</figure>';
    }

    /**
     * Horizontal bars for a category breakdown.
     *
     * @param list<array{label:string,amount:float,count:int}> $rows
     */
    public static function bars(array $rows, string $currency = ''): string
    {
        $total = array_sum(array_column($rows, 'amount'));
        $h = '<ul class="sfin-bars">';
        foreach ($rows as $r) {
            $share = $total > 0 ? $r['amount'] / $total * 100 : 0;
            $h .= '<li><span class="sfin-bars__label">' . esc_html($r['label']) . '</span><span class="sfin-bars__value">' . self::money($r['amount'], $currency) . ' <small>' . esc_html(number_format_i18n($share, 0)) . '%</small></span>'
                . '<span class="sfin-bars__track" aria-hidden="true"><span style="width:' . round($share, 1) . '%"></span></span></li>';
        }
        return $h . '</ul>';
    }

    private static function niceMax(float $v): float
    {
        $exp = 10 ** floor(log10($v));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($v <= $m * $exp) {
                return $m * $exp;
            }
        }
        return 10 * $exp;
    }

    private static function short(float $v): string
    {
        if ($v >= 1000000) {
            return number_format_i18n($v / 1000000, $v >= 10000000 ? 0 : 1) . 'M';
        }
        if ($v >= 1000) {
            return number_format_i18n($v / 1000, $v >= 10000 ? 0 : 1) . 'k';
        }
        return number_format_i18n($v, 0);
    }

    public static function pager(int $total, int $per, int $page, array $args): string
    {
        $pages = (int) ceil($total / max(1, $per));
        if ($pages < 2) {
            return '';
        }
        $h = '<nav class="sfin-pager">';
        if ($page > 1) {
            $h .= '<a class="sfin-btn sfin-btn--sm" href="' . esc_url(add_query_arg($args + ['paged' => $page - 1])) . '">' . esc_html__('Newer', 'sepo-finance') . '</a>';
        }
        /* translators: 1: page, 2: pages */
        $h .= '<span>' . esc_html(sprintf(__('Page %1$d of %2$d', 'sepo-finance'), $page, $pages)) . '</span>';
        if ($page < $pages) {
            $h .= '<a class="sfin-btn sfin-btn--sm" href="' . esc_url(add_query_arg($args + ['paged' => $page + 1])) . '">' . esc_html__('Older', 'sepo-finance') . '</a>';
        }
        return $h . '</nav>';
    }
}
