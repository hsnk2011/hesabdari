<?php
// /api/models/Partner.php
require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Account.php';

class Partner extends BaseModel {
    protected $tableName = 'partners';

    public function __construct($db) {
        parent::__construct($db);
        // Partners are now entity-specific
        $this->hasEntityId = true;
        $this->ensurePhoneColumn();
    }

    private function ensurePhoneColumn() {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $res = $this->conn->query("SHOW COLUMNS FROM `{$this->tableName}` LIKE 'phone'");
            if ($res && $res->num_rows === 0) {
                $this->conn->query("ALTER TABLE `{$this->tableName}` ADD COLUMN `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `name`");
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    /**
     * @param array $data
     * @param bool $manageTransaction When false, caller owns the DB transaction (avoids nested commit).
     */
    public function save($data, $manageTransaction = true) {
        if (empty($data['name']) || !isset($data['share']) || !is_numeric($data['share']) || $data['share'] < 0 || $data['share'] > 1) {
            return ['error' => 'نام شریک و سهم (عددی بین 0 و 1) الزامی است.', 'statusCode' => 400];
        }

        $id = $data['id'] ?? null;
        $entity_id = $_SESSION['current_entity_id'];
        $phone = trim((string)($data['phone'] ?? ''));

        // After the first closing, share timeline is owned by partner_share_history.
        // Changing share here would desync live partners from historical allocation.
        if ($id && empty($data['_allowShareUpdateFromClosing'])) {
            $histCheck = $this->conn->prepare(
                "SELECT COUNT(*) as cnt FROM partner_share_history WHERE entity_id = ?"
            );
            $histCheck->bind_param('i', $entity_id);
            $histCheck->execute();
            $histCount = db_stmt_to_assoc_array($histCheck);
            if (!empty($histCount[0]['cnt'])) {
                $curStmt = $this->conn->prepare(
                    "SELECT share FROM partners WHERE id = ? AND entity_id = ?"
                );
                $curStmt->bind_param('ii', $id, $entity_id);
                $curStmt->execute();
                $curRows = db_stmt_to_assoc_array($curStmt);
                if (!empty($curRows) && abs((float)$curRows[0]['share'] - (float)$data['share']) > 0.0001) {
                    return [
                        'error' => 'پس از بستن حساب، تغییر سهم شرکا فقط از مسیر «بستن + تغییر ساختار شرکا» امکان‌پذیر است.',
                        'statusCode' => 409,
                    ];
                }
            }
        }

        if ($id) {
            $stmt = $this->conn->prepare("UPDATE `{$this->tableName}` SET name = ?, phone = ?, share = ? WHERE id = ? AND entity_id = ?");
            $stmt->bind_param("ssdii", $data['name'], $phone, $data['share'], $id, $entity_id);
            if ($stmt->execute()) {
                $stmt->close();
                return ['success' => true, 'id' => $id];
            } else {
                $error = $stmt->error;
                $stmt->close();
                return ['error' => $error, 'statusCode' => 500];
            }
        }

        $ownTransaction = false;
        if ($manageTransaction) {
            $this->conn->begin_transaction();
            $ownTransaction = true;
        }
        try {
            $stmt_partner = $this->conn->prepare("INSERT INTO `partners` (entity_id, name, phone, share) VALUES (?, ?, ?, ?)");
            $stmt_partner->bind_param("issd", $entity_id, $data['name'], $phone, $data['share']);
            $stmt_partner->execute();
            $savedId = $this->conn->insert_id;
            $stmt_partner->close();

            if (!$savedId || $savedId == 0) {
                throw new Exception("ایجاد شریک ناموفق بود، شناسه جدیدی دریافت نشد.");
            }

            $accountName = "حساب شخصی: " . $data['name'];
            $isCash = 0;
            $initialBalance = 0.00;

            $stmt_account = $this->conn->prepare(
                "INSERT INTO `accounts` (entity_id, name, current_balance, is_cash, partner_id) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt_account->bind_param("isdii", $entity_id, $accountName, $initialBalance, $isCash, $savedId);
            $stmt_account->execute();
            $stmt_account->close();

            if ($ownTransaction) {
                $this->conn->commit();
            }
            return ['success' => true, 'id' => $savedId];
        } catch (Exception $e) {
            if ($ownTransaction) {
                $this->conn->rollback();
            }
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }
    
    public function delete($id) {
        try {
            // Because of ON DELETE CASCADE, deleting a partner will also delete their personal account.
            return parent::delete($id);
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1451) {
                // This check is now more specific.
                $stmt = $this->conn->prepare("SELECT id FROM payments WHERE person_type='partner' AND person_id = ? LIMIT 1");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = db_stmt_to_assoc_array($stmt);
                if (!empty($result)) {
                    return ['error' => 'این شریک قابل حذف نیست. ابتدا باید تمام تراکنش‌های مالی ثبت‌شده برای او را حذف کنید.', 'statusCode' => 409];
                }
                
                return ['error' => 'این مورد قابل حذف نیست زیرا در بخش دیگری استفاده شده است.', 'statusCode' => 409];
            }
            return ['error' => $e->getMessage(), 'statusCode' => 500];
        }
    }
}
