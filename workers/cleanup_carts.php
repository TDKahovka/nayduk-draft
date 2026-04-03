<?php
// workers/cleanup_carts.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

$db = Database::getInstance();
// Delete carts not updated in 30 days
$db->query("DELETE FROM carts WHERE updated_at < NOW() - INTERVAL 30 DAY");