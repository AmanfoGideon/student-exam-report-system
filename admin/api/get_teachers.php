<?php
// admin/api/get_teachers.php
session_start();
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

// Basic auth guard
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$out = [];
// More robust: filter by role name (Teacher) instead of hard-coded role_id
$sql = "SELECT t.id, t.user_id, t.first_name, t.last_name FROM teachers t ORDER BY t.first_name, t.last_name";
$res = $conn->query($sql);
while ($r = $res->fetch_assoc()) {
    $out[] = [
        'id' => (int)$r['id'],
        'user_id' => (int)$r['user_id'],
        'full_name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))
    ];
}
echo json_encode(['success' => true, 'data' => $out]);
?>
