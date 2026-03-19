<?php
// admin/api/get_classes.php
session_start();
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

// No auth required if called from admin pages, but check session for safety
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$res = $conn->query("SELECT id, class_name, stream FROM classes ORDER BY class_name ASC");
$out = [];
while ($r = $res->fetch_assoc()) {
    $out[] = ['id' => (int)$r['id'], 'class_name' => $r['class_name'], 'stream' => $r['stream']];
}
echo json_encode($out);
