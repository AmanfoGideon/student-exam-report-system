<?php
// logout.php — Secure logout for all user roles

declare(strict_types=1);
session_start();

// Optional: If you log user activity, uncomment below
/*
require_once __DIR__ . '/includes/db.php';
if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("UPDATE users SET last_logout = NOW() WHERE id = ?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->close();
}
*/

// Clear all session variables
$_SESSION = [];

// Destroy session completely
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Prevent back button access
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Redirect to login page
header("Location: login.php?logout=1");
exit;
?>
            $sourcePath = $real;