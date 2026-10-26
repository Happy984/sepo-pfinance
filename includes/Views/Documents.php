<?php

declare(strict_types=1);

namespace SepoFinance\Views;

use SepoFinance\Admin\Actions;
use SepoFinance\Admin\Menu;
use SepoFinance\Documents as Docs;
use SepoFinance\Ledger;
use SepoFinance\Parse;
use SepoFinance\Settings;
use SepoFinance\Store;

defined('ABSPATH') || exit;

/**
 * Invoices and bills: the same object pointing in opposite directions. An
 * invoice is money owed to you, a bill is money you owe. Same fields, same
 * payments, so one screen serves both.
 */
final class Documents
{
    public static function render(string $screen): void
    {
        $isInv = $screen === 'invoices';
        $cur = Settings::currency();
        $docs = Store::all($screen);
        $names = Store::names('counterparties');
        $today = current_time('Y-m-d');
        $show = in_array(Ui::query('show'), ['open', 'overdue', 'paid', 'draft', 'cancelled', 'all'], true) ? Ui::query('show') : 'open';

        $tot = ['open' => 0.0, 'overdue' => 0.0, 'paid' => 0.0];
        $counts = ['open' => 0, 'overdue' => 0, 'paid' => 0, 'draft' => 0, 'cancelled' => 0, 'all' => count($docs)];
        foreach ($docs as $d) {
            $st = (string) $d['status'];
            $tot['paid'] += (float) $d['paid_amount'];
            if (isset($counts[$st]) && in_array($st, ['paid', 'draft', 'cancelled'], true)) {
                $counts[$st]++;
            }
            if ($st === 'sent' && Ledger::outstanding($d) > 0) {
                $counts['open']++;
                $tot['open'] += Ledger::outstanding($d);
                if ($d['date_due'] !== '' && $d['date_due'] < $today) {
                    $counts['overdue']++;
                    $tot['overdue'] += Ledger::outstanding($d);
                }
            }
        }

        $new = '<button type="button" class="sfin-btn sfin-btn--primary" data-sfin-new="' . esc_attr($screen) . '"' . ($isInv ? ' data-defaults="' . Ui::data(['number' => Docs::nextNumber(), 'date_due' => '+' . (int) Settings::get('invoice_due_days') . 'd', 'tax_rate' => (float) Settings::get('tax_rate')]) . '"' : ' data-defaults="' . Ui::data(['tax_rate' => (float) Settings::get('tax_rate')]) . '"') . '>' . esc_html($isInv ? __('New invoice', 'sepo-finance') : __('Add a bill', 'sepo-finance')) . '</button>';
        $h = Ui::head($isInv ? __('Invoices', 'sepo-finance') : __('Bills', 'sepo-finance'),
            esc_html($isInv ? __('What customers owe you. Record each payment as it arrives: part payments are fine.', 'sepo-finance') : __('What you owe suppliers. Record each payment as you make it: part payments are fine.', 'sepo-finance')), $new);

        if (Store::all('accounts') === []) {
            echo $h . Ui::empty('<p>' . esc_html__('Add an account first: recording a payment writes an entry, and it has to land somewhere.', 'sepo-finance') . '</p><p><a class="sfin-btn sfin-btn--primary" href="' . esc_url(Menu::url('accounts')) . '">' . esc_html__('Add an account', 'sepo-finance') . '</a></p>'); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }

        $h .= '<div class="sfin-tiles">'
            . Ui::tile(__('Overdue', 'sepo-finance'), Ui::money($tot['overdue'], $cur), $tot['overdue'] > 0 ? 'alert' : '', Menu::url($screen, ['show' => 'overdue']))
            . Ui::tile($isInv ? __('Still owed to you', 'sepo-finance') : __('Still to pay', 'sepo-finance'), Ui::money($tot['open'], $cur), 'live', Menu::url($screen, ['show' => 'open']))
            . Ui::tile($isInv ? __('Received to date', 'sepo-finance') : __('Paid to date', 'sepo-finance'), Ui::money($tot['paid'], $cur))
            . '</div>';

        $labels = ['open' => __('Open', 'sepo-finance'), 'overdue' => __('Overdue', 'sepo-finance'), 'draft' => __('Drafts', 'sepo-finance'), 'paid' => __('Paid', 'sepo-finance'), 'cancelled' => __('Cancelled', 'sepo-finance'), 'all' => __('All', 'sepo-finance')];
        $h .= '<div class="sfin-chips">';
        foreach ($labels as $k => $l) {
            $h .= '<a class="sfin-chip' . ($show === $k ? ' is-on' : '') . '" href="' . esc_url(Menu::url($screen, ['show' => $k])) . '"' . ($show === $k ? ' aria-current="page"' : '') . '>' . esc_html($l) . ' <span class="sfin-count">' . (int) $counts[$k] . '</span></a>';
        }
        $h .= '</div>';

        $list = array_values(array_filter($docs, static function (array $d) use ($show, $today): bool {
            $owed = Ledger::outstanding($d);
            return match ($show) {
                'open'    => $d['status'] === 'sent' && $owed > 0,
                'overdue' => $d['status'] === 'sent' && $owed > 0 && $d['date_due'] !== '' && $d['date_due'] < $today,
                'all'     => true,
                default   => $d['status'] === $show,
            };
        }));

        if ($docs === []) {
            echo $h . Ui::empty(esc_html($isInv ? __('No invoices yet. Create one, print it or email it, and record payments against it as they come in.', 'sepo-finance') : __('No bills yet. Add the bills suppliers send you, and record what you pay against them.', 'sepo-finance'))); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }
        if ($list === []) {
            echo $h . Ui::empty(esc_html__('Nothing here with this filter.', 'sepo-finance')); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }

        $h .= '<div class="sfin-tablewrap"><table class="sfin-table" data-sfin-table><thead><tr><th>' . esc_html__('Number', 'sepo-finance') . '</th><th>' . esc_html($isInv ? __('Customer', 'sepo-finance') : __('Supplier', 'sepo-finance')) . '</th><th>' . esc_html__('Issued', 'sepo-finance') . '</th><th>' . esc_html__('Due', 'sepo-finance') . '</th><th>' . esc_html__('Status', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Total', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Still owed', 'sepo-finance') . '</th><th></th></tr></thead><tbody>';
        foreach ($list as $d) {
            $owed = Ledger::outstanding($d);
            $late = Ledger::isOverdue($d, $today);
            $c = (string) $d['currency'] ?: $cur;
            $st = $late ? 'overdue' : (string) $d['status'];
            $stLabel = $st === 'sent' && !$isInv ? __('Received', 'sepo-finance') : '';
            $part = (float) $d['paid_amount'] > 0 && $owed > 0;
            $cp = Store::find('counterparties', (string) $d['counterparty_id'], true);
            $h .= '<tr data-type="' . esc_attr($screen) . '" data-id="' . esc_attr((string) $d['id']) . '" data-record="' . Ui::data($d) . '" data-owed="' . esc_attr((string) $owed) . '" data-email="' . esc_attr((string) ($cp['email'] ?? '')) . '" class="' . ($late ? 'sfin-row--late' : '') . ($d['status'] === 'cancelled' ? ' sfin-row--muted' : '') . '">'
                . '<td class="sfin-nowrap"><strong>' . Ui::e($d['number']) . '</strong>' . ($d['lines'] !== [] ? '<br><span class="sfin-sub">' . esc_html(sprintf(/* translators: %d: items */ _n('%d item', '%d items', count($d['lines']), 'sepo-finance'), count($d['lines']))) . '</span>' : '') . '</td>'
                . '<td>' . Ui::e($names[$d['counterparty_id']] ?? __('(deleted)', 'sepo-finance')) . '</td>'
                . '<td class="sfin-nowrap">' . Ui::e(Parse::show((string) $d['date_issued'])) . '</td>'
                . '<td class="sfin-nowrap">' . Ui::e(Parse::show((string) $d['date_due'])) . '</td>'
                . '<td>' . Ui::statusPill($st, $stLabel) . ($part ? ' <span class="sfin-tag">' . esc_html__('Part paid', 'sepo-finance') . '</span>' : '') . ($isInv && (int) $d['sent_at'] > 0 ? '<br><span class="sfin-sub">' . esc_html(sprintf(/* translators: %s: date */ __('emailed %s', 'sepo-finance'), wp_date((string) get_option('date_format'), (int) $d['sent_at']))) . '</span>' : '') . '</td>'
                . '<td class="sfin-num">' . Ui::money($d['amount'], $c) . '</td>'
                . '<td class="sfin-num"><strong>' . ($owed > 0 ? Ui::money($owed, $c) : '—') . '</strong></td>'
                . '<td class="sfin-actions">'
                . ($owed > 0 && $d['status'] !== 'cancelled' ? '<button type="button" class="sfin-link sfin-link--strong" data-act="settle">' . esc_html($isInv ? __('Payment received', 'sepo-finance') : __('Pay', 'sepo-finance')) . '</button>' : '')
                . '<a class="sfin-link" href="' . esc_url(Actions::printUrl($screen, (string) $d['id'])) . '" target="_blank" rel="noopener">' . esc_html__('Print', 'sepo-finance') . '</a>'
                . ($isInv && !in_array($d['status'], ['paid', 'cancelled'], true) ? '<button type="button" class="sfin-link" data-act="email">' . esc_html($late ? __('Remind', 'sepo-finance') : __('Email', 'sepo-finance')) . '</button>' : '')
                . '<button type="button" class="sfin-link" data-act="edit">' . esc_html__('Edit', 'sepo-finance') . '</button>'
                . '<button type="button" class="sfin-link sfin-link--danger" data-act="delete">' . esc_html__('Delete', 'sepo-finance') . '</button></td></tr>';
        }
        $h .= '</tbody></table></div>';
        echo $h; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }
}
