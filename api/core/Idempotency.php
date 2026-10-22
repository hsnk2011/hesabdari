<?php
// /api/core/Idempotency.php

/**
 * Makes "create" requests safe to retry. The client sends X-Idempotency-Key
 * (one key per form submission); if the same user repeats the same action
 * with the same key — a double click, or a resubmit after a network timeout
 * that actually reached the server — the stored response is replayed instead
 * of creating a second invoice/payment.
 */
class Idempotency {
    const ACTIONS = [
        'save_sales_invoice', 'save_purchase_invoice', 'save_sales_return', 'save_purchase_return',
        'save_payment', 'save_expense', 'save_income', 'transfer_money', 'save_quotation',
        'convert_quotation_to_invoice', 'pay_installment', 'save_installment_plan',
        'discount_and_transfer_checks', 'cash_check', 'clear_payable_check', 'bounce_check',
        'create_from_bank_item', 'save_asset_document', 'convert_purchase_to_asset',
    ];

    public static function begin(mysqli $db, $action) {
        $key = trim((string)($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''));
        if ($key === '' || !in_array($action, self::ACTIONS, true) || strlen($key) > 100) {
            return;
        }
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $hash = hash('sha256', $userId . '|' . $action . '|' . $key);
        try {
            $st = $db->prepare("SELECT status_code, response_json FROM idempotency_keys WHERE key_hash = ?");
            $st->bind_param('s', $hash);
            $st->execute();
            $row = db_stmt_to_assoc_array($st)[0] ?? null;
            if ($row) {
                if ($row['response_json'] === null) {
                    send_json(['error' => 'این درخواست در حال پردازش است. چند لحظه صبر کنید.'], 409);
                }
                $data = json_decode($row['response_json'], true);
                if (is_array($data)) {
                    $data['idempotent_replay'] = true;
                }
                send_json($data, (int)$row['status_code']);
            }
            $ins = $db->prepare("INSERT IGNORE INTO idempotency_keys (key_hash, user_id, action) VALUES (?, ?, ?)");
            $ins->bind_param('sis', $hash, $userId, $action);
            $ins->execute();
            if ($ins->affected_rows === 0) {
                send_json(['error' => 'این درخواست در حال پردازش است. چند لحظه صبر کنید.'], 409);
            }
        } catch (Throwable $e) {
            return; // never block saving because of the replay table
        }
        register_shutdown_function(function () use ($db, $hash) {
            $resp = $GLOBALS['__api_last_response'] ?? null;
            try {
                if (!$resp || (int)$resp['status'] >= 300) {
                    // Only successes are replayed; a rejected or failed attempt may be
                    // corrected and resubmitted with the same key.
                    $d = $db->prepare("DELETE FROM idempotency_keys WHERE key_hash = ?");
                    $d->bind_param('s', $hash);
                    $d->execute();
                    return;
                }
                $json = json_encode($resp['data'], JSON_UNESCAPED_UNICODE);
                $code = (int)$resp['status'];
                $u = $db->prepare("UPDATE idempotency_keys SET status_code = ?, response_json = ? WHERE key_hash = ?");
                $u->bind_param('iss', $code, $json, $hash);
                $u->execute();
            } catch (Throwable $e) {
                error_log('Idempotency store failed: ' . $e->getMessage());
            }
        });
    }

    public static function purgeOld(mysqli $db) {
        $db->query("DELETE FROM idempotency_keys WHERE created_at < (NOW() - INTERVAL 2 DAY)");
        return $db->affected_rows;
    }
}
