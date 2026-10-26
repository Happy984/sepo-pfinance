<?php

declare(strict_types=1);

namespace SepoFinance\Views;

use SepoFinance\Admin\Menu;
use SepoFinance\Fields;
use SepoFinance\Ledger as Book;
use SepoFinance\Parse;
use SepoFinance\Settings;
use SepoFinance\Store;

defined('ABSPATH') || exit;

/**
 * Where money gets entered: a quick-add row that keeps its context between
 * entries, the full form for anything that needs thought, and the entries
 * themselves, filtered by a range and anything else in the address, so a
 * filtered ledger is a link you can keep.
 */
final class Ledger
{
    public const LIMIT = 500;

    public static function render(string $screen): void
    {
        $cur = Settings::currency();
        $refs = Store::refs();
        [$ms, $me] = Parse::monthBounds();
        $f = [
            'from'         => Parse::dateOr(Ui::query('from'), $ms),
            'to'           => Parse::dateOr(Ui::query('to'), $me),
            'account'      => Ui::query('account'),
            'category'     => Ui::query('category'),
            'counterparty' => Ui::query('counterparty'),
            'type'         => in_array(Ui::query('type'), ['income', 'expense', 'transfer'], true) ? Ui::query('type') : '',
            'status'       => in_array(Ui::query('status'), ['pending', 'cleared', 'reconciled'], true) ? Ui::query('status') : '',
            'search'       => mb_substr(Ui::query('q'), 0, 120),
            'limit'        => self::LIMIT + 1,
        ];
        $rows = Book::transactions($f);
        $more = count($rows) > self::LIMIT;
        $rows = array_slice($rows, 0, self::LIMIT);
        $sums = Book::sums($rows);

        $csv = wp_nonce_url(add_query_arg(['action' => 'sfin_ledger_csv'] + array_filter($f, static fn($v, $k) => $k !== 'limit' && $v !== '', ARRAY_FILTER_USE_BOTH), admin_url('admin-post.php')), 'sfin_ledger_csv');
        $query = array_filter($f, static fn($v, $k) => $k !== 'limit' && $v !== '', ARRAY_FILTER_USE_BOTH);
        $h = Ui::head(__('Ledger', 'sepo-finance'), '<span data-sfin-summary="' . esc_attr((string) wp_json_encode($query)) . '" aria-live="polite">' . esc_html(sprintf(
            /* translators: 1: money in, 2: money out, 3: net, 4: count, 5: from, 6: to */
            _n('%1$s in, %2$s out, net %3$s, %4$d entry from %5$s to %6$s.', '%1$s in, %2$s out, net %3$s, %4$d entries from %5$s to %6$s.', $sums['count'], 'sepo-finance'),
            Parse::money($sums['income'], $cur),
            Parse::money($sums['expenses'], $cur),
            Parse::signed($sums['net'], $cur),
            $sums['count'],
            Parse::show($f['from']),
            Parse::show($f['to'])
        )) . '</span>' . ($sums['count'] < count($rows) ? ' ' . esc_html__('Pending entries are listed but not counted.', 'sepo-finance') : ''),
            '<a class="sfin-btn" href="' . esc_url(Menu::url('import')) . '">' . esc_html__('Import statement', 'sepo-finance') . '</a><a class="sfin-btn" href="' . esc_url($csv) . '">' . esc_html__('Download CSV', 'sepo-finance') . '</a>');

        if ($refs['accounts'] === []) {
            echo $h . Ui::empty('<p>' . esc_html__('Add an account first: an entry has to land somewhere.', 'sepo-finance') . '</p><p><a class="sfin-btn sfin-btn--primary" href="' . esc_url(Menu::url('accounts')) . '">' . esc_html__('Add an account', 'sepo-finance') . '</a></p>'); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }

        $opt = static function (array $list, string $sel, string $all): string {
            $o = '<option value="">' . esc_html($all) . '</option>';
            foreach ($list as $x) {
                $o .= '<option value="' . esc_attr($x['id']) . '"' . selected($sel, $x['id'], false) . '>' . esc_html($x['name']) . ($x['archived'] ? ' ' . esc_html__('(archived)', 'sepo-finance') : '') . '</option>';
            }
            return $o;
        };
        $h .= '<form class="sfin-toolbar sfin-filters" method="get">' . Ui::pageField()
            . '<label><span class="screen-reader-text">' . esc_html__('From', 'sepo-finance') . '</span><input type="date" name="from" value="' . esc_attr($f['from']) . '"></label>'
            . '<label><span class="screen-reader-text">' . esc_html__('To', 'sepo-finance') . '</span><input type="date" name="to" value="' . esc_attr($f['to']) . '"></label>'
            . '<label><span class="screen-reader-text">' . esc_html__('Account', 'sepo-finance') . '</span><select name="account">' . $opt($refs['accounts'], $f['account'], __('All accounts', 'sepo-finance')) . '</select></label>'
            . '<label><span class="screen-reader-text">' . esc_html__('Category', 'sepo-finance') . '</span><select name="category">' . $opt($refs['categories'], $f['category'], __('All categories', 'sepo-finance')) . '</select></label>'
            . '<label><span class="screen-reader-text">' . esc_html__('Type', 'sepo-finance') . '</span><select name="type"><option value="">' . esc_html__('In and out', 'sepo-finance') . '</option>';
        foreach (['income', 'expense', 'transfer'] as $t) {
            $h .= '<option value="' . $t . '"' . selected($f['type'], $t, false) . '>' . esc_html(Ui::typeLabel($t)) . '</option>';
        }
        $h .= '</select></label><label class="sfin-grow"><span class="screen-reader-text">' . esc_html__('Search', 'sepo-finance') . '</span><input type="search" name="q" data-sfin-search value="' . esc_attr($f['search']) . '" placeholder="' . esc_attr__('Search description, reference, notes', 'sepo-finance') . '"></label>'
            . ($f['counterparty'] !== '' ? '<input type="hidden" name="counterparty" value="' . esc_attr($f['counterparty']) . '">' : '')
            . '<button class="sfin-btn" type="submit">' . esc_html__('Filter', 'sepo-finance') . '</button>'
            . '<a class="sfin-btn sfin-btn--quiet" href="' . esc_url(Menu::url('ledger')) . '">' . esc_html__('This month', 'sepo-finance') . '</a></form>';

        $h .= self::quick($refs);

        $names = ['accounts' => Store::names('accounts'), 'categories' => Store::names('categories'), 'counterparties' => Store::names('counterparties')];
        $h .= '<div class="sfin-tablewrap"><table class="sfin-table sfin-ledger" data-sfin-table><thead><tr>'
            . '<th>' . esc_html__('Date', 'sepo-finance') . '</th><th>' . esc_html__('Description', 'sepo-finance') . '</th><th>' . esc_html__('Who', 'sepo-finance') . '</th><th>' . esc_html__('Category', 'sepo-finance') . '</th><th>' . esc_html__('Account', 'sepo-finance') . '</th><th>' . esc_html__('Status', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Amount', 'sepo-finance') . '</th><th><span class="screen-reader-text">' . esc_html__('Actions', 'sepo-finance') . '</span></th></tr></thead><tbody>';
        foreach ($rows as $t) {
            $h .= self::row($t, $names, $cur);
        }
        $h .= '</tbody></table></div>';
        $h .= '<p class="sfin-muted" data-sfin-empty' . ($rows === [] ? '' : ' hidden') . '>' . esc_html__('Nothing in this range yet. The row above is the quickest way in.', 'sepo-finance') . '</p>';
        if ($more) {
            /* translators: %d: limit */
            $h .= Ui::notice(esc_html(sprintf(__('Showing the newest %d entries. Narrow the range or filter to see the rest.', 'sepo-finance'), self::LIMIT)));
        }
        echo $h; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }

    /** The quick-add row, built from the same spec as the full form. */
    private static function quick(array $refs): string
    {
        $h = '<div class="sfin-entry" data-sfin-quick><div class="sfin-entry__row">';
        foreach (Fields::get('transactions') as $field) {
            if (empty($field['quick'])) {
                continue;
            }
            $key = (string) $field['key'];
            $attrs = 'name="' . esc_attr($key) . '" data-field="' . esc_attr($key) . '" aria-label="' . esc_attr((string) $field['label']) . '"';
            $h .= '<div class="sfin-entry__cell sfin-entry__cell--' . esc_attr($key) . '">';
            if ($field['type'] === 'select') {
                $h .= '<select ' . $attrs . '>';
                foreach ($field['options'] as $v => $l) {
                    $h .= '<option value="' . esc_attr((string) $v) . '"' . selected((string) ($field['default'] ?? ''), (string) $v, false) . '>' . esc_html($l) . '</option>';
                }
                $h .= '</select>';
            } elseif ($field['type'] === 'ref') {
                // The script fills these from the reference lists, with the "add a new one" choice.
                $h .= '<select ' . $attrs . ' data-ref="' . esc_attr((string) $field['ref']) . '"' . (!empty($field['filter']) ? ' data-filter="' . esc_attr((string) $field['filter']) . '"' : '') . '><option value="">— ' . esc_html((string) $field['label']) . ' —</option></select>';
                if ($key === 'category_id') {
                    $h .= '<select name="to_account_id" data-field="to_account_id" data-ref="accounts" aria-label="' . esc_attr__('To account', 'sepo-finance') . '" hidden disabled><option value="">— ' . esc_html__('To account', 'sepo-finance') . ' —</option></select>';
                }
            } elseif ($field['type'] === 'money') {
                $h .= '<input type="text" inputmode="decimal" class="sfin-num" placeholder="0.00" autocomplete="off" ' . $attrs . '>';
            } elseif ($field['type'] === 'date') {
                $h .= '<input type="text" value="' . esc_attr(current_time('Y-m-d')) . '" autocomplete="off" ' . $attrs . '>';
            } else {
                $h .= '<input type="text" placeholder="' . esc_attr((string) $field['label']) . '" autocomplete="off" ' . $attrs . '>';
            }
            $h .= '</div>';
        }
        $h .= '<button type="button" class="sfin-btn sfin-btn--primary" data-sfin-add>' . esc_html__('Add', 'sepo-finance') . '</button></div>'
            . '<p class="sfin-entry__hint">' . esc_html__('Enter saves and keeps the date, account and category for the next line. Dates read day first: 13/09, today, -3d. A negative amount or (450) books an expense. Press n for the full form.', 'sepo-finance') . '</p>'
            . '<button type="button" class="sfin-link" data-sfin-new="transactions">' . esc_html__('Open the full form', 'sepo-finance') . '</button></div>';
        return $h;
    }

    /** One ledger row; the script builds the same markup after a save. */
    public static function row(array $t, array $names, string $cur): string
    {
        $type = (string) $t['type'];
        $files = count($t['attachments']);
        $amountCls = $type === 'income' ? 'sfin-in' : ($type === 'expense' ? 'sfin-out' : '');
        $sign = $type === 'income' ? '+' : ($type === 'expense' ? '−' : '');
        $account = $names['accounts'][$t['account_id']] ?? '';
        if ($type === 'transfer') {
            $account .= ' → ' . ($names['accounts'][$t['to_account_id']] ?? '');
        }
        return '<tr data-type="transactions" data-id="' . esc_attr((string) $t['id']) . '" data-record="' . Ui::data($t) . '" class="' . ($t['status'] === 'pending' ? 'sfin-row--pending' : '') . '">'
            . '<td data-cell="date" class="sfin-nowrap">' . esc_html(Parse::show((string) $t['date'])) . '</td>'
            . '<td data-cell="description">' . esc_html((string) $t['description']) . ($t['reference'] !== '' ? ' <span class="sfin-sub">' . esc_html((string) $t['reference']) . '</span>' : '')
            . ($files > 0 ? ' <span class="sfin-clip" title="' . esc_attr(sprintf(/* translators: %d: files */ _n('%d file', '%d files', $files, 'sepo-finance'), $files)) . '">' . Ui::clip() . $files . '</span>' : '')
            . ($t['source'] === 'sepo-membership' ? ' <span class="sfin-tag">' . esc_html__('Membership', 'sepo-finance') . '</span>' : '') . '</td>'
            . '<td data-cell="counterparty_id">' . esc_html($names['counterparties'][$t['counterparty_id']] ?? '') . '</td>'
            . '<td data-cell="category_id">' . esc_html($type === 'transfer' ? '—' : ($names['categories'][$t['category_id']] ?? ($t['category_id'] !== '' ? __('(deleted)', 'sepo-finance') : ''))) . '</td>'
            . '<td data-cell="account_id">' . esc_html($account) . '</td>'
            . '<td data-cell="status">' . Ui::statusPill((string) $t['status']) . '</td>'
            . '<td data-cell="amount" class="sfin-num ' . $amountCls . '">' . esc_html($sign . Parse::money($t['amount'], (string) $t['currency'] ?: $cur)) . '</td>'
            . '<td class="sfin-actions"><button type="button" class="sfin-link" data-act="edit">' . esc_html__('Edit', 'sepo-finance') . '</button><button type="button" class="sfin-link" data-act="files">' . esc_html__('Files', 'sepo-finance') . '</button><button type="button" class="sfin-link" data-act="copy">' . esc_html__('Copy', 'sepo-finance') . '</button><button type="button" class="sfin-link sfin-link--danger" data-act="delete">' . esc_html__('Delete', 'sepo-finance') . '</button></td></tr>';
    }
}
