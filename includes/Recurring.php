<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * Recurring entries: rent, salaries, subscriptions.
 *
 * A rule holds the entry it makes and when it is next due. Posting makes that
 * entry, dated on the due date, and moves the rule on by one period. A rule
 * that was already posted for its due date posts nothing, so a double click
 * cannot charge the rent twice, and a paused rule posts nothing at all.
 */
final class Recurring
{
    /** Rules due on or before a date (today by default), soonest first. @return list<array<string,mixed>> */
    public static function due(string $by = ''): array
    {
        $by = $by !== '' ? $by : current_time('Y-m-d');
        return array_values(array_filter(Store::all('recurring'), static fn(array $r): bool => !empty($r['active']) && (string) $r['next_due'] !== '' && $r['next_due'] <= $by));
    }

    /** @return array{transaction:array<string,mixed>,rule:array<string,mixed>} */
    public static function post(string $id): array
    {
        $rule = Store::find('recurring', $id);
        if ($rule === null) {
            throw new \InvalidArgumentException(__('That rule was not found.', 'sepo-finance'));
        }
        if (empty($rule['active'])) {
            throw new \InvalidArgumentException(__('That rule is paused.', 'sepo-finance'));
        }
        $due = (string) $rule['next_due'];
        if ($due === '') {
            throw new \InvalidArgumentException(__('That rule has no due date.', 'sepo-finance'));
        }
        if ((string) $rule['last_created'] === $due) {
            throw new \InvalidArgumentException(__('That date has already been posted.', 'sepo-finance'));
        }
        $txn = Records::save('transactions', [
            'date'            => $due,
            'type'            => $rule['t_type'],
            'amount'          => $rule['t_amount'],
            'account_id'      => $rule['t_account_id'],
            'to_account_id'   => $rule['t_to_account_id'],
            'category_id'     => $rule['t_category_id'],
            'counterparty_id' => $rule['t_counterparty_id'],
            'description'     => (string) $rule['t_description'] !== '' ? $rule['t_description'] : $rule['name'],
            'method'          => $rule['t_method'],
            'status'          => 'cleared',
        ]);
        Store::patch('transactions', (string) $txn['id'], ['source' => 'recurring', 'source_id' => $id]);
        $rule = Store::patch('recurring', $id, ['last_created' => $due, 'next_due' => Parse::nextDue($due, (string) $rule['frequency'])]) ?? $rule;
        Log::add('posted', 'recurring', $id, null, null, (string) $rule['name'] . ' · ' . Parse::show($due) . ' · ' . Parse::money($txn['amount'], (string) $txn['currency']));
        return ['transaction' => $txn, 'rule' => $rule];
    }

    /** Move a rule past one due date without posting it (a month the rent was waived). */
    public static function skip(string $id): array
    {
        $rule = Store::find('recurring', $id);
        if ($rule === null) {
            throw new \InvalidArgumentException(__('That rule was not found.', 'sepo-finance'));
        }
        $due = (string) $rule['next_due'];
        $rule = Store::patch('recurring', $id, ['next_due' => Parse::nextDue($due !== '' ? $due : current_time('Y-m-d'), (string) $rule['frequency'])]) ?? $rule;
        /* translators: %s: date */
        Log::add('skipped', 'recurring', $id, null, null, (string) $rule['name'] . ' · ' . sprintf(__('skipped %s', 'sepo-finance'), Parse::show($due)));
        return $rule;
    }
}
