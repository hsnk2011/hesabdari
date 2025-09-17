<?php
// /api/models/DashboardModel.php

class DashboardModel {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getDashboardData() {
        $entityId = $_SESSION['current_entity_id'];
        $thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
        $today = date('Y-m-d');
        
        $output = [
            'salesLast30Days' => $this->getSalesLast30Days($thirty_days_ago, $entityId),
            'topIndebtedSuppliers' => $this->getTopIndebtedSuppliers($entityId),
            'unsettledCustomers' => $this->getUnsettledCustomers($entityId),
            'expensesByCategory' => $this->getExpensesByCategory($entityId),
            'dueReceivedChecks' => $this->getDueChecks('received', 'in_hand', $today, 5, $entityId),
            'duePayableChecks' => $this->getDueChecks('payable', 'payable', $today, 5, $entityId),
        ];

        return $output;
    }

    public function getNotificationsData() {
        $entityId = $_SESSION['current_entity_id'];
        $seven_days_later = date('Y-m-d', strtotime('+7 days'));
        $today = date('Y-m-d');

        $due_received_stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM checks WHERE entity_id = ? AND type = 'received' AND status = 'in_hand' AND dueDate BETWEEN ? AND ?");
        $due_received_stmt->bind_param("iss", $entityId, $today, $seven_days_later);
        $due_received_stmt->execute();
        $received_result = db_stmt_to_assoc_array($due_received_stmt);
        $dueReceivedCount = $received_result[0]['count'] ?? 0;

        return ['due_checks_count' => $dueReceivedCount];
    }

    public function getDueChecksList() {
        $entityId = $_SESSION['current_entity_id'];
        $seven_days_later = date('Y-m-d', strtotime('+7 days'));
        $today = date('Y-m-d');

        $stmt = $this->conn->prepare("SELECT id, checkNumber, amount, dueDate FROM checks WHERE entity_id = ? AND type = 'received' AND status = 'in_hand' AND dueDate BETWEEN ? AND ? ORDER BY dueDate ASC LIMIT 10");
        $stmt->bind_param("iss", $entityId, $today, $seven_days_later);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }


    private function getSalesLast30Days($startDate, $entityId) {
        $stmt = $this->conn->prepare("SELECT SUM(totalAmount - discount) as total FROM sales_invoices WHERE entity_id = ? AND is_consignment = 0 AND date >= ?");
        $stmt->bind_param("is", $entityId, $startDate);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        return $result[0]['total'] ?? 0;
    }

    private function getTopIndebtedSuppliers($entityId) {
        $sql = "
            SELECT 
                s.id, s.name,
                (
                    s.initial_balance + 
                    (SELECT COALESCE(SUM(pi.totalAmount - pi.discount - pi.paidAmount), 0) FROM purchase_invoices pi WHERE pi.supplierId = s.id AND pi.entity_id = ?) -
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.person_type = 'supplier' AND p.person_id = s.id AND p.invoiceId IS NULL AND p.transaction_type = 'payment' AND p.entity_id = ?) +
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.person_type = 'supplier' AND p.person_id = s.id AND p.invoiceId IS NULL AND p.transaction_type = 'receipt' AND p.entity_id = ?)
                ) as total_debt
            FROM suppliers s
            WHERE s.entity_id = ?
            HAVING total_debt > 0
            ORDER BY total_debt DESC
            LIMIT 5
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiii", $entityId, $entityId, $entityId, $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    private function getUnsettledCustomers($entityId) {
        $sql = "
            SELECT 
                c.id, c.name,
                (
                    c.initial_balance +
                    (SELECT COALESCE(SUM(si.totalAmount - si.discount - si.paidAmount), 0) FROM sales_invoices si WHERE si.customerId = c.id AND si.entity_id = ?) -
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.person_type = 'customer' AND p.person_id = c.id AND p.invoiceId IS NULL AND p.transaction_type = 'receipt' AND p.entity_id = ?)
                ) as total_debt
            FROM customers c
            WHERE c.entity_id = ?
            HAVING total_debt > 0
            ORDER BY total_debt DESC
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $entityId, $entityId, $entityId);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }

    private function getExpensesByCategory($entityId) {
        $sql = "
            SELECT category, SUM(amount) as total
            FROM expenses
            WHERE entity_id = ?
            GROUP BY category
            ORDER BY total DESC
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $entityId);
        $stmt->execute();
        $result = db_stmt_to_assoc_array($stmt);
        $categories = [];
        foreach ($result as $row) {
            $categories[$row['category']] = (float)$row['total'];
        }
        return $categories;
    }

    private function getDueChecks($type, $status, $today, $limit, $entityId) {
        $stmt = $this->conn->prepare("
            SELECT 
                c.checkNumber, 
                c.amount, 
                c.dueDate,
                COALESCE(cust.name, sup.name, prt.name, 'ثبت مستقل') as personName
            FROM checks c
            LEFT JOIN payments p ON c.id = p.checkId
            LEFT JOIN customers cust ON p.person_id = cust.id AND p.person_type = 'customer'
            LEFT JOIN suppliers sup ON p.person_id = sup.id AND p.person_type = 'supplier'
            LEFT JOIN partners prt ON p.person_id = prt.id AND p.person_type = 'partner'
            WHERE c.entity_id = ? AND c.type = ? AND c.status = ? AND c.dueDate >= ?
            ORDER BY c.dueDate ASC
            LIMIT ?
        ");
        $stmt->bind_param("isssi", $entityId, $type, $status, $today, $limit);
        $stmt->execute();
        return db_stmt_to_assoc_array($stmt);
    }
}