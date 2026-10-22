<?php
// /api/controllers/CheckController.php

require_once __DIR__ . '/../models/Check.php';
class CheckController {
    private $conn;
    private $checkModel;

    public function __construct($db) {
        $this->conn = $db;
        $this->checkModel = new Check($db);
    }

    public function cashCheck($data) {
        try {
            $checkId = $data['checkId'] ?? null;
            $accountId = $data['accountId'] ?? null;
            $cashedDate = $data['cashedDate'] ?? ($data['cashed_date'] ?? null);
            if (empty($checkId) || !is_numeric($checkId) || empty($accountId) || !is_numeric($accountId)) {
                send_json(['error' => 'شناسه چک و حساب مقصد الزامی است.'], 400);
                return;
            }
            if ($cashedDate && !validate_date_format($cashedDate)) {
                send_json(['error' => 'فرمت تاریخ وصول نامعتبر است. فرمت صحیح: YYYY-MM-DD'], 400);
                return;
            }
            $result = $this->checkModel->cashCheck($checkId, $accountId, $cashedDate);
            
            if (isset($result['success'])) {
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام وصول چک: ' . $e->getMessage()], 500);
        }
    }

    public function clearPayableCheck($data) {
        try {
            $checkId = $data['checkId'] ?? null;
            $accountId = $data['accountId'] ?? null;
            $cashedDate = $data['cashedDate'] ?? ($data['cashed_date'] ?? null);
            if (empty($checkId) || !is_numeric($checkId) || empty($accountId) || !is_numeric($accountId)) {
                send_json(['error' => 'شناسه چک و حساب مبدا الزامی است.'], 400);
                return;
            }
            if ($cashedDate && !validate_date_format($cashedDate)) {
                send_json(['error' => 'فرمت تاریخ پاس شدن نامعتبر است. فرمت صحیح: YYYY-MM-DD'], 400);
                return;
            }
            $result = $this->checkModel->clearPayableCheck($checkId, $accountId, $cashedDate);
            
            if (isset($result['success'])) {
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام پاس کردن چک: ' . $e->getMessage()], 500);
        }
    }

    private function lifecycleResponse($result, $okMessage) {
        if (!empty($result['success'])) {
            $result['message'] = $okMessage;
            send_json($result);
        } else {
            send_json(['error' => $result['error'] ?? 'خطا'], $result['statusCode'] ?? 500);
        }
    }

    private function lifecycleArgs($data) {
        $checkId = intval($data['checkId'] ?? ($data['id'] ?? 0));
        if ($checkId <= 0) {
            send_json(['error' => 'شناسه چک نامعتبر است.'], 400);
        }
        $date = $data['date'] ?? null;
        if ($date && !validate_date_format($date)) {
            send_json(['error' => 'فرمت تاریخ نامعتبر است. فرمت صحیح: YYYY-MM-DD'], 400);
        }
        return [$checkId, $date];
    }

    /** برگشت چک */
    public function bounceCheck($data) {
        list($checkId, $date) = $this->lifecycleArgs($data);
        $reason = mb_substr(trim((string)($data['reason'] ?? '')), 0, 250);
        $this->lifecycleResponse($this->checkModel->bounceCheck($checkId, $date, $reason), 'چک برگشتی ثبت شد و مانده طرف حساب اصلاح شد.');
    }

    /** لغو برگشت */
    public function undoBounce($data) {
        list($checkId) = $this->lifecycleArgs($data);
        $this->lifecycleResponse($this->checkModel->undoBounce($checkId), 'برگشت چک لغو شد.');
    }

    /** عودت چک خرج‌شده */
    public function returnEndorsedCheck($data) {
        list($checkId, $date) = $this->lifecycleArgs($data);
        $note = mb_substr(trim((string)($data['note'] ?? '')), 0, 250);
        $this->lifecycleResponse($this->checkModel->returnEndorsedCheck($checkId, $date, $note), 'چک عودت داده شد و به وضعیت «نزد ما» برگشت.');
    }

    /** لغو وصول / لغو پاس */
    public function undoCash($data) {
        list($checkId) = $this->lifecycleArgs($data);
        $this->lifecycleResponse($this->checkModel->undoCash($checkId), 'وصول/پاس چک لغو شد و موجودی حساب اصلاح شد.');
    }

    public function deleteCheck($data) {
        try {
            $id = $data['id'] ?? null;
            if (empty($id) || !is_numeric($id)) {
                send_json(['error' => 'شناسه چک نامعتبر است.'], 400);
                return;
            }
            $result = $this->checkModel->delete($id);
            if (isset($result['success'])) {
                log_activity($this->conn, 'DELETE_CHECK', "چک با شناسه {$id} و تراکنش مالی مرتبط با آن حذف شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام حذف چک: ' . $e->getMessage()], 500);
        }
    }

    public function saveCheck($data) {
        try {
            if (empty($data['id'])) {
                send_json(['error' => 'شناسه چک برای ویرایش الزامی است.'], 400);
                return;
            }
            if (empty($data['checkNumber']) || empty($data['dueDate']) || empty($data['bankName'])) {
                send_json(['error' => 'شماره چک، تاریخ سررسید و نام بانک الزامی است.'], 400);
                return;
            }
            // Validate dueDate format
            if (!validate_date_format($data['dueDate'])) {
                send_json(['error' => 'فرمت تاریخ سررسید نامعتبر است. فرمت صحیح: YYYY-MM-DD'], 400);
                return;
            }
            if (!isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
                send_json(['error' => 'مبلغ چک باید یک عدد مثبت باشد.'], 400);
                return;
            }
            
            $result = $this->checkModel->save($data);
            if (isset($result['success'])) {
                log_activity($this->conn, 'SAVE_CHECK', "چک «{$data['checkNumber']}» با شناسه {$result['id']} ویرایش شد.");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام ذخیره چک: ' . $e->getMessage()], 500);
        }
    }

    public function getChecksSummary() {
        try {
            $entity_id = $_SESSION['current_entity_id'] ?? null;
            if (!$entity_id) {
                send_json(['error' => 'شناسه مجموعه تجاری نامعتبر است.'], 401);
                return;
            }

            // Check if checks table exists
            $check_result = $this->conn->query("SHOW TABLES LIKE 'checks'");
            
            if (!$check_result || $check_result->num_rows === 0) {
                // Table doesn't exist - return empty summary
                send_json([
                    'received' => [
                        'inHand' => ['count' => 0, 'total' => 0.0],
                        'pastDue' => ['count' => 0, 'total' => 0.0]
                    ],
                    'payable' => [
                        'unpaid' => ['count' => 0, 'total' => 0.0],
                        'pastDue' => ['count' => 0, 'total' => 0.0]
                    ]
                ]);
                return;
            }

            $today = date('Y-m-d');
            
            // Received checks (in_hand) - not past due
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as count, IFNULL(SUM(amount), 0) as total
                FROM checks 
                WHERE entity_id = ? AND type = 'received' AND status = 'in_hand' AND dueDate >= ?
            ");
            $stmt->bind_param('is', $entity_id, $today);
            $stmt->execute();
            $count = 0;
            $total = 0.0;
            $stmt->bind_result($count, $total);
            $stmt->fetch();
            $receivedInHand = ['count' => $count, 'total' => $total];
            $stmt->close();

            // Received checks (in_hand) - past due
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as count, IFNULL(SUM(amount), 0) as total
                FROM checks 
                WHERE entity_id = ? AND type = 'received' AND status = 'in_hand' AND dueDate < ?
            ");
            $stmt->bind_param('is', $entity_id, $today);
            $stmt->execute();
            $stmt->bind_result($count, $total);
            $stmt->fetch();
            $receivedPastDue = ['count' => $count, 'total' => $total];
            $stmt->close();

            // Payable checks (payable status) - not past due
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as count, IFNULL(SUM(amount), 0) as total
                FROM checks 
                WHERE entity_id = ? AND type = 'payable' AND status = 'payable' AND dueDate >= ?
            ");
            $stmt->bind_param('is', $entity_id, $today);
            $stmt->execute();
            $stmt->bind_result($count, $total);
            $stmt->fetch();
            $payableUnpaid = ['count' => $count, 'total' => $total];
            $stmt->close();

            // Payable checks (payable status) - past due
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as count, IFNULL(SUM(amount), 0) as total
                FROM checks 
                WHERE entity_id = ? AND type = 'payable' AND status = 'payable' AND dueDate < ?
            ");
            $stmt->bind_param('is', $entity_id, $today);
            $stmt->execute();
            $stmt->bind_result($count, $total);
            $stmt->fetch();
            $payablePastDue = ['count' => $count, 'total' => $total];
            $stmt->close();

            send_json([
                'received' => [
                    'inHand' => [
                        'count' => (int)$receivedInHand['count'],
                        'total' => (float)$receivedInHand['total']
                    ],
                    'pastDue' => [
                        'count' => (int)$receivedPastDue['count'],
                        'total' => (float)$receivedPastDue['total']
                    ]
                ],
                'payable' => [
                    'unpaid' => [
                        'count' => (int)$payableUnpaid['count'],
                        'total' => (float)$payableUnpaid['total']
                    ],
                    'pastDue' => [
                        'count' => (int)$payablePastDue['count'],
                        'total' => (float)$payablePastDue['total']
                    ]
                ]
            ]);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام دریافت خلاصه چک‌ها: ' . $e->getMessage()], 500);
        }
    }

    /**
     * راس‌گیری و انتقال چند چک به یک شخص
     */
    public function discountAndTransferChecks($data) {
        try {
            $checks = $data['checks'] ?? [];
            $person_id = $data['person_id'] ?? null;
            $person_type = $data['person_type'] ?? null;
            $transaction_date = $data['transaction_date'] ?? date('Y-m-d');
            $description = $data['description'] ?? '';
            $discount_rate = $data['discount_rate'] ?? 3;
            $base_date = $data['base_date'] ?? date('Y-m-d');

            // Validation
            if (empty($checks) || !is_array($checks)) {
                send_json(['error' => 'لیست چک‌ها خالی یا نامعتبر است.'], 400);
                return;
            }
            if (empty($person_id) || !is_numeric($person_id)) {
                send_json(['error' => 'شخص مقصد انتخاب نشده است.'], 400);
                return;
            }
            if (empty($person_type) || !in_array($person_type, ['customer', 'supplier', 'partner', 'contact'])) {
                send_json(['error' => 'نوع شخص نامعتبر است.'], 400);
                return;
            }
            if (!validate_date_format($transaction_date)) {
                send_json(['error' => 'فرمت تاریخ تراکنش نامعتبر است.'], 400);
                return;
            }

            $result = $this->checkModel->discountAndTransferChecks(
                $checks,
                $person_id,
                $person_type,
                $transaction_date,
                $description,
                $discount_rate,
                $base_date
            );

            if (isset($result['success'])) {
                $checkCount = count($checks);
                $totalDiscount = array_sum(array_column($checks, 'discountAmount'));
                log_activity($this->conn, 'DISCOUNT_TRANSFER_CHECKS', "راس‌گیری و انتقال {$checkCount} چک با مجموع تنزیل {$totalDiscount} به شخص {$person_type}:{$person_id}");
                send_json($result);
            } else {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
            }
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام راس‌گیری و انتقال چک‌ها: ' . $e->getMessage()], 500);
        }
    }

    public function getCheckHistory($data) {
        try {
            $checkIds = $data['checkIds'] ?? ($data['checkId'] ?? null);
            if ($checkIds === null) {
                send_json(['error' => 'شناسه چک الزامی است.'], 400);
                return;
            }
            $result = $this->checkModel->getCheckHistory($checkIds);
            if (isset($result['error'])) {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
                return;
            }
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام دریافت تاریخچه چک: ' . $e->getMessage()], 500);
        }
    }

    public function getChecksForPrint($data) {
        try {
            $checkIds = $data['checkIds'] ?? ($data['checkId'] ?? null);
            if ($checkIds === null) {
                send_json(['error' => 'شناسه چک الزامی است.'], 400);
                return;
            }
            $result = $this->checkModel->getChecksByIds($checkIds);
            if (isset($result['error'])) {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
                return;
            }
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام آماده‌سازی چاپ چک: ' . $e->getMessage()], 500);
        }
    }

    /**
     * چک‌های هم‌گروه یک راس‌گیری / انتقال گروهی
     */
    public function getCheckTransferGroup($data) {
        try {
            $groupId = $data['transfer_group_id'] ?? ($data['transferGroupId'] ?? null);
            $checkId = $data['checkId'] ?? ($data['check_id'] ?? null);
            if (empty($groupId) && empty($checkId)) {
                send_json(['error' => 'شناسه گروه انتقال یا شناسه چک الزامی است.'], 400);
                return;
            }
            $result = $this->checkModel->getTransferGroup($groupId, $checkId);
            if (isset($result['error'])) {
                send_json(['error' => $result['error']], $result['statusCode'] ?? 500);
                return;
            }
            send_json($result);
        } catch (Exception $e) {
            send_json(['error' => 'خطای سرور هنگام دریافت گروه انتقال چک: ' . $e->getMessage()], 500);
        }
    }
}
