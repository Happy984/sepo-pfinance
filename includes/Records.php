<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * Saving, deleting and restoring records, with the checks a field spec cannot
 * make on its own (does the account exist, is the document already paid for
 * more than this) and an activity log entry for each.
 */
final class Records
{
    public const EDITABLE = ['transactions', 'accounts', 'categories', 'counterparties', 'invoices', 'bills', 'recurring'];

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> the saved record
     * @throws FieldErrors
     */
    public static function save(string $type, array $input): array
    {
        if (!in_array($type, self::EDITABLE, true)) {
            throw new \InvalidArgumentException(__('Unknown record type.', 'sepo-finance'));
        }
        $id = trim((string) ($input['id'] ?? ''));
        $existing = $id !== '' ? Store::find($type, $id, true) : null;
        [$record, $errors] = Fields::build($type, $input, $existing);

        if ($type === 'transactions') {
            if ($record['account_id'] !== '' && Store::find('accounts', $record['account_id'], true) === null) {
                $errors['account_id'] = __('That account does not exist.', 'sepo-finance');
            }
            if ($record['to_account_id'] !== '' && Store::find('accounts', $record['to_account_id'], true) === null) {
                $errors['to_account_id'] = __('That account does not exist.', 'sepo-finance');
            }
            // Keys the form never sends and an edit must not lose.
            foreach (['invoice_id', 'bill_id', 'source', 'source_id', 'attachments'] as $k) {
                $record[$k] = $existing[$k] ?? ($k === 'attachments' ? [] : '');
            }
        }
        if ($type === 'invoices' || $type === 'bills') {
            $record['paid_amount'] = round((float) ($existing['paid_amount'] ?? 0), 2);
            if ($record['paid_amount'] > (float) $record['amount'] + 0.004) {
                $errors['amount'] = __('More than this has already been paid against the document.', 'sepo-finance');
            }
            // Paid is something payments do, not something a form sets.
            if (($existing['status'] ?? '') === 'paid' && $record['status'] !== 'cancelled') {
                $record['status'] = $record['paid_amount'] >= (float) $record['amount'] - 0.004 ? 'paid' : 'sent';
            }
            $dupe = self::numberTaken($type, (string) $record['number'], (string) $record['id'], (string) $record['counterparty_id']);
            if ($dupe) {
                $errors['number'] = $type === 'invoices' ? __('Another invoice already has this number.', 'sepo-finance') : __('A bill from this supplier already has this number.', 'sepo-finance');
            }
        }
        if ($type === 'recurring') {
            $record['last_created'] = (string) ($existing['last_created'] ?? '');
        }
        if ($errors !== []) {
            throw new FieldErrors($errors);
        }
        $record['deleted'] = (int) ($existing['deleted'] ?? 0);
        $saved = Store::write($type, $record);
        Log::add($existing === null ? 'created' : 'updated', $type, (string) $saved['id'], $existing, $saved);
        if ($type === 'transactions') {
            self::touchDocument($saved);
        }
        return $saved;
    }

    private static function numberTaken(string $type, string $number, string $id, string $cp): bool
    {
        foreach (Store::all($type) as $d) {
            if ($d['id'] !== $id && strcasecmp((string) $d['number'], $number) === 0 && ($type === 'invoices' || $d['counterparty_id'] === $cp)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{deleted:bool,references:int,note:string} */
    public static function delete(string $type, string $id): array
    {
        $before = Store::find($type, $id, true);
        if ($before === null) {
            throw new \InvalidArgumentException(__('That record was not found.', 'sepo-finance'));
        }
        $refs = Ledger::references($type, $id);
        if ($type === 'accounts' && $refs['count'] > 0) {
            // Every entry on it would point at nothing and the balances would go with it.
            throw new \InvalidArgumentException(__('That account still has entries. Archive it instead: it stays in history and drops out of new entries.', 'sepo-finance'));
        }
        if (($type === 'invoices' || $type === 'bills') && $refs['count'] > 0) {
            throw new \InvalidArgumentException(__('Payments have been recorded against this document. Delete those entries first, or cancel the document instead.', 'sepo-finance'));
        }
        Store::softDelete($type, $id);
        Log::add('deleted', $type, $id, null, null, Log::describe($type, $before));
        if ($type === 'transactions') {
            self::touchDocument($before);
        }
        return ['deleted' => true, 'references' => $refs['count'], 'note' => $refs['note']];
    }

    public static function restore(string $type, string $id): array
    {
        $r = Store::find($type, $id, true);
        if ($r === null) {
            throw new \InvalidArgumentException(__('That record was not found.', 'sepo-finance'));
        }
        Store::restore($type, $id);
        Log::add('restored', $type, $id, null, null, Log::describe($type, $r));
        $r = Store::find($type, $id) ?? $r;
        if ($type === 'transactions') {
            self::touchDocument($r);
        }
        return $r;
    }

    /** A payment entry changed: its invoice or bill recounts what has been paid. */
    private static function touchDocument(array $t): void
    {
        if ((string) ($t['invoice_id'] ?? '') !== '') {
            Documents::repay('invoices', (string) $t['invoice_id']);
        }
        if ((string) ($t['bill_id'] ?? '') !== '') {
            Documents::repay('bills', (string) $t['bill_id']);
        }
    }
}
