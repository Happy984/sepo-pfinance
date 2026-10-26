<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * Personal data held about customers, suppliers and staff: names, email
 * addresses, phone numbers and addresses on counterparties. WordPress's
 * export tool lists them by email; the erase tool clears the contact details
 * and keeps the record, renamed, because the money that moved with them is
 * part of the books.
 */
final class Privacy
{
    public static function hook(): void
    {
        add_action('admin_init', static function (): void {
            if (function_exists('wp_add_privacy_policy_content')) {
                wp_add_privacy_policy_content(__('Sepo Finance', 'sepo-finance'), wp_kses_post('<p>' . __('We keep financial records of the money we receive and pay. For customers, suppliers and staff these can include a name, email address, phone number, postal address and tax number, kept with the invoices, bills and payments they relate to. They are seen only by the people who keep our books.', 'sepo-finance') . '</p>'));
            }
        });
        add_filter('wp_privacy_personal_data_exporters', static function (array $e): array {
            $e['sepo-finance'] = ['exporter_friendly_name' => __('Sepo Finance', 'sepo-finance'), 'callback' => [self::class, 'export']];
            return $e;
        });
        add_filter('wp_privacy_personal_data_erasers', static function (array $e): array {
            $e['sepo-finance'] = ['eraser_friendly_name' => __('Sepo Finance', 'sepo-finance'), 'callback' => [self::class, 'erase']];
            return $e;
        });
    }

    /** @return list<array<string,mixed>> */
    private static function matches(string $email): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::table('counterparties') . ' WHERE email = %s', $email), ARRAY_A);
        return array_map([Store::class, 'row'], $rows ?: []);
    }

    public static function export(string $email, int $page = 1): array
    {
        $data = [];
        foreach (self::matches($email) as $c) {
            $data[] = ['group_id' => 'sfin-contacts', 'group_label' => __('Finance contacts', 'sepo-finance'), 'item_id' => 'sfin-' . $c['id'], 'data' => [
                ['name' => __('Name', 'sepo-finance'), 'value' => (string) $c['name']],
                ['name' => __('Email', 'sepo-finance'), 'value' => (string) $c['email']],
                ['name' => __('Phone', 'sepo-finance'), 'value' => (string) $c['phone']],
                ['name' => __('Address', 'sepo-finance'), 'value' => (string) $c['address']],
                ['name' => __('Tax number', 'sepo-finance'), 'value' => (string) $c['tax_id']],
            ]];
        }
        return ['data' => $data, 'done' => true];
    }

    public static function erase(string $email, int $page = 1): array
    {
        $done = false;
        foreach (self::matches($email) as $c) {
            Store::patch('counterparties', (string) $c['id'], ['name' => __('Removed contact', 'sepo-finance'), 'email' => '', 'phone' => '', 'address' => '', 'tax_id' => '', 'notes' => '', 'archived' => 1]);
            Log::add('updated', 'counterparties', (string) $c['id'], null, null, __('Contact details erased on request', 'sepo-finance'));
            $done = true;
        }
        return [
            'items_removed'  => $done,
            'items_retained' => $done,
            'messages'       => $done ? [__('The finance records are kept as accounting records, without the name or contact details.', 'sepo-finance')] : [],
            'done'           => true,
        ];
    }
}
