<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * Receipts and other files on a ledger entry.
 *
 * A file is either uploaded to the private folder, which cannot be opened by
 * its address, or chosen from the media library. Either way it is opened
 * through a checked route, only by people who may use Finance.
 */
final class Attachments
{
    public const MAX = 10;

    /** @param list<array<string,mixed>> $files */
    public static function addUploads(string $txnId, array $files): array
    {
        $t = self::txn($txnId);
        $room = self::MAX - count($t['attachments']);
        if ($room < 1) {
            /* translators: %d: limit */
            throw new \InvalidArgumentException(sprintf(__('An entry can have at most %d files.', 'sepo-finance'), self::MAX));
        }
        $stored = Files::store('receipts', $files, Files::receiptMimes(), Files::maxMb(), $room);
        $list = $t['attachments'];
        foreach ($stored as $s) {
            $list[] = ['kind' => 'private'] + $s;
        }
        $t = Store::patch('transactions', $txnId, ['attachments' => $list]) ?? $t;
        Log::add('attached', 'transactions', $txnId, null, null, Log::describe('transactions', $t) . ' · ' . implode(', ', array_column($stored, 'name')));
        return self::present($t);
    }

    public static function addMedia(string $txnId, int $mediaId): array
    {
        $t = self::txn($txnId);
        if ($mediaId < 1 || get_post_type($mediaId) !== 'attachment') {
            throw new \InvalidArgumentException(__('That file is not in the media library.', 'sepo-finance'));
        }
        if (count($t['attachments']) >= self::MAX) {
            /* translators: %d: limit */
            throw new \InvalidArgumentException(sprintf(__('An entry can have at most %d files.', 'sepo-finance'), self::MAX));
        }
        $path = (string) get_attached_file($mediaId);
        $list = $t['attachments'];
        $list[] = ['kind' => 'media', 'media' => $mediaId, 'name' => wp_basename($path), 'type' => (string) get_post_mime_type($mediaId), 'size' => is_file($path) ? (int) filesize($path) : 0, 'at' => time()];
        $t = Store::patch('transactions', $txnId, ['attachments' => $list]) ?? $t;
        Log::add('attached', 'transactions', $txnId, null, null, Log::describe('transactions', $t) . ' · ' . wp_basename($path));
        return self::present($t);
    }

    public static function remove(string $txnId, int $index): array
    {
        $t = self::txn($txnId);
        $list = $t['attachments'];
        if (!isset($list[$index])) {
            throw new \InvalidArgumentException(__('That file is no longer on the entry.', 'sepo-finance'));
        }
        $f = $list[$index];
        if (($f['kind'] ?? '') === 'private') {
            Files::remove('receipts', [$f]);
        }
        array_splice($list, $index, 1);
        $t = Store::patch('transactions', $txnId, ['attachments' => $list]) ?? $t;
        Log::add('detached', 'transactions', $txnId, null, null, Log::describe('transactions', $t) . ' · ' . (string) ($f['name'] ?? ''));
        return self::present($t);
    }

    /** @return array<string,mixed> */
    private static function txn(string $id): array
    {
        $t = Store::find('transactions', $id);
        if ($t === null) {
            throw new \InvalidArgumentException(__('Save the entry first, then attach files to it.', 'sepo-finance'));
        }
        return $t;
    }

    /** The entry's files with their checked addresses. @return array{attachments:list<array<string,mixed>>} */
    public static function present(array $t): array
    {
        $out = [];
        foreach ($t['attachments'] as $i => $f) {
            $out[] = [
                'name'  => (string) ($f['name'] ?? ''),
                'type'  => (string) ($f['type'] ?? ''),
                'size'  => size_format((int) ($f['size'] ?? 0), 1),
                'kind'  => (string) ($f['kind'] ?? 'private'),
                'url'   => self::url((string) $t['id'], (int) $i),
                'image' => str_starts_with((string) ($f['type'] ?? ''), 'image/'),
            ];
        }
        return ['attachments' => $out];
    }

    public static function url(string $txnId, int $index): string
    {
        return add_query_arg(['action' => 'sfin_file', 'txn' => $txnId, 'i' => $index, '_wpnonce' => wp_create_nonce('sfin_file')], admin_url('admin-post.php'));
    }

    /** admin-post.php?action=sfin_file */
    public static function serve(): void
    {
        if (!Plugin::can() || !wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'sfin_file')) {
            wp_die(esc_html__('You cannot open this file.', 'sepo-finance'), '', ['response' => 403]);
        }
        $t = Store::find('transactions', sanitize_text_field(wp_unslash((string) ($_GET['txn'] ?? ''))), true);
        $f = $t['attachments'][(int) ($_GET['i'] ?? -1)] ?? null;
        if (!is_array($f)) {
            wp_die(esc_html__('That file could not be found.', 'sepo-finance'), '', ['response' => 404]);
        }
        if (($f['kind'] ?? '') === 'media') {
            Files::sendAttachment((int) $f['media']);
        }
        Files::send('receipts', $f, true);
    }
}
