<?php
// admin/teachers/teacher_action.php
session_start();
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? '';

// Helper: JSON response
function resp($arr) {
    echo json_encode($arr);
    exit;
}

// ---------------- LIST ----------------
if ($action === 'list') {
    $search = $conn->real_escape_string($_REQUEST['search_value'] ?? '');
    $sql = "SELECT u.id, u.first_name, u.last_name, u.username, u.email, u.phone,
            GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS subjects
            FROM users u
            LEFT JOIN teachers_subjects ts ON u.id = ts.teacher_id
            LEFT JOIN subjects s ON ts.subject_id = s.id
            WHERE u.role_id=2";

    if ($search !== '') {
        $sql .= " AND (u.username LIKE '%$search%' OR u.first_name LIKE '%$search%' OR u.last_name LIKE '%$search%' OR u.email LIKE '%$search%' OR u.phone LIKE '%$search%' OR s.name LIKE '%$search%')";
    }

    $sql .= " GROUP BY u.id ORDER BY u.first_name, u.last_name";
    $res = $conn->query($sql);

    if (!$res) resp(['success' => false, 'message' => 'DB error: ' . $conn->error]);

    $data = [];
    while ($row = $res->fetch_assoc()) {
        $row['full_name'] = trim($row['first_name'] . ' ' . ($row['last_name'] ?? ''));
        $row['subjects'] = $row['subjects'] ?: '-';
        $data[] = $row;
    }

    resp(['success' => true, 'data' => $data]);
}

// ---------------- SAVE (add / edit) ----------------
if ($action === 'add' || $action === 'edit') {
    $id       = intval($_POST['teacher_id'] ?? 0);
    $first    = trim($_POST['first_name'] ?? '');
    $last     = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($first === '' || $username === '') {
        resp(['success'=>false,'message'=>'First name and username are required.']);
    }

    // Username uniqueness
    $stmt = $conn->prepare("SELECT id FROM users WHERE username=? AND id<>?");
    $stmt->bind_param("si", $username, $id);
    $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows>0) { $stmt->close(); resp(['success'=>false,'message'=>'Username already exists.']); }
    $stmt->close();

    // Email uniqueness
    if ($email!=='') {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email=? AND id<>?");
        $stmt->bind_param("si", $email, $id);
        $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows>0){$stmt->close(); resp(['success'=>false,'message'=>'Email already exists.']);}
        $stmt->close();
    }

    if ($id > 0) {
        // Edit existing teacher
        if ($password) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, username=?, email=?, phone=?, password=? WHERE id=? AND role_id=2");
            $stmt->bind_param("ssssssi", $first, $last, $username, $email, $phone, $hash, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, username=?, email=?, phone=? WHERE id=? AND role_id=2");
            $stmt->bind_param("sssssi", $first, $last, $username, $email, $phone, $id);
        }
        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();
        resp(['success'=>$ok,'message'=>$ok?'Teacher updated successfully.':"DB error: $err"]);
    } else {
        // Add new teacher
        if ($password==='') resp(['success'=>false,'message'=>'Password is required for new teacher.']);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $roleId = 2;
        $stmt = $conn->prepare("INSERT INTO users (role_id,first_name,last_name,username,email,phone,password) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("issssss",$roleId,$first,$last,$username,$email,$phone,$hash);
        $ok = $stmt->execute();
        $err = $stmt->error;
        $teacher_id = $conn->insert_id;
        $stmt->close();
        resp(['success'=>$ok,'message'=>$ok?'Teacher added successfully.':"DB error: $err"]);
    }
}

// ---------------- ASSIGN subjects/classes ----------------
if ($action === 'assign') {
    $teacher_id = intval($_POST['teacher_id'] ?? 0);
    $subjects = isset($_POST['subjects']) ? (array)$_POST['subjects'] : [];
    $classes  = isset($_POST['classes']) ? (array)$_POST['classes'] : [];

    if ($teacher_id <= 0) resp(['success'=>false,'message'=>'Invalid teacher ID']);

    // Delete previous assignments
    $conn->query("DELETE FROM teachers_subjects WHERE teacher_id=$teacher_id");

    // Insert new assignments
    if (!empty($subjects) && !empty($classes)) {
        $stmt = $conn->prepare("INSERT INTO teachers_subjects (teacher_id,class_id,subject_id) VALUES (?,?,?)");
        foreach ($classes as $cid) {
            foreach ($subjects as $sid) {
                $stmt->bind_param('iii', $teacher_id, $cid, $sid);
                $stmt->execute();
            }
        }
        $stmt->close();
    }

    resp(['success'=>true,'message'=>'Assignments saved successfully.']);
}

// ---------------- GET ----------------
if ($action === 'get') {
    $id = intval($_REQUEST['id'] ?? 0);
    if ($id<=0) resp(['success'=>false,'message'=>'Invalid id']);
    $stmt = $conn->prepare("SELECT id, first_name, last_name, username, email, phone FROM users WHERE id=? AND role_id=2 LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        resp(['success'=>true,'data'=>$row]);
    } else {
        resp(['success'=>false,'message'=>'Teacher not found.']);
    }
    $stmt->close();
}

// ---------------- DELETE ----------------
if ($action === 'delete') {
    $id = intval($_REQUEST['id'] ?? 0);
    if ($id<=0) resp(['success'=>false,'message'=>'Invalid id']);
    $stmt = $conn->prepare("DELETE FROM users WHERE id=? AND role_id=2");
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();
    resp(['success'=>$ok,'message'=>$ok?'Teacher deleted successfully.':"DB error: $err"]);
}

// ---------------- GET ALL TEACHERS (optional) ----------------
if ($action === 'get_teachers') {
    $out = [];
    $res = $conn->query("SELECT id, first_name, last_name FROM users WHERE role_id=2 ORDER BY first_name, last_name");
    while ($r = $res->fetch_assoc()) {
        $out[] = ['id'=>$r['id'],'full_name'=>trim($r['first_name'].' '.$r['last_name'])];
    }
    resp(['success'=>true,'data'=>$out]);
}

// Invalid action
resp(['success'=>false,'message'=>'Invalid action']);
