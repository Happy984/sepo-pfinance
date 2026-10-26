<?php

declare(strict_types=1);

namespace SepoFinance\Views;

use SepoFinance\Admin\Menu;
use SepoFinance\Store;

defined('ABSPATH') || exit;

/**
 * Bring a bank or mobile money statement in from CSV, in three steps: choose
 * the file, say which column is which, then check the rows before adding
 * them. Rows already in the ledger are found and left unticked. The steps run
 * in the page; this prints their frame.
 */
final class ImportView
{
    public static function render(string $screen): void
    {
        $h = Ui::head(__('Import a statement', 'sepo-finance'), esc_html__('Add entries from a CSV statement instead of typing them. Nothing is added until you have checked the rows.', 'sepo-finance'),
            '<a class="sfin-btn sfin-btn--quiet" href="' . esc_url(Menu::url('ledger')) . '">' . esc_html__('Back to the ledger', 'sepo-finance') . '</a>');
        $accounts = array_values(array_filter(Store::all('accounts'), static fn(array $a): bool => empty($a['archived'])));
        if ($accounts === []) {
            echo $h . Ui::empty('<p>' . esc_html__('Add the account the statement is for first.', 'sepo-finance') . '</p><p><a class="sfin-btn sfin-btn--primary" href="' . esc_url(Menu::url('accounts')) . '">' . esc_html__('Add an account', 'sepo-finance') . '</a></p>'); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }
        $pre = Ui::query('account');
        $opts = '';
        foreach ($accounts as $a) {
            $opts .= '<option value="' . esc_attr((string) $a['id']) . '"' . selected($pre, (string) $a['id'], false) . '>' . Ui::e($a['name']) . '</option>';
        }
        $steps = [__('Choose the file', 'sepo-finance'), __('Match the columns', 'sepo-finance'), __('Check and add', 'sepo-finance')];
        $h .= '<div data-sfin-import>';
        $h .= '<ol class="sfin-steps" aria-label="' . esc_attr__('Steps', 'sepo-finance') . '">';
        foreach ($steps as $i => $s) {
            $h .= '<li class="sfin-steps__item' . ($i === 0 ? ' is-on' : '') . '" data-step-marker="' . ($i + 1) . '"' . ($i === 0 ? ' aria-current="step"' : '') . '><span class="sfin-steps__n">' . ($i + 1) . '</span>' . esc_html($s) . '</li>';
        }
        $h .= '</ol>';

        $h .= '<section class="sfin-panel" data-step="1"><form data-import-read class="sfin-form-grid">'
            . '<label class="sfin-field"><span class="sfin-field__label">' . esc_html__('Account', 'sepo-finance') . '</span><select name="account_id" required>' . $opts . '</select></label>'
            . '<label class="sfin-field"><span class="sfin-field__label">' . esc_html__('Statement file (CSV)', 'sepo-finance') . '</span><input type="file" name="file" accept=".csv,.txt,.tsv,text/csv" required></label>'
            . '<p class="sfin-sub sfin-span">' . esc_html__('Most banks and mobile money services let you download a statement as CSV. If yours gives Excel, open it and save it as CSV. Up to 3,000 rows and 5 MB.', 'sepo-finance') . '</p>'
            . '<div class="sfin-span"><button type="submit" class="sfin-btn sfin-btn--primary">' . esc_html__('Read the file', 'sepo-finance') . '</button></div></form></section>';

        $h .= '<section class="sfin-panel" data-step="2" hidden><h3>' . esc_html__('Which column is which?', 'sepo-finance') . '</h3>'
            . '<p class="sfin-muted">' . esc_html__('A guess has been made from the headings. Check it against the sample below.', 'sepo-finance') . '</p>'
            . '<div class="sfin-form-grid" data-import-map></div>'
            . '<fieldset class="sfin-options"><legend class="screen-reader-text">' . esc_html__('Options', 'sepo-finance') . '</legend>'
            . '<label><input type="checkbox" data-map-opt="header"> ' . esc_html__('The first row is headings', 'sepo-finance') . '</label>'
            . '<label><input type="checkbox" data-map-opt="month_first"> ' . esc_html__('Dates are written month first (04/03/2026 is 3 April)', 'sepo-finance') . '</label>'
            . '<label><input type="checkbox" data-map-opt="invert"> ' . esc_html__('The statement shows money out as positive numbers', 'sepo-finance') . '</label></fieldset>'
            . '<div class="sfin-tablewrap" data-import-sample></div>'
            . '<div class="sfin-toolbar"><button type="button" class="sfin-btn sfin-btn--quiet" data-import-back="1">' . esc_html__('Choose another file', 'sepo-finance') . '</button><button type="button" class="sfin-btn sfin-btn--primary" data-import-preview>' . esc_html__('Check the rows', 'sepo-finance') . '</button></div></section>';

        $h .= '<section class="sfin-panel" data-step="3" hidden><div data-import-counts class="sfin-tiles"></div>'
            . '<div class="sfin-form-grid">'
            . '<label class="sfin-field"><span class="sfin-field__label">' . esc_html__('Category for money in', 'sepo-finance') . '</span><select data-ref="categories" data-filter="income" data-import-cat="income"></select></label>'
            . '<label class="sfin-field"><span class="sfin-field__label">' . esc_html__('Category for money out', 'sepo-finance') . '</span><select data-ref="categories" data-filter="expense" data-import-cat="expense"></select></label>'
            . '</div><p class="sfin-sub">' . esc_html__('Rows can have their category changed one by one below. Leave one empty to sort it later in the ledger.', 'sepo-finance') . '</p>'
            . '<div class="sfin-tablewrap" data-import-rows></div>'
            . '<div class="sfin-toolbar"><button type="button" class="sfin-btn sfin-btn--quiet" data-import-back="2">' . esc_html__('Back to the columns', 'sepo-finance') . '</button><button type="button" class="sfin-btn sfin-btn--primary" data-import-save>' . esc_html__('Add the ticked rows', 'sepo-finance') . '</button></div></section>';

        $h .= '<section class="sfin-panel" data-step="4" hidden><div data-import-done></div><div class="sfin-toolbar"><a class="sfin-btn sfin-btn--primary" data-import-ledger href="' . esc_url(Menu::url('ledger')) . '">' . esc_html__('See them in the ledger', 'sepo-finance') . '</a><a class="sfin-btn" href="' . esc_url(Menu::url('import')) . '">' . esc_html__('Import another', 'sepo-finance') . '</a></div></section>';
        $h .= '</div>';
        echo $h; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }
}
