<?php
// /api/models/CrmPerson.php
// Who a CRM row is about: a customer, lead, supplier or contact of the entity.

class CrmPerson {
    const TABLES = ['customer' => 'customers', 'lead' => 'crm_leads', 'supplier' => 'suppliers', 'contact' => 'contacts'];

    public static function isType($type): bool {
        return is_string($type) && isset(self::TABLES[$type]);
    }

    public static function exists($db, int $entityId, string $type, int $id): bool {
        if (!self::isType($type) || $id <= 0) {
            return false;
        }
        $stmt = $db->prepare("SELECT 1 FROM `" . self::TABLES[$type] . "` WHERE id = ? AND entity_id = ? LIMIT 1");
        $stmt->bind_param('ii', $id, $entityId);
        $stmt->execute();
        return !empty(db_stmt_to_assoc_array($stmt));
    }

    /** SQL joins giving `person_name` / `person_phone` for rows aliased $alias with person_type/person_id. */
    public static function joinSql(string $alias): string {
        return " LEFT JOIN customers pc ON {$alias}.person_type = 'customer' AND pc.id = {$alias}.person_id
                 LEFT JOIN crm_leads pl ON {$alias}.person_type = 'lead' AND pl.id = {$alias}.person_id
                 LEFT JOIN suppliers ps ON {$alias}.person_type = 'supplier' AND ps.id = {$alias}.person_id
                 LEFT JOIN contacts pt ON {$alias}.person_type = 'contact' AND pt.id = {$alias}.person_id ";
    }

    public static function nameSelect(): string {
        return " COALESCE(pc.name, pl.name, ps.name, pt.name) AS person_name, COALESCE(pc.phone, pl.phone, ps.phone, pt.phone) AS person_phone ";
    }
}
