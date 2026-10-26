<?php

declare(strict_types=1);

namespace SepoFinance\Views;

use SepoFinance\MembershipSync;
use SepoFinance\Settings;
use SepoFinance\Store;

defined('ABSPATH') || exit;

/** The settings: money, invoices, the business details on them, reminders and the Membership link. */
final class SettingsView
{
    public static function render(string $screen): void
    {
        $s = Settings::all();
        $h = Ui::head(__('Finance settings', 'sepo-finance'));
        if (Ui::query('sfin_saved') === '1') {
            $h .= Ui::notice(esc_html__('Settings saved.', 'sepo-finance'), 'good');
        }
        $f = static function (string $name, string $label, string $input, string $help = ''): string {
            $id = 'sfin-set-' . $name;
            return '<div class="sfin-field"><label class="sfin-field__label" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>' . str_replace('{id}', esc_attr($id), $input)
                . ($help !== '' ? '<p class="sfin-field__help" id="' . esc_attr($id) . '-help">' . esc_html($help) . '</p>' : '') . '</div>';
        };
        $text = static fn(string $k, string $type = 'text', string $extra = ''): string => '<input type="' . $type . '" id="{id}" name="' . esc_attr($k) . '" value="' . esc_attr((string) $s[$k]) . '"' . $extra . '>';
        $area = static fn(string $k, int $rows = 3): string => '<textarea id="{id}" name="' . esc_attr($k) . '" rows="' . $rows . '">' . esc_textarea((string) $s[$k]) . '</textarea>';
        $check = static fn(string $k, string $label): string => '<label class="sfin-check"><input type="checkbox" name="' . esc_attr($k) . '" value="1"' . checked((int) $s[$k], 1, false) . '> ' . esc_html($label) . '</label>';

        $h .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="sfin-settings">' . wp_nonce_field('sfin_settings', '_wpnonce', true, false) . '<input type="hidden" name="action" value="sfin_settings">';

        $h .= '<section class="sfin-panel"><h3>' . esc_html__('Money', 'sepo-finance') . '</h3><div class="sfin-form-grid">'
            . $f('currency', __('Main currency', 'sepo-finance'), $text('currency', 'text', ' maxlength="3" autocapitalize="characters" style="max-width:8em"'), __('The three-letter code, such as ZMW or USD. Totals and reports count accounts in this currency.', 'sepo-finance'))
            . $f('decimals', __('Decimal places', 'sepo-finance'), $text('decimals', 'number', ' min="0" max="4" style="max-width:6em"'))
            . $f('tax_rate', __('Tax rate (%)', 'sepo-finance'), $text('tax_rate', 'text', ' inputmode="decimal" style="max-width:8em"'), __('Used for invoice tax and the tax summary. 16 for standard VAT in Zambia.', 'sepo-finance'))
            . $f('fiscal_start', __('Financial year starts', 'sepo-finance'), $text('fiscal_start', 'text', ' pattern="(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])" placeholder="01-01" style="max-width:8em"'), __('Month and day, as MM-DD. 01-01 for a calendar year, 07-01 for July.', 'sepo-finance'))
            . '</div></section>';

        $logo = (int) $s['company_logo'];
        $logoUrl = $logo > 0 ? (string) wp_get_attachment_image_url($logo, 'medium') : '';
        $h .= '<section class="sfin-panel"><h3>' . esc_html__('Your business, as it appears on invoices', 'sepo-finance') . '</h3><div class="sfin-form-grid">'
            . $f('company_name', __('Business name', 'sepo-finance'), $text('company_name', 'text', ' placeholder="' . esc_attr(get_bloginfo('name')) . '"'), __('Left empty, the site title is used.', 'sepo-finance'))
            . $f('company_tax_id', __('Tax number (TPIN)', 'sepo-finance'), $text('company_tax_id'))
            . $f('company_email', __('Email', 'sepo-finance'), $text('company_email', 'email'), __('Invoices are sent with this as the reply address.', 'sepo-finance'))
            . $f('company_phone', __('Phone', 'sepo-finance'), $text('company_phone', 'tel'))
            . '<div class="sfin-span">' . $f('company_address', __('Address', 'sepo-finance'), $area('company_address')) . '</div>'
            . '<div class="sfin-field sfin-span" data-sfin-logo><span class="sfin-field__label">' . esc_html__('Logo', 'sepo-finance') . '</span><input type="hidden" name="company_logo" value="' . esc_attr((string) $logo) . '" data-logo-id>'
            . '<div class="sfin-logo"><img src="' . esc_url($logoUrl) . '" alt="" data-logo-img' . ($logoUrl === '' ? ' hidden' : '') . '>'
            . '<button type="button" class="sfin-btn" data-logo-pick>' . esc_html($logoUrl === '' ? __('Choose a logo', 'sepo-finance') : __('Change the logo', 'sepo-finance')) . '</button>'
            . '<button type="button" class="sfin-btn sfin-btn--quiet" data-logo-clear' . ($logoUrl === '' ? ' hidden' : '') . '>' . esc_html__('Remove', 'sepo-finance') . '</button></div></div>'
            . '</div></section>';

        $h .= '<section class="sfin-panel"><h3>' . esc_html__('Invoices', 'sepo-finance') . '</h3><div class="sfin-form-grid">'
            . $f('invoice_prefix', __('Number prefix', 'sepo-finance'), $text('invoice_prefix', 'text', ' maxlength="16" style="max-width:10em"'), __('New invoices are numbered with this and the next number, such as INV-0042.', 'sepo-finance'))
            . $f('invoice_due_days', __('Days to pay', 'sepo-finance'), $text('invoice_due_days', 'number', ' min="0" max="365" style="max-width:6em"'), __('A new invoice is due this many days after it is issued.', 'sepo-finance'))
            . '<div class="sfin-span">' . $f('payment_details', __('How to pay you', 'sepo-finance'), $area('payment_details', 4), __('Bank account, mobile money number and so on. Printed on every invoice.', 'sepo-finance')) . '</div>'
            . '<div class="sfin-span">' . $f('invoice_footer', __('Footer note', 'sepo-finance'), $area('invoice_footer', 2), __('A line at the bottom of every invoice, such as thanks or terms.', 'sepo-finance')) . '</div>'
            . '<div class="sfin-span">' . $check('reminders', __('Email a reminder when an invoice is overdue', 'sepo-finance')) . '</div>'
            . $f('reminder_every', __('Remind again every (days)', 'sepo-finance'), $text('reminder_every', 'number', ' min="1" max="90" style="max-width:6em"'), __('Reminders go to the customer\'s email and stop when the invoice is paid.', 'sepo-finance'))
            . '</div></section>';

        $h .= '<section class="sfin-panel"><h3>' . esc_html__('Sepo Membership', 'sepo-finance') . '</h3>';
        if (!MembershipSync::available()) {
            $h .= '<p class="sfin-muted">' . esc_html__('When Sepo Membership is active, payments confirmed there can be written to the ledger as income here.', 'sepo-finance') . '</p>';
            $h .= '<input type="hidden" name="membership_sync" value="' . esc_attr((string) (int) $s['membership_sync']) . '"><input type="hidden" name="membership_account" value="' . esc_attr((string) $s['membership_account']) . '">';
        } else {
            $opts = '<option value="">' . esc_html__('Choose an account', 'sepo-finance') . '</option>';
            foreach (Store::all('accounts') as $a) {
                if (empty($a['archived'])) {
                    $opts .= '<option value="' . esc_attr((string) $a['id']) . '"' . selected((string) $s['membership_account'], (string) $a['id'], false) . '>' . Ui::e($a['name']) . '</option>';
                }
            }
            $h .= '<div class="sfin-form-grid"><div class="sfin-span">' . $check('membership_sync', __('Record confirmed membership payments as income', 'sepo-finance')) . '</div>'
                . $f('membership_account', __('Account they are paid into', 'sepo-finance'), '<select id="{id}" name="membership_account">' . $opts . '</select>', __('A payment that is later rejected or deleted in Membership is taken back out of the ledger.', 'sepo-finance'))
                . '</div>';
        }
        $h .= '</section>';

        $h .= '<section class="sfin-panel"><h3>' . esc_html__('Removing the plugin', 'sepo-finance') . '</h3>'
            . $check('delete_on_uninstall', __('Delete all finance records and files when the plugin is deleted', 'sepo-finance'))
            . '<p class="sfin-field__help">' . esc_html__('Left off, the records stay in the database, so reinstalling brings everything back.', 'sepo-finance') . '</p></section>';

        $h .= '<p><button type="submit" class="sfin-btn sfin-btn--primary">' . esc_html__('Save settings', 'sepo-finance') . '</button></p></form>';
        echo $h; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }
}
