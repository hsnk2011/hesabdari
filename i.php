<?php
/**
 * Short public invoice link for SMS: i.php?c=Ab12Xy9z
 */
$_GET['token'] = trim((string)($_GET['c'] ?? $_GET['token'] ?? ''));
require __DIR__ . '/invoice.php';
