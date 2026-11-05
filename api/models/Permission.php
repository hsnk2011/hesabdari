<?php
// /api/models/Permission.php
require_once __DIR__ . '/BaseModel.php';

class Permission extends BaseModel {
    protected $tableName = 'permissions';
    protected $hasEntityId = false;
}