<?php

declare(strict_types=1);

namespace SepoFinance\Views;

use SepoFinance\Admin\Menu;
use SepoFinance\Parse;
use SepoFinance\Recurring;
use SepoFinance\Settings;
use SepoFinance\Store;

defined('ABSPATH') || exit;

/** Rent, salaries and subscriptions: entries that repeat, posted when they fall due. */
final class RecurringView
{
    public static function render(string $screen): void
    {
        $cur = Settings::currency();
        $rules = Store::all('recurring');
        $due = Recurring::due();
        $names = ['accounts' => Store::names('accounts'), 'categories' => Store::names('categories'), 'counterparties' => Store::names('counterparties')];
        $freq = ['weekly' => __('Weekly', 'sepo-finance'), 'monthly' => __('Monthly', 'sepo-finance'), 'quarterly' => __('Quarterly', 'sepo-finance'), 'yearly' => __('Yearly', 'sepo-finance')];

        $h = Ui::head(__('Recurring', 'sepo-finance'), esc_html__('Entries that repeat. Each one waits here until its date, then you post it with one click, or skip it for a month it does not apply.', 'sepo-finance'),
            '<button type="button" class="sfin-btn sfin-btn--primary" data-sfin-new="recurring">' . esc_html__('Add recurring entry', 'sepo-finance') . '</button>');
        if (Store::all('accounts') === []) {
            echo $h . Ui::empty('<p>' . esc_html__('Add an account first.', 'sepo-finance') . '</p><p><a class="sfin-btn sfin-btn--primary" href="' . esc_url(Menu::url('accounts')) . '">' . esc_html__('Add an account', 'sepo-finance') . '</a></p>'); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }
        if ($due !== []) {
            /* translators: %d: count */
            $h .= Ui::notice('<strong>' . esc_html(sprintf(_n('%d entry is due to be posted.', '%d entries are due to be posted.', count($due), 'sepo-finance'), count($due))) . '</strong> ' . esc_html__('Post each when the money has moved.', 'sepo-finance'), 'warn');
        }
        if ($rules === []) {
            echo $h . Ui::empty(esc_html__('Nothing repeats yet. Add the rent, salaries, subscriptions and anything else that comes round on a schedule.', 'sepo-finance')); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }
        $today = current_time('Y-m-d');
        $monthly = 0.0;
        $factor = ['weekly' => 52 / 12, 'monthly' => 1, 'quarterly' => 1 / 3, 'yearly' => 1 / 12];
        foreach ($rules as $r) {
            if (!empty($r['active']) && $r['t_type'] !== 'transfer') {
                $monthly += ($r['t_type'] === 'income' ? 1 : -1) * (float) $r['t_amount'] * ($factor[$r['frequency']] ?? 1);
            }
        }
        /* translators: %s: amount */
        $h .= '<p class="sfin-muted">' . esc_html(sprintf(__('Together the active rules come to about %s a month.', 'sepo-finance'), Parse::signed(round($monthly, 2), $cur))) . '</p>';
        $h .= '<div class="sfin-tablewrap"><table class="sfin-table" data-sfin-table><thead><tr><th>' . esc_html__('Name', 'sepo-finance') . '</th><th>' . esc_html__('Repeats', 'sepo-finance') . '</th><th>' . esc_html__('Next due', 'sepo-finance') . '</th><th>' . esc_html__('Account', 'sepo-finance') . '</th><th>' . esc_html__('Category', 'sepo-finance') . '</th><th class="sfin-num">' . esc_html__('Amount', 'sepo-finance') . '</th><th></th></tr></thead><tbody>';
        foreach ($rules as $r) {
            $isDue = !empty($r['active']) && $r['next_due'] !== '' && $r['next_due'] <= $today;
            $sign = $r['t_type'] === 'income' ? '+' : ($r['t_type'] === 'expense' ? '−' : '');
            $h .= '<tr data-type="recurring" data-id="' . esc_attr((string) $r['id']) . '" data-record="' . Ui::data($r) . '" class="' . (empty($r['active']) ? 'sfin-row--muted' : ($isDue ? 'sfin-row--late' : '')) . '">'
                . '<td><strong>' . Ui::e($r['name']) . '</strong>' . ($r['t_description'] !== '' && $r['t_description'] !== $r['name'] ? '<br><span class="sfin-sub">' . Ui::e($r['t_description']) . '</span>' : '') . (empty($r['active']) ? ' <span class="sfin-tag">' . esc_html__('Paused', 'sepo-finance') . '</span>' : '') . '</td>'
                . '<td>' . Ui::e($freq[$r['frequency']] ?? $r['frequency']) . '</td>'
                . '<td class="sfin-nowrap">' . Ui::e(Parse::show((string) $r['next_due'])) . ($isDue ? ' ' . Ui::statusPill('pending', __('Due', 'sepo-finance')) : '') . ($r['last_created'] !== '' ? '<br><span class="sfin-sub">' . esc_html(sprintf(/* translators: %s: date */ __('last posted %s', 'sepo-finance'), Parse::show((string) $r['last_created']))) . '</span>' : '') . '</td>'
                . '<td>' . Ui::e($names['accounts'][$r['t_account_id']] ?? '') . ($r['t_type'] === 'transfer' ? ' → ' . Ui::e($names['accounts'][$r['t_to_account_id']] ?? '') : '') . '</td>'
                . '<td>' . Ui::e($names['categories'][$r['t_category_id']] ?? '') . ($r['t_counterparty_id'] !== '' ? '<br><span class="sfin-sub">' . Ui::e($names['counterparties'][$r['t_counterparty_id']] ?? '') . '</span>' : '') . '</td>'
                . '<td class="sfin-num ' . ($r['t_type'] === 'income' ? 'sfin-in' : ($r['t_type'] === 'expense' ? 'sfin-out' : '')) . '">' . esc_html($sign . Parse::money($r['t_amount'], $cur)) . '</td>'
                . '<td class="sfin-actions">' . (!empty($r['active']) ? '<button type="button" class="sfin-link' . ($isDue ? ' sfin-link--strong' : '') . '" data-act="post">' . esc_html($isDue ? __('Post now', 'sepo-finance') : __('Post early', 'sepo-finance')) . '</button><button type="button" class="sfin-link" data-act="skip">' . esc_html__('Skip', 'sepo-finance') . '</button>' : '')
                . '<button type="button" class="sfin-link" data-act="edit">' . esc_html__('Edit', 'sepo-finance') . '</button><button type="button" class="sfin-link sfin-link--danger" data-act="delete">' . esc_html__('Delete', 'sepo-finance') . '</button></td></tr>';
        }
        $h .= '</tbody></table></div>';
        echo $h; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }
}
