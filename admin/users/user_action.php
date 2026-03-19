<?php
ob_start(); // Start output buffering
require_once __DIR__ . "/../../includes/db.php"; // adjust if needed
header("Content-Type: application/json");

$action = $_POST['action'] ?? '';

switch ($action) {
    case "list":
        fetchUsers();
        break;
    case "get":
        getUser();
        break;
    case "save":
        saveUser();
        break;
    case "delete":
        deleteUser();
        break;
    default:
        echo json_encode(["status" => "error", "message" => "Invalid action"]);
        ob_clean();
        break;
}

/**
 * Fetch users with role filter + search
 */
function fetchUsers() {
    global $conn;
    $role_id = $_POST['role_id'] ?? '';
    $search = trim($_POST['search_value'] ?? '');

    $sql = "SELECT u.*, r.role_name,
                   CONCAT_WS(' ', u.first_name, u.last_name) AS full_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE 1=1";

    $params = [];
    $types  = "";

    if ($role_id !== '') {
        $sql .= " AND u.role_id = ?";
        $params[] = $role_id;
        $types   .= "i";
    }

    if ($search !== '') {
        $sql .= " AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        $like = "%" . $search . "%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= "sss";
    }

    $sql .= " ORDER BY u.id DESC";

    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            "id"        => $row['id'],
            "username"  => $row['username'],
            "full_name" => $row['full_name'],
            "email"     => $row['email'],
            "phone"     => $row['phone'],
            "role_name" => $row['role_name'],
            "is_active" => $row['is_active'],
            "last_login"=> $row['last_login']
        ];
    }

    ob_clean();
    echo json_encode($users);
}

/**
 * Get single user for edit
 */
function getUser() {
    global $conn;
    $id = intval($_POST['id'] ?? 0);

    $stmt = $conn->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();

    if ($res) {
        echo json_encode(["status" => "success", "data" => $res]);
        ob_clean();
    } else {
        echo json_encode(["status" => "error", "message" => "User not found"]);
        ob_clean();
    }
}

/**
 * Save user (insert or update)
 */
function saveUser() {
    global $conn;

    $id        = intval($_POST['id'] ?? 0);
    $role_id   = intval($_POST['role_id'] ?? 0);
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $first     = trim($_POST['first_name'] ?? '');
    $last      = trim($_POST['last_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $password  = $_POST['password'] ?? '';

    if ($id > 0) {
        // Update
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users SET role_id=?, username=?, email=?, password=?, first_name=?, last_name=?, phone=? WHERE id=?");
            $stmt->bind_param("issssssi", $role_id, $username, $email, $hash, $first, $last, $phone, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET role_id=?, username=?, email=?, first_name=?, last_name=?, phone=? WHERE id=?");
            $stmt->bind_param("isssssi", $role_id, $username, $email, $first, $last, $phone, $id);
        }
        $ok = $stmt->execute();
    } else {
        // Insert
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users(role_id, username, email, password, first_name, last_name, phone) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("issssss", $role_id, $username, $email, $hash, $first, $last, $phone);
        $ok = $stmt->execute();
    }

    if ($ok) {
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error", "message" => $stmt->error]);
    }
}

/**
 * Delete user
 */
function deleteUser() {
    global $conn;
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid ID"]);
        return;
    }

    $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();

    if ($ok) {
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error", "message" => $stmt->error]);
    }
}
