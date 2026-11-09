<?php
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom plugin tables: names are built from $wpdb->prefix plus a fixed suffix, values go through $wpdb->prepare(), and dynamic fragments come from fixed allow-lists.
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

/** Remove the plugin's data when the site owner asked for it. */
function sepo_finance_uninstall(): void
{
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
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        (new WP_Filesystem_Direct(null))->delete($dir, true);
    }
}

sepo_finance_uninstall();
