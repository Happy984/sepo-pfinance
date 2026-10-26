<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * Queries and sums over transactions.
 *
 * CASH BASIS. A pending entry is money promised but not moved, so it is left
 * out of balances, sums and every report unless a caller asks for it. A
 * transfer moves money between accounts and is never income or expense.
 */
final class Ledger
{
    /**
     * Transactions matching a filter, newest first.
     *
     * @param array{from?:string,to?:string,account?:string,category?:string,counterparty?:string,type?:string,status?:string,search?:string,reference?:string,include_deleted?:bool,limit?:int} $f
     * @return list<array<string,mixed>>
     */
    public static function transactions(array $f = []): array
    {
        global $wpdb;
        $where = [];
        $params = [];
        if (empty($f['include_deleted'])) {
            $where[] = 'deleted = 0';
        }
        if (($f['from'] ?? '') !== '') {
            $where[] = '`date` >= %s';
            $params[] = (string) $f['from'];
        }
        if (($f['to'] ?? '') !== '') {
            $where[] = '`date` <= %s';
            $params[] = (string) $f['to'];
        }
        if (($f['account'] ?? '') !== '') {
            // An account is on either side of a transfer.
            $where[] = '(account_id = %s OR to_account_id = %s)';
            $params[] = (string) $f['account'];
            $params[] = (string) $f['account'];
        }
        foreach (['category' => 'category_id', 'counterparty' => 'counterparty_id', 'type' => 'type', 'status' => 'status'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $where[] = "$col = %s";
                $params[] = (string) $f[$k];
            }
        }
        if (($f['reference'] ?? '') !== '') {
            $where[] = 'reference = %s';
            $params[] = (string) $f['reference'];
        }
        if (($f['search'] ?? '') !== '') {
            $like = '%' . $wpdb->esc_like((string) $f['search']) . '%';
            $where[] = '(description LIKE %s OR reference LIKE %s OR notes LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        $limit = max(1, min(50000, (int) ($f['limit'] ?? 50000)));
        $sql = 'SELECT * FROM ' . Schema::table('transactions') . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY `date` DESC, created DESC LIMIT ' . $limit;
        // phpcs:ignore WordPress.DB.PreparedSQL -- clauses use placeholders
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        return array_map([Store::class, 'row'], $rows ?: []);
    }

    /**
     * Income, expenses and net over a set of rows.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{income:float,expenses:float,net:float,count:int}
     */
    public static function sums(array $rows, bool $includePending = false): array
    {
        $in = 0.0;
        $out = 0.0;
        $n = 0;
        foreach ($rows as $r) {
            if (!empty($r['deleted']) || (!$includePending && $r['status'] === 'pending')) {
                continue;
            }
            $n++;
            if ($r['type'] === 'income') {
                $in += (float) $r['amount'];
            } elseif ($r['type'] === 'expense') {
                $out += (float) $r['amount'];
            }
        }
        return ['income' => round($in, 2), 'expenses' => round($out, 2), 'net' => round($in - $out, 2), 'count' => $n];
    }

    /**
     * Amounts by category for income or expense.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{category_id:string,amount:float,count:int}>
     */
    public static function byCategory(array $rows, string $type): array
    {
        $b = [];
        foreach ($rows as $r) {
            if ($r['type'] !== $type || !empty($r['deleted']) || $r['status'] === 'pending') {
                continue;
            }
            $c = (string) $r['category_id'] !== '' ? (string) $r['category_id'] : '__none';
            $b[$c] ??= ['category_id' => $c, 'amount' => 0.0, 'count' => 0];
            $b[$c]['amount'] += (float) $r['amount'];
            $b[$c]['count']++;
        }
        foreach ($b as &$x) {
            $x['amount'] = round($x['amount'], 2);
        }
        unset($x);
        usort($b, static fn(array $a, array $c): int => $c['amount'] <=> $a['amount']);
        return array_values($b);
    }

    /**
     * Income and expenses by month or ISO week.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{period:string,income:float,expenses:float,net:float}>
     */
    public static function byPeriod(array $rows, string $group = 'month'): array
    {
        $b = [];
        foreach ($rows as $r) {
            if ((string) $r['date'] === '' || !empty($r['deleted']) || $r['status'] === 'pending') {
                continue;
            }
            $key = $group === 'week' ? gmdate('o-\WW', strtotime($r['date'] . ' 12:00:00 UTC') ?: time()) : substr((string) $r['date'], 0, 7);
            $b[$key] ??= ['period' => $key, 'income' => 0.0, 'expenses' => 0.0, 'net' => 0.0];
            if ($r['type'] === 'income') {
                $b[$key]['income'] += (float) $r['amount'];
            } elseif ($r['type'] === 'expense') {
                $b[$key]['expenses'] += (float) $r['amount'];
            }
        }
        foreach ($b as &$x) {
            $x['income'] = round($x['income'], 2);
            $x['expenses'] = round($x['expenses'], 2);
            $x['net'] = round($x['income'] - $x['expenses'], 2);
        }
        unset($x);
        ksort($b);
        return array_values($b);
    }

    /** What one transaction does to one account's balance. */
    public static function effect(array $t, string $accountId): float
    {
        $a = (float) $t['amount'];
        if ($t['type'] === 'income' && $t['account_id'] === $accountId) {
            return $a;
        }
        if ($t['type'] === 'expense' && $t['account_id'] === $accountId) {
            return -$a;
        }
        if ($t['type'] === 'transfer') {
            $e = 0.0;
            if ($t['account_id'] === $accountId) {
                $e -= $a;
            }
            if ($t['to_account_id'] === $accountId) {
                $e += $a;
            }
            return $e;
        }
        return 0.0;
    }

    /**
     * Every account's balance, as of a date when given: opening balance plus
     * every cleared or reconciled entry that touches it. One query.
     *
     * @return array<string,float>
     */
    public static function balances(?string $asOf = null): array
    {
        global $wpdb;
        $out = [];
        foreach (Store::all('accounts', true) as $a) {
            $out[(string) $a['id']] = (float) $a['opening_balance'];
        }
        $t = Schema::table('transactions');
        $date = $asOf !== null ? $wpdb->prepare(' AND `date` <= %s', $asOf) : '';
        // phpcs:disable WordPress.DB.PreparedSQL -- table is fixed, date is prepared above
        $rows = $wpdb->get_results("SELECT account_id, to_account_id, type, SUM(amount) AS total FROM $t WHERE deleted = 0 AND status <> 'pending'$date GROUP BY account_id, to_account_id, type", ARRAY_A);
        // phpcs:enable
        foreach ($rows ?: [] as $r) {
            $amt = (float) $r['total'];
            $from = (string) $r['account_id'];
            $to = (string) $r['to_account_id'];
            if ($r['type'] === 'income') {
                $out[$from] = ($out[$from] ?? 0) + $amt;
            } elseif ($r['type'] === 'expense') {
                $out[$from] = ($out[$from] ?? 0) - $amt;
            } elseif ($r['type'] === 'transfer') {
                $out[$from] = ($out[$from] ?? 0) - $amt;
                if ($to !== '') {
                    $out[$to] = ($out[$to] ?? 0) + $amt;
                }
            }
        }
        foreach ($out as &$v) {
            $v = round($v, 2);
        }
        return $out;
    }

    /** What is still owed on an invoice or bill. */
    public static function outstanding(array $doc): float
    {
        $b = (float) $doc['amount'] - (float) $doc['paid_amount'];
        return $b > 0.004 ? round($b, 2) : 0.0;
    }

    /** Is an invoice or bill open, past due and still owed? */
    public static function isOverdue(array $doc, string $asOf = ''): bool
    {
        $asOf = $asOf !== '' ? $asOf : current_time('Y-m-d');
        return in_array($doc['status'], ['sent'], true) && self::outstanding($doc) > 0 && (string) $doc['date_due'] !== '' && $doc['date_due'] < $asOf;
    }

    /**
     * Aged balances: what is owed, by how far past due. "Current" is not yet
     * due. Only the unpaid part of each document is aged.
     *
     * @param list<array<string,mixed>> $docs
     * @return array{current:float,d30:float,d60:float,d90:float,d90plus:float,total:float}
     */
    public static function aged(array $docs, string $asOf = ''): array
    {
        $asOf = $asOf !== '' ? $asOf : current_time('Y-m-d');
        $asTs = strtotime($asOf . ' 12:00:00 UTC') ?: time();
        $o = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90plus' => 0.0, 'total' => 0.0];
        foreach ($docs as $d) {
            if (in_array($d['status'], ['paid', 'cancelled', 'draft'], true) || (string) $d['date_due'] === '' || $d['date_issued'] > $asOf) {
                continue;
            }
            $amt = self::outstanding($d);
            if ($amt <= 0) {
                continue;
            }
            $age = (int) floor(($asTs - (strtotime($d['date_due'] . ' 12:00:00 UTC') ?: $asTs)) / 86400);
            $k = $age <= 0 ? 'current' : ($age <= 30 ? 'd30' : ($age <= 60 ? 'd60' : ($age <= 90 ? 'd90' : 'd90plus')));
            $o[$k] += $amt;
            $o['total'] += $amt;
        }
        foreach ($o as &$v) {
            $v = round($v, 2);
        }
        return $o;
    }

    /** How many live records point at a record, and in words. @return array{count:int,note:string} */
    public static function references(string $type, string $id): array
    {
        global $wpdb;
        $t = Schema::table('transactions');
        $parts = [];
        $count = 0;
        $q = static fn(string $sql, ...$p): int => (int) $wpdb->get_var($wpdb->prepare($sql, ...$p));
        if ($type === 'categories') {
            $n = $q("SELECT COUNT(*) FROM $t WHERE deleted = 0 AND category_id = %s", $id);
            $c = $q('SELECT COUNT(*) FROM ' . Schema::table('categories') . ' WHERE deleted = 0 AND parent_id = %s', $id);
            if ($n) {
                /* translators: %d: count */
                $parts[] = sprintf(_n('%d entry', '%d entries', $n, 'sepo-finance'), $n);
            }
            if ($c) {
                /* translators: %d: count */
                $parts[] = sprintf(_n('%d category filed under it', '%d categories filed under it', $c, 'sepo-finance'), $c);
            }
            $count = $n + $c;
        } elseif ($type === 'counterparties') {
            $n = $q("SELECT COUNT(*) FROM $t WHERE deleted = 0 AND counterparty_id = %s", $id);
            $i = $q('SELECT COUNT(*) FROM ' . Schema::table('invoices') . ' WHERE deleted = 0 AND counterparty_id = %s', $id);
            $b = $q('SELECT COUNT(*) FROM ' . Schema::table('bills') . ' WHERE deleted = 0 AND counterparty_id = %s', $id);
            foreach ([[$n, _n('%d entry', '%d entries', $n, 'sepo-finance')], [$i, _n('%d invoice', '%d invoices', $i, 'sepo-finance')], [$b, _n('%d bill', '%d bills', $b, 'sepo-finance')]] as [$k, $label]) {
                if ($k) {
                    $parts[] = sprintf($label, $k);
                }
            }
            $count = $n + $i + $b;
        } elseif ($type === 'invoices' || $type === 'bills') {
            $col = $type === 'invoices' ? 'invoice_id' : 'bill_id';
            $count = $q("SELECT COUNT(*) FROM $t WHERE deleted = 0 AND $col = %s", $id);
            if ($count) {
                /* translators: %d: count */
                $parts[] = sprintf(_n('%d payment entry', '%d payment entries', $count, 'sepo-finance'), $count);
            }
        } elseif ($type === 'accounts') {
            $count = $q("SELECT COUNT(*) FROM $t WHERE deleted = 0 AND (account_id = %s OR to_account_id = %s)", $id, $id);
        }
        /* translators: %s: list such as "3 entries and 1 invoice" */
        return ['count' => $count, 'note' => $parts === [] ? '' : sprintf(__('%s still point at it', 'sepo-finance'), implode(__(' and ', 'sepo-finance'), $parts))];
    }
}
