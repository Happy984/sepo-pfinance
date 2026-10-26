<?php

declare(strict_types=1);

namespace SepoFinance\Admin;

use SepoFinance\Attachments;
use SepoFinance\Documents;
use SepoFinance\Ledger;
use SepoFinance\Parse;
use SepoFinance\Plugin;
use SepoFinance\Reports;
use SepoFinance\Settings;
use SepoFinance\Store;

defined('ABSPATH') || exit;

/** Dashboard actions that are not REST calls: settings, downloads, the printable page, files. */
final class Actions
{
    public static function hook(): void
    {
        add_action('admin_post_sfin_settings', [self::class, 'settings']);
        add_action('admin_post_sfin_export', [self::class, 'export']);
        add_action('admin_post_sfin_ledger_csv', [self::class, 'ledgerCsv']);
        add_action('admin_post_sfin_print', [self::class, 'print']);
        add_action('admin_post_sfin_file', [Attachments::class, 'serve']);
    }

    private static function gate(string $nonce, bool $settings = false): void
    {
        if (!($settings ? current_user_can('manage_options') : Plugin::can())) {
            wp_die(esc_html__('You cannot do that.', 'sepo-finance'), '', ['response' => 403]);
        }
        check_admin_referer($nonce);
    }

    public static function settings(): void
    {
        self::gate('sfin_settings', true);
        $in = static fn(string $k, $d = '') => isset($_POST[$k]) ? wp_unslash($_POST[$k]) : $d; // phpcs:ignore WordPress.Security.NonceVerification -- checked in gate()
        $fs = (string) $in('fiscal_start');
        Settings::update([
            'currency'            => strtoupper(substr(preg_replace('~[^A-Za-z]~', '', (string) $in('currency')) ?: 'ZMW', 0, 3)),
            'tax_rate'            => max(0, min(100, (float) (Parse::amount($in('tax_rate')) ?? 0))),
            'fiscal_start'        => preg_match('~^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$~', $fs) ? $fs : '01-01',
            'decimals'            => max(0, min(4, (int) $in('decimals', 2))),
            'invoice_prefix'      => mb_substr(sanitize_text_field((string) $in('invoice_prefix')), 0, 16),
            'invoice_due_days'    => max(0, min(365, (int) $in('invoice_due_days', 30))),
            'company_name'        => sanitize_text_field((string) $in('company_name')),
            'company_address'     => sanitize_textarea_field((string) $in('company_address')),
            'company_email'       => sanitize_email((string) $in('company_email')),
            'company_phone'       => sanitize_text_field((string) $in('company_phone')),
            'company_tax_id'      => sanitize_text_field((string) $in('company_tax_id')),
            'company_logo'        => absint($in('company_logo', 0)),
            'payment_details'     => sanitize_textarea_field((string) $in('payment_details')),
            'invoice_footer'      => sanitize_textarea_field((string) $in('invoice_footer')),
            'reminders'           => (string) $in('reminders') === '1' ? 1 : 0,
            'reminder_every'      => max(1, min(90, (int) $in('reminder_every', 7))),
            'membership_sync'     => (string) $in('membership_sync') === '1' ? 1 : 0,
            'membership_account'  => sanitize_text_field((string) $in('membership_account')),
            'delete_on_uninstall' => (string) $in('delete_on_uninstall') === '1' ? 1 : 0,
        ]);
        wp_safe_redirect(add_query_arg('sfin_saved', '1', Menu::url('settings')));
        exit;
    }

    private static function sendCsv(string $name, array $context, array $header, array $rows): void
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '.csv"');
        $out = fopen('php://output', 'w');
        // Excel reads a UTF-8 file as Windows text unless it starts with this mark.
        fwrite($out, "\xEF\xBB\xBF");
        // A cell starting with = + - @ would run as a formula in a spreadsheet.
        $cell = static fn($v) => is_string($v) && preg_match('~^[=+\-@\t\r]~', $v) ? "'" . $v : $v;
        if ($context !== []) {
            fputcsv($out, $context);
            fputcsv($out, []);
        }
        fputcsv($out, $header);
        foreach ($rows as $r) {
            fputcsv($out, array_map($cell, $r));
        }
        fclose($out);
        exit;
    }

    /** A report as CSV or JSON, with the range and filter on screen. */
    public static function export(): void
    {
        self::gate('sfin_export');
        $views = Reports::views();
        $view = sanitize_key((string) ($_GET['view'] ?? 'pnl'));
        $view = isset($views[$view]) ? $view : 'pnl';
        [$ms, $me] = Parse::monthBounds();
        $wide = in_array($view, ['cashflow', 'tax'], true);
        $from = Parse::dateOr(sanitize_text_field((string) ($_GET['from'] ?? '')), $wide ? substr(current_time('Y-m-d'), 0, 4) . '-01-01' : $ms);
        $to = Parse::dateOr(sanitize_text_field((string) ($_GET['to'] ?? '')), $wide ? current_time('Y-m-d') : $me);
        $type = sanitize_key((string) ($_GET['type'] ?? 'expense'));
        $data = Reports::data($view, $from, $to, $type);
        $stem = 'finance-' . $view . '-' . $from . '-to-' . $to;
        if (sanitize_key((string) ($_GET['format'] ?? 'csv')) === 'json') {
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $stem . '.json"');
            echo wp_json_encode(['report' => $view, 'from' => $from, 'to' => $to, 'currency' => Settings::currency(), 'generated' => gmdate('c'), 'data' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        [$header, $rows] = Reports::rows($view, $data);
        self::sendCsv($stem, [__('Report', 'sepo-finance'), $views[$view], __('From', 'sepo-finance'), $from, __('To', 'sepo-finance'), $to, __('Currency', 'sepo-finance'), Settings::currency()], $header, $rows);
    }

    /** The ledger as on screen, as CSV. */
    public static function ledgerCsv(): void
    {
        self::gate('sfin_ledger_csv');
        $f = [];
        foreach (['from', 'to', 'account', 'category', 'counterparty', 'type', 'status', 'search'] as $k) {
            $f[$k] = sanitize_text_field(wp_unslash((string) ($_GET[$k] ?? '')));
        }
        $rows = Ledger::transactions($f);
        $acc = Store::names('accounts');
        $cat = Store::names('categories');
        $cp = Store::names('counterparties');
        $out = [];
        foreach ($rows as $t) {
            $out[] = [$t['date'], $t['type'], number_format((float) $t['amount'], 2, '.', ''), $t['currency'], $acc[$t['account_id']] ?? '', $acc[$t['to_account_id']] ?? '', $cat[$t['category_id']] ?? '', $cp[$t['counterparty_id']] ?? '', $t['description'], $t['reference'], $t['method'], $t['status'], $t['notes']];
        }
        self::sendCsv('ledger-' . ($f['from'] ?: 'all') . '-to-' . ($f['to'] ?: 'all'), [], ['Date', 'Type', 'Amount', 'Currency', 'Account', 'To account', 'Category', 'Who', 'Description', 'Reference', 'Method', 'Status', 'Notes'], $out);
    }

    /** The printable invoice or bill. */
    public static function print(): void
    {
        self::gate('sfin_print');
        $type = ($_GET['type'] ?? '') === 'bills' ? 'bills' : 'invoices';
        $doc = Store::find($type, sanitize_text_field(wp_unslash((string) ($_GET['id'] ?? ''))), true);
        if ($doc === null) {
            wp_die(esc_html__('That document could not be found.', 'sepo-finance'), '', ['response' => 404]);
        }
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        echo Documents::printable($type, $doc); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
        exit;
    }

    public static function printUrl(string $type, string $id): string
    {
        return wp_nonce_url(add_query_arg(['action' => 'sfin_print', 'type' => $type, 'id' => $id], admin_url('admin-post.php')), 'sfin_print');
    }
}
