<?php

/**
 * Plugin Name:       Sepo Finance
 * Description:       Money in, money out, and where the business stands: a ledger with quick entry, accounts, categories, customers and suppliers, invoices and bills with part payments, recurring entries, statement reconciliation, bank CSV import, receipts, and reports you can print or export. Runs on WordPress alone.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Euneolink Solutions
 * Author URI:        https://sepodesk.com
 * Plugin URI:        https://edu.sepodesk.com
 * License:           GPL-2.0-or-later
 * Text Domain:       sepo-finance
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Sepo Finance needs PHP 8.1 or newer. The plugin is installed but not running.', 'sepo-finance')
            . '</p></div>';
    });
    return;
}

define('SFIN_VERSION', '1.0.0');
define('SFIN_FILE', __FILE__);
define('SFIN_DIR', plugin_dir_path(__FILE__));
define('SFIN_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'SepoFinance\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = SFIN_DIR . 'includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, ['SepoFinance\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['SepoFinance\\Plugin', 'deactivate']);

add_action('plugins_loaded', ['SepoFinance\\Plugin', 'boot'], 20);
