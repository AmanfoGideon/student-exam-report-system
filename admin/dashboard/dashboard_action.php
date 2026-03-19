<?php
header('Content-Type: application/json');
require_once '../../includes/db.php';

$response = ['success' => true];

// Total counts
$response['students'] = $conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'];
$response['teachers'] = $conn->query("SELECT COUNT(*) AS c FROM teachers")->fetch_assoc()['c'];
$response['classes'] = $conn->query("SELECT COUNT(*) AS c FROM classes")->fetch_assoc()['c'];
$response['subjects'] = $conn->query("SELECT COUNT(*) AS c FROM subjects")->fetch_assoc()['c'];
$response['exams'] = $conn->query("SELECT COUNT(DISTINCT class_id) AS c FROM scores_summary WHERE term_id=1")->fetch_assoc()['c'];

// Average class performance (chart)
$classAvg = [];
$q = $conn->query("SELECT c.class_name, AVG(s.average) AS avg_score FROM scores_summary s JOIN classes c ON s.class_id=c.id GROUP BY c.class_name");
while($r = $q->fetch_assoc()) { $classAvg['labels'][] = $r['class_name']; $classAvg['data'][] = $r['avg_score']; }
$response['class_avg'] = $classAvg;

// Subject performance (chart)
$subAvg = [];
$q2 = $conn->query("SELECT sub.name, AVG(sc.class_score+sc.exam_score) AS avg_score FROM scores sc JOIN subjects sub ON sc.subject_id=sub.id GROUP BY sub.name");
while($r = $q2->fetch_assoc()) { $subAvg['labels'][] = $r['name']; $subAvg['data'][] = $r['avg_score']; }
$response['subject_avg'] = $subAvg;

// Recent activity
$acts = [];
$q3 = $conn->query("SELECT r.id, s.first_name, s.last_name, r.report_type, r.generated_at FROM reports_log r LEFT JOIN students s ON r.student_id=s.id ORDER BY r.generated_at DESC LIMIT 5");
while($r = $q3->fetch_assoc()) { $acts[] = $r; }
$response['activities'] = $acts;

echo json_encode($response);
?>
