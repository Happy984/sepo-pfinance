<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/** Starter categories on first activation, so the first entry has somewhere to go. */
final class Seed
{
    public static function categories(): void
    {
        if (get_option('sepo_finance_seeded')) {
            return;
        }
        global $wpdb;
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('categories')); // phpcs:ignore WordPress.DB.PreparedSQL
        if ($count === 0) {
            $list = [
                'income'  => [__('Sales', 'sepo-finance'), __('Services', 'sepo-finance'), __('Membership fees', 'sepo-finance'), __('Donations', 'sepo-finance'), __('Grants', 'sepo-finance'), __('Interest', 'sepo-finance'), __('Other income', 'sepo-finance')],
                'expense' => [__('Rent', 'sepo-finance'), __('Salaries and wages', 'sepo-finance'), __('Utilities', 'sepo-finance'), __('Internet and phone', 'sepo-finance'), __('Fuel and transport', 'sepo-finance'), __('Office supplies', 'sepo-finance'), __('Bank and mobile money charges', 'sepo-finance'), __('Taxes and levies', 'sepo-finance'), __('Repairs and maintenance', 'sepo-finance'), __('Marketing', 'sepo-finance'), __('Other expenses', 'sepo-finance')],
            ];
            foreach ($list as $type => $names) {
                foreach ($names as $name) {
                    Store::write('categories', ['id' => Fields::newId('categories'), 'name' => $name, 'type' => $type, 'parent_id' => '', 'colour' => '', 'archived' => 0]);
                }
            }
        }
        update_option('sepo_finance_seeded', 1, false);
    }

    /** Find a category by name and type, adding it when missing. */
    public static function category(string $name, string $type): string
    {
        foreach (Store::all('categories') as $c) {
            if ($c['type'] === $type && strcasecmp((string) $c['name'], $name) === 0) {
                return (string) $c['id'];
            }
        }
        $saved = Store::write('categories', ['id' => Fields::newId('categories'), 'name' => $name, 'type' => $type, 'parent_id' => '', 'colour' => '', 'archived' => 0]);
        return (string) $saved['id'];
    }
}
