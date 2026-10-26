<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * Invoices and bills: recording payments, the printable page, and emails.
 *
 * SETTLING IS NOT EDITING. A payment is an event with its own date, account
 * and method, and recording it writes a ledger entry linked to the document.
 * What has been paid is always counted from those entries, so deleting a
 * payment entry puts the amount back on the document by itself.
 */
final class Documents
{
    /** Record a payment against a document. @return array{transaction:array<string,mixed>,document:array<string,mixed>,outstanding:float} */
    public static function settle(string $type, string $id, array $in): array
    {
        $doc = Store::find($type, $id);
        if ($doc === null) {
            throw new \InvalidArgumentException(__('That document was not found.', 'sepo-finance'));
        }
        if ($doc['status'] === 'cancelled') {
            throw new \InvalidArgumentException(__('That document is cancelled.', 'sepo-finance'));
        }
        $owed = Ledger::outstanding($doc);
        if ($owed <= 0) {
            throw new \InvalidArgumentException(__('That document is already paid in full.', 'sepo-finance'));
        }
        $asked = Parse::amount($in['amount'] ?? '');
        $pay = $asked === null ? $owed : round(abs($asked), 2);
        if ($pay <= 0) {
            throw new FieldErrors(['amount' => __('Enter the amount paid.', 'sepo-finance')]);
        }
        if ($pay > $owed + 0.004) {
            /* translators: %s: amount */
            throw new FieldErrors(['amount' => sprintf(__('That is more than the %s still owed.', 'sepo-finance'), Parse::money($owed, (string) $doc['currency']))]);
        }
        $account = trim((string) ($in['account_id'] ?? ''));
        if ($account === '' || Store::find('accounts', $account) === null) {
            throw new FieldErrors(['account_id' => __('Choose the account the money moved through.', 'sepo-finance')]);
        }
        $date = Parse::date((string) ($in['date'] ?? '')) ?? current_time('Y-m-d');
        $isInv = $type === 'invoices';
        $txn = Records::save('transactions', [
            'date'            => $date,
            'type'            => $isInv ? 'income' : 'expense',
            'amount'          => $pay,
            'currency'        => (string) $doc['currency'],
            'account_id'      => $account,
            'category_id'     => trim((string) ($in['category_id'] ?? '')),
            'counterparty_id' => (string) $doc['counterparty_id'],
            'reference'       => (string) $doc['number'],
            /* translators: %s: document number */
            'description'     => sprintf($isInv ? __('Payment for invoice %s', 'sepo-finance') : __('Payment of bill %s', 'sepo-finance'), (string) $doc['number']),
            'method'          => sanitize_key((string) ($in['method'] ?? '')),
            'status'          => 'cleared',
        ]);
        $txn = Store::patch('transactions', (string) $txn['id'], [$isInv ? 'invoice_id' : 'bill_id' => $id]) ?? $txn;
        $doc = self::repay($type, $id) ?? $doc;
        Log::add('settled', $type, $id, null, null, Log::describe($type, $doc) . ' · ' . Parse::money($pay, (string) $doc['currency']));
        return ['transaction' => $txn, 'document' => $doc, 'outstanding' => Ledger::outstanding($doc)];
    }

    /** Count what has been paid from the linked entries and set the status to match. */
    public static function repay(string $type, string $id): ?array
    {
        global $wpdb;
        $doc = Store::find($type, $id, true);
        if ($doc === null) {
            return null;
        }
        $col = $type === 'invoices' ? 'invoice_id' : 'bill_id';
        $paid = (float) $wpdb->get_var($wpdb->prepare('SELECT SUM(amount) FROM ' . Schema::table('transactions') . " WHERE deleted = 0 AND $col = %s", $id)); // phpcs:ignore WordPress.DB.PreparedSQL
        $paid = round($paid, 2);
        $status = (string) $doc['status'];
        if ($status !== 'cancelled') {
            if ($paid >= (float) $doc['amount'] - 0.004 && (float) $doc['amount'] > 0) {
                $status = 'paid';
            } elseif ($status === 'paid' || ($paid > 0 && $status === 'draft')) {
                $status = 'sent';
            }
        }
        if (abs($paid - (float) $doc['paid_amount']) > 0.004 || $status !== $doc['status']) {
            $doc = Store::patch($type, $id, ['paid_amount' => $paid, 'status' => $status]) ?? $doc;
        }
        return $doc;
    }

    /** The next invoice number: the prefix and one more than the highest number in use. */
    public static function nextNumber(): string
    {
        $prefix = (string) Settings::get('invoice_prefix');
        $max = 0;
        foreach (Store::all('invoices', true) as $d) {
            $n = (string) $d['number'];
            if ($prefix === '' || str_starts_with($n, $prefix)) {
                $digits = (int) preg_replace('/\D/', '', substr($n, strlen($prefix)));
                $max = max($max, $digits);
            }
        }
        return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** The document page, ready to print or save as PDF. */
    public static function printable(string $type, array $doc): string
    {
        $isInv = $type === 'invoices';
        $cp = Store::find('counterparties', (string) $doc['counterparty_id'], true) ?? [];
        $cur = (string) $doc['currency'];
        $logo = (int) Settings::get('company_logo') > 0 ? (string) wp_get_attachment_image_url((int) Settings::get('company_logo'), 'medium') : '';
        $title = $isInv ? __('Invoice', 'sepo-finance') : __('Bill', 'sepo-finance');
        $e = static fn($v): string => esc_html((string) $v);
        $lines = $doc['lines'];
        if ($lines === []) {
            $lines = [['description' => $isInv ? __('As agreed', 'sepo-finance') : __('As billed', 'sepo-finance'), 'qty' => 1, 'price' => (float) $doc['subtotal']]];
        }
        $rows = '';
        foreach ($lines as $l) {
            $rows .= '<tr><td>' . $e($l['description']) . '</td><td class="n">' . $e(rtrim(rtrim(number_format_i18n((float) $l['qty'], 3), '0'), '.,')) . '</td><td class="n">' . $e(Parse::money($l['price'], $cur)) . '</td><td class="n">' . $e(Parse::money((float) $l['qty'] * (float) $l['price'], $cur)) . '</td></tr>';
        }
        $owed = Ledger::outstanding($doc);
        $company = Settings::companyName();
        $from = '<strong>' . $e($company) . '</strong>' . ((string) Settings::get('company_address') !== '' ? '<br>' . nl2br($e(Settings::get('company_address'))) : '')
            . ((string) Settings::get('company_email') !== '' ? '<br>' . $e(Settings::get('company_email')) : '')
            . ((string) Settings::get('company_phone') !== '' ? '<br>' . $e(Settings::get('company_phone')) : '')
            . ((string) Settings::get('company_tax_id') !== '' ? '<br>' . esc_html__('TPIN', 'sepo-finance') . ' ' . $e(Settings::get('company_tax_id')) : '');
        $to = '<strong>' . $e($cp['name'] ?? '') . '</strong>' . (($cp['address'] ?? '') !== '' ? '<br>' . nl2br($e($cp['address'])) : '')
            . (($cp['email'] ?? '') !== '' ? '<br>' . $e($cp['email']) : '') . (($cp['tax_id'] ?? '') !== '' ? '<br>' . esc_html__('TPIN', 'sepo-finance') . ' ' . $e($cp['tax_id']) : '');
        $status = $doc['status'] === 'paid' ? __('Paid', 'sepo-finance') : (Ledger::isOverdue($doc) ? __('Overdue', 'sepo-finance') : '');
        $h = '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($title . ' ' . $doc['number']) . '</title><style>'
            . 'body{margin:0;background:#eef1f4;color:#111;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
            . '.bar{display:flex;gap:8px;justify-content:center;padding:14px}.bar button,.bar a{font:inherit;font-weight:600;padding:9px 16px;border-radius:8px;border:1px solid #1f4e79;background:#1f4e79;color:#fff;cursor:pointer;text-decoration:none}.bar a{background:#fff;color:#1f4e79}'
            . '.page{max-width:820px;margin:0 auto 40px;background:#fff;padding:48px 52px;border:1px solid #d9dee4;border-radius:6px}'
            . '.top{display:flex;justify-content:space-between;gap:24px;align-items:flex-start}.top img{max-height:70px;max-width:220px}'
            . 'h1{margin:0 0 6px;font-size:30px;letter-spacing:-.02em}.meta{color:#444}.meta b{color:#111}.stamp{display:inline-block;margin-top:8px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;background:#e7f6ec;color:#14532d}.stamp.late{background:#fdecea;color:#7a1d16}'
            . '.parties{display:grid;grid-template-columns:1fr 1fr;gap:28px;margin:30px 0}.parties h2{margin:0 0 6px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#555}'
            . 'table{width:100%;border-collapse:collapse}th{text-align:left;font-size:11px;letter-spacing:.07em;text-transform:uppercase;color:#555;border-bottom:2px solid #111;padding:8px 6px}td{padding:10px 6px;border-bottom:1px solid #e3e6ea;vertical-align:top}.n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}'
            . '.totals{margin:16px 0 0 auto;width:320px}.totals td{border:0;padding:5px 6px}.totals tr.big td{border-top:2px solid #111;font-weight:800;font-size:17px;padding-top:9px}'
            . '.notes{margin-top:30px;color:#333;font-size:14px}.notes h2{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#555;margin:0 0 4px}.foot{margin-top:34px;padding-top:12px;border-top:1px solid #e3e6ea;color:#555;font-size:13px}'
            . '@media(max-width:640px){.page{padding:26px 20px;border-radius:0}.parties{grid-template-columns:1fr}.totals{width:100%}}'
            . '@media print{body{background:#fff}.bar{display:none}.page{border:0;margin:0;padding:0;max-width:none}@page{margin:16mm}}'
            . '</style></head><body><div class="bar"><button type="button" onclick="window.print()">' . esc_html__('Print or save as PDF', 'sepo-finance') . '</button><a href="' . esc_url(admin_url('admin.php?page=sepo-finance-' . $type)) . '">' . esc_html__('Back', 'sepo-finance') . '</a></div>'
            . '<div class="page"><div class="top"><div><h1>' . $e($title) . '</h1><div class="meta">' . esc_html__('Number', 'sepo-finance') . ' <b>' . $e($doc['number']) . '</b><br>'
            . esc_html__('Issued', 'sepo-finance') . ' <b>' . $e(Parse::show((string) $doc['date_issued'])) . '</b><br>' . esc_html__('Due', 'sepo-finance') . ' <b>' . $e(Parse::show((string) $doc['date_due'])) . '</b></div>'
            . ($status !== '' ? '<span class="stamp' . ($status !== __('Paid', 'sepo-finance') ? ' late' : '') . '">' . $e($status) . '</span>' : '') . '</div>'
            . ($logo !== '' ? '<img src="' . esc_url($logo) . '" alt="' . esc_attr($company) . '">' : '') . '</div>'
            . '<div class="parties"><div><h2>' . ($isInv ? esc_html__('From', 'sepo-finance') : esc_html__('Supplier', 'sepo-finance')) . '</h2>' . ($isInv ? $from : $to) . '</div><div><h2>' . ($isInv ? esc_html__('Bill to', 'sepo-finance') : esc_html__('Billed to', 'sepo-finance')) . '</h2>' . ($isInv ? $to : $from) . '</div></div>'
            . '<table><thead><tr><th>' . esc_html__('Description', 'sepo-finance') . '</th><th class="n">' . esc_html__('Qty', 'sepo-finance') . '</th><th class="n">' . esc_html__('Price', 'sepo-finance') . '</th><th class="n">' . esc_html__('Amount', 'sepo-finance') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<table class="totals"><tbody><tr><td>' . esc_html__('Subtotal', 'sepo-finance') . '</td><td class="n">' . $e(Parse::money($doc['subtotal'], $cur)) . '</td></tr>'
            . ((float) $doc['tax'] > 0 ? '<tr><td>' . esc_html(sprintf(/* translators: %s: rate */ __('Tax (%s%%)', 'sepo-finance'), rtrim(rtrim(number_format((float) $doc['tax_rate'], 3), '0'), '.'))) . '</td><td class="n">' . $e(Parse::money($doc['tax'], $cur)) . '</td></tr>' : '')
            . '<tr class="big"><td>' . esc_html__('Total', 'sepo-finance') . '</td><td class="n">' . $e(Parse::money($doc['amount'], $cur)) . '</td></tr>'
            . ((float) $doc['paid_amount'] > 0 ? '<tr><td>' . esc_html__('Paid', 'sepo-finance') . '</td><td class="n">' . $e(Parse::money($doc['paid_amount'], $cur)) . '</td></tr><tr class="big"><td>' . esc_html__('Still owed', 'sepo-finance') . '</td><td class="n">' . $e(Parse::money($owed, $cur)) . '</td></tr>' : '')
            . '</tbody></table>'
            . ((string) $doc['notes'] !== '' ? '<div class="notes"><h2>' . esc_html__('Notes', 'sepo-finance') . '</h2>' . nl2br($e($doc['notes'])) . '</div>' : '')
            . ($isInv && trim((string) Settings::get('payment_details')) !== '' && $owed > 0 ? '<div class="notes"><h2>' . esc_html__('How to pay', 'sepo-finance') . '</h2>' . nl2br($e(Settings::get('payment_details'))) . '</div>' : '')
            . ($isInv && trim((string) Settings::get('invoice_footer')) !== '' ? '<div class="foot">' . nl2br($e(Settings::get('invoice_footer'))) . '</div>' : '')
            . '</div></body></html>';
        return $h;
    }

    /** The invoice as an email body (HTML). */
    private static function emailBody(array $doc, bool $reminder): string
    {
        $cp = Store::find('counterparties', (string) $doc['counterparty_id'], true) ?? [];
        $cur = (string) $doc['currency'];
        $e = static fn($v): string => esc_html((string) $v);
        $owed = Ledger::outstanding($doc);
        $rows = '';
        foreach ($doc['lines'] as $l) {
            $rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #e3e6ea">' . $e($l['description']) . ' &times; ' . $e(rtrim(rtrim(number_format_i18n((float) $l['qty'], 3), '0'), '.,')) . '</td><td style="padding:6px 8px;border-bottom:1px solid #e3e6ea;text-align:right;white-space:nowrap">' . $e(Parse::money((float) $l['qty'] * (float) $l['price'], $cur)) . '</td></tr>';
        }
        $greeting = trim((string) ($cp['name'] ?? '')) !== '' ? sprintf(/* translators: %s: name */ __('Dear %s,', 'sepo-finance'), (string) $cp['name']) : __('Hello,', 'sepo-finance');
        $intro = $reminder
            /* translators: 1: number, 2: amount, 3: date */
            ? sprintf(__('This is a reminder that invoice %1$s for %2$s was due on %3$s and is still unpaid.', 'sepo-finance'), (string) $doc['number'], Parse::money($owed, $cur), Parse::show((string) $doc['date_due']))
            /* translators: 1: number, 2: amount, 3: date */
            : sprintf(__('Please find invoice %1$s for %2$s, due on %3$s.', 'sepo-finance'), (string) $doc['number'], Parse::money($doc['amount'], $cur), Parse::show((string) $doc['date_due']));
        return '<div style="font:15px/1.55 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#111;max-width:600px">'
            . '<p>' . $e($greeting) . '</p><p>' . $e($intro) . '</p>'
            . ($rows !== '' ? '<table style="border-collapse:collapse;width:100%;margin:14px 0">' . $rows . '</table>' : '')
            . '<p style="font-size:17px"><strong>' . esc_html__('Amount due:', 'sepo-finance') . ' ' . $e(Parse::money($owed, $cur)) . '</strong></p>'
            . (trim((string) Settings::get('payment_details')) !== '' ? '<p><strong>' . esc_html__('How to pay', 'sepo-finance') . '</strong><br>' . nl2br($e(Settings::get('payment_details'))) . '</p>' : '')
            . ((string) $doc['notes'] !== '' ? '<p>' . nl2br($e($doc['notes'])) . '</p>' : '')
            . '<p>' . esc_html__('Thank you.', 'sepo-finance') . '<br>' . $e(Settings::companyName()) . '</p>'
            . (trim((string) Settings::get('invoice_footer')) !== '' ? '<p style="color:#555;font-size:13px">' . nl2br($e(Settings::get('invoice_footer'))) . '</p>' : '')
            . '</div>';
    }

    /** Email an invoice to its customer: the invoice, or a reminder when it is overdue. */
    public static function email(string $id, bool $auto = false): array
    {
        $doc = Store::find('invoices', $id);
        if ($doc === null) {
            throw new \InvalidArgumentException(__('That invoice was not found.', 'sepo-finance'));
        }
        if (in_array($doc['status'], ['cancelled', 'paid'], true)) {
            throw new \InvalidArgumentException(__('A paid or cancelled invoice is not emailed.', 'sepo-finance'));
        }
        $cp = Store::find('counterparties', (string) $doc['counterparty_id'], true);
        $to = (string) ($cp['email'] ?? '');
        if (!is_email($to)) {
            throw new \InvalidArgumentException(__('This customer has no email address. Add one under Customers and suppliers.', 'sepo-finance'));
        }
        $reminder = Ledger::isOverdue($doc);
        $subject = $reminder
            /* translators: 1: number, 2: company */
            ? sprintf(__('Reminder: invoice %1$s from %2$s is overdue', 'sepo-finance'), (string) $doc['number'], Settings::companyName())
            /* translators: 1: number, 2: company */
            : sprintf(__('Invoice %1$s from %2$s', 'sepo-finance'), (string) $doc['number'], Settings::companyName());
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $reply = (string) Settings::get('company_email');
        if (is_email($reply)) {
            $headers[] = 'Reply-To: ' . Settings::companyName() . ' <' . $reply . '>';
        }
        if (!wp_mail($to, $subject, self::emailBody($doc, $reminder), $headers)) {
            throw new \RuntimeException(__('The email could not be sent. Check the site can send mail.', 'sepo-finance'));
        }
        $fields = ['reminded_at' => time()];
        if (!$reminder) {
            $fields['sent_at'] = time();
            if ($doc['status'] === 'draft') {
                $fields['status'] = 'sent';
            }
        }
        $doc = Store::patch('invoices', $id, $fields) ?? $doc;
        Log::add($reminder ? 'reminded' : 'emailed', 'invoices', $id, null, null, Log::describe('invoices', $doc) . ' → ' . $to . ($auto ? ' · ' . __('automatic', 'sepo-finance') : ''));
        return ['document' => $doc, 'reminder' => $reminder, 'to' => $to];
    }

    /** Once a day, when turned on: remind customers about overdue invoices, at most once per interval each. */
    public static function sendReminders(): void
    {
        if ((int) Settings::get('reminders') !== 1) {
            return;
        }
        $every = max(1, (int) Settings::get('reminder_every')) * DAY_IN_SECONDS;
        $sent = 0;
        foreach (Store::all('invoices') as $d) {
            if ($sent >= 50 || !Ledger::isOverdue($d) || time() - (int) $d['reminded_at'] < $every) {
                continue;
            }
            try {
                self::email((string) $d['id'], true);
                $sent++;
            } catch (\Throwable $e) {
                continue;
            }
        }
    }
}
