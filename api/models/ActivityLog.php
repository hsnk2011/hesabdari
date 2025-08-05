<?php
// /api/models/ActivityLog.php
require_once __DIR__ . '/BaseModel.php';

/**
 * Model for handling read-only operations for the activity_log table.
 */
class ActivityLog extends BaseModel {
    protected $tableName = 'activity_log';
    protected $allowedFilters = ['username', 'action_type', 'description'];
    protected $allowedSorts = ['id', 'timestamp', 'username', 'action_type'];
}