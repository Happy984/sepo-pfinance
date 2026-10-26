<?php

declare(strict_types=1);

namespace SepoFinance\Views;

use SepoFinance\Admin\Menu;
use SepoFinance\Ledger;
use SepoFinance\Parse;
use SepoFinance\Reconcile;
use SepoFinance\Settings;
use SepoFinance\Store;

defined('ABSPATH') || exit;

/**
 * Tick what the statement shows until the difference is zero. A zero
 * difference means the books and the bank agree; anything else means
 * something is missing, entered twice, or entered wrong.
 *
 * Choosing the account, date and closing balance is part of the address, so
 * a half-finished reconciliation survives a reload or a closed tab.
 */
final class ReconcileView
{
    public static function render(string $screen): void
    {
        $cur = Settings::currency();
        $accounts = Store::all('accounts');
        $h = Ui::head(__('Reconcile', 'sepo-finance'), esc_html__('Check the ledger against a bank or mobile money statement, line by line.', 'sepo-finance'));
        if ($accounts === []) {
            echo $h . Ui::empty('<p>' . esc_html__('Nothing to reconcile yet. Add an account first.', 'sepo-finance') . '</p><p><a class="sfin-btn sfin-btn--primary" href="' . esc_url(Menu::url('accounts')) . '">' . esc_html__('Add an account', 'sepo-finance') . '</a></p>'); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }
        $accountId = Ui::query('account');
        $date = Ui::query('date') !== '' ? Parse::dateOr(Ui::query('date')) : '';
        $given = Ui::query('balance');
        $hasBal = $given !== '' && Parse::amount($given) !== null;
        $stmt = $hasBal ? (float) Parse::amount($given) : 0.0;
        $account = $accountId !== '' ? Store::find('accounts', $accountId) : null;
        $started = $account !== null && $date !== '';

        $opts = '<option value="">' . esc_html__('Choose an account', 'sepo-finance') . '</option>';
        foreach ($accounts as $a) {
            if (empty($a['archived'])) {
                $opts .= '<option value="' . esc_attr((string) $a['id']) . '"' . selected($accountId, (string) $a['id'], false) . '>' . Ui::e($a['name']) . '</option>';
            }
        }
        $h .= '<form class="sfin-toolbar sfin-recform" method="get">' . Ui::pageField()
            . '<label class="sfin-field"><span class="sfin-field__label">' . esc_html__('Account', 'sepo-finance') . '</span><select name="account" required>' . $opts . '</select></label>'
            . '<label class="sfin-field"><span class="sfin-field__label">' . esc_html__('Statement date', 'sepo-finance') . '</span><input type="date" name="date" value="' . esc_attr($date) . '" required></label>'
            . '<label class="sfin-field"><span class="sfin-field__label">' . esc_html__('Closing balance on the statement', 'sepo-finance') . '</span><input type="text" inputmode="decimal" class="sfin-num" name="balance" value="' . esc_attr($given) . '" placeholder="0.00" required></label>'
            . '<button type="submit" class="sfin-btn sfin-btn--primary">' . esc_html__('Open', 'sepo-finance') . '</button></form>';

        if (!$started) {
            $h .= Ui::empty(esc_html__('Pick the account, the statement\'s closing date and its closing balance. Everything on the account up to that date appears, ready to tick.', 'sepo-finance'));
        } else {
            $rows = Reconcile::candidates($accountId, $date);
            $opening = (float) $account['opening_balance'];
            $ticked = Reconcile::ticked($accountId, $date);
            $diff = round($ticked - $stmt, 2);
            $c = (string) $account['currency'] ?: $cur;
            $h .= '<div class="sfin-scope-data" data-recon data-account="' . esc_attr($accountId) . '" data-date="' . esc_attr($date) . '" data-statement="' . esc_attr((string) $stmt) . '" data-opening="' . esc_attr((string) $opening) . '" data-currency="' . esc_attr($c) . '">';
            $h .= '<div class="sfin-tiles">'
                . Ui::tile(__('Statement says', 'sepo-finance'), $hasBal ? Ui::signed($stmt, $c) : '—', $hasBal ? '' : 'alert')
                . '<div class="sfin-tile sfin-tile--live"><b data-recon-ticked>' . Ui::signed($ticked, $c) . '</b><span>' . esc_html__('Ticked so far', 'sepo-finance') . '</span></div>'
                . '<div class="sfin-tile ' . (abs($diff) >= 0.01 ? 'sfin-tile--alert' : 'sfin-tile--good') . '" data-recon-tile><b data-recon-diff>' . Ui::signed($diff, $c) . '</b><span data-recon-label>' . esc_html(abs($diff) < 0.01 ? __('Balanced', 'sepo-finance') : ($diff > 0 ? __('Books are higher', 'sepo-finance') : __('Statement is higher', 'sepo-finance'))) . '</span></div></div>';
            if ($rows === []) {
                /* translators: %s: date */
                $h .= Ui::empty(esc_html(sprintf(__('Nothing on this account up to %s.', 'sepo-finance'), Parse::show($date))));
            } else {
                $h .= '<div class="sfin-tablewrap"><table class="sfin-table"><thead><tr><th><span class="screen-reader-text">' . esc_html__('On the statement', 'sepo-finance') . '</span></th><th>' . esc_html__('Date', 'sepo-finance') . '</th><th>' . esc_html__('Description', 'sepo-finance') . '</th><th>' . esc_html__('Reference', 'sepo-finance') . '</th><th>' . esc_html__('Status', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Effect', 'sepo-finance') . '</th></tr></thead><tbody>';
                foreach ($rows as $t) {
                    $eff = Ledger::effect($t, $accountId);
                    $pend = $t['status'] === 'pending';
                    $id = 'sfin-tick-' . $t['id'];
                    $h .= '<tr class="' . ($pend ? 'sfin-row--muted' : '') . '" data-id="' . esc_attr((string) $t['id']) . '" data-effect="' . esc_attr((string) $eff) . '">'
                        . '<td><input type="checkbox" id="' . esc_attr($id) . '" data-tick' . checked($t['status'], 'reconciled', false) . disabled($pend, true, false) . '></td>'
                        . '<td class="sfin-nowrap"><label for="' . esc_attr($id) . '">' . Ui::e(Parse::show((string) $t['date'])) . '</label></td>'
                        . '<td>' . Ui::e($t['description']) . '</td><td>' . Ui::e($t['reference']) . '</td>'
                        . '<td>' . Ui::statusPill((string) $t['status']) . ($pend ? ' <span class="sfin-tag">' . esc_html__('Not moved yet', 'sepo-finance') . '</span>' : '') . '</td>'
                        . '<td class="sfin-num">' . ($eff >= 0 ? '<span class="sfin-in">+' . Ui::money($eff, $c) . '</span>' : '<span class="sfin-out">−' . Ui::money($eff, $c) . '</span>') . '</td></tr>';
                }
                $h .= '</tbody></table></div>';
                /* translators: %s: amount */
                $h .= '<div class="sfin-toolbar sfin-toolbar--end"><span class="sfin-muted">' . esc_html(sprintf(__('The opening balance of %s is included in the ticked total.', 'sepo-finance'), Parse::signed($opening, $c))) . '</span><button type="button" class="sfin-btn sfin-btn--primary" data-recon-finish' . disabled(!$hasBal, true, false) . '>' . esc_html__('Finish reconciliation', 'sepo-finance') . '</button></div>';
            }
            $h .= '</div>';
        }

        $hist = Reconcile::history($accountId);
        if ($hist !== []) {
            $names = Store::names('accounts');
            $h .= '<section class="sfin-panel"><h3>' . esc_html__('Past reconciliations', 'sepo-finance') . '</h3><div class="sfin-tablewrap"><table class="sfin-table"><thead><tr><th>' . esc_html__('Account', 'sepo-finance') . '</th><th>' . esc_html__('Statement date', 'sepo-finance') . '</th><th>' . esc_html__('Result', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Statement', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Ticked', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Difference', 'sepo-finance') . '</th><th></th></tr></thead><tbody>';
            foreach (array_slice($hist, 0, 24) as $r) {
                $h .= '<tr><td>' . Ui::e($names[$r['account_id']] ?? '') . '</td><td>' . Ui::e(Parse::show((string) $r['statement_date'])) . '</td><td>' . Ui::statusPill((string) $r['status'], $r['status'] === 'balanced' ? '' : __('Difference', 'sepo-finance')) . '</td>'
                    . '<td class="sfin-num">' . Ui::signed($r['statement_balance']) . '</td><td class="sfin-num">' . Ui::signed($r['cleared_balance']) . '</td><td class="sfin-num">' . Ui::signed($r['difference']) . '</td>'
                    . '<td class="sfin-actions"><a class="sfin-link" href="' . esc_url(Menu::url('reconcile', ['account' => $r['account_id'], 'date' => $r['statement_date'], 'balance' => (string) $r['statement_balance']])) . '">' . esc_html__('Reopen', 'sepo-finance') . '</a></td></tr>';
            }
            $h .= '</tbody></table></div></section>';
        }
        echo $h; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }
}
