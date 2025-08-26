<?php
// /api/models/PartnerTransaction.php
require_once __DIR__ . '/BaseModel.php';

class PartnerTransaction extends BaseModel {
    protected $tableName = 'partner_transactions';

    public function __construct($db) {
        parent::__construct($db);

        // This model's data comes from a complex UNION query, so we override the entire FROM clause.
        // The BaseModel's getPaginated method will use this complex query as its source.
        $unionQuery = "
            (SELECT 
                pt.id, 
                p.name AS partnerName, 
                pt.date, 
                IF(pt.type = 'DEPOSIT', 'واریز شریک', 'برداشت شریک') AS type, 
                pt.amount, 
                pt.description, 
                'partner_transaction' AS source,
                pt.type AS original_type,
                a.name as accountName
            FROM partner_transactions pt
            JOIN partners p ON pt.partnerId = p.id
            LEFT JOIN accounts a ON pt.account_id = a.id)
            
            UNION ALL
            
            (SELECT 
                e.id, 
                p.name AS partnerName, 
                e.date, 
                CONCAT('هزینه: ', e.category) AS type, 
                e.amount, 
                e.description, 
                'expense' AS source,
                'EXPENSE' AS original_type,
                a.name as accountName
            FROM expenses e
            JOIN accounts a ON e.account_id = a.id
            JOIN partners p ON a.partner_id = p.id
            WHERE a.partner_id IS NOT NULL)
            
            UNION ALL

            (SELECT 
                pay.id, 
                p.name AS partnerName, 
                pay.date, 
                CONCAT('دریافت بابت فاکتور فروش #', pay.invoiceId) AS type, 
                pay.amount, 
                pay.description, 
                'payment_in' AS source,
                'PAYMENT_IN' AS original_type,
                a.name as accountName
            FROM payments pay
            JOIN accounts a ON pay.account_id = a.id
            JOIN partners p ON a.partner_id = p.id
            WHERE pay.invoiceType = 'sales' AND pay.type = 'cash' AND a.partner_id IS NOT NULL)

            UNION ALL

            (SELECT 
                pay.id, 
                p.name AS partnerName, 
                pay.date, 
                CONCAT('پرداخت بابت فاکتور خرید #', pay.invoiceId) AS type, 
                pay.amount, 
                pay.description, 
                'payment_out' AS source,
                'PAYMENT_OUT' AS original_type,
                a.name as accountName
            FROM payments pay
            JOIN accounts a ON pay.account_id = a.id
            JOIN partners p ON a.partner_id = p.id
            WHERE pay.invoiceType = 'purchase' AND pay.type = 'cash' AND a.partner_id IS NOT NULL)
        ";

        $this->alias = 'all_transactions';
        $this->select = "SELECT *";
        $this->from = "FROM ({$unionQuery}) AS {$this->alias}";
        
        $this->allowedFilters = ['partnerName', 'type', 'amount', 'description', 'accountName'];
        $this->allowedSorts = ['date', 'partnerName', 'amount'];
    }
}