<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * Reading and writing records.
 *
 * Writes are upserts keyed on the id. Lists are read once per request and
 * kept, and any write to a type forgets that type's lists, so one page that
 * asks for the accounts four times reads them once and never sees a stale
 * copy after a save.
 *
 * Records come back with their numbers as floats, flags as ints, and the JSON
 * columns (attachments, lines) decoded.
 */
final class Store
{
    /** @var array<string,array<string,mixed>> */
    private static array $memo = [];
    /** @var array<string,list<array<string,mixed>>> */
    private static array $lists = [];

    private const NUMERIC = ['amount', 'opening_balance', 'paid_amount', 'subtotal', 'tax', 'tax_rate', 'statement_balance', 'cleared_balance', 'difference', 't_amount'];
    private const INTS = ['archived', 'active', 'deleted', 'created', 'updated', 'created_by', 'sent_at', 'reminded_at'];
    private const JSON = ['attachments', 'lines'];

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function row(array $r): array
    {
        foreach ($r as $k => $v) {
            if (in_array($k, self::NUMERIC, true)) {
                $r[$k] = round((float) $v, 4);
            } elseif (in_array($k, self::INTS, true)) {
                $r[$k] = (int) $v;
            } elseif (in_array($k, self::JSON, true)) {
                $d = is_string($v) ? json_decode($v, true) : $v;
                $r[$k] = is_array($d) ? $d : [];
            } elseif ($v === null) {
                $r[$k] = '';
            } elseif (is_string($v) && str_starts_with($v, '1970-01-01')) {
                $r[$k] = '';
            }
        }
        return $r;
    }

    /** @return array<string,mixed>|null */
    public static function find(string $type, string $id, bool $withDeleted = false): ?array
    {
        global $wpdb;
        if ($id === '') {
            return null;
        }
        $key = $type . ':' . $id;
        if (!array_key_exists($key, self::$memo)) {
            $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table($type) . ' WHERE id = %s', $id), ARRAY_A);
            self::$memo[$key] = is_array($r) ? self::row($r) : null;
        }
        $row = self::$memo[$key];
        if ($row === null || (!$withDeleted && !empty($row['deleted']))) {
            return null;
        }
        return $row;
    }

    /**
     * Every record of a type, newest first (or by name for reference lists).
     *
     * @return list<array<string,mixed>>
     */
    public static function all(string $type, bool $withDeleted = false): array
    {
        global $wpdb;
        $key = $type . ':' . ($withDeleted ? '1' : '0');
        if (isset(self::$lists[$key])) {
            return self::$lists[$key];
        }
        $order = match ($type) {
            'transactions'        => '`date` DESC, created DESC',
            'invoices', 'bills'   => 'date_issued DESC, created DESC',
            'reconciliations'     => 'statement_date DESC',
            'recurring'           => 'next_due ASC, name ASC',
            default               => 'archived ASC, name ASC',
        };
        $sql = 'SELECT * FROM ' . Schema::table($type) . ($withDeleted ? '' : ' WHERE deleted = 0') . ' ORDER BY ' . $order;
        $rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL -- table and order are fixed
        $out = [];
        foreach ($rows ?: [] as $r) {
            $row = self::row($r);
            self::$memo[$type . ':' . $row['id']] = $row;
            $out[] = $row;
        }
        return self::$lists[$key] = $out;
    }

    /**
     * Save a record, inserting or replacing by id. Housekeeping fields are
     * filled in. Returns the record as stored.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public static function write(string $type, array $record): array
    {
        global $wpdb;
        $id = trim((string) ($record['id'] ?? ''));
        if ($id === '' || preg_match('/^[a-z]+_[A-Za-z0-9_]+$/', $id) !== 1 || strlen($id) > 40) {
            throw new \InvalidArgumentException(__('That record id is not valid.', 'sepo-finance'));
        }
        $existing = self::find($type, $id, true);
        $now = time();
        $data = ['id' => $id];
        foreach (array_keys(Schema::columns($type)) as $col) {
            $v = array_key_exists($col, $record) ? $record[$col] : ($existing[$col] ?? null);
            if (in_array($col, self::JSON, true)) {
                $v = wp_json_encode(is_array($v) ? array_values($v) : []);
            } elseif ($v === null) {
                continue;
            } elseif (is_bool($v)) {
                $v = $v ? 1 : 0;
            } elseif ($v === '' && str_contains(Schema::columns($type)[$col], 'date ')) {
                $v = '1970-01-01';
            }
            $data[$col] = $v;
        }
        $data['created'] = (int) ($existing['created'] ?? ($record['created'] ?? $now)) ?: $now;
        $data['updated'] = $now;
        $data['created_by'] = (int) ($existing['created_by'] ?? ($record['created_by'] ?? get_current_user_id()));
        $data['deleted'] = (int) ($record['deleted'] ?? ($existing['deleted'] ?? 0));
        $ok = $wpdb->replace(Schema::table($type), $data);
        if ($ok === false) {
            throw new \RuntimeException(__('The record could not be saved.', 'sepo-finance'));
        }
        self::forget($type, $id);
        return self::find($type, $id, true) ?? $data;
    }

    /** Mark a record deleted. It stays in the table for Undo and the log. */
    public static function softDelete(string $type, string $id): bool
    {
        global $wpdb;
        $ok = $wpdb->update(Schema::table($type), ['deleted' => 1, 'updated' => time()], ['id' => $id]);
        self::forget($type, $id);
        return $ok !== false;
    }

    public static function restore(string $type, string $id): bool
    {
        global $wpdb;
        $ok = $wpdb->update(Schema::table($type), ['deleted' => 0, 'updated' => time()], ['id' => $id]);
        self::forget($type, $id);
        return $ok !== false;
    }

    /** Change a few columns without rebuilding the record. @param array<string,mixed> $fields */
    public static function patch(string $type, string $id, array $fields): ?array
    {
        global $wpdb;
        foreach ($fields as $k => $v) {
            if (in_array($k, self::JSON, true)) {
                $fields[$k] = wp_json_encode(is_array($v) ? array_values($v) : []);
            }
        }
        $fields['updated'] = time();
        $wpdb->update(Schema::table($type), $fields, ['id' => $id]);
        self::forget($type, $id);
        return self::find($type, $id, true);
    }

    public static function forget(string $type, string $id = ''): void
    {
        unset(self::$lists[$type . ':0'], self::$lists[$type . ':1']);
        if ($id !== '') {
            unset(self::$memo[$type . ':' . $id]);
        }
    }

    /** A name lookup for a reference type: id => name. @return array<string,string> */
    public static function names(string $type): array
    {
        $out = [];
        foreach (self::all($type, true) as $r) {
            $out[(string) $r['id']] = (string) ($r['name'] ?? $r['number'] ?? $r['id']);
        }
        return $out;
    }

    /**
     * A reference list for dropdowns: id, name, type, archived.
     *
     * @return list<array{id:string,name:string,type:string,archived:int}>
     */
    public static function refList(string $type): array
    {
        $out = [];
        foreach (self::all($type) as $r) {
            $out[] = ['id' => (string) $r['id'], 'name' => (string) ($r['name'] ?? $r['id']), 'type' => (string) ($r['type'] ?? ''), 'archived' => (int) ($r['archived'] ?? 0)];
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /** @return array<string,list<array<string,mixed>>> */
    public static function refs(): array
    {
        return ['accounts' => self::refList('accounts'), 'categories' => self::refList('categories'), 'counterparties' => self::refList('counterparties')];
    }
}
