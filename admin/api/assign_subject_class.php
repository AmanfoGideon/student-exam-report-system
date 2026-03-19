<?php
// admin/subjects/api/assign_subject_class.php
require_once("../../../includes/db.php");

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "Invalid request"]);
    exit;
}

$subject_id = intval($_POST['subject_id'] ?? 0);
$class_id   = intval($_POST['class_id'] ?? 0);

if ($subject_id <= 0 || $class_id <= 0) {
    echo json_encode(["success" => false, "message" => "Missing subject or class"]);
    exit;
}

$stmt = $conn->prepare("INSERT IGNORE INTO classes_subjects (class_id, subject_id) VALUES (?, ?)");
$stmt->bind_param("ii", $class_id, $subject_id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Class assigned to subject successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "Database error: " . $conn->error]);
}
