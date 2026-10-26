<?php

declare(strict_types=1);

namespace SepoFinance\Views;

use SepoFinance\Admin\Menu;
use SepoFinance\Ledger;
use SepoFinance\Settings;
use SepoFinance\Store;

defined('ABSPATH') || exit;

/**
 * Accounts, categories, and customers and suppliers: a heading, an Add
 * button, a table, and the form built from the field spec.
 */
final class Records
{
    public static function render(string $screen): void
    {
        $cur = Settings::currency();
        $rows = Store::all($screen);
        $meta = [
            'accounts' => [__('Accounts', 'sepo-finance'), __('Every place money sits: bank, mobile money, cash, card. Only the opening balance is typed; every balance after it is worked out from the ledger.', 'sepo-finance'), __('Add account', 'sepo-finance'),
                __('No accounts yet. Add one for each bank account, mobile money wallet or cash box, with what it held on the day you start.', 'sepo-finance')],
            'categories' => [__('Categories', 'sepo-finance'), __('What money comes in for and goes out on. Reports group by these. Archive one you no longer use: old entries keep it.', 'sepo-finance'), __('Add category', 'sepo-finance'),
                __('No categories yet.', 'sepo-finance')],
            'counterparties' => [__('Customers and suppliers', 'sepo-finance'), __('Who you deal with: customers, suppliers, staff and government. Invoices and reminders go to the email here.', 'sepo-finance'), __('Add customer or supplier', 'sepo-finance'),
                __('Nobody here yet. Add a customer to invoice them, or a supplier to record their bills.', 'sepo-finance')],
        ][$screen];

        $h = Ui::head($meta[0], esc_html($meta[1]), '<button type="button" class="sfin-btn sfin-btn--primary" data-sfin-new="' . esc_attr($screen) . '">' . esc_html($meta[2]) . '</button>');
        if ($rows === []) {
            echo $h . Ui::empty(esc_html($meta[3])); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }
        $h .= match ($screen) {
            'accounts'   => self::accounts($rows, $cur),
            'categories' => self::categories($rows),
            default      => self::counterparties($rows, $cur),
        };
        echo $h; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }

    private static function actions(bool $statement = false): string
    {
        return '<td class="sfin-actions">' . ($statement ? '<button type="button" class="sfin-link" data-act="statement">' . esc_html__('Statement', 'sepo-finance') . '</button>' : '')
            . '<button type="button" class="sfin-link" data-act="edit">' . esc_html__('Edit', 'sepo-finance') . '</button><button type="button" class="sfin-link sfin-link--danger" data-act="delete">' . esc_html__('Delete', 'sepo-finance') . '</button></td>';
    }

    private static function tr(string $type, array $r): string
    {
        return '<tr data-type="' . esc_attr($type) . '" data-id="' . esc_attr((string) $r['id']) . '" data-record="' . Ui::data($r) . '" class="' . (!empty($r['archived']) ? 'sfin-row--muted' : '') . '">';
    }

    private static function accounts(array $rows, string $cur): string
    {
        $bal = Ledger::balances();
        $h = '<div class="sfin-tablewrap"><table class="sfin-table" data-sfin-table><thead><tr><th>' . esc_html__('Account', 'sepo-finance') . '</th><th>' . esc_html__('Type', 'sepo-finance') . '</th><th>' . esc_html__('Opened', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Opening balance', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Balance now', 'sepo-finance') . '</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $c = (string) $r['currency'] !== '' ? (string) $r['currency'] : $cur;
            $h .= self::tr('accounts', $r) . '<td><a href="' . esc_url(Menu::url('ledger', ['account' => $r['id'], 'from' => '2000-01-01'])) . '"><strong>' . Ui::e($r['name']) . '</strong></a>'
                . (!empty($r['archived']) ? ' <span class="sfin-tag">' . esc_html__('Archived', 'sepo-finance') . '</span>' : '')
                . ($r['institution'] !== '' || $r['number_masked'] !== '' ? '<br><span class="sfin-sub">' . Ui::e(trim($r['institution'] . ($r['number_masked'] !== '' ? ' ··' . $r['number_masked'] : ''))) . '</span>' : '') . '</td>'
                . '<td>' . Ui::e(Ui::accountType((string) $r['type'])) . ($c !== $cur ? ' <span class="sfin-tag">' . Ui::e($c) . '</span>' : '') . '</td>'
                . '<td>' . Ui::e(\SepoFinance\Parse::show((string) $r['opening_date'])) . '</td>'
                . '<td class="sfin-num">' . Ui::signed($r['opening_balance'], $c) . '</td>'
                . '<td class="sfin-num"><strong>' . Ui::signed($bal[$r['id']] ?? 0, $c) . '</strong></td>' . self::actions() . '</tr>';
        }
        return $h . '</tbody></table></div>';
    }

    private static function categories(array $rows): string
    {
        global $wpdb;
        $used = [];
        // phpcs:ignore WordPress.DB.PreparedSQL -- fixed table
        foreach ($wpdb->get_results('SELECT category_id, COUNT(*) n FROM ' . \SepoFinance\Schema::table('transactions') . ' WHERE deleted = 0 GROUP BY category_id', ARRAY_A) ?: [] as $u) {
            $used[(string) $u['category_id']] = (int) $u['n'];
        }
        $h = '<div class="sfin-columns">';
        foreach (['income' => __('Money in', 'sepo-finance'), 'expense' => __('Money out', 'sepo-finance')] as $type => $label) {
            $list = array_values(array_filter($rows, static fn(array $r): bool => $r['type'] === $type));
            $byParent = [];
            foreach ($list as $r) {
                $byParent[(string) $r['parent_id']][] = $r;
            }
            $ids = array_column($list, 'id');
            // Categories whose parent is gone are shown at the top level.
            foreach ($list as $r) {
                if ($r['parent_id'] !== '' && !in_array($r['parent_id'], $ids, true)) {
                    $byParent[''][] = $r;
                }
            }
            $h .= '<section class="sfin-panel"><h3>' . esc_html($label) . '</h3><table class="sfin-table" data-sfin-table><tbody>';
            $walk = static function (string $parent, int $depth) use (&$walk, $byParent, $used): string {
                $o = '';
                foreach ($byParent[$parent] ?? [] as $r) {
                    $o .= self::tr('categories', $r) . '<td><span class="sfin-indent" style="--depth:' . $depth . '"></span>' . ($r['colour'] !== '' ? '<span class="sfin-swatch" style="background:' . esc_attr($r['colour']) . '"></span>' : '') . Ui::e($r['name'])
                        . (!empty($r['archived']) ? ' <span class="sfin-tag">' . esc_html__('Archived', 'sepo-finance') . '</span>' : '') . '</td>'
                        /* translators: %d: count */
                        . '<td class="sfin-num sfin-sub">' . (($used[$r['id']] ?? 0) > 0 ? '<a href="' . esc_url(Menu::url('ledger', ['category' => $r['id'], 'from' => '2000-01-01'])) . '">' . esc_html(sprintf(_n('%d entry', '%d entries', $used[$r['id']], 'sepo-finance'), $used[$r['id']])) . '</a>' : '') . '</td>'
                        . self::actions() . '</tr>' . $walk((string) $r['id'], $depth + 1);
                }
                return $o;
            };
            $h .= $walk('', 0) . '</tbody></table></section>';
        }
        return $h . '</div>';
    }

    private static function counterparties(array $rows, string $cur): string
    {
        $owed = [];
        foreach (['invoices' => 1, 'bills' => -1] as $type => $sign) {
            foreach (Store::all($type) as $d) {
                if (!in_array($d['status'], ['cancelled', 'draft'], true)) {
                    $owed[$d['counterparty_id']] = ($owed[$d['counterparty_id']] ?? 0) + $sign * Ledger::outstanding($d);
                }
            }
        }
        $types = ['customer' => __('Customer', 'sepo-finance'), 'supplier' => __('Supplier', 'sepo-finance'), 'staff' => __('Staff', 'sepo-finance'), 'government' => __('Government', 'sepo-finance'), 'other' => __('Other', 'sepo-finance')];
        $show = Ui::query('kind');
        $h = '<div class="sfin-chips">';
        foreach (['' => __('Everyone', 'sepo-finance')] + $types as $k => $l) {
            $h .= '<a class="sfin-chip' . ($show === $k ? ' is-on' : '') . '" href="' . esc_url(Menu::url('counterparties', $k !== '' ? ['kind' => $k] : [])) . '">' . esc_html($l) . '</a>';
        }
        $h .= '</div><div class="sfin-tablewrap"><table class="sfin-table" data-sfin-table><thead><tr><th>' . esc_html__('Name', 'sepo-finance') . '</th><th>' . esc_html__('Type', 'sepo-finance') . '</th><th>' . esc_html__('Contact', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Balance', 'sepo-finance') . '</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            if ($show !== '' && $r['type'] !== $show) {
                continue;
            }
            $o = round($owed[$r['id']] ?? 0, 2);
            $h .= self::tr('counterparties', $r) . '<td><strong>' . Ui::e($r['name']) . '</strong>' . (!empty($r['archived']) ? ' <span class="sfin-tag">' . esc_html__('Archived', 'sepo-finance') . '</span>' : '') . ($r['tax_id'] !== '' ? '<br><span class="sfin-sub">' . esc_html__('TPIN', 'sepo-finance') . ' ' . Ui::e($r['tax_id']) . '</span>' : '') . '</td>'
                . '<td>' . Ui::e($types[$r['type']] ?? $r['type']) . '</td>'
                . '<td>' . ($r['email'] !== '' ? '<a href="mailto:' . esc_attr($r['email']) . '">' . Ui::e($r['email']) . '</a>' : '') . ($r['phone'] !== '' ? '<br><span class="sfin-sub">' . Ui::e($r['phone']) . '</span>' : '') . '</td>'
                . '<td class="sfin-num">' . (abs($o) < 0.005 ? '<span class="sfin-sub">—</span>' : ($o > 0 ? '<span class="sfin-in">' . esc_html(sprintf(/* translators: %s: amount */ __('owes you %s', 'sepo-finance'), \SepoFinance\Parse::money($o, $cur))) . '</span>' : '<span class="sfin-out">' . esc_html(sprintf(/* translators: %s: amount */ __('you owe %s', 'sepo-finance'), \SepoFinance\Parse::money($o, $cur))) . '</span>')) . '</td>'
                . self::actions(true) . '</tr>';
        }
        return $h . '</tbody></table></div>';
    }
}
