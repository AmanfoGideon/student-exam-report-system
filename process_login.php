<?php
session_start();
require_once "includes/db.php"; // database connection

if (!$conn) {
    die("❌ Database connection not established.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? '');
    $password = trim($_POST["password"] ?? '');

    $stmt = $conn->prepare("SELECT u.id, u.username, u.password, r.role_name 
                            FROM users u
                            JOIN roles r ON u.role_id = r.id
                            WHERE u.username = ? AND u.is_active = 1 
                            LIMIT 1");

    if (!$stmt) {
        die("❌ SQL prepare failed: " . $conn->error);
    }

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {
            // ✅ Login success
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['role']      = $user['role_name'];

            // Redirect by role
            if ($user['role_name'] === 'Admin') {
                header("Location: admin/dashboard/dashboard.php");
                exit();
            } elseif ($user['role_name'] === 'Teacher') {
                header("Location: teacher/modules/dashboard.php");
                exit();
            } else {
                // Student/Parent portals not enabled yet
                session_unset();
                session_destroy();
                header("Location: login.php?error=" . urlencode('Student/Parent portal is not enabled yet.'));
                exit();
            }
        }
    }

    // ❌ Login failed
    header("Location: login.php?error=Invalid username or password");
    exit();
}
