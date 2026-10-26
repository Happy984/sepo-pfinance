<?php

declare(strict_types=1);

namespace SepoFinance\Admin;

use SepoFinance\Fields;
use SepoFinance\Plugin;
use SepoFinance\Recurring;
use SepoFinance\Rest;
use SepoFinance\Settings;
use SepoFinance\Store;
use SepoFinance\Views;

defined('ABSPATH') || exit;

/** The Finance menu in the dashboard, its screens and their assets. */
final class Menu
{
    public const SLUG = 'sepo-finance';

    /** @return array<string,array{0:string,1:class-string}> slug suffix => [menu label, view class] */
    public static function screens(): array
    {
        return [
            ''               => [__('Dashboard', 'sepo-finance'), Views\Dashboard::class],
            'ledger'         => [__('Ledger', 'sepo-finance'), Views\Ledger::class],
            'invoices'       => [__('Invoices', 'sepo-finance'), Views\Documents::class],
            'bills'          => [__('Bills', 'sepo-finance'), Views\Documents::class],
            'recurring'      => [__('Recurring', 'sepo-finance'), Views\RecurringView::class],
            'accounts'       => [__('Accounts', 'sepo-finance'), Views\Records::class],
            'categories'     => [__('Categories', 'sepo-finance'), Views\Records::class],
            'counterparties' => [__('Customers and suppliers', 'sepo-finance'), Views\Records::class],
            'reconcile'      => [__('Reconcile', 'sepo-finance'), Views\ReconcileView::class],
            'reports'        => [__('Reports', 'sepo-finance'), Views\ReportsView::class],
            'import'         => [__('Import statement', 'sepo-finance'), Views\ImportView::class],
            'activity'       => [__('Activity log', 'sepo-finance'), Views\Activity::class],
            'settings'       => [__('Settings', 'sepo-finance'), Views\SettingsView::class],
        ];
    }

    public static function hook(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_filter('admin_body_class', static function (string $c): string {
            return self::current() !== null ? $c . ' sfin-admin' : $c;
        });
        add_filter('plugin_action_links_' . plugin_basename(SFIN_FILE), static function (array $links): array {
            array_unshift($links, '<a href="' . esc_url(self::url('settings')) . '">' . esc_html__('Settings', 'sepo-finance') . '</a>');
            return $links;
        });
    }

    public static function url(string $screen = '', array $args = []): string
    {
        return add_query_arg(array_merge(['page' => $screen === '' ? self::SLUG : self::SLUG . '-' . $screen], $args), admin_url('admin.php'));
    }

    /** The screen being shown, or null when it is not one of ours. */
    public static function current(): ?string
    {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ($page === self::SLUG) {
            return '';
        }
        if (str_starts_with($page, self::SLUG . '-')) {
            $s = substr($page, strlen(self::SLUG) + 1);
            return isset(self::screens()[$s]) ? $s : null;
        }
        return null;
    }

    public static function menu(): void
    {
        $cap = current_user_can(Plugin::CAP) ? Plugin::CAP : 'manage_options';
        $due = count(Recurring::due());
        $badge = $due > 0 ? ' <span class="awaiting-mod">' . (int) $due . '</span>' : '';
        add_menu_page(__('Finance', 'sepo-finance'), __('Finance', 'sepo-finance') . $badge, $cap, self::SLUG, [self::class, 'render'], 'dashicons-chart-area', 57);
        foreach (self::screens() as $slug => [$label]) {
            $c = $slug === 'settings' ? 'manage_options' : $cap;
            add_submenu_page(self::SLUG, $label, $label . ($slug === 'recurring' ? $badge : ''), $c, $slug === '' ? self::SLUG : self::SLUG . '-' . $slug, [self::class, 'render']);
        }
    }

    public static function render(): void
    {
        $screen = self::current();
        if ($screen === null || !Plugin::can()) {
            return;
        }
        [, $class] = self::screens()[$screen];
        echo '<div class="wrap sfin-page"><h1 class="screen-reader-text">' . esc_html(self::screens()[$screen][0]) . '</h1>';
        echo '<div class="sfin-scope sfin-wrap" data-sfin-root data-screen="' . esc_attr($screen === '' ? 'dashboard' : $screen) . '">';
        $class::render($screen);
        echo '</div></div>';
    }

    public static function assets(): void
    {
        $screen = self::current();
        if ($screen === null) {
            return;
        }
        wp_enqueue_style('sepo-finance', SFIN_URL . 'assets/css/finance.css', [], Plugin::assetVersion('assets/css/finance.css'));
        wp_enqueue_script('sepo-finance', SFIN_URL . 'assets/js/finance.js', [], Plugin::assetVersion('assets/js/finance.js'), true);
        if (in_array($screen, ['ledger', 'settings'], true)) {
            wp_enqueue_media();
        }
        wp_add_inline_script('sepo-finance', 'window.SepoFinance=' . wp_json_encode([
            'api'      => esc_url_raw(rest_url(Rest::NS)),
            'nonce'    => wp_create_nonce('wp_rest'),
            'currency' => Settings::currency(),
            'decimals' => Settings::decimals(),
            'locale'   => str_replace('_', '-', get_user_locale()),
            'today'    => current_time('Y-m-d'),
            'dateFormat' => (string) get_option('date_format'),
            'pages'    => array_map(static fn(string $s): string => self::url($s), array_combine(array_keys(self::screens()), array_keys(self::screens()))),
            'refs'     => Store::refs(),
            'i18n'     => Strings::all(),
        ], JSON_HEX_TAG | JSON_HEX_AMP) . ';window.SepoFinanceSpec=' . Fields::json(['transactions', 'accounts', 'categories', 'counterparties', 'invoices', 'bills', 'recurring']) . ';', 'before');
    }
}
