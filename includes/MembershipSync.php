<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * Payments confirmed in Sepo Membership become income in the ledger.
 *
 * When a membership fee, donation or sponsorship is confirmed there, an entry
 * is added here: income, into the account chosen in Settings, filed under
 * Membership fees, Donations or Sponsorships, with the payer as the customer
 * (found by email, or added). Each payment makes exactly one entry, whose id
 * comes from the payment, so confirming twice never counts it twice.
 *
 * When a confirmed payment is later marked not received or deleted there, its
 * entry here is deleted (it can be restored) and the log says why. Nothing is
 * posted until an account is chosen, so the books never receive money into
 * nowhere.
 */
final class MembershipSync
{
    public static function hook(): void
    {
        add_action('sepo_membership_payment_confirmed', [self::class, 'confirmed'], 20);
        add_action('sepo_membership_logged', [self::class, 'logged'], 20, 2);
    }

    public static function available(): bool
    {
        return class_exists('\\SepoMembership\\Payments');
    }

    public static function enabled(): bool
    {
        return self::available() && (int) Settings::get('membership_sync') === 1 && Store::find('accounts', (string) Settings::get('membership_account')) !== null;
    }

    private static function txnId(int $paymentId): string
    {
        return 'txn_smem_' . $paymentId;
    }

    /** @param array<string,mixed> $p */
    public static function confirmed($p): void
    {
        if (!is_array($p) || !self::enabled() || empty($p['id'])) {
            return;
        }
        try {
            self::post($p);
        } catch (\Throwable $e) {
            error_log('Sepo Finance: a membership payment could not be added to the ledger: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        }
    }

    /** @param array<string,mixed> $p */
    private static function post(array $p): void
    {
        $kind = (string) ($p['kind'] ?? 'donation');
        $category = match ($kind) {
            'membership'  => Seed::category(__('Membership fees', 'sepo-finance'), 'income'),
            'sponsorship' => Seed::category(__('Sponsorships', 'sepo-finance'), 'income'),
            default       => Seed::category(__('Donations', 'sepo-finance'), 'income'),
        };
        $name = trim((string) ($p['name'] ?? ''));
        $what = match ($kind) {
            /* translators: 1: plan, 2: payer */
            'membership'  => sprintf(__('Membership: %1$s — %2$s', 'sepo-finance'), class_exists('\\SepoMembership\\Tiers') ? \SepoMembership\Tiers::label((string) $p['level']) : (string) $p['level'], $name),
            /* translators: 1: project, 2: payer */
            'sponsorship' => sprintf(__('Sponsorship: %1$s — %2$s', 'sepo-finance'), get_the_title((int) ($p['project_id'] ?? 0)), $name),
            /* translators: %s: payer */
            default       => sprintf(__('Donation — %s', 'sepo-finance'), $name),
        };
        $existing = Store::find('transactions', self::txnId((int) $p['id']), true);
        [$record] = Fields::build('transactions', [
            'date'            => $existing['date'] ?? current_time('Y-m-d'),
            'type'            => 'income',
            'amount'          => (float) $p['amount'],
            'currency'        => (string) ($p['currency'] ?? ''),
            'account_id'      => (string) Settings::get('membership_account'),
            'category_id'     => $category,
            'counterparty_id' => self::counterparty((string) ($p['email'] ?? ''), $name),
            'description'     => $what,
            /* translators: %d: payment number */
            'reference'       => (string) ($p['reference'] ?? '') !== '' ? (string) $p['reference'] : sprintf(__('Payment #%d', 'sepo-finance'), (int) $p['id']),
            'method'          => self::method((string) ($p['method'] ?? '')),
            'status'          => 'cleared',
            'notes'           => (string) ($p['message'] ?? ''),
        ], null);
        $record['id'] = self::txnId((int) $p['id']);
        $record['source'] = 'sepo-membership';
        $record['source_id'] = (string) $p['id'];
        $record['attachments'] = $existing['attachments'] ?? [];
        $record['deleted'] = 0;
        $saved = Store::write('transactions', $record);
        Log::add('synced', 'transactions', (string) $saved['id'], null, null, Log::describe('transactions', $saved));
    }

    /** A confirmed payment that turned out not to be received, or was deleted, comes back out. */
    public static function logged($action, $p): void
    {
        if (!is_array($p) || empty($p['id']) || !in_array($action, ['rejected', 'deleted'], true)) {
            return;
        }
        $t = Store::find('transactions', self::txnId((int) $p['id']));
        if ($t === null) {
            return;
        }
        Store::softDelete('transactions', (string) $t['id']);
        Log::add('reversed', 'transactions', (string) $t['id'], null, null, Log::describe('transactions', $t) . ' · ' . ($action === 'rejected' ? __('marked not received in Sepo Membership', 'sepo-finance') : __('deleted in Sepo Membership', 'sepo-finance')));
    }

    private static function counterparty(string $email, string $name): string
    {
        $email = sanitize_email($email);
        if ($email === '' && $name === '') {
            return '';
        }
        foreach (Store::all('counterparties') as $c) {
            if (($email !== '' && strcasecmp((string) $c['email'], $email) === 0) || ($email === '' && strcasecmp((string) $c['name'], $name) === 0)) {
                return (string) $c['id'];
            }
        }
        $saved = Store::write('counterparties', ['id' => Fields::newId('counterparties'), 'name' => $name !== '' ? $name : $email, 'type' => 'customer', 'email' => $email, 'phone' => '', 'address' => '', 'tax_id' => '', 'notes' => __('Added from Sepo Membership', 'sepo-finance'), 'archived' => 0]);
        Log::add('created', 'counterparties', (string) $saved['id'], null, $saved);
        return (string) $saved['id'];
    }

    /** The finance method closest to a Membership payment method. */
    private static function method(string $id): string
    {
        $label = strtolower($id);
        if (class_exists('\\SepoMembership\\Settings')) {
            $m = \SepoMembership\Settings::methods()[$id] ?? null;
            $label = strtolower((string) ($m['label'] ?? $id) . ' ' . (string) ($m['type'] ?? ''));
        }
        return match (true) {
            str_contains($label, 'mobile') || str_contains($label, 'momo') || str_contains($label, 'airtel') || str_contains($label, 'mtn') => 'mobile',
            str_contains($label, 'bank') || str_contains($label, 'transfer') || str_contains($label, 'eft') => 'bank',
            str_contains($label, 'card') || str_contains($label, 'link') => 'card',
            str_contains($label, 'cash') => 'cash',
            str_contains($label, 'cheque') || str_contains($label, 'check') => 'cheque',
            default => 'other',
        };
    }
}
