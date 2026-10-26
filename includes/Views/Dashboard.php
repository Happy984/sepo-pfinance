<?php

declare(strict_types=1);

namespace SepoFinance\Views;

use SepoFinance\Admin\Menu;
use SepoFinance\Ledger;
use SepoFinance\MembershipSync;
use SepoFinance\Parse;
use SepoFinance\Recurring;
use SepoFinance\Reports;
use SepoFinance\Settings;
use SepoFinance\Store;

defined('ABSPATH') || exit;

/** The state of things now: tiles, twelve months of money in and out, and what needs doing. */
final class Dashboard
{
    public static function render(string $screen): void
    {
        $cur = Settings::currency();
        $s = Reports::summary();
        $accounts = Store::all('accounts');
        $balances = Ledger::balances();

        $h = Ui::head(__('Finance', 'sepo-finance'), esc_html__('Money in, money out, and where things stand.', 'sepo-finance'),
            '<a class="sfin-btn sfin-btn--primary" href="' . esc_url(Menu::url('ledger')) . '">' . esc_html__('Record money in or out', 'sepo-finance') . '</a>');

        if ($accounts === []) {
            echo $h . Ui::empty('<p><strong>' . esc_html__('Start with an account.', 'sepo-finance') . '</strong></p><p>' . esc_html__('Add each place money sits: a bank account, a mobile money wallet, the petty cash tin. Give each the balance it had on the day you start; every balance after that is worked out from what you record.', 'sepo-finance') . '</p><p><a class="sfin-btn sfin-btn--primary" href="' . esc_url(Menu::url('accounts')) . '">' . esc_html__('Add an account', 'sepo-finance') . '</a></p>'); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }

        if ($s['foreign_accounts'] > 0) {
            /* translators: 1: count, 2: currency */
            $h .= Ui::notice(esc_html(sprintf(_n('%1$d account is held in another currency and is left out of the %2$s totals.', '%1$d accounts are held in other currencies and are left out of the %2$s totals.', $s['foreign_accounts'], 'sepo-finance'), $s['foreign_accounts'], $cur)));
        }
        if (MembershipSync::available() && (int) Settings::get('membership_sync') === 1 && !MembershipSync::enabled()) {
            $h .= Ui::notice(esc_html__('Payments confirmed in Sepo Membership are not reaching the ledger yet: choose the account they go into.', 'sepo-finance') . ' <a href="' . esc_url(Menu::url('settings')) . '">' . esc_html__('Settings', 'sepo-finance') . '</a>', 'warn');
        }

        $h .= '<div class="sfin-tiles">'
            . Ui::tile(__('Cash position', 'sepo-finance'), Ui::signed($s['position'], $cur), 'live', Menu::url('reports', ['view' => 'position']))
            . Ui::tile(__('In this month', 'sepo-finance'), Ui::money($s['income_month'], $cur), '', Menu::url('reports'))
            . Ui::tile(__('Out this month', 'sepo-finance'), Ui::money($s['expenses_month'], $cur), '', Menu::url('reports'))
            . Ui::tile(__('Net this month', 'sepo-finance'), Ui::signed($s['net_month'], $cur), $s['net_month'] < 0 ? 'alert' : 'good')
            . Ui::tile(__('Customers owe, overdue', 'sepo-finance'), Ui::money($s['receivables_overdue'], $cur), $s['receivables_overdue'] > 0 ? 'alert' : '', Menu::url('invoices', ['show' => 'overdue']))
            . Ui::tile(__('Bills due within a week', 'sepo-finance'), Ui::money($s['payables_soon'], $cur), $s['payables_soon'] > 0 ? 'warn' : '', Menu::url('bills', ['show' => 'open']))
            . '</div>';

        $from = gmdate('Y-m-01', strtotime(substr(current_time('Y-m-d'), 0, 7) . '-01 -11 months 12:00:00 UTC') ?: time());
        $cf = Reports::cashflow($from, current_time('Y-m-d'));
        $h .= '<section class="sfin-panel">' . ($cf['periods'] !== [] ? Ui::columns($cf['periods'], __('Money in and out, last 12 months', 'sepo-finance')) : '<h3>' . esc_html__('Money in and out', 'sepo-finance') . '</h3><p class="sfin-muted">' . esc_html__('Nothing recorded in the last twelve months yet.', 'sepo-finance') . '</p>') . '</section>';

        [$ms, $me] = Parse::monthBounds();
        $cats = Reports::categories($ms, $me, 'expense');
        $h .= '<div class="sfin-columns"><section class="sfin-panel"><h3>' . esc_html__('Where money went this month', 'sepo-finance') . '</h3>'
            . ($cats === [] ? '<p class="sfin-muted">' . esc_html__('No spending recorded this month.', 'sepo-finance') . '</p>' : Ui::bars(array_slice($cats, 0, 8), $cur))
            . '</section>';

        $h .= '<section class="sfin-panel"><h3>' . esc_html__('Needs attention', 'sepo-finance') . '</h3>' . self::attention() . '</section></div>';

        $h .= '<section class="sfin-panel"><h3>' . esc_html__('Accounts', 'sepo-finance') . '</h3><table class="sfin-table"><thead><tr><th>' . esc_html__('Account', 'sepo-finance') . '</th><th>' . esc_html__('Type', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Balance', 'sepo-finance') . '</th></tr></thead><tbody>';
        foreach ($accounts as $a) {
            if (!empty($a['archived'])) {
                continue;
            }
            $h .= '<tr><td><a href="' . esc_url(Menu::url('ledger', ['account' => $a['id'], 'from' => '2000-01-01'])) . '">' . Ui::e($a['name']) . '</a></td><td>' . Ui::e(Ui::accountType((string) $a['type'])) . '</td><td class="sfin-num">' . Ui::signed($balances[$a['id']] ?? 0, (string) $a['currency']) . '</td></tr>';
        }
        $h .= '</tbody></table></section>';
        echo $h; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }

    private static function attention(): string
    {
        $items = [];
        $today = current_time('Y-m-d');
        $soon = gmdate('Y-m-d', (strtotime($today . ' 12:00:00 UTC') ?: time()) + 7 * 86400);
        $names = Store::names('counterparties');
        foreach (Recurring::due() as $r) {
            /* translators: %s: rule name */
            $items[] = [$r['next_due'], '<a href="' . esc_url(Menu::url('recurring')) . '">' . esc_html(sprintf(__('%s is due to be posted', 'sepo-finance'), $r['name'])) . '</a>', Ui::money($r['t_amount']), 'warn'];
        }
        foreach (Store::all('invoices') as $d) {
            if (Ledger::isOverdue($d, $today)) {
                /* translators: 1: number, 2: customer */
                $items[] = [$d['date_due'], '<a href="' . esc_url(Menu::url('invoices', ['show' => 'overdue'])) . '">' . esc_html(sprintf(__('Invoice %1$s to %2$s is overdue', 'sepo-finance'), $d['number'], $names[$d['counterparty_id']] ?? '')) . '</a>', Ui::money(Ledger::outstanding($d), (string) $d['currency']), 'bad'];
            }
        }
        foreach (Store::all('bills') as $d) {
            if (!in_array($d['status'], ['paid', 'cancelled', 'draft'], true) && $d['date_due'] !== '' && $d['date_due'] <= $soon && Ledger::outstanding($d) > 0) {
                $late = $d['date_due'] < $today;
                /* translators: 1: number, 2: supplier, 3: date */
                $items[] = [$d['date_due'], '<a href="' . esc_url(Menu::url('bills', ['show' => 'open'])) . '">' . esc_html(sprintf($late ? __('Bill %1$s from %2$s was due %3$s', 'sepo-finance') : __('Bill %1$s from %2$s is due %3$s', 'sepo-finance'), $d['number'], $names[$d['counterparty_id']] ?? '', Parse::show((string) $d['date_due']))) . '</a>', Ui::money(Ledger::outstanding($d), (string) $d['currency']), $late ? 'bad' : 'warn'];
            }
        }
        if ($items === []) {
            return '<p class="sfin-muted">' . esc_html__('Nothing overdue, nothing due this week.', 'sepo-finance') . '</p>';
        }
        usort($items, static fn(array $a, array $b): int => strcmp((string) $a[0], (string) $b[0]));
        $h = '<ul class="sfin-alerts">';
        foreach (array_slice($items, 0, 10) as [, $label, $amount, $kind]) {
            $h .= '<li class="sfin-alert sfin-alert--' . esc_attr($kind) . '"><span>' . $label . '</span><b>' . $amount . '</b></li>';
        }
        return $h . '</ul>';
    }
}
