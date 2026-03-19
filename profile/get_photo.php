<?php
// profile/get_photo.php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['photo' => '/assets/images/default_user.png']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT photo_path FROM users WHERE id=? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

$photo = $res['photo_path'] ?? $_SESSION['photo_path'] ?? '/assets/images/default_user.png';
echo json_encode(['photo' => $photo]);
exit;