<?php
// teacher/fetch_students.php — AJAX endpoint for viewing students in a class
session_start();
require_once __DIR__ . '/../../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Teacher') {
    http_response_code(403);
    exit('Unauthorized');
}

$class_id = (int)($_GET['class_id'] ?? 0);
if ($class_id <= 0) {
    exit('<div class="text-muted">Invalid class ID.</div>');
}

$q = $conn->prepare("SELECT student_index, first_name, last_name, gender FROM students WHERE class_id = ? ORDER BY first_name, last_name");
$q->bind_param('i', $class_id);
$q->execute();
$res = $q->get_result();

if ($res->num_rows === 0) {
    echo '<div class="text-muted">No students found for this class.</div>';
    exit;
}

echo '<div class="table-responsive"><table class="table table-striped table-sm align-middle">';
echo '<thead><tr><th>#</th><th>Name</th><th>Gender</th><th>Index No.</th></tr></thead><tbody>';
$i = 1;
while ($s = $res->fetch_assoc()) {
    $name = htmlspecialchars($s['first_name'].' '.$s['last_name']);
    $gender = htmlspecialchars($s['gender']);
    $index = htmlspecialchars($s['student_index']);
    echo "<tr><td>$i</td><td>$name</td><td>$gender</td><td>$index</td></tr>";
    $i++;
}
echo '</tbody></table></div>';
