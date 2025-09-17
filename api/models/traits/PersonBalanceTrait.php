<?php
// /api/models/traits/PersonBalanceTrait.php

trait PersonBalanceTrait {
    
    protected function attachBalanceDataToPersons(array $persons, string $personType): array {
        if (empty($persons)) {
            return [];
        }

        $isCustomer = ($personType === 'customer');
        $personIdColumn = $isCustomer ? 'customerId' : 'supplierId';
        $invoiceTable = $isCustomer ? 'sales_invoices' : 'purchase_invoices';
        
        $personIds = array_column($persons, 'id');
        if (empty($personIds)) {
            return $persons;
        }

        $ids_placeholder = implode(',', array_fill(0, count($personIds), '?'));
        $types = str_repeat('i', count($personIds));

        // Get total unsettled invoices
        $unsettled = [];
        $unsettled_sql = "SELECT {$personIdColumn}, SUM(totalAmount - discount - paidAmount) as total_unsettled 
                          FROM {$invoiceTable} 
                          WHERE {$personIdColumn} IN ({$ids_placeholder}) AND (totalAmount - discount - paidAmount) > 0.01 
                          GROUP BY {$personIdColumn}";
        $unsettled_stmt = $this->conn->prepare($unsettled_sql);
        $unsettled_stmt->bind_param($types, ...$personIds);
        $unsettled_stmt->execute();
        foreach (db_stmt_to_assoc_array($unsettled_stmt) as $row) {
            $unsettled[$row[$personIdColumn]] = $row['total_unsettled'];
        }

        // Get available credit from on-account payments
        $credits = [];
        $credit_sql_logic = $isCustomer 
            ? "SUM(IF(transaction_type = 'receipt', amount, -amount))" 
            : "SUM(IF(transaction_type = 'payment', amount, -amount))";
        
        $credit_sql = "SELECT person_id, {$credit_sql_logic} as available_credit 
                       FROM payments 
                       WHERE person_type = ? AND invoiceId IS NULL AND person_id IN ({$ids_placeholder}) 
                       GROUP BY person_id";
        $credit_stmt = $this->conn->prepare($credit_sql);
        $credit_stmt->bind_param("s" . $types, $personType, ...$personIds);
        $credit_stmt->execute();
        foreach (db_stmt_to_assoc_array($credit_stmt) as $row) {
            $credits[$row['person_id']] = $row['available_credit'];
        }

        foreach ($persons as &$person) {
            $person['total_unsettled'] = $unsettled[$person['id']] ?? 0;
            $person['available_credit'] = $credits[$person['id']] ?? 0;
        }
        unset($person);

        return $persons;
    }
}