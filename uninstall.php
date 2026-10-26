<?php
/**
 * Removes the plugin's data when it is deleted, if the setting asks for it:
 * the finance tables, the activity log, the settings, the Bookkeeper role and
 * the private receipts folder. Media library files attached to entries stay
 * in the library.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('sepo_finance_daily');

$settings = get_option('sepo_finance_settings', []);
if (empty($settings['delete_on_uninstall'])) {
    return;
}

global $wpdb;
foreach (['transactions', 'accounts', 'categories', 'counterparties', 'invoices', 'bills', 'recurring', 'reconciliations', 'log'] as $t) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'sfin_' . $t); // phpcs:ignore WordPress.DB.PreparedSQL
}
foreach (['sepo_finance_settings', 'sepo_finance_version', 'sepo_finance_seeded'] as $o) {
    delete_option($o);
}
remove_role('sepo_bookkeeper');
foreach (wp_roles()->role_objects as $role) {
    if ($role->has_cap('sepo_finance_manage')) {
        $role->remove_cap('sepo_finance_manage');
    }
}

$uploads = wp_upload_dir(null, false);
$dir = trailingslashit($uploads['basedir']) . 'sepo-finance-private';
if (is_dir($dir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}
