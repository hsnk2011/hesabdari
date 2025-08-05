<?php
// /api/models/Transaction.php
require_once __DIR__ . '/BaseModel.php';

/**
 * The Transaction model handles read-only operations for the general transactions table.
 * Transactions are created/deleted by other models (e.g., Expense, Invoice, Partner).
 */
class Transaction extends BaseModel {
    protected $tableName = 'transactions';

    // Define fields that can be used for searching.
    protected $allowedFilters = ['type', 'description', 'amount'];

    // Define fields that can be used for sorting.
    protected $allowedSorts = ['id', 'date', 'type', 'amount'];
}