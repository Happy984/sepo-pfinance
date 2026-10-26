<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * The record specification: one definition of every record type.
 *
 * The entry forms are built from it, the REST routes validate against it, and
 * the store writes what it produces. Three hand-written lists of the same
 * fields is how a form and an API end up disagreeing about what is required.
 *
 * Each field:
 *   key       the record key and the form input name
 *   label     the form label
 *   type      date|money|text|textarea|select|ref|bool|percent|lines
 *   required  true, or a "field=value" condition
 *   quick     belongs in the ledger's quick-add row
 *   options   select: value => label
 *   ref       ref: the record type to choose from
 *   refType   ref: a fixed narrowing (a bill's counterparty is a supplier)
 *   filter    ref: the name of another field whose value narrows the options
 *   showWhen / hideWhen  "field=value" conditions for the forms
 *   default   used when the input leaves the key out
 *   help      shown under the input
 *
 * refType and filter only shape the forms. The server does not refuse a bill
 * because its supplier was filed as "other": a counterparty's type is a label
 * for people, not a rule.
 */
final class Fields
{
    /** @return list<array<string,mixed>> */
    public static function get(string $type): array
    {
        static $spec = null;
        if ($spec === null) {
            $spec = self::spec();
        }
        return $spec[$type] ?? [];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private static function spec(): array
    {
        $methods = ['' => '—', 'cash' => __('Cash', 'sepo-finance'), 'mobile' => __('Mobile money', 'sepo-finance'), 'bank' => __('Bank transfer', 'sepo-finance'), 'card' => __('Card', 'sepo-finance'), 'cheque' => __('Cheque', 'sepo-finance'), 'other' => __('Other', 'sepo-finance')];
        $txTypes = ['expense' => __('Expense', 'sepo-finance'), 'income' => __('Income', 'sepo-finance'), 'transfer' => __('Transfer', 'sepo-finance')];
        $spec = [
            'transactions' => [
                ['key' => 'date', 'label' => __('Date', 'sepo-finance'), 'type' => 'date', 'required' => true, 'quick' => true, 'default' => 'today'],
                ['key' => 'type', 'label' => __('Type', 'sepo-finance'), 'type' => 'select', 'required' => true, 'quick' => true, 'default' => 'expense', 'options' => $txTypes],
                ['key' => 'amount', 'label' => __('Amount', 'sepo-finance'), 'type' => 'money', 'required' => true, 'quick' => true, 'help' => __('A negative amount or (450) books an expense.', 'sepo-finance')],
                ['key' => 'account_id', 'label' => __('Account', 'sepo-finance'), 'type' => 'ref', 'ref' => 'accounts', 'required' => true, 'quick' => true, 'help' => __('Where the money left from or landed.', 'sepo-finance')],
                ['key' => 'to_account_id', 'label' => __('To account', 'sepo-finance'), 'type' => 'ref', 'ref' => 'accounts', 'required' => 'type=transfer', 'showWhen' => 'type=transfer'],
                ['key' => 'category_id', 'label' => __('Category', 'sepo-finance'), 'type' => 'ref', 'ref' => 'categories', 'quick' => true, 'filter' => 'type', 'hideWhen' => 'type=transfer'],
                ['key' => 'counterparty_id', 'label' => __('Who', 'sepo-finance'), 'type' => 'ref', 'ref' => 'counterparties', 'quick' => true],
                ['key' => 'description', 'label' => __('Description', 'sepo-finance'), 'type' => 'text', 'quick' => true, 'max' => 240],
                ['key' => 'reference', 'label' => __('Reference', 'sepo-finance'), 'type' => 'text', 'max' => 191, 'help' => __('Receipt, cheque or transaction number.', 'sepo-finance')],
                ['key' => 'method', 'label' => __('Method', 'sepo-finance'), 'type' => 'select', 'default' => '', 'options' => $methods],
                ['key' => 'status', 'label' => __('Status', 'sepo-finance'), 'type' => 'select', 'default' => 'cleared',
                    'options' => ['pending' => __('Pending', 'sepo-finance'), 'cleared' => __('Cleared', 'sepo-finance'), 'reconciled' => __('Reconciled', 'sepo-finance')],
                    'help' => __('Pending is money promised but not moved; it stays out of balances and reports.', 'sepo-finance')],
                ['key' => 'currency', 'label' => __('Currency', 'sepo-finance'), 'type' => 'text', 'max' => 8, 'default' => ''],
                ['key' => 'notes', 'label' => __('Notes', 'sepo-finance'), 'type' => 'textarea'],
            ],
            'accounts' => [
                ['key' => 'name', 'label' => __('Name', 'sepo-finance'), 'type' => 'text', 'required' => true, 'max' => 191],
                ['key' => 'type', 'label' => __('Type', 'sepo-finance'), 'type' => 'select', 'required' => true, 'default' => 'bank',
                    'options' => ['bank' => __('Bank', 'sepo-finance'), 'mobile' => __('Mobile money', 'sepo-finance'), 'cash' => __('Cash', 'sepo-finance'), 'card' => __('Card', 'sepo-finance'), 'other' => __('Other', 'sepo-finance')]],
                ['key' => 'currency', 'label' => __('Currency', 'sepo-finance'), 'type' => 'text', 'max' => 8, 'default' => ''],
                ['key' => 'opening_balance', 'label' => __('Opening balance', 'sepo-finance'), 'type' => 'money', 'default' => 0,
                    'help' => __('What was in the account on the opening date. Every balance after it is calculated.', 'sepo-finance')],
                ['key' => 'opening_date', 'label' => __('Opening date', 'sepo-finance'), 'type' => 'date', 'default' => 'today'],
                ['key' => 'institution', 'label' => __('Bank or provider', 'sepo-finance'), 'type' => 'text', 'max' => 191],
                ['key' => 'number_masked', 'label' => __('Account number (last four)', 'sepo-finance'), 'type' => 'text', 'max' => 8,
                    'help' => __('The last four digits only. Do not store the full number.', 'sepo-finance')],
                ['key' => 'archived', 'label' => __('Archived', 'sepo-finance'), 'type' => 'bool', 'help' => __('Hidden from new entries; kept in history and reports.', 'sepo-finance')],
            ],
            'categories' => [
                ['key' => 'name', 'label' => __('Name', 'sepo-finance'), 'type' => 'text', 'required' => true, 'max' => 191],
                ['key' => 'type', 'label' => __('Type', 'sepo-finance'), 'type' => 'select', 'required' => true, 'default' => 'expense',
                    'options' => ['expense' => __('Expense', 'sepo-finance'), 'income' => __('Income', 'sepo-finance')]],
                ['key' => 'parent_id', 'label' => __('Filed under', 'sepo-finance'), 'type' => 'ref', 'ref' => 'categories', 'filter' => 'type'],
                ['key' => 'colour', 'label' => __('Colour', 'sepo-finance'), 'type' => 'color', 'max' => 16],
                ['key' => 'archived', 'label' => __('Archived', 'sepo-finance'), 'type' => 'bool'],
            ],
            'counterparties' => [
                ['key' => 'name', 'label' => __('Name', 'sepo-finance'), 'type' => 'text', 'required' => true, 'max' => 191],
                ['key' => 'type', 'label' => __('Type', 'sepo-finance'), 'type' => 'select', 'required' => true, 'default' => 'customer',
                    'options' => ['customer' => __('Customer', 'sepo-finance'), 'supplier' => __('Supplier', 'sepo-finance'), 'staff' => __('Staff', 'sepo-finance'), 'government' => __('Government', 'sepo-finance'), 'other' => __('Other', 'sepo-finance')]],
                ['key' => 'email', 'label' => __('Email', 'sepo-finance'), 'type' => 'email', 'max' => 191, 'help' => __('Invoices and reminders are sent here.', 'sepo-finance')],
                ['key' => 'phone', 'label' => __('Phone', 'sepo-finance'), 'type' => 'text', 'max' => 64],
                ['key' => 'address', 'label' => __('Address', 'sepo-finance'), 'type' => 'textarea'],
                ['key' => 'tax_id', 'label' => __('Tax number (TPIN)', 'sepo-finance'), 'type' => 'text', 'max' => 64],
                ['key' => 'notes', 'label' => __('Notes', 'sepo-finance'), 'type' => 'textarea'],
                ['key' => 'archived', 'label' => __('Archived', 'sepo-finance'), 'type' => 'bool'],
            ],
            'invoices' => [
                ['key' => 'number', 'label' => __('Number', 'sepo-finance'), 'type' => 'text', 'required' => true, 'max' => 64],
                ['key' => 'counterparty_id', 'label' => __('Customer', 'sepo-finance'), 'type' => 'ref', 'ref' => 'counterparties', 'refType' => 'customer', 'required' => true],
                ['key' => 'date_issued', 'label' => __('Issued', 'sepo-finance'), 'type' => 'date', 'required' => true, 'default' => 'today'],
                ['key' => 'date_due', 'label' => __('Due', 'sepo-finance'), 'type' => 'date', 'required' => true, 'default' => '+30d'],
                ['key' => 'lines', 'label' => __('Items', 'sepo-finance'), 'type' => 'lines', 'help' => __('Add items and the total is worked out for you, or leave them out and type the amount.', 'sepo-finance')],
                ['key' => 'tax_rate', 'label' => __('Tax rate %', 'sepo-finance'), 'type' => 'percent', 'default' => 0],
                ['key' => 'amount', 'label' => __('Total', 'sepo-finance'), 'type' => 'money', 'required' => true, 'help' => __('Worked out from the items when there are any.', 'sepo-finance')],
                ['key' => 'currency', 'label' => __('Currency', 'sepo-finance'), 'type' => 'text', 'max' => 8, 'default' => ''],
                ['key' => 'status', 'label' => __('Status', 'sepo-finance'), 'type' => 'select', 'default' => 'draft',
                    'options' => ['draft' => __('Draft', 'sepo-finance'), 'sent' => __('Sent', 'sepo-finance'), 'cancelled' => __('Cancelled', 'sepo-finance')],
                    'help' => __('A document becomes Paid when payments recorded against it cover it in full.', 'sepo-finance')],
                ['key' => 'notes', 'label' => __('Notes on the document', 'sepo-finance'), 'type' => 'textarea'],
            ],
            'recurring' => [
                ['key' => 'name', 'label' => __('Name', 'sepo-finance'), 'type' => 'text', 'required' => true, 'max' => 191, 'help' => __('For example: Office rent.', 'sepo-finance')],
                ['key' => 'frequency', 'label' => __('Repeats', 'sepo-finance'), 'type' => 'select', 'required' => true, 'default' => 'monthly',
                    'options' => ['weekly' => __('Weekly', 'sepo-finance'), 'monthly' => __('Monthly', 'sepo-finance'), 'quarterly' => __('Quarterly', 'sepo-finance'), 'yearly' => __('Yearly', 'sepo-finance')]],
                ['key' => 'next_due', 'label' => __('Next due', 'sepo-finance'), 'type' => 'date', 'required' => true, 'default' => 'today'],
                ['key' => 't_type', 'label' => __('Type', 'sepo-finance'), 'type' => 'select', 'required' => true, 'default' => 'expense', 'options' => $txTypes],
                ['key' => 't_amount', 'label' => __('Amount', 'sepo-finance'), 'type' => 'money', 'required' => true],
                ['key' => 't_account_id', 'label' => __('Account', 'sepo-finance'), 'type' => 'ref', 'ref' => 'accounts', 'required' => true],
                ['key' => 't_to_account_id', 'label' => __('To account', 'sepo-finance'), 'type' => 'ref', 'ref' => 'accounts', 'required' => 't_type=transfer', 'showWhen' => 't_type=transfer'],
                ['key' => 't_category_id', 'label' => __('Category', 'sepo-finance'), 'type' => 'ref', 'ref' => 'categories', 'filter' => 't_type', 'hideWhen' => 't_type=transfer'],
                ['key' => 't_counterparty_id', 'label' => __('Who', 'sepo-finance'), 'type' => 'ref', 'ref' => 'counterparties'],
                ['key' => 't_description', 'label' => __('Description on each entry', 'sepo-finance'), 'type' => 'text', 'max' => 240],
                ['key' => 't_method', 'label' => __('Method', 'sepo-finance'), 'type' => 'select', 'default' => '', 'options' => $methods],
                ['key' => 'active', 'label' => __('Active', 'sepo-finance'), 'type' => 'bool', 'default' => 1],
                ['key' => 'notes', 'label' => __('Notes', 'sepo-finance'), 'type' => 'textarea'],
            ],
        ];
        // Bills are invoices pointing the other way. Matched on the key, so a
        // field added above the counterparty never inherits its label.
        $spec['bills'] = [];
        foreach ($spec['invoices'] as $field) {
            if ($field['key'] === 'counterparty_id') {
                $field['label'] = __('Supplier', 'sepo-finance');
                $field['refType'] = 'supplier';
            }
            if ($field['key'] === 'number') {
                $field['help'] = __("The supplier's own number, so you can match it later.", 'sepo-finance');
            }
            if ($field['key'] === 'status') {
                $field['options'] = ['draft' => __('Draft', 'sepo-finance'), 'sent' => __('Received', 'sepo-finance'), 'cancelled' => __('Cancelled', 'sepo-finance')];
            }
            $spec['bills'][] = $field;
        }
        return $spec;
    }

    /** "field=value" against a record being built. A bare true is always met. */
    public static function met($condition, array $record): bool
    {
        if (is_bool($condition)) {
            return $condition;
        }
        $condition = trim((string) $condition);
        if ($condition === '') {
            return false;
        }
        if (!str_contains($condition, '=')) {
            return !empty($record[$condition]);
        }
        [$key, $want] = explode('=', $condition, 2);
        return (string) ($record[trim($key)] ?? '') === trim($want);
    }

    /**
     * Build and check a record from flat input.
     *
     * Only keys the input carries are taken from it; everything else comes
     * from the record being edited, then from the field's default. So a form
     * that does not show a field never blanks it.
     *
     * @param array<string,mixed>      $input
     * @param array<string,mixed>|null $existing
     * @return array{0:array<string,mixed>,1:array<string,string>} record and field errors
     */
    public static function build(string $type, array $input, ?array $existing): array
    {
        $fields = self::get($type);
        $errors = [];
        $record = [];
        if ($fields === []) {
            return [[], ['type' => __('Unknown record type.', 'sepo-finance')]];
        }

        foreach ($fields as $field) {
            $key = (string) $field['key'];
            $kind = (string) ($field['type'] ?? 'text');
            $supplied = array_key_exists($key, $input);
            $raw = $supplied ? $input[$key] : ($existing[$key] ?? $field['default'] ?? '');
            switch ($kind) {
                case 'money':
                    $record[$key] = Parse::amount($raw) ?? 0.0;
                    break;
                case 'percent':
                    $record[$key] = max(0.0, min(100.0, (float) (Parse::amount($raw) ?? 0)));
                    break;
                case 'date':
                    $parsed = Parse::date((string) $raw);
                    if ($parsed === null && trim((string) $raw) !== '') {
                        $errors[$key] = __('That is not a date I can read. Try 2026-09-13 or 13/09/2026.', 'sepo-finance');
                        $record[$key] = '';
                        break;
                    }
                    $record[$key] = $parsed ?? '';
                    break;
                case 'bool':
                    $record[$key] = Parse::truthy($raw) ? 1 : 0;
                    break;
                case 'select':
                    $value = trim((string) $raw);
                    $allowed = array_map('strval', array_keys((array) ($field['options'] ?? [])));
                    if (!in_array($value, $allowed, true)) {
                        // Selects come from our own markup, so a value outside them is a stale page, not a typo.
                        $value = (string) ($field['default'] ?? ($allowed[0] ?? ''));
                    }
                    $record[$key] = $value;
                    break;
                case 'lines':
                    $record[$key] = self::lines($raw);
                    break;
                case 'email':
                    $value = sanitize_email((string) $raw);
                    if (trim((string) $raw) !== '' && $value === '') {
                        $errors[$key] = __('That email address does not look right.', 'sepo-finance');
                    }
                    $record[$key] = $value;
                    break;
                case 'color':
                    $record[$key] = (string) (sanitize_hex_color((string) $raw) ?? '');
                    break;
                case 'textarea':
                    $record[$key] = trim(sanitize_textarea_field((string) $raw));
                    break;
                default:
                    $value = trim(sanitize_text_field((string) $raw));
                    if (isset($field['max'])) {
                        $value = mb_substr($value, 0, (int) $field['max']);
                    }
                    $record[$key] = $value;
            }
        }

        // Documents with items: the items decide the total.
        if (($type === 'invoices' || $type === 'bills')) {
            $sub = 0.0;
            foreach ($record['lines'] as $l) {
                $sub += $l['qty'] * $l['price'];
            }
            $sub = round($sub, 2);
            if ($record['lines'] !== []) {
                $record['subtotal'] = $sub;
                $record['tax'] = round($sub * $record['tax_rate'] / 100, 2);
                $record['amount'] = round($sub + $record['tax'], 2);
            } else {
                $record['amount'] = round(abs((float) $record['amount']), 2);
                $record['subtotal'] = $record['tax_rate'] > 0 ? round($record['amount'] / (1 + $record['tax_rate'] / 100), 2) : $record['amount'];
                $record['tax'] = round($record['amount'] - $record['subtotal'], 2);
            }
        }

        foreach ($fields as $field) {
            $key = (string) $field['key'];
            if (!isset($field['required']) || !self::met($field['required'], $record) || isset($errors[$key])) {
                continue;
            }
            $value = $record[$key] ?? '';
            if (($field['type'] ?? '') === 'money') {
                if (abs((float) $value) < 0.005) {
                    /* translators: %s: field label */
                    $errors[$key] = sprintf(__('%s is required.', 'sepo-finance'), $field['label']);
                }
                continue;
            }
            if ($value === '' || $value === null) {
                /* translators: %s: field label */
                $errors[$key] = sprintf(__('%s is required.', 'sepo-finance'), $field['label']);
            }
        }

        if ($type === 'transactions' || $type === 'recurring') {
            $p = $type === 'recurring' ? 't_' : '';
            // A negative amount is how a spreadsheet says "expense". Honour it when the type was not given.
            if ((float) $record[$p . 'amount'] < 0) {
                if (!array_key_exists($p . 'type', $input)) {
                    $record[$p . 'type'] = 'expense';
                }
                $record[$p . 'amount'] = abs((float) $record[$p . 'amount']);
            }
            if ($record[$p . 'type'] === 'transfer') {
                $record[$p . 'category_id'] = '';
                if ($record[$p . 'to_account_id'] !== '' && $record[$p . 'to_account_id'] === $record[$p . 'account_id']) {
                    $errors[$p . 'to_account_id'] = __('A transfer needs two different accounts.', 'sepo-finance');
                }
            } else {
                $record[$p . 'to_account_id'] = '';
            }
        }

        if (($type === 'invoices' || $type === 'bills') && $record['date_issued'] !== '' && $record['date_due'] !== '' && $record['date_due'] < $record['date_issued']) {
            $errors['date_due'] = __('The due date is before the issue date.', 'sepo-finance');
        }

        if ($type === 'categories' && $record['parent_id'] !== '' && $record['parent_id'] === (string) ($existing['id'] ?? $input['id'] ?? '')) {
            $errors['parent_id'] = __('A category cannot be filed under itself.', 'sepo-finance');
        }

        if (array_key_exists('currency', $record) && $record['currency'] === '') {
            $record['currency'] = Settings::currency();
        }
        if (array_key_exists('currency', $record)) {
            $record['currency'] = strtoupper(preg_replace('~[^A-Za-z]~', '', (string) $record['currency']) ?? '');
        }

        $record['id'] = self::id($type, (string) ($input['id'] ?? ''), $existing);
        return [$record, $errors];
    }

    /**
     * Items on an invoice or bill, cleaned: description, quantity, unit price.
     *
     * @param mixed $raw a list, or its JSON
     * @return list<array{description:string,qty:float,price:float}>
     */
    public static function lines($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $out = [];
        foreach ((array) $raw as $l) {
            if (!is_array($l)) {
                continue;
            }
            $desc = mb_substr(trim(sanitize_text_field((string) ($l['description'] ?? ''))), 0, 255);
            $qty = Parse::amount($l['qty'] ?? 1) ?? 1.0;
            $price = Parse::amount($l['price'] ?? 0) ?? 0.0;
            if ($desc === '' && abs($price) < 0.005) {
                continue;
            }
            $out[] = ['description' => $desc, 'qty' => round($qty, 3), 'price' => round($price, 2)];
            if (count($out) >= 100) {
                break;
            }
        }
        return $out;
    }

    /**
     * The id for a record.
     *
     * An id may be minted by the page: saving is an upsert keyed on the id, so
     * a form that mints its own turns a double-submit into one overwrite
     * instead of two identical entries. The shape is checked so a page cannot
     * pick an id that collides across types.
     */
    public static function id(string $type, string $supplied, ?array $existing): string
    {
        if ($existing !== null && isset($existing['id'])) {
            return (string) $existing['id'];
        }
        $prefix = self::PREFIXES[$type] ?? 'rec';
        $supplied = trim($supplied);
        if ($supplied !== '' && preg_match('/^' . $prefix . '_\d{10}_[0-9a-f]{8}$/', $supplied) === 1) {
            return $supplied;
        }
        return self::newId($type);
    }

    public const PREFIXES = [
        'transactions'    => 'txn',
        'accounts'        => 'acct',
        'categories'      => 'cat',
        'counterparties'  => 'cp',
        'invoices'        => 'inv',
        'bills'           => 'bill',
        'recurring'       => 'rule',
        'reconciliations' => 'rec',
    ];

    public static function newId(string $type): string
    {
        return (self::PREFIXES[$type] ?? 'rec') . '_' . time() . '_' . bin2hex(random_bytes(4));
    }

    /** The spec for the page script. @param list<string> $types */
    public static function json(array $types): string
    {
        $out = [];
        foreach ($types as $t) {
            $out[$t] = self::get($t);
        }
        $json = wp_json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        return is_string($json) ? $json : '{}';
    }
}
