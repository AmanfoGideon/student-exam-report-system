<?php
// teacher/view_students.php — Shows students for a specific class assigned to the teacher
session_start();
require_once __DIR__ . '/../includes/db.php';

// Auth check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Teacher') {
    header('Location: ../auth/login.php?error=Please+login+as+Teacher');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Fetch teacher record
$teacher = null;
if ($stmt = $conn->prepare("SELECT id, first_name, last_name FROM teachers WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$teacherId = (int)($teacher['id'] ?? $user_id);
$teacherName = trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? '')) ?: 'Teacher';

// Get class_id param
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
if (!$class_id) {
    header('Location: classes.php');
    exit;
}

// Check if the class is actually taught by this teacher (via subject_teachers + subject_classes)
$check = $conn->prepare("
  SELECT COUNT(*) 
  FROM subject_teachers st
  JOIN subject_classes sc ON st.subject_id = sc.subject_id
  WHERE st.teacher_id = ? AND sc.class_id = ?
");
$check->bind_param('ii', $teacherId, $class_id);
$check->execute();
$isAllowed = $check->get_result()->fetch_row()[0] ?? 0;
$check->close();

if (!$isAllowed) {
    echo "<div class='alert alert-danger m-4'>Access denied. You are not assigned to this class.</div>";
    exit;
}

// Fetch class info
$classInfo = $conn->query("SELECT class_name FROM classes WHERE id=$class_id")->fetch_assoc();
$className = htmlspecialchars($classInfo['class_name'] ?? '');

// Fetch students for that class
$students = [];
$q = $conn->prepare("SELECT id, admission_no, first_name, last_name, gender FROM students WHERE class_id = ? ORDER BY first_name, last_name");
$q->bind_param('i', $class_id);
$q->execute();
$students = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();
?>

<!doctype html>

<html lang="en">
<head>
  <?php include __DIR__.'/../../pwa-head.php'; ?>

<meta charset="utf-8">
<title>Students in <?= $className ?> | <?= htmlspecialchars($teacherName) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="../../favicon-96x96.png" sizes="96x96" />
  <link rel="icon" type="image/svg+xml" href="../../favicon.svg" />
  <link rel="shortcut icon" href="../../favicon.ico" />
  <link rel="apple-touch-icon" sizes="180x180" href="../../apple-touch-icon.png" />
  <link rel="manifest" href="../../site.webmanifest" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
:root { --deep:#0d47a1; --deep2:#1565c0; }
body { background:#f5f7fb; }
.page-header {
  display:flex; justify-content:space-between; align-items:center;
  background:#fff; padding:1rem 1.5rem; margin-bottom:1rem;
  border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,0.05);
}
.table thead th { background:var(--deep2); color:#fff; }
</style>
</head>
<body>

<?php include __DIR__ . '/../../admin/partials/header_stub.php'; ?>

<div class="container-fluid p-4">

  <div class="page-header">
    <div>
      <h4 class="mb-0">Students in <?= $className ?></h4>
      <small class="text-muted">Taught by <?= htmlspecialchars($teacherName) ?></small>
    </div>
    <a href="classes.php" class="btn btn-outline-primary btn-sm">
      <i class="fa fa-arrow-left me-1"></i> Back to Classes
    </a>
  </div>

  <div class="card">
    <div class="card-body">
      <?php if (empty($students)): ?>
        <div class="alert alert-info mb-0">No students found for this class.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table id="studentsTable" class="table table-striped table-bordered align-middle">
            <thead>
              <tr>
                <th>#</th>
                <th>Full Name</th>
                <th>Gender</th>
                <th>Admission No.</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $i = 1;
              foreach ($students as $s):
                $name = htmlspecialchars($s['first_name'] . ' ' . $s['last_name']);
                $gender = htmlspecialchars($s['gender']);
                $adm = htmlspecialchars($s['admission_no']);
              ?>
                <tr>
                  <td><?= $i++ ?></td>
                  <td><?= $name ?></td>
                  <td><?= $gender ?></td>
                  <td><?= $adm ?></td>
                  <td>
                    <a href="enter_marks.php?student_id=<?= $s['id'] ?>&class_id=<?= $class_id ?>" class="btn btn-sm btn-primary">
                      <i class="fa fa-pen"></i> Enter Marks
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php include __DIR__ . '/../../admin/partials/footer_stub.php'; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(function(){
  $('#studentsTable').DataTable({
    responsive: true,
    pageLength: 10,
    language: { search: "_INPUT_", searchPlaceholder: "Search students..." }
  });
});
</script>


<?php include __DIR__.'/../../pwa-footer.php'; ?>\n</body>
</html>
