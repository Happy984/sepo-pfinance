<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * Statement reconciliation: tick off what the bank or mobile money statement
 * shows until the ticked total matches its closing balance.
 *
 * A ticked entry is marked "reconciled"; unticking puts it back to "cleared".
 * A pending entry cannot be ticked: money that has not moved cannot be on a
 * statement, and quietly promoting it would change the balance being checked.
 *
 * A finished reconciliation is kept per account and statement date. Finishing
 * the same pair again updates it rather than adding a second one.
 */
final class Reconcile
{
    public static function idFor(string $accountId, string $date): string
    {
        return 'rec_' . (preg_replace('/[^A-Za-z0-9_]/', '', $accountId) ?? '') . '_' . str_replace('-', '', $date);
    }

    /** Entries on the account up to the statement date, oldest first. @return list<array<string,mixed>> */
    public static function candidates(string $accountId, string $date): array
    {
        $rows = Ledger::transactions(['account' => $accountId, 'to' => $date]);
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']) ?: ($a['created'] <=> $b['created']));
        return $rows;
    }

    /** Opening balance plus every reconciled entry on the account up to the date. */
    public static function ticked(string $accountId, string $date): float
    {
        $acct = Store::find('accounts', $accountId, true);
        $total = (float) ($acct['opening_balance'] ?? 0);
        foreach (self::candidates($accountId, $date) as $t) {
            if ($t['status'] === 'reconciled') {
                $total += Ledger::effect($t, $accountId);
            }
        }
        return round($total, 2);
    }

    /** Tick or untick one entry. */
    public static function tick(string $txnId, bool $on): array
    {
        $t = Store::find('transactions', $txnId);
        if ($t === null) {
            throw new \InvalidArgumentException(__('That entry is no longer in the ledger.', 'sepo-finance'));
        }
        if ($on && $t['status'] === 'pending') {
            throw new \InvalidArgumentException(__('A pending entry has not moved any money yet, so it cannot be on a statement. Mark it cleared first if it has.', 'sepo-finance'));
        }
        $next = $on ? 'reconciled' : 'cleared';
        if ($t['status'] !== $next) {
            Store::patch('transactions', $txnId, ['status' => $next]);
            Log::add($on ? 'ticked' : 'unticked', 'transactions', $txnId, null, null, Log::describe('transactions', $t));
        }
        return Store::find('transactions', $txnId) ?? $t;
    }

    public static function finish(string $accountId, string $date, float $statement): array
    {
        if (Store::find('accounts', $accountId) === null) {
            throw new \InvalidArgumentException(__('Choose an account.', 'sepo-finance'));
        }
        $id = self::idFor($accountId, $date);
        $ticked = self::ticked($accountId, $date);
        $diff = round($ticked - $statement, 2);
        $rec = Store::write('reconciliations', [
            'id'                => $id,
            'account_id'        => $accountId,
            'statement_date'    => $date,
            'statement_balance' => round($statement, 2),
            'cleared_balance'   => $ticked,
            'difference'        => $diff,
            'status'            => abs($diff) < 0.01 ? 'balanced' : 'pending',
            'deleted'           => 0,
        ]);
        Log::add('reconciled', 'reconciliations', $id, null, null, Log::describe('reconciliations', $rec) . ' · ' . (abs($diff) < 0.01 ? __('balanced', 'sepo-finance') : sprintf(/* translators: %s: amount */ __('difference %s', 'sepo-finance'), Parse::signed($diff))));
        return $rec;
    }

    /** @return list<array<string,mixed>> */
    public static function history(string $accountId = ''): array
    {
        $rows = Store::all('reconciliations');
        if ($accountId !== '') {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => $r['account_id'] === $accountId));
        }
        return $rows;
    }
}
