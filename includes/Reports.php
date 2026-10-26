<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * The reports. One method per report; each returns a shaped array the screen
 * prints without working anything out itself.
 *
 * ONE CURRENCY. Balances in another currency are listed but never added in:
 * converting without a rate would be inventing a number.
 */
final class Reports
{
    /** @return array<string,string> */
    private static function categoryNames(): array
    {
        return Store::names('categories');
    }

    /** @param list<array{category_id:string,amount:float,count:int}> $buckets */
    private static function label(array $buckets): array
    {
        $names = self::categoryNames();
        return array_map(static fn(array $b): array => [
            'id'     => $b['category_id'] === '__none' ? '' : $b['category_id'],
            'label'  => $b['category_id'] === '__none' ? __('Uncategorised', 'sepo-finance') : ($names[$b['category_id']] ?? __('Deleted category', 'sepo-finance')),
            'amount' => $b['amount'],
            'count'  => $b['count'],
        ], $buckets);
    }

    /** The dashboard numbers. @return array<string,mixed> */
    public static function summary(): array
    {
        $cur = Settings::currency();
        $balances = Ledger::balances();
        $position = 0.0;
        $foreign = 0;
        foreach (Store::all('accounts') as $a) {
            if (!empty($a['archived'])) {
                continue;
            }
            if ((string) $a['currency'] !== '' && $a['currency'] !== $cur) {
                $foreign++;
                continue;
            }
            $position += $balances[$a['id']] ?? 0;
        }
        [$from, $to] = Parse::monthBounds();
        $month = Ledger::sums(Ledger::transactions(['from' => $from, 'to' => $to]));
        $today = current_time('Y-m-d');
        $soon = gmdate('Y-m-d', (strtotime($today . ' 12:00:00 UTC') ?: time()) + 7 * 86400);
        $overdue = 0.0;
        $dueSoon = 0.0;
        foreach (Store::all('invoices') as $d) {
            if (Ledger::isOverdue($d, $today)) {
                $overdue += Ledger::outstanding($d);
            }
        }
        foreach (Store::all('bills') as $d) {
            if (!in_array($d['status'], ['paid', 'cancelled', 'draft'], true) && $d['date_due'] !== '' && $d['date_due'] <= $soon) {
                $dueSoon += Ledger::outstanding($d);
            }
        }
        return [
            'position'            => round($position, 2),
            'income_month'        => $month['income'],
            'expenses_month'      => $month['expenses'],
            'net_month'           => $month['net'],
            'transactions_month'  => $month['count'],
            'receivables_overdue' => round($overdue, 2),
            'payables_soon'       => round($dueSoon, 2),
            'foreign_accounts'    => $foreign,
            'currency'            => $cur,
        ];
    }

    /** @return array<string,mixed> */
    public static function pnl(string $from, string $to): array
    {
        $rows = Ledger::transactions(['from' => $from, 'to' => $to]);
        $s = Ledger::sums($rows);
        return [
            'income'         => self::label(Ledger::byCategory($rows, 'income')),
            'expenses'       => self::label(Ledger::byCategory($rows, 'expense')),
            'total_income'   => $s['income'],
            'total_expenses' => $s['expenses'],
            'net'            => $s['net'],
        ];
    }

    /**
     * Money in and out by month (or week), with the opening position before
     * the range and the closing position after it.
     *
     * @return array<string,mixed>
     */
    public static function cashflow(string $from, string $to, string $group = 'month'): array
    {
        $cur = Settings::currency();
        $periods = Ledger::byPeriod(Ledger::transactions(['from' => $from, 'to' => $to]), $group);
        $before = gmdate('Y-m-d', (strtotime($from . ' 12:00:00 UTC') ?: time()) - 86400);
        $opening = 0.0;
        $bal = Ledger::balances($before);
        foreach (Store::all('accounts', true) as $a) {
            if ((string) $a['currency'] === '' || $a['currency'] === $cur) {
                $opening += $bal[$a['id']] ?? 0;
            }
        }
        $closing = $opening;
        foreach ($periods as $p) {
            $closing += $p['net'];
        }
        return ['periods' => $periods, 'opening' => round($opening, 2), 'closing' => round($closing, 2)];
    }

    /** @return array<string,mixed> */
    public static function position(string $asOf): array
    {
        $cur = Settings::currency();
        $bal = Ledger::balances($asOf);
        $rows = [];
        $total = 0.0;
        foreach (Store::all('accounts') as $a) {
            if (!empty($a['archived'])) {
                continue;
            }
            $c = (string) $a['currency'] !== '' ? (string) $a['currency'] : $cur;
            $b = $bal[$a['id']] ?? 0.0;
            $rows[] = ['id' => $a['id'], 'name' => $a['name'], 'type' => $a['type'], 'currency' => $c, 'balance' => $b];
            if ($c === $cur) {
                $total += $b;
            }
        }
        return ['accounts' => $rows, 'total' => round($total, 2), 'as_of' => $asOf];
    }

    /** @return list<array<string,mixed>> */
    public static function categories(string $from, string $to, string $type): array
    {
        return self::label(Ledger::byCategory(Ledger::transactions(['from' => $from, 'to' => $to]), $type));
    }

    /**
     * Not a tax return: the numbers one needs, at one flat rate.
     *
     * @return array<string,float>
     */
    public static function tax(string $from, string $to, float $rate): array
    {
        $s = Ledger::sums(Ledger::transactions(['from' => $from, 'to' => $to]));
        $r = max(0.0, $rate) / 100;
        $out = round($s['income'] * $r / (1 + $r), 2);
        $in = round($s['expenses'] * $r / (1 + $r), 2);
        return ['income' => $s['income'], 'output_tax' => $out, 'expenses' => $s['expenses'], 'input_tax' => $in, 'net_liability' => round($out - $in, 2), 'tax_rate' => $rate];
    }

    /** @return array{receivables:array<string,float>,payables:array<string,float>} */
    public static function aged(string $asOf): array
    {
        return ['receivables' => Ledger::aged(Store::all('invoices'), $asOf), 'payables' => Ledger::aged(Store::all('bills'), $asOf)];
    }

    /**
     * Everything with one customer or supplier: entries, documents, and what
     * is owed each way.
     *
     * @return array<string,mixed>
     */
    public static function statement(string $id): array
    {
        $rows = Ledger::transactions(['counterparty' => $id]);
        $s = Ledger::sums($rows);
        $owedToUs = 0.0;
        $owedByUs = 0.0;
        $docs = [];
        foreach (['invoices', 'bills'] as $type) {
            foreach (Store::all($type) as $d) {
                if ($d['counterparty_id'] !== $id) {
                    continue;
                }
                $d['_type'] = $type;
                $docs[] = $d;
                if (!in_array($d['status'], ['cancelled', 'draft'], true)) {
                    if ($type === 'invoices') {
                        $owedToUs += Ledger::outstanding($d);
                    } else {
                        $owedByUs += Ledger::outstanding($d);
                    }
                }
            }
        }
        usort($docs, static fn(array $a, array $b): int => strcmp((string) $b['date_issued'], (string) $a['date_issued']));
        return [
            'counterparty' => Store::find('counterparties', $id, true),
            'transactions' => $rows,
            'documents'    => $docs,
            'income'       => $s['income'],
            'expenses'     => $s['expenses'],
            'owed_to_us'   => round($owedToUs, 2),
            'owed_by_us'   => round($owedByUs, 2),
        ];
    }

    /**
     * A report as a header row and data rows, for the CSV download: one fact
     * per line, no merged headings, so it sums in a spreadsheet without edits.
     *
     * @return array{0:list<string>,1:list<list<string|float|int>>}
     */
    public static function rows(string $view, array $d): array
    {
        switch ($view) {
            case 'cashflow':
                $r = [];
                foreach ($d['periods'] as $p) {
                    $r[] = [$p['period'], $p['income'], $p['expenses'], $p['net']];
                }
                $r[] = [__('Opening', 'sepo-finance'), '', '', $d['opening']];
                $r[] = [__('Closing', 'sepo-finance'), '', '', $d['closing']];
                return [[__('Period', 'sepo-finance'), __('In', 'sepo-finance'), __('Out', 'sepo-finance'), __('Net', 'sepo-finance')], $r];
            case 'categories':
                $total = array_sum(array_column($d, 'amount'));
                $r = [];
                foreach ($d as $x) {
                    $r[] = [$x['label'], $x['amount'], $total > 0 ? round($x['amount'] / $total * 100, 1) : 0, $x['count']];
                }
                $r[] = [__('Total', 'sepo-finance'), round($total, 2), 100, ''];
                return [[__('Category', 'sepo-finance'), __('Amount', 'sepo-finance'), __('Share %', 'sepo-finance'), __('Entries', 'sepo-finance')], $r];
            case 'position':
                $r = [];
                foreach ($d['accounts'] as $a) {
                    $r[] = [$a['name'], $a['type'], $a['currency'], $a['balance']];
                }
                $r[] = [__('Total', 'sepo-finance'), '', Settings::currency(), $d['total']];
                return [[__('Account', 'sepo-finance'), __('Type', 'sepo-finance'), __('Currency', 'sepo-finance'), __('Balance', 'sepo-finance')], $r];
            case 'aged':
                $r = [];
                foreach (['receivables' => __('Owed to you', 'sepo-finance'), 'payables' => __('Owed by you', 'sepo-finance')] as $side => $label) {
                    foreach (self::agedLabels() + ['total' => __('Total', 'sepo-finance')] as $k => $b) {
                        $r[] = [$label, $b, $d[$side][$k] ?? 0];
                    }
                }
                return [[__('Side', 'sepo-finance'), __('Age', 'sepo-finance'), __('Amount', 'sepo-finance')], $r];
            case 'tax':
                return [[__('Item', 'sepo-finance'), __('Amount', 'sepo-finance')], [
                    [__('Income', 'sepo-finance'), $d['income']], [__('Output tax', 'sepo-finance'), $d['output_tax']],
                    [__('Expenses', 'sepo-finance'), $d['expenses']], [__('Input tax', 'sepo-finance'), $d['input_tax']],
                    [__('Net liability', 'sepo-finance'), $d['net_liability']], [__('Rate %', 'sepo-finance'), $d['tax_rate']],
                ]];
            default:
                $r = [];
                foreach ($d['income'] as $x) {
                    $r[] = [__('Income', 'sepo-finance'), $x['label'], $x['amount'], $x['count']];
                }
                foreach ($d['expenses'] as $x) {
                    $r[] = [__('Expense', 'sepo-finance'), $x['label'], $x['amount'], $x['count']];
                }
                $r[] = [__('Total', 'sepo-finance'), __('Income', 'sepo-finance'), $d['total_income'], ''];
                $r[] = [__('Total', 'sepo-finance'), __('Expenses', 'sepo-finance'), $d['total_expenses'], ''];
                $r[] = [__('Total', 'sepo-finance'), __('Net', 'sepo-finance'), $d['net'], ''];
                return [[__('Section', 'sepo-finance'), __('Category', 'sepo-finance'), __('Amount', 'sepo-finance'), __('Entries', 'sepo-finance')], $r];
        }
    }

    /** @return array<string,string> */
    public static function agedLabels(): array
    {
        return [
            'current' => __('Not yet due', 'sepo-finance'),
            'd30'     => __('1 to 30 days late', 'sepo-finance'),
            'd60'     => __('31 to 60 days late', 'sepo-finance'),
            'd90'     => __('61 to 90 days late', 'sepo-finance'),
            'd90plus' => __('Over 90 days late', 'sepo-finance'),
        ];
    }

    /** @return array<string,string> */
    public static function views(): array
    {
        return [
            'pnl'        => __('Profit and loss', 'sepo-finance'),
            'cashflow'   => __('Cash flow', 'sepo-finance'),
            'categories' => __('Categories', 'sepo-finance'),
            'position'   => __('Cash position', 'sepo-finance'),
            'aged'       => __('Aged balances', 'sepo-finance'),
            'tax'        => __('Tax summary', 'sepo-finance'),
        ];
    }

    /**
     * The report data for a view and range.
     *
     * @return array<string,mixed>
     */
    public static function data(string $view, string $from, string $to, string $type = 'expense'): array
    {
        return match ($view) {
            'cashflow'   => self::cashflow($from, $to),
            'categories' => self::categories($from, $to, $type === 'income' ? 'income' : 'expense'),
            'position'   => self::position($to),
            'aged'       => self::aged($to),
            'tax'        => self::tax($from, $to, (float) Settings::get('tax_rate')),
            default      => self::pnl($from, $to),
        };
    }
}
