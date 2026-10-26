<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * Wires the plugin together and keeps the database in step with the code.
 *
 * Who may use Finance: anyone with the `sepo_finance_manage` capability.
 * Administrators are given it on activation, and the Bookkeeper role has it
 * and nothing else beyond reading, so a bookkeeper can keep the books without
 * being able to change the rest of the site.
 */
final class Plugin
{
    public const CAP = 'sepo_finance_manage';
    public const ROLE = 'sepo_bookkeeper';
    public const VERSION_OPTION = 'sepo_finance_version';
    public const CRON = 'sepo_finance_daily';

    public static function activate(): void
    {
        Schema::install();
        Files::protect();
        self::roles();
        Seed::categories();
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 900, 'daily', self::CRON);
        }
        update_option(self::VERSION_OPTION, SFIN_VERSION);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    public static function roles(): void
    {
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap(self::CAP)) {
            $admin->add_cap(self::CAP);
        }
        // view_admin_dashboard lets shop plugins that keep customers out of the dashboard (WooCommerce) let a bookkeeper in.
        $caps = ['read' => true, self::CAP => true, 'upload_files' => true, 'view_admin_dashboard' => true];
        $role = get_role(self::ROLE);
        if (!$role) {
            add_role(self::ROLE, __('Bookkeeper', 'sepo-finance'), $caps);
        } else {
            foreach ($caps as $cap => $grant) {
                if (!$role->has_cap($cap)) {
                    $role->add_cap($cap, $grant);
                }
            }
        }
    }

    public static function boot(): void
    {
        add_action('init', static function (): void {
            load_plugin_textdomain('sepo-finance', false, dirname(plugin_basename(SFIN_FILE)) . '/languages');
        }, 1);

        Rest::hook();
        MembershipSync::hook();
        Privacy::hook();
        add_action(self::CRON, [Documents::class, 'sendReminders']);

        if (is_admin()) {
            Admin\Menu::hook();
            Admin\Actions::hook();
        }

        add_action('init', static function (): void {
            if (get_option(self::VERSION_OPTION) !== SFIN_VERSION) {
                Schema::install();
                Files::protect();
                self::roles();
                update_option(self::VERSION_OPTION, SFIN_VERSION);
            }
            if (!wp_next_scheduled(self::CRON)) {
                wp_schedule_event(time() + 900, 'daily', self::CRON);
            }
        }, 99);
    }

    public static function can(): bool
    {
        return current_user_can(self::CAP) || current_user_can('manage_options');
    }

    /** The plugin version plus the file's change time, so browsers fetch an edited file at once. */
    public static function assetVersion(string $rel): string
    {
        $file = SFIN_DIR . $rel;
        return SFIN_VERSION . (is_file($file) ? '.' . filemtime($file) : '');
    }
}
