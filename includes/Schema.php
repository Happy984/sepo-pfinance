<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * The tables, one per record type, plus the activity log.
 *
 * Every record has a string id with a type prefix (txn_…, acct_…), created
 * and updated times, who created it, and a deleted flag. Nothing is removed
 * from these tables by the screens: deleting sets the flag, so Undo and the
 * activity log always have the record to point at.
 *
 * Amounts are stored unsigned with four decimal places; whether money came in
 * or went out is the record's type.
 */
final class Schema
{
    /** Columns per type: name => SQL type. The housekeeping columns are added to every table. */
    public const COLUMNS = [
        'transactions' => [
            'date'            => "date NOT NULL DEFAULT '1970-01-01'",
            'type'            => "varchar(16) NOT NULL DEFAULT 'expense'",
            'amount'          => 'decimal(18,4) NOT NULL DEFAULT 0',
            'currency'        => "varchar(8) NOT NULL DEFAULT ''",
            'account_id'      => "varchar(40) NOT NULL DEFAULT ''",
            'to_account_id'   => "varchar(40) NOT NULL DEFAULT ''",
            'category_id'     => "varchar(40) NOT NULL DEFAULT ''",
            'counterparty_id' => "varchar(40) NOT NULL DEFAULT ''",
            'description'     => "varchar(255) NOT NULL DEFAULT ''",
            'reference'       => "varchar(191) NOT NULL DEFAULT ''",
            'method'          => "varchar(16) NOT NULL DEFAULT ''",
            'status'          => "varchar(16) NOT NULL DEFAULT 'cleared'",
            'notes'           => 'text NULL',
            'invoice_id'      => "varchar(40) NOT NULL DEFAULT ''",
            'bill_id'         => "varchar(40) NOT NULL DEFAULT ''",
            'source'          => "varchar(32) NOT NULL DEFAULT ''",
            'source_id'       => "varchar(64) NOT NULL DEFAULT ''",
            'attachments'     => 'longtext NULL',
        ],
        'accounts' => [
            'name'            => "varchar(191) NOT NULL DEFAULT ''",
            'type'            => "varchar(16) NOT NULL DEFAULT 'bank'",
            'currency'        => "varchar(8) NOT NULL DEFAULT ''",
            'opening_balance' => 'decimal(18,4) NOT NULL DEFAULT 0',
            'opening_date'    => "date NOT NULL DEFAULT '1970-01-01'",
            'institution'     => "varchar(191) NOT NULL DEFAULT ''",
            'number_masked'   => "varchar(32) NOT NULL DEFAULT ''",
            'archived'        => 'tinyint(1) NOT NULL DEFAULT 0',
        ],
        'categories' => [
            'name'      => "varchar(191) NOT NULL DEFAULT ''",
            'type'      => "varchar(16) NOT NULL DEFAULT 'expense'",
            'parent_id' => "varchar(40) NOT NULL DEFAULT ''",
            'colour'    => "varchar(16) NOT NULL DEFAULT ''",
            'archived'  => 'tinyint(1) NOT NULL DEFAULT 0',
        ],
        'counterparties' => [
            'name'     => "varchar(191) NOT NULL DEFAULT ''",
            'type'     => "varchar(16) NOT NULL DEFAULT 'customer'",
            'email'    => "varchar(191) NOT NULL DEFAULT ''",
            'phone'    => "varchar(64) NOT NULL DEFAULT ''",
            'address'  => 'text NULL',
            'tax_id'   => "varchar(64) NOT NULL DEFAULT ''",
            'notes'    => 'text NULL',
            'archived' => 'tinyint(1) NOT NULL DEFAULT 0',
        ],
        'invoices' => [
            'number'          => "varchar(64) NOT NULL DEFAULT ''",
            'counterparty_id' => "varchar(40) NOT NULL DEFAULT ''",
            'date_issued'     => "date NOT NULL DEFAULT '1970-01-01'",
            'date_due'        => "date NOT NULL DEFAULT '1970-01-01'",
            'lines'           => 'longtext NULL',
            'tax_rate'        => 'decimal(7,3) NOT NULL DEFAULT 0',
            'subtotal'        => 'decimal(18,4) NOT NULL DEFAULT 0',
            'tax'             => 'decimal(18,4) NOT NULL DEFAULT 0',
            'amount'          => 'decimal(18,4) NOT NULL DEFAULT 0',
            'paid_amount'     => 'decimal(18,4) NOT NULL DEFAULT 0',
            'currency'        => "varchar(8) NOT NULL DEFAULT ''",
            'status'          => "varchar(16) NOT NULL DEFAULT 'draft'",
            'notes'           => 'text NULL',
            'sent_at'         => 'bigint(20) NOT NULL DEFAULT 0',
            'reminded_at'     => 'bigint(20) NOT NULL DEFAULT 0',
        ],
        'recurring' => [
            'name'              => "varchar(191) NOT NULL DEFAULT ''",
            'frequency'         => "varchar(16) NOT NULL DEFAULT 'monthly'",
            'next_due'          => "date NOT NULL DEFAULT '1970-01-01'",
            'active'            => 'tinyint(1) NOT NULL DEFAULT 1',
            'last_created'      => "varchar(10) NOT NULL DEFAULT ''",
            't_type'            => "varchar(16) NOT NULL DEFAULT 'expense'",
            't_amount'          => 'decimal(18,4) NOT NULL DEFAULT 0',
            't_account_id'      => "varchar(40) NOT NULL DEFAULT ''",
            't_to_account_id'   => "varchar(40) NOT NULL DEFAULT ''",
            't_category_id'     => "varchar(40) NOT NULL DEFAULT ''",
            't_counterparty_id' => "varchar(40) NOT NULL DEFAULT ''",
            't_description'     => "varchar(255) NOT NULL DEFAULT ''",
            't_method'          => "varchar(16) NOT NULL DEFAULT ''",
            'notes'             => 'text NULL',
        ],
        'reconciliations' => [
            'account_id'        => "varchar(40) NOT NULL DEFAULT ''",
            'statement_date'    => "date NOT NULL DEFAULT '1970-01-01'",
            'statement_balance' => 'decimal(18,4) NOT NULL DEFAULT 0',
            'cleared_balance'   => 'decimal(18,4) NOT NULL DEFAULT 0',
            'difference'        => 'decimal(18,4) NOT NULL DEFAULT 0',
            'status'            => "varchar(16) NOT NULL DEFAULT 'pending'",
        ],
    ];

    /** Bills have the same shape as invoices, pointing the other way. */
    public static function columns(string $type): array
    {
        return self::COLUMNS[$type === 'bills' ? 'invoices' : $type] ?? [];
    }

    public const TYPES = ['transactions', 'accounts', 'categories', 'counterparties', 'invoices', 'bills', 'recurring', 'reconciliations'];

    private const INDEXES = [
        'transactions'    => ['date', 'account_id', 'to_account_id', 'category_id', 'counterparty_id', 'status', 'type', 'invoice_id', 'bill_id', 'source_id'],
        'invoices'        => ['counterparty_id', 'status', 'date_due'],
        'bills'           => ['counterparty_id', 'status', 'date_due'],
        'categories'      => ['type', 'parent_id'],
        'counterparties'  => ['type', 'email'],
        'recurring'       => ['next_due'],
        'reconciliations' => ['account_id'],
    ];

    public static function table(string $type): string
    {
        global $wpdb;
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown finance record type.');
        }
        return $wpdb->prefix . 'sfin_' . $type;
    }

    public static function log(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sfin_log';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        foreach (self::TYPES as $type) {
            $cols = "  id varchar(40) NOT NULL,\n";
            foreach (self::columns($type) as $name => $def) {
                $cols .= '  `' . $name . '` ' . $def . ",\n";
            }
            $cols .= "  created bigint(20) NOT NULL DEFAULT 0,\n  updated bigint(20) NOT NULL DEFAULT 0,\n  created_by bigint(20) unsigned NOT NULL DEFAULT 0,\n  deleted tinyint(1) NOT NULL DEFAULT 0,\n  PRIMARY KEY  (id),\n  KEY deleted (deleted)";
            foreach (self::INDEXES[$type] ?? [] as $ix) {
                $cols .= ",\n  KEY " . $ix . ' (' . $ix . ')';
            }
            dbDelta('CREATE TABLE ' . self::table($type) . " (\n" . $cols . "\n) $c;");
        }
        dbDelta('CREATE TABLE ' . self::log() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  at bigint(20) NOT NULL DEFAULT 0,
  actor bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(32) NOT NULL DEFAULT '',
  type varchar(24) NOT NULL DEFAULT '',
  record_id varchar(40) NOT NULL DEFAULT '',
  summary varchar(255) NOT NULL DEFAULT '',
  changes longtext NULL,
  PRIMARY KEY  (id),
  KEY record (type,record_id),
  KEY at (at)
) $c;");
    }
}
