<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * Importing a bank or mobile money statement saved as CSV.
 *
 * Three steps, and nothing is saved until the last:
 *   read     the file is read into rows; the separator and text encoding are
 *            worked out, so a file saved by Excel in Windows encoding reads
 *            the same as one saved in UTF-8
 *   preview  the chosen columns become entries; each is checked, and any
 *            that look like one already in the ledger (same account, date and
 *            amount, and the same reference or description) are flagged
 *   save     the entries that were kept are added, as cleared
 */
final class Import
{
    public const MAX_ROWS = 3000;

    /** @return array{rows:list<list<string>>,delimiter:string,header:bool} */
    public static function read(array $file): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \InvalidArgumentException(__('Choose a CSV file to import.', 'sepo-finance'));
        }
        if ((int) $file['size'] > 5 * 1048576) {
            throw new \InvalidArgumentException(__('That file is larger than 5 MB. Split the statement into shorter periods.', 'sepo-finance'));
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt', 'tsv'], true)) {
            throw new \InvalidArgumentException(__('Save the statement as CSV first. In Excel: File, Save as, CSV.', 'sepo-finance'));
        }
        $raw = (string) file_get_contents((string) $file['tmp_name']);
        $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $first = strtok($raw, "\n") ?: '';
        $delimiter = ',';
        $best = 0;
        foreach ([',', ';', "\t", '|'] as $d) {
            $n = substr_count($first, $d);
            if ($n > $best) {
                $best = $n;
                $delimiter = $d;
            }
        }
        $h = fopen('php://temp', 'r+');
        fwrite($h, $raw);
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, 0, $delimiter, '"', '\\')) !== false) {
            if ($r === [null] || implode('', array_map('trim', array_map('strval', $r))) === '') {
                continue;
            }
            $rows[] = array_map(static fn($v): string => mb_substr(trim((string) $v), 0, 255), $r);
            if (count($rows) > self::MAX_ROWS) {
                break;
            }
        }
        fclose($h);
        if (count($rows) < 1) {
            throw new \InvalidArgumentException(__('That file has no rows.', 'sepo-finance'));
        }
        // A header row has no amount in it and no date in its first cells.
        $header = true;
        foreach (array_slice($rows[0], 0, 6) as $cell) {
            if (Parse::date($cell) !== null || preg_match('/^\(?-?[\d.,\s]+\)?$/', $cell) === 1) {
                $header = false;
            }
        }
        return ['rows' => array_slice($rows, 0, self::MAX_ROWS + 1), 'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter, 'header' => $header];
    }

    /**
     * Turn rows into entries using the chosen columns, and check each.
     *
     * $map: date, description, reference, amount, debit, credit (column
     * indexes, -1 for none), header (bool), invert (bool: the statement shows
     * money out as positive), month_first (bool: dates are written 04/03/2026
     * for 3 April).
     *
     * @param list<list<string>> $rows
     * @param array<string,mixed> $map
     * @return array{items:list<array<string,mixed>>,counts:array<string,int>}
     */
    public static function preview(string $accountId, array $rows, array $map): array
    {
        $acct = Store::find('accounts', $accountId);
        if ($acct === null) {
            throw new \InvalidArgumentException(__('Choose the account this statement is for.', 'sepo-finance'));
        }
        $col = static fn(string $k): int => isset($map[$k]) && is_numeric($map[$k]) ? (int) $map[$k] : -1;
        $cDate = $col('date');
        $cAmount = $col('amount');
        $cDebit = $col('debit');
        $cCredit = $col('credit');
        if ($cDate < 0 || ($cAmount < 0 && $cDebit < 0 && $cCredit < 0)) {
            throw new \InvalidArgumentException(__('Choose the date column and the amount column (or the money in and money out columns).', 'sepo-finance'));
        }
        $invert = !empty($map['invert']);
        $rows = array_values($rows);
        if (!empty($map['header'])) {
            array_shift($rows);
        }
        $rows = array_slice($rows, 0, self::MAX_ROWS);

        $existing = [];
        foreach (Ledger::transactions(['account' => $accountId, 'limit' => 50000]) as $t) {
            $existing[$t['date'] . '|' . number_format((float) $t['amount'], 2, '.', '')][] = $t;
        }
        $seen = [];
        $items = [];
        $counts = ['ok' => 0, 'duplicate' => 0, 'error' => 0];
        foreach ($rows as $i => $r) {
            $get = static fn(int $c): string => $c >= 0 ? (string) ($r[$c] ?? '') : '';
            $rawDate = $get($cDate);
            if (!empty($map['month_first']) && preg_match('#^(\d{1,2})([/.\-])(\d{1,2})(.*)$#', $rawDate, $mm) === 1) {
                $rawDate = $mm[3] . $mm[2] . $mm[1] . $mm[4];
            }
            $date = Parse::date($rawDate);
            $amount = null;
            if ($cAmount >= 0) {
                $amount = Parse::amount($get($cAmount));
            } else {
                $out = Parse::amount($get($cDebit));
                $in = Parse::amount($get($cCredit));
                if ($in !== null && abs($in) > 0.004) {
                    $amount = abs($in);
                } elseif ($out !== null && abs($out) > 0.004) {
                    $amount = -abs($out);
                }
            }
            if ($amount !== null && $invert) {
                $amount = -$amount;
            }
            $desc = mb_substr(sanitize_text_field($get($col('description'))), 0, 240);
            $ref = mb_substr(sanitize_text_field($get($col('reference'))), 0, 191);
            $item = ['row' => $i + 1, 'date' => $date ?? '', 'type' => ($amount ?? 0) < 0 ? 'expense' : 'income', 'amount' => $amount === null ? 0.0 : round(abs($amount), 2), 'description' => $desc, 'reference' => $ref, 'status' => 'ok', 'note' => ''];
            if ($date === null) {
                $item['status'] = 'error';
                /* translators: %s: the text in the cell */
                $item['note'] = sprintf(__('"%s" is not a date', 'sepo-finance'), $get($cDate));
            } elseif ($amount === null || abs($amount) < 0.005) {
                $item['status'] = 'error';
                $item['note'] = __('No amount', 'sepo-finance');
            } else {
                $key = $date . '|' . number_format($item['amount'], 2, '.', '');
                foreach ($existing[$key] ?? [] as $t) {
                    $sameDir = ($t['type'] === 'transfer') || $t['type'] === $item['type'];
                    if ($sameDir && (($ref !== '' && strcasecmp((string) $t['reference'], $ref) === 0) || ($desc !== '' && strcasecmp((string) $t['description'], $desc) === 0) || ($ref === '' && $desc === ''))) {
                        $item['status'] = 'duplicate';
                        $item['note'] = __('Already in the ledger', 'sepo-finance');
                        break;
                    }
                }
                $sig = $key . '|' . $item['type'] . '|' . strtolower($ref . '|' . $desc);
                if ($item['status'] === 'ok' && isset($seen[$sig])) {
                    $item['status'] = 'duplicate';
                    /* translators: %d: row number */
                    $item['note'] = sprintf(__('Same as row %d', 'sepo-finance'), $seen[$sig]);
                }
                $seen[$sig] ??= $item['row'];
            }
            $counts[$item['status']]++;
            $items[] = $item;
        }
        return ['items' => $items, 'counts' => $counts];
    }

    /**
     * Add the kept entries.
     *
     * @param list<array<string,mixed>> $items date, type, amount, description, reference, category_id, counterparty_id
     * @return array{added:int,failed:list<array{row:int,message:string}>}
     */
    public static function save(string $accountId, array $items): array
    {
        if (Store::find('accounts', $accountId) === null) {
            throw new \InvalidArgumentException(__('Choose the account this statement is for.', 'sepo-finance'));
        }
        $added = 0;
        $failed = [];
        $batch = 'imp_' . gmdate('Ymd_His');
        foreach (array_slice($items, 0, self::MAX_ROWS) as $it) {
            if (!is_array($it)) {
                continue;
            }
            try {
                $t = Records::save('transactions', [
                    'date'            => (string) ($it['date'] ?? ''),
                    'type'            => ($it['type'] ?? '') === 'income' ? 'income' : 'expense',
                    'amount'          => (string) ($it['amount'] ?? ''),
                    'account_id'      => $accountId,
                    'category_id'     => (string) ($it['category_id'] ?? ''),
                    'counterparty_id' => (string) ($it['counterparty_id'] ?? ''),
                    'description'     => (string) ($it['description'] ?? ''),
                    'reference'       => (string) ($it['reference'] ?? ''),
                    'status'          => 'cleared',
                ]);
                Store::patch('transactions', (string) $t['id'], ['source' => 'import', 'source_id' => $batch]);
                $added++;
            } catch (\Throwable $e) {
                $failed[] = ['row' => (int) ($it['row'] ?? 0), 'message' => $e->getMessage()];
            }
        }
        $name = Store::names('accounts')[$accountId] ?? '';
        /* translators: 1: count, 2: account */
        Log::add('imported', 'accounts', $accountId, null, null, sprintf(_n('%1$d entry into %2$s', '%1$d entries into %2$s', $added, 'sepo-finance'), $added, $name));
        return ['added' => $added, 'failed' => $failed];
    }
}
