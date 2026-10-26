<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * The activity log: who did what to which record, and what changed.
 *
 * Every create, edit, delete, restore, payment, posting, reconciliation and
 * import is written here, with the fields that changed (before and after).
 * Entries are only ever added. A summary line is stored with each one, so the
 * entry still reads after the record itself is gone.
 */
final class Log
{
    /** Keys not worth showing as a change. */
    private const SKIP = ['updated', 'created', 'created_by'];

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public static function add(string $action, string $type, string $id, ?array $before = null, ?array $after = null, string $summary = ''): void
    {
        global $wpdb;
        $changes = [];
        if ($before !== null && $after !== null) {
            foreach ($after as $k => $v) {
                if (in_array($k, self::SKIP, true)) {
                    continue;
                }
                $old = $before[$k] ?? null;
                if (self::same($old, $v)) {
                    continue;
                }
                $changes[$k] = [$old, $v];
            }
        } elseif ($after !== null && $action === 'created') {
            foreach ($after as $k => $v) {
                if (!in_array($k, self::SKIP, true) && $v !== '' && $v !== null && $v !== [] && $v !== 0 && $v !== 0.0) {
                    $changes[$k] = [null, $v];
                }
            }
        }
        $wpdb->insert(Schema::log(), [
            'at'        => time(),
            'actor'     => get_current_user_id(),
            'action'    => sanitize_key($action),
            'type'      => sanitize_key($type),
            'record_id' => mb_substr($id, 0, 40),
            'summary'   => mb_substr($summary !== '' ? $summary : self::describe($type, $after ?? $before ?? []), 0, 255),
            'changes'   => $changes === [] ? null : wp_json_encode($changes),
        ]);
    }

    private static function same($a, $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.00005;
        }
        if (is_array($a) || is_array($b)) {
            return wp_json_encode($a) === wp_json_encode($b);
        }
        return (string) $a === (string) $b;
    }

    /** One line that says which record this was. */
    public static function describe(string $type, array $r): string
    {
        return match ($type) {
            'transactions' => trim(Parse::show((string) ($r['date'] ?? '')) . ' · ' . Parse::money($r['amount'] ?? 0, (string) ($r['currency'] ?? '')) . ' · ' . (string) ($r['description'] ?? $r['reference'] ?? ''), ' ·'),
            'invoices', 'bills' => trim((string) ($r['number'] ?? '') . ' · ' . Parse::money($r['amount'] ?? 0, (string) ($r['currency'] ?? '')), ' ·'),
            'reconciliations' => (Store::names('accounts')[(string) ($r['account_id'] ?? '')] ?? '') . ' · ' . Parse::show((string) ($r['statement_date'] ?? '')),
            default => (string) ($r['name'] ?? $r['id'] ?? ''),
        };
    }

    /**
     * @param array{type?:string,record?:string,action?:string,page?:int,per?:int} $a
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function query(array $a = []): array
    {
        global $wpdb;
        $where = ['1=1'];
        $params = [];
        foreach (['type' => 'type', 'record' => 'record_id', 'action' => 'action'] as $in => $col) {
            if (!empty($a[$in])) {
                $where[] = "$col = %s";
                $params[] = (string) $a[$in];
            }
        }
        $per = max(1, min(200, (int) ($a['per'] ?? 50)));
        $page = max(1, (int) ($a['page'] ?? 1));
        $w = implode(' AND ', $where);
        // phpcs:disable WordPress.DB.PreparedSQL -- clauses use placeholders
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::log() . " WHERE $w", ...$params)) : $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::log()));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::log() . " WHERE $w ORDER BY id DESC LIMIT %d OFFSET %d", ...array_merge($params, [$per, ($page - 1) * $per])), ARRAY_A);
        // phpcs:enable
        $out = [];
        foreach ($rows ?: [] as $r) {
            $u = (int) $r['actor'] > 0 ? get_userdata((int) $r['actor']) : null;
            $r['actor_name'] = $u ? (string) $u->display_name : ((int) $r['actor'] > 0 ? __('Removed account', 'sepo-finance') : __('Site', 'sepo-finance'));
            $c = json_decode((string) $r['changes'], true);
            $r['changes'] = is_array($c) ? $c : [];
            $out[] = $r;
        }
        return ['rows' => $out, 'total' => $total];
    }

    public static function label(string $action): string
    {
        return [
            'created'    => __('Added', 'sepo-finance'),
            'updated'    => __('Changed', 'sepo-finance'),
            'deleted'    => __('Deleted', 'sepo-finance'),
            'restored'   => __('Restored', 'sepo-finance'),
            'settled'    => __('Payment recorded', 'sepo-finance'),
            'posted'     => __('Posted', 'sepo-finance'),
            'skipped'    => __('Skipped', 'sepo-finance'),
            'reconciled' => __('Reconciliation finished', 'sepo-finance'),
            'ticked'     => __('Ticked on a statement', 'sepo-finance'),
            'unticked'   => __('Unticked on a statement', 'sepo-finance'),
            'imported'   => __('Imported', 'sepo-finance'),
            'emailed'    => __('Emailed', 'sepo-finance'),
            'reminded'   => __('Reminder sent', 'sepo-finance'),
            'attached'   => __('File attached', 'sepo-finance'),
            'detached'   => __('File removed', 'sepo-finance'),
            'synced'     => __('From Sepo Membership', 'sepo-finance'),
            'reversed'   => __('Reversed', 'sepo-finance'),
        ][$action] ?? ucfirst($action);
    }
}
