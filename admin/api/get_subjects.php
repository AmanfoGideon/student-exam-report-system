<?php
// admin/subjects/api/get_subjects.php
require_once("../../../includes/db.php");

header('Content-Type: application/json');

$draw   = intval($_POST['draw'] ?? 1);
$start  = intval($_POST['start'] ?? 0);
$length = intval($_POST['length'] ?? 10);
$search = $_POST['search']['value'] ?? '';

$where = '';
$params = [];
$types  = '';

if (!empty($search)) {
    $where = "WHERE s.name LIKE ?";
    $params[] = "%" . $search . "%";
    $types .= "s";
}

$countQuery = "SELECT COUNT(*) as total FROM subjects s $where";
$stmt = $conn->prepare($countQuery);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$totalRecords = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$query = "
    SELECT s.id, s.name
    FROM subjects s
    $where
    ORDER BY s.name ASC
    LIMIT ?, ?
";
$params[] = $start;
$params[] = $length;
$types   .= "ii";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $subject_id = $row['id'];

    // Assigned teachers
    $teacherRes = $conn->query("
        SELECT CONCAT(u.first_name, ' ', u.last_name) AS teacher_name
        FROM teachers_subjects ts
        JOIN users u ON ts.teacher_id = u.id
        WHERE ts.subject_id = $subject_id
    ");
    $teachers = [];
    while ($t = $teacherRes->fetch_assoc()) {
        $teachers[] = $t['teacher_name'];
    }

    // Assigned classes
    $classRes = $conn->query("
        SELECT CONCAT(c.class_name, ' ', c.stream) AS class_name
        FROM classes_subjects cs
        JOIN classes c ON cs.class_id = c.id
        WHERE cs.subject_id = $subject_id
    ");
    $classes = [];
    while ($c = $classRes->fetch_assoc()) {
        $classes[] = $c['class_name'];
    }

    $data[] = [
        "id"       => $row['id'],
        "name"     => $row['name'],
        "teachers" => !empty($teachers) ? implode(", ", $teachers) : "-",
        "classes"  => !empty($classes) ? implode(", ", $classes) : "-",
        "actions"  => '
            <button class="btn btn-sm btn-primary assignTeacherBtn" data-id="'.$row['id'].'">Assign Teacher</button>
            <button class="btn btn-sm btn-success assignClassBtn" data-id="'.$row['id'].'">Assign Class</button>
            <button class="btn btn-sm btn-warning editBtn" data-id="'.$row['id'].'">Edit</button>
            <button class="btn btn-sm btn-danger deleteBtn" data-id="'.$row['id'].'">Delete</button>
        '
    ];
}

$response = [
    "draw" => $draw,
    "recordsTotal" => $totalRecords,
    "recordsFiltered" => $totalRecords,
    "data" => $data
];

echo json_encode($response);
