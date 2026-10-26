<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/** The module's settings, in one option. */
final class Settings
{
    public const OPTION = 'sepo_finance_settings';

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'currency'           => 'ZMW',
            'tax_rate'           => 0,
            'fiscal_start'       => '01-01',
            'decimals'           => 2,
            'invoice_prefix'     => 'INV-',
            'invoice_due_days'   => 30,
            'company_name'       => '',
            'company_address'    => '',
            'company_email'      => '',
            'company_phone'      => '',
            'company_tax_id'     => '',
            'company_logo'       => 0,
            'payment_details'    => '',
            'invoice_footer'     => '',
            'reminders'          => 0,
            'reminder_every'     => 7,
            'membership_sync'    => 1,
            'membership_account' => '',
            'delete_on_uninstall' => 0,
        ];
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $o = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($o) ? $o : []);
    }

    /** @return mixed */
    public static function get(string $key)
    {
        return self::all()[$key] ?? null;
    }

    /** @param array<string,mixed> $values */
    public static function update(array $values): void
    {
        update_option(self::OPTION, array_merge(self::all(), $values), false);
    }

    public static function currency(): string
    {
        $c = strtoupper(preg_replace('~[^A-Za-z]~', '', (string) self::get('currency')) ?? '');
        return $c !== '' ? substr($c, 0, 3) : 'ZMW';
    }

    public static function decimals(): int
    {
        return max(0, min(4, (int) self::get('decimals')));
    }

    public static function companyName(): string
    {
        $n = trim((string) self::get('company_name'));
        return $n !== '' ? $n : wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
    }
}
