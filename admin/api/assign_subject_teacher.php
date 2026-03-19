<?php
// admin/subjects/api/assign_subject_teacher.php
require_once("../../../includes/db.php");

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "Invalid request"]);
    exit;
}

$subject_id = intval($_POST['subject_id'] ?? 0);
$teacher_id = intval($_POST['teacher_id'] ?? 0);

if ($subject_id <= 0 || $teacher_id <= 0) {
    echo json_encode(["success" => false, "message" => "Missing subject or teacher"]);
    exit;
}

$stmt = $conn->prepare("INSERT IGNORE INTO teachers_subjects (teacher_id, subject_id) VALUES (?, ?)");
$stmt->bind_param("ii", $teacher_id, $subject_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Teacher assigned to subject successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "Database error: " . $conn->error]);
}
