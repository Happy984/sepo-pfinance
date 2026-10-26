<?php

declare(strict_types=1);

namespace SepoFinance\Views;

use SepoFinance\Admin\Menu;
use SepoFinance\Log;
use SepoFinance\Parse;

defined('ABSPATH') || exit;

/** The activity log: every change to the books, who made it, and what it changed. */
final class Activity
{
    private const HIDDEN = ['id', 'deleted', 'attachments', 'lines'];

    public static function render(string $screen): void
    {
        $type = Ui::query('type');
        $record = Ui::query('record');
        $action = Ui::query('act');
        $page = max(1, (int) Ui::query('paged', '1'));
        $per = 50;
        $q = Log::query(['type' => $type, 'record' => $record, 'action' => $action, 'page' => $page, 'per' => $per]);

        $h = Ui::head(__('Activity', 'sepo-finance'), esc_html__('Every change to the books, newest first. Entries here are never edited or removed.', 'sepo-finance'));
        $types = ['' => __('Everything', 'sepo-finance'), 'transactions' => __('Entries', 'sepo-finance'), 'invoices' => __('Invoices', 'sepo-finance'), 'bills' => __('Bills', 'sepo-finance'), 'recurring' => __('Recurring', 'sepo-finance'), 'accounts' => __('Accounts', 'sepo-finance'), 'categories' => __('Categories', 'sepo-finance'), 'counterparties' => __('Customers and suppliers', 'sepo-finance'), 'reconciliations' => __('Reconciliations', 'sepo-finance')];
        $acts = ['' => __('Any action', 'sepo-finance')];
        foreach (['created', 'updated', 'deleted', 'restored', 'settled', 'posted', 'skipped', 'reconciled', 'ticked', 'unticked', 'imported', 'emailed', 'reminded', 'attached', 'detached', 'synced', 'reversed'] as $a) {
            $acts[$a] = Log::label($a);
        }
        $h .= '<form class="sfin-filters" method="get">' . Ui::pageField()
            . '<label class="sfin-field"><span class="sfin-field__label">' . esc_html__('Records', 'sepo-finance') . '</span><select name="type">';
        foreach ($types as $k => $l) {
            $h .= '<option value="' . esc_attr($k) . '"' . selected($type, $k, false) . '>' . esc_html($l) . '</option>';
        }
        $h .= '</select></label><label class="sfin-field"><span class="sfin-field__label">' . esc_html__('Action', 'sepo-finance') . '</span><select name="act">';
        foreach ($acts as $k => $l) {
            $h .= '<option value="' . esc_attr($k) . '"' . selected($action, $k, false) . '>' . esc_html($l) . '</option>';
        }
        $h .= '</select></label>' . ($record !== '' ? '<input type="hidden" name="record" value="' . esc_attr($record) . '">' : '') . '<button type="submit" class="sfin-btn">' . esc_html__('Show', 'sepo-finance') . '</button>'
            . ($type !== '' || $action !== '' || $record !== '' ? '<a class="sfin-link" href="' . esc_url(Menu::url('activity')) . '">' . esc_html__('Clear', 'sepo-finance') . '</a>' : '') . '</form>';
        if ($record !== '') {
            /* translators: %s: record id */
            $h .= Ui::notice(esc_html(sprintf(__('Showing the history of one record (%s).', 'sepo-finance'), $record)));
        }
        if ($q['rows'] === []) {
            echo $h . Ui::empty(esc_html__('Nothing has been recorded yet.', 'sepo-finance')); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }
        $fmt = get_option('date_format') . ' ' . get_option('time_format');
        $h .= '<div class="sfin-tablewrap"><table class="sfin-table sfin-log"><thead><tr><th>' . esc_html__('When', 'sepo-finance') . '</th><th>' . esc_html__('Who', 'sepo-finance') . '</th><th>' . esc_html__('What', 'sepo-finance') . '</th><th>' . esc_html__('Record', 'sepo-finance') . '</th></tr></thead><tbody>';
        foreach ($q['rows'] as $r) {
            $changes = '';
            $shown = array_diff_key($r['changes'], array_flip(self::HIDDEN));
            if ($shown !== []) {
                $changes = '<details class="sfin-changes"><summary>' . esc_html(sprintf(/* translators: %d: count */ _n('%d field', '%d fields', count($shown), 'sepo-finance'), count($shown))) . '</summary><dl>';
                foreach ($shown as $k => [$old, $new]) {
                    $changes .= '<dt>' . esc_html(ucfirst(str_replace(['_id', '_'], ['', ' '], (string) $k))) . '</dt><dd>'
                        . ($old !== null && $old !== '' ? '<del>' . esc_html(self::val($k, $old)) . '</del> → ' : '')
                        . '<ins>' . esc_html(self::val($k, $new)) . '</ins></dd>';
                }
                $changes .= '</dl></details>';
            }
            $h .= '<tr><td class="sfin-nowrap">' . esc_html(wp_date($fmt, (int) $r['at'])) . '</td><td>' . Ui::e($r['actor_name']) . '</td>'
                . '<td><span class="sfin-tag sfin-tag--' . esc_attr((string) $r['action']) . '">' . esc_html(Log::label((string) $r['action'])) . '</span> <span class="sfin-sub">' . esc_html($types[$r['type']] ?? $r['type']) . '</span></td>'
                . '<td><a class="sfin-link" href="' . esc_url(Menu::url('activity', ['record' => $r['record_id'], 'type' => $r['type']])) . '">' . Ui::e($r['summary'] !== '' ? $r['summary'] : $r['record_id']) . '</a>' . $changes . '</td></tr>';
        }
        $h .= '</tbody></table></div>';
        $h .= Ui::pager($q['total'], $per, $page, array_filter(['page' => 'sepo-finance-activity', 'type' => $type, 'act' => $action, 'record' => $record]));
        echo $h; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }

    /** @param mixed $v */
    private static function val(string $key, $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if (is_array($v)) {
            return (string) wp_json_encode($v);
        }
        if (is_bool($v)) {
            return $v ? __('Yes', 'sepo-finance') : __('No', 'sepo-finance');
        }
        if (in_array($key, ['amount', 'paid_amount', 'opening_balance', 't_amount', 'subtotal', 'tax'], true) && is_numeric($v)) {
            return Parse::money($v);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) === 1) {
            return Parse::show((string) $v);
        }
        return mb_strimwidth((string) $v, 0, 120, '…');
    }
}
