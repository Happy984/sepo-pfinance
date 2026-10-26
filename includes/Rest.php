<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/**
 * The REST routes behind the Finance screens, under sepo-finance/v1.
 *
 * Every route needs the Finance capability and the REST nonce. The screens
 * send one request at a time, so pasting a statement or saving a run of
 * entries never opens a burst of parallel database connections.
 */
final class Rest
{
    public const NS = 'sepo-finance/v1';

    public static function hook(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        $perm = static fn(): bool => Plugin::can();
        $types = implode('|', Records::EDITABLE);
        $r = static function (string $path, string $methods, callable $cb) use ($perm): void {
            register_rest_route(self::NS, $path, ['methods' => $methods, 'callback' => $cb, 'permission_callback' => $perm]);
        };

        $r('/transactions', 'GET', [self::class, 'transactions']);
        $r('/(?P<type>' . $types . ')', 'POST', [self::class, 'save']);
        $r('/(?P<type>' . $types . ')/(?P<id>[A-Za-z0-9_]+)', 'DELETE', [self::class, 'delete']);
        $r('/(?P<type>' . $types . ')/(?P<id>[A-Za-z0-9_]+)/restore', 'POST', [self::class, 'restore']);
        $r('/(?P<type>invoices|bills)/(?P<id>[A-Za-z0-9_]+)/settle', 'POST', [self::class, 'settle']);
        $r('/invoices/(?P<id>[A-Za-z0-9_]+)/email', 'POST', [self::class, 'email']);
        $r('/recurring/(?P<id>[A-Za-z0-9_]+)/post', 'POST', [self::class, 'post']);
        $r('/recurring/(?P<id>[A-Za-z0-9_]+)/skip', 'POST', [self::class, 'skip']);
        $r('/reconcile/tick', 'POST', [self::class, 'tick']);
        $r('/reconcile/finish', 'POST', [self::class, 'finish']);
        $r('/transactions/(?P<id>[A-Za-z0-9_]+)/attachments', 'GET', [self::class, 'files']);
        $r('/transactions/(?P<id>[A-Za-z0-9_]+)/attachments', 'POST', [self::class, 'attach']);
        $r('/transactions/(?P<id>[A-Za-z0-9_]+)/attachments/(?P<i>\d+)', 'DELETE', [self::class, 'detach']);
        $r('/counterparties/(?P<id>[A-Za-z0-9_]+)/statement', 'GET', [self::class, 'statement']);
        $r('/import/read', 'POST', [self::class, 'importRead']);
        $r('/import/preview', 'POST', [self::class, 'importPreview']);
        $r('/import/save', 'POST', [self::class, 'importSave']);
    }

    /** Run a callback, turning the plugin's exceptions into REST errors. */
    private static function guard(callable $cb)
    {
        try {
            return $cb();
        } catch (FieldErrors $e) {
            return new \WP_Error('sfin_invalid', $e->getMessage(), ['status' => 422, 'errors' => $e->errors]);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('sfin_invalid', $e->getMessage(), ['status' => 422]);
        } catch (\Throwable $e) {
            error_log('Sepo Finance: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
            return new \WP_Error('sfin_failed', __('That could not be done. Please try again.', 'sepo-finance'), ['status' => 500]);
        }
    }

    /**
     * A value from the route itself. The body may carry keys of the same
     * name (a record's own "type" or "id"), and those must not change which
     * route was asked for.
     */
    private static function url(\WP_REST_Request $req, string $key): string
    {
        $p = $req->get_url_params();
        return (string) ($p[$key] ?? '');
    }

    /** The body as a flat array, from JSON or a form post. @return array<string,mixed> */
    private static function body(\WP_REST_Request $req): array
    {
        $json = $req->get_json_params();
        return is_array($json) && $json !== [] ? $json : (array) $req->get_body_params();
    }

    public static function transactions(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $f = [];
            foreach (['from', 'to', 'account', 'category', 'counterparty', 'type', 'status', 'search'] as $k) {
                $f[$k] = sanitize_text_field((string) $req->get_param($k));
            }
            $f['limit'] = min(2000, max(1, (int) ($req->get_param('limit') ?: 500)));
            $rows = Ledger::transactions($f);
            return ['ok' => true, 'transactions' => $rows, 'sums' => Ledger::sums($rows)];
        });
    }

    public static function save(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $type = self::url($req, 'type');
            $saved = Records::save($type, self::body($req));
            return ['ok' => true, 'record' => $saved, 'refs' => in_array($type, ['accounts', 'categories', 'counterparties'], true) ? Store::refList($type) : null];
        });
    }

    public static function delete(\WP_REST_Request $req)
    {
        return self::guard(static fn() => ['ok' => true] + Records::delete(self::url($req, 'type'), self::url($req, 'id')));
    }

    public static function restore(\WP_REST_Request $req)
    {
        return self::guard(static fn() => ['ok' => true, 'record' => Records::restore(self::url($req, 'type'), self::url($req, 'id'))]);
    }

    public static function settle(\WP_REST_Request $req)
    {
        return self::guard(static fn() => ['ok' => true] + Documents::settle(self::url($req, 'type'), self::url($req, 'id'), self::body($req)));
    }

    public static function email(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $r = Documents::email(self::url($req, 'id'));
            return ['ok' => true, 'reminder' => $r['reminder'], 'message' => $r['reminder']
                /* translators: %s: email address */
                ? sprintf(__('Reminder sent to %s.', 'sepo-finance'), $r['to'])
                /* translators: %s: email address */
                : sprintf(__('Invoice sent to %s.', 'sepo-finance'), $r['to'])];
        });
    }

    public static function post(\WP_REST_Request $req)
    {
        return self::guard(static fn() => ['ok' => true] + Recurring::post(self::url($req, 'id')));
    }

    public static function skip(\WP_REST_Request $req)
    {
        return self::guard(static fn() => ['ok' => true, 'rule' => Recurring::skip(self::url($req, 'id'))]);
    }

    public static function tick(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $b = self::body($req);
            return ['ok' => true, 'transaction' => Reconcile::tick((string) ($b['id'] ?? ''), Parse::truthy($b['on'] ?? true))];
        });
    }

    public static function finish(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $b = self::body($req);
            $date = Parse::date((string) ($b['date'] ?? ''));
            $bal = Parse::amount($b['balance'] ?? '');
            if ($date === null || $bal === null) {
                throw new \InvalidArgumentException(__('Enter the statement date and its closing balance.', 'sepo-finance'));
            }
            return ['ok' => true, 'reconciliation' => Reconcile::finish((string) ($b['account_id'] ?? ''), $date, $bal)];
        });
    }

    public static function files(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $t = Store::find('transactions', self::url($req, 'id'));
            if ($t === null) {
                throw new \InvalidArgumentException(__('That entry was not found.', 'sepo-finance'));
            }
            return ['ok' => true] + Attachments::present($t);
        });
    }

    public static function attach(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $id = self::url($req, 'id');
            $media = (int) $req->get_param('media_id');
            if ($media > 0) {
                return ['ok' => true] + Attachments::addMedia($id, $media);
            }
            $f = $req->get_file_params();
            $files = isset($f['files']) && is_array($f['files']) ? Files::fromUpload($f['files']) : [];
            if ($files === []) {
                throw new \InvalidArgumentException(__('Choose a file to attach.', 'sepo-finance'));
            }
            return ['ok' => true] + Attachments::addUploads($id, $files);
        });
    }

    public static function detach(\WP_REST_Request $req)
    {
        return self::guard(static fn() => ['ok' => true] + Attachments::remove(self::url($req, 'id'), (int) self::url($req, 'i')));
    }

    public static function statement(\WP_REST_Request $req)
    {
        return self::guard(static fn() => ['ok' => true] + Reports::statement(self::url($req, 'id')));
    }

    public static function importRead(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $f = $req->get_file_params();
            if (!isset($f['file']) || !is_array($f['file'])) {
                throw new \InvalidArgumentException(__('Choose a CSV file to import.', 'sepo-finance'));
            }
            return ['ok' => true] + Import::read($f['file']);
        });
    }

    public static function importPreview(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $b = self::body($req);
            $rows = is_array($b['rows'] ?? null) ? $b['rows'] : [];
            $rows = array_map(static fn($r): array => array_map(static fn($v): string => (string) $v, is_array($r) ? array_values($r) : []), $rows);
            return ['ok' => true] + Import::preview((string) ($b['account_id'] ?? ''), $rows, is_array($b['map'] ?? null) ? $b['map'] : []);
        });
    }

    public static function importSave(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $b = self::body($req);
            return ['ok' => true] + Import::save((string) ($b['account_id'] ?? ''), is_array($b['items'] ?? null) ? $b['items'] : []);
        });
    }
}
