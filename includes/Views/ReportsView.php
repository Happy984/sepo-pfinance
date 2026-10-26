<?php

declare(strict_types=1);

namespace SepoFinance\Views;

use SepoFinance\Admin\Menu;
use SepoFinance\Parse;
use SepoFinance\Reports;
use SepoFinance\Settings;

defined('ABSPATH') || exit;

/**
 * The reports, one tab each, over a date range chosen with presets or typed.
 * Every report downloads as CSV or JSON for the range on screen, and prints.
 * Pending entries are left out of all of them: money that has not moved.
 */
final class ReportsView
{
    public static function render(string $screen): void
    {
        $cur = Settings::currency();
        $views = Reports::views();
        $view = Ui::query('view', 'pnl');
        $view = isset($views[$view]) ? $view : 'pnl';
        $today = current_time('Y-m-d');
        [$ms, $me] = Parse::monthBounds();
        $wide = in_array($view, ['cashflow', 'tax'], true);
        $from = Parse::dateOr(Ui::query('from'), $wide ? substr($today, 0, 4) . '-01-01' : $ms);
        $to = Parse::dateOr(Ui::query('to'), $wide ? $today : $me);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $type = Ui::query('type') === 'income' ? 'income' : 'expense';
        $pointInTime = in_array($view, ['position', 'aged'], true);
        $data = Reports::data($view, $from, $to, $type);

        $args = ['view' => $view, 'from' => $from, 'to' => $to] + ($view === 'categories' ? ['type' => $type] : []);
        $exp = static fn(string $fmt): string => wp_nonce_url(add_query_arg($args + ['action' => 'sfin_export', 'format' => $fmt], admin_url('admin-post.php')), 'sfin_export');
        $actions = '<a class="sfin-btn" href="' . esc_url($exp('csv')) . '">' . esc_html__('Download CSV', 'sepo-finance') . '</a>'
            . '<a class="sfin-btn sfin-btn--quiet" href="' . esc_url($exp('json')) . '">' . esc_html__('JSON', 'sepo-finance') . '</a>'
            . '<button type="button" class="sfin-btn sfin-btn--quiet" data-sfin-print>' . esc_html__('Print', 'sepo-finance') . '</button>';
        $h = Ui::head(__('Reports', 'sepo-finance'), esc_html__('Counted on what has actually moved: pending entries are left out until they clear.', 'sepo-finance'), $actions);

        $h .= '<nav class="sfin-tabs" aria-label="' . esc_attr__('Reports', 'sepo-finance') . '">';
        foreach ($views as $k => $l) {
            $h .= '<a class="sfin-tab' . ($k === $view ? ' is-on' : '') . '" href="' . esc_url(Menu::url('reports', ['view' => $k] + (!$pointInTime && !in_array($k, ['position', 'aged'], true) ? ['from' => $from, 'to' => $to] : []))) . '"' . ($k === $view ? ' aria-current="page"' : '') . '>' . esc_html($l) . '</a>';
        }
        $h .= '</nav>';

        // The range: presets, then the two dates.
        $y = (int) substr($today, 0, 4);
        [$fy0, $fy1] = Parse::fiscalBounds();
        [$lm0, $lm1] = Parse::monthBounds(gmdate('Y-m', strtotime($ms . ' -1 month 12:00:00 UTC') ?: time()));
        $qStart = sprintf('%04d-%02d-01', $y, intdiv((int) substr($today, 5, 2) - 1, 3) * 3 + 1);
        $presets = $pointInTime ? [
            __('Today', 'sepo-finance') => [$today, $today],
            __('End of last month', 'sepo-finance') => [$lm1, $lm1],
            __('End of last year', 'sepo-finance') => [($y - 1) . '-12-31', ($y - 1) . '-12-31'],
        ] : [
            __('This month', 'sepo-finance') => [$ms, $me],
            __('Last month', 'sepo-finance') => [$lm0, $lm1],
            __('This quarter', 'sepo-finance') => [$qStart, gmdate('Y-m-t', strtotime($qStart . ' +2 months 12:00:00 UTC') ?: time())],
            __('Year to date', 'sepo-finance') => [$y . '-01-01', $today],
            __('This financial year', 'sepo-finance') => [$fy0, $fy1],
            __('Last year', 'sepo-finance') => [($y - 1) . '-01-01', ($y - 1) . '-12-31'],
        ];
        $h .= '<form class="sfin-filters sfin-noprint" method="get">' . Ui::pageField() . '<input type="hidden" name="view" value="' . esc_attr($view) . '">';
        $h .= '<div class="sfin-chips">';
        foreach ($presets as $label => [$a, $b]) {
            $on = $pointInTime ? $to === $b : ($from === $a && $to === $b);
            $h .= '<a class="sfin-chip' . ($on ? ' is-on' : '') . '" href="' . esc_url(Menu::url('reports', ['view' => $view, 'from' => $a, 'to' => $b] + ($view === 'categories' ? ['type' => $type] : []))) . '">' . esc_html($label) . '</a>';
        }
        $h .= '</div>';
        if (!$pointInTime) {
            $h .= '<label class="sfin-field"><span class="sfin-field__label">' . esc_html__('From', 'sepo-finance') . '</span><input type="date" name="from" value="' . esc_attr($from) . '"></label>';
        } else {
            $h .= '<input type="hidden" name="from" value="' . esc_attr($from) . '">';
        }
        $h .= '<label class="sfin-field"><span class="sfin-field__label">' . esc_html($pointInTime ? __('As of', 'sepo-finance') : __('To', 'sepo-finance')) . '</span><input type="date" name="to" value="' . esc_attr($to) . '"></label>';
        if ($view === 'categories') {
            $h .= '<label class="sfin-field"><span class="sfin-field__label">' . esc_html__('Show', 'sepo-finance') . '</span><select name="type"><option value="expense"' . selected($type, 'expense', false) . '>' . esc_html__('Money out', 'sepo-finance') . '</option><option value="income"' . selected($type, 'income', false) . '>' . esc_html__('Money in', 'sepo-finance') . '</option></select></label>';
        }
        $h .= '<button type="submit" class="sfin-btn">' . esc_html__('Show', 'sepo-finance') . '</button></form>';

        /* translators: 1: report, 2: from, 3: to */
        $range = $pointInTime ? sprintf(__('%1$s as of %2$s', 'sepo-finance'), $views[$view], Parse::show($to)) : sprintf(__('%1$s, %2$s to %3$s', 'sepo-finance'), $views[$view], Parse::show($from), Parse::show($to));
        $company = Settings::companyName();
        $h .= '<section class="sfin-panel sfin-report"><header class="sfin-report__head"><h3>' . esc_html($range) . '</h3>' . ($company !== '' ? '<p class="sfin-muted">' . esc_html($company) . ' · ' . esc_html($cur) . '</p>' : '') . '</header>';
        $h .= match ($view) {
            'cashflow'   => self::cashflow($data, $cur),
            'categories' => self::categories($data, $cur, $type),
            'position'   => self::position($data, $cur),
            'aged'       => self::aged($data, $cur),
            'tax'        => self::tax($data, $cur),
            default      => self::pnl($data, $cur),
        };
        $h .= '</section>';
        echo $h; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }

    private static function nothing(): string
    {
        return '<p class="sfin-muted">' . esc_html__('Nothing recorded in this range.', 'sepo-finance') . '</p>';
    }

    private static function pnl(array $d, string $cur): string
    {
        if ($d['income'] === [] && $d['expenses'] === []) {
            return self::nothing();
        }
        $h = '<table class="sfin-table sfin-table--report"><tbody>';
        foreach (['income' => [__('Income', 'sepo-finance'), $d['total_income'], __('Total income', 'sepo-finance')], 'expenses' => [__('Expenses', 'sepo-finance'), $d['total_expenses'], __('Total expenses', 'sepo-finance')]] as $k => [$label, $total, $totalLabel]) {
            $h .= '<tr class="sfin-table__section"><th colspan="3" scope="colgroup">' . esc_html($label) . '</th></tr>';
            if ($d[$k] === []) {
                $h .= '<tr><td colspan="3" class="sfin-muted">' . esc_html__('None', 'sepo-finance') . '</td></tr>';
            }
            foreach ($d[$k] as $x) {
                /* translators: %d: count */
                $h .= '<tr><td>' . Ui::e($x['label']) . '</td><td class="sfin-sub sfin-num">' . esc_html(sprintf(_n('%d entry', '%d entries', $x['count'], 'sepo-finance'), $x['count'])) . '</td><td class="sfin-num">' . Ui::money($x['amount'], $cur) . '</td></tr>';
            }
            $h .= '<tr class="sfin-table__total"><th scope="row" colspan="2">' . esc_html($totalLabel) . '</th><td class="sfin-num">' . Ui::money($total, $cur) . '</td></tr>';
        }
        $h .= '<tr class="sfin-table__grand"><th scope="row" colspan="2">' . esc_html($d['net'] >= 0 ? __('Surplus', 'sepo-finance') : __('Deficit', 'sepo-finance')) . '</th><td class="sfin-num">' . Ui::signed($d['net'], $cur) . '</td></tr>';
        return $h . '</tbody></table><p class="sfin-sub">' . esc_html__('Transfers between your own accounts are not income or expense, and are left out.', 'sepo-finance') . '</p>';
    }

    private static function cashflow(array $d, string $cur): string
    {
        $h = Ui::columns($d['periods'], __('Money in and out', 'sepo-finance'));
        $h .= '<table class="sfin-table sfin-table--report"><thead><tr><th>' . esc_html__('Period', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('In', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Out', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Net', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Running position', 'sepo-finance') . '</th></tr></thead><tbody>';
        $h .= '<tr class="sfin-table__total"><th scope="row" colspan="4">' . esc_html__('Opening position', 'sepo-finance') . '</th><td class="sfin-num">' . Ui::signed($d['opening'], $cur) . '</td></tr>';
        $run = $d['opening'];
        $ti = 0.0;
        $to = 0.0;
        foreach ($d['periods'] as $p) {
            $run += $p['net'];
            $ti += $p['income'];
            $to += $p['expenses'];
            $label = strlen($p['period']) === 7 ? wp_date('F Y', strtotime($p['period'] . '-15 12:00:00 UTC') ?: time(), new \DateTimeZone('UTC')) : $p['period'];
            $h .= '<tr><td>' . esc_html($label) . '</td><td class="sfin-num sfin-in">' . Ui::money($p['income'], $cur) . '</td><td class="sfin-num sfin-out">' . Ui::money($p['expenses'], $cur) . '</td><td class="sfin-num">' . Ui::signed($p['net'], $cur) . '</td><td class="sfin-num">' . Ui::signed($run, $cur) . '</td></tr>';
        }
        if ($d['periods'] === []) {
            $h .= '<tr><td colspan="5" class="sfin-muted">' . esc_html__('Nothing recorded in this range.', 'sepo-finance') . '</td></tr>';
        }
        $h .= '<tr class="sfin-table__grand"><th scope="row">' . esc_html__('Closing position', 'sepo-finance') . '</th><td class="sfin-num">' . Ui::money($ti, $cur) . '</td><td class="sfin-num">' . Ui::money($to, $cur) . '</td><td class="sfin-num">' . Ui::signed(round($ti - $to, 2), $cur) . '</td><td class="sfin-num">' . Ui::signed($d['closing'], $cur) . '</td></tr>';
        return $h . '</tbody></table><p class="sfin-sub">' . esc_html__('The position counts accounts in the main currency only.', 'sepo-finance') . '</p>';
    }

    private static function categories(array $d, string $cur, string $type): string
    {
        if ($d === []) {
            return self::nothing();
        }
        $total = array_sum(array_column($d, 'amount'));
        $h = Ui::bars($d, $cur);
        $h .= '<table class="sfin-table sfin-table--report"><thead><tr><th>' . esc_html__('Category', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Entries', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Share', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Amount', 'sepo-finance') . '</th></tr></thead><tbody>';
        foreach ($d as $x) {
            $link = $x['id'] !== '' ? '<a href="' . esc_url(Menu::url('ledger', ['category' => $x['id'], 'type' => $type, 'from' => Ui::query('from'), 'to' => Ui::query('to')])) . '">' . Ui::e($x['label']) . '</a>' : Ui::e($x['label']);
            $h .= '<tr><td>' . $link . '</td><td class="sfin-num">' . (int) $x['count'] . '</td><td class="sfin-num">' . esc_html(number_format_i18n($total > 0 ? $x['amount'] / $total * 100 : 0, 1)) . '%</td><td class="sfin-num">' . Ui::money($x['amount'], $cur) . '</td></tr>';
        }
        return $h . '<tr class="sfin-table__grand"><th scope="row" colspan="3">' . esc_html__('Total', 'sepo-finance') . '</th><td class="sfin-num">' . Ui::money($total, $cur) . '</td></tr></tbody></table>';
    }

    private static function position(array $d, string $cur): string
    {
        if ($d['accounts'] === []) {
            return self::nothing();
        }
        $h = '<table class="sfin-table sfin-table--report"><thead><tr><th>' . esc_html__('Account', 'sepo-finance') . '</th><th>' . esc_html__('Type', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Balance', 'sepo-finance') . '</th></tr></thead><tbody>';
        $foreign = false;
        foreach ($d['accounts'] as $a) {
            $other = $a['currency'] !== $cur;
            $foreign = $foreign || $other;
            $h .= '<tr' . ($other ? ' class="sfin-row--muted"' : '') . '><td>' . Ui::e($a['name']) . ($other ? ' <span class="sfin-tag">' . Ui::e($a['currency']) . '</span>' : '') . '</td><td>' . Ui::e(Ui::accountType((string) $a['type'])) . '</td><td class="sfin-num">' . Ui::signed($a['balance'], $a['currency']) . '</td></tr>';
        }
        $h .= '<tr class="sfin-table__grand"><th scope="row" colspan="2">' . esc_html__('Total', 'sepo-finance') . '</th><td class="sfin-num">' . Ui::signed($d['total'], $cur) . '</td></tr></tbody></table>';
        if ($foreign) {
            /* translators: %s: currency */
            $h .= '<p class="sfin-sub">' . esc_html(sprintf(__('Accounts in other currencies are shown but not added to the %s total.', 'sepo-finance'), $cur)) . '</p>';
        }
        return $h;
    }

    private static function aged(array $d, string $cur): string
    {
        $labels = Reports::agedLabels();
        $h = '<div class="sfin-tablewrap"><table class="sfin-table sfin-table--report"><thead><tr><th></th>';
        foreach ($labels as $l) {
            $h .= '<th class="sfin-num">' . esc_html($l) . '</th>';
        }
        $h .= '<th class="sfin-num">' . esc_html__('Total', 'sepo-finance') . '</th></tr></thead><tbody>';
        foreach (['receivables' => [__('Customers owe you', 'sepo-finance'), 'invoices'], 'payables' => [__('You owe suppliers', 'sepo-finance'), 'bills']] as $side => [$label, $screen]) {
            $h .= '<tr><th scope="row"><a href="' . esc_url(Menu::url($screen, ['show' => 'open'])) . '">' . esc_html($label) . '</a></th>';
            foreach (array_keys($labels) as $k) {
                $v = (float) ($d[$side][$k] ?? 0);
                $h .= '<td class="sfin-num' . ($k !== 'current' && $v > 0 ? ' sfin-out' : '') . '">' . ($v > 0 ? Ui::money($v, $cur) : '<span class="sfin-sub">—</span>') . '</td>';
            }
            $h .= '<td class="sfin-num"><strong>' . Ui::money($d[$side]['total'] ?? 0, $cur) . '</strong></td></tr>';
        }
        return $h . '</tbody></table></div><p class="sfin-sub">' . esc_html__('Counted from each document\'s due date. Drafts and cancelled documents are left out.', 'sepo-finance') . '</p>';
    }

    private static function tax(array $d, string $cur): string
    {
        $h = '';
        if ((float) $d['tax_rate'] <= 0) {
            $h .= Ui::notice(esc_html__('No tax rate is set, so there is nothing to work out.', 'sepo-finance') . (current_user_can('manage_options') ? ' <a href="' . esc_url(Menu::url('settings')) . '">' . esc_html__('Set the rate in Settings', 'sepo-finance') . '</a>' : ''), 'warn');
        }
        $rate = number_format_i18n((float) $d['tax_rate'], 2);
        $h .= '<table class="sfin-table sfin-table--report"><tbody>'
            . '<tr><td>' . esc_html__('Income in the range', 'sepo-finance') . '</td><td class="sfin-num">' . Ui::money($d['income'], $cur) . '</td></tr>'
            /* translators: %s: rate */
            . '<tr><td>' . esc_html(sprintf(__('Tax included in income at %s%%', 'sepo-finance'), $rate)) . '</td><td class="sfin-num">' . Ui::money($d['output_tax'], $cur) . '</td></tr>'
            . '<tr><td>' . esc_html__('Expenses in the range', 'sepo-finance') . '</td><td class="sfin-num">' . Ui::money($d['expenses'], $cur) . '</td></tr>'
            /* translators: %s: rate */
            . '<tr><td>' . esc_html(sprintf(__('Tax included in expenses at %s%%', 'sepo-finance'), $rate)) . '</td><td class="sfin-num">' . Ui::money($d['input_tax'], $cur) . '</td></tr>'
            . '<tr class="sfin-table__grand"><th scope="row">' . esc_html($d['net_liability'] >= 0 ? __('Estimated tax to pay', 'sepo-finance') : __('Estimated tax to reclaim', 'sepo-finance')) . '</th><td class="sfin-num">' . Ui::money(abs($d['net_liability']), $cur) . '</td></tr>'
            . '</tbody></table>';
        return $h . Ui::notice(esc_html__('Amounts are treated as including tax: the tax is the part of each amount that the rate accounts for (at 16%, 16/116 of the total), not the rate applied on top. Every entry is counted at the one rate, including any that carry no tax, so treat this as an estimate to check, not a tax return.', 'sepo-finance'));
    }
}
