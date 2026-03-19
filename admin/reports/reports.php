<?php
// admin/reports/reports.php (optimized)
// - Improved error handling, consistent prepared statements, output escaping,
//   CSRF token handling, small UX enhancements (loading overlay hook).
// Note: This file is intended to be a drop-in replacement for your original.

session_start();
require_once __DIR__ . '/../../includes/db.php';

// Basic auth check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php?error=' . urlencode('Please login'));
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$role     = strtolower(trim($_SESSION['role'] ?? ''));

// CSRF token (create once)
if (empty($_SESSION['csrf_token'])) {
    // random_bytes may throw; wrap in try/catch
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        // fallback
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}
$csrf_token = $_SESSION['csrf_token'];

// Utility: safe echo
function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Prepare filters containers
$classes = $subjects = $years = $terms = [];

try {
    // If teacher, limit to assigned classes & subjects (use prepared)
    if ($role === 'teacher') {
        $sql = "SELECT DISTINCT c.id, c.class_name
                FROM teachers_subjects ts
                JOIN classes c ON ts.class_id = c.id
                WHERE ts.teacher_id = ?
                ORDER BY c.class_name";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $classes[] = $r;
            $stmt->close();
        }

        $sql = "SELECT DISTINCT s.id, s.name
                FROM teachers_subjects ts
                JOIN subjects s ON ts.subject_id = s.id
                WHERE ts.teacher_id = ?
                ORDER BY s.name";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $subjects[] = $r;
            $stmt->close();
        }
    } else {
        // Admin or other roles: fetch all classes & subjects
        $res = $conn->query("SELECT id, class_name FROM classes ORDER BY class_name");
        while ($r = $res->fetch_assoc()) $classes[] = $r;
        $res->free();

        $res = $conn->query("SELECT id, name FROM subjects ORDER BY name");
        while ($r = $res->fetch_assoc()) $subjects[] = $r;
        $res->free();
    }

    // Academic years & terms (rarely changed — small result set)
    $res = $conn->query("SELECT id, year_label FROM academic_years ORDER BY year_label DESC");
    while ($r = $res->fetch_assoc()) $years[] = $r;
    $res->free();

    $res = $conn->query("SELECT id, term_name FROM terms ORDER BY position ASC");
    while ($r = $res->fetch_assoc()) $terms[] = $r;
    $res->free();

} catch (Throwable $e) {
    // Log detailed error for debugging, show generic UI message if needed
    @file_put_contents(__DIR__ . '/logs/reports_php.log', '['.date('c').'] Error loading filters: '.$e->getMessage().PHP_EOL, FILE_APPEND);
    // Keep page rendering with empty lists (UI will show empty options)
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__.'/../../pwa-head.php'; ?>

  <meta charset="UTF-8">
  <title>Reports Management</title>
   <link rel="icon" type="image/png" href="../../favicon-96x96.png" sizes="96x96" />
  <link rel="icon" type="image/svg+xml" href="../../favicon.svg" />
  <link rel="shortcut icon" href="../../favicon.ico" />
  <link rel="apple-touch-icon" sizes="180x180" href="../../apple-touch-icon.png" />
  <link rel="manifest" href="../../site.webmanifest" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <!-- DataTables + Buttons -->
  <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css" rel="stylesheet">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
  <style>
    .container { max-width:1200px; }
    .card { border-radius:10px; }
    .student-photo-thumb {
      width:56px; height:56px; object-fit:cover;
      border-radius:50%; border:2px solid #fff;
      box-shadow:0 4px 12px rgba(2,22,60,0.06);
    }
    .top-right-toast { position: fixed; top: 1rem; right: 1rem; z-index: 10800; }
    .filters-row .form-label { font-weight:600; }
    #loadingOverlay {
      position:fixed; top:0; left:0; width:100%; height:100%;
      display:flex; align-items:center; justify-content:center;
      background:rgba(255,255,255,0.7); z-index:10700;
    }




    /* --- Responsive improvements (added) --- */
    :root {
      --card-radius: 12px;
      --gutter: 1rem;
      --muted: #6c757d;
      --brand: #0d47a1;
    }

    /* Make layout adapt: filters row becomes stacked on small screens */
    .filters-row .col-md-3,
    .filters-row .col-md-2,
    .filters-row .col-md-4 {
      flex: 0 0 auto;
      max-width: 100%;
    }

    @media (min-width: 768px) {
      .filters-row .col-md-3 { flex: 0 0 25%; max-width: 25%; }
      .filters-row .col-md-2 { flex: 0 0 16.666667%; max-width: 16.666667%; }
      .filters-row .col-md-4 { flex: 0 0 33.333333%; max-width: 33.333333%; }
    }

    @media (max-width: 767.98px) {
      /* stack filters and make apply button full width */
      .filters-row { gap: .5rem; }
      .filters-row .form-label { font-size: .95rem; }
      .filters-row .btn { width: 100%; }
      #btnApply { padding: .5rem; }
      /* Tables: allow wrapping and reduce padding */
      table#studentsTable thead th, table#scoresTable thead th { font-size: .85rem; }
      table#studentsTable tbody td, table#scoresTable tbody td { font-size: .88rem; padding: .45rem .5rem; white-space: normal; }
      /* Hide less-critical columns on very small screens */
      table#studentsTable th:nth-child(3), table#studentsTable td:nth-child(3) { display: none; } /* hide photo */
      table#scoresTable th:nth-child(6), table#scoresTable td:nth-child(6) { display: none; } /* hide class if needed */
      /* Make small controls more tappable */
      .btn { min-height: 40px; }
    }

    /* Table responsiveness */
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }

    /* Improve modal readability on small screens */
    .modal-fullscreen-sm-down .modal-content { border-radius: 0; }
    .modal .form-label { font-weight: 600; color: var(--muted); }

    /* Toast / loading overlay */
    #loadingOverlay { display:none; }
    #loadingOverlay.show { display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.8); position:fixed; inset:0; z-index:10800; }

    /* Accessibility focus */
    :focus { outline: 3px solid rgba(13,61,145,0.12); outline-offset: 2px; }

    /* Minor card tuning */
    .card { border-radius: var(--card-radius); box-shadow: 0 6px 18px rgba(17,24,39,0.04); }
  </style>
 

</head>

<body>
<?php  include __DIR__ . '/../partials/header_stub.php'; ?>
<div class="container p-4"
     data-report-action="report_action.php"
     data-render-student="render_student_report.php"
     data-csrf="<?= e($csrf_token) ?>">

  <div class="d-flex align-items-center mb-3">
    <div>
      <h4 class="mb-0">Report Cards</h4>
      <small class="text-muted">Generate printable student or class report cards (PDF)</small>
    </div>
    

    <div class="ms-auto d-flex gap-2">
      <button class="btn btn-outline-primary ms-3" id="previewClassBtn">
      <i class="bi bi-eye"></i> Preview Class Reports
    </button>
    </div>
  </div>

  <div class="card mb-4 p-3">
    <form id="reportFilters" class="row g-3 filters-row" autocomplete="off" onsubmit="return false;">
      <input type="hidden" id="csrf_token" name="csrf_token" value="<?= e($csrf_token) ?>">
      <div class="col-md-3">
        <label class="form-label" for="filter_class">Class</label>
        <select id="filter_class" class="form-select" required>
          <option value="">-- Select Class --</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e($c['class_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label" for="filter_year">Academic Year</label>
        <select id="filter_year" class="form-select" required>
          <option value="">-- Select Year --</option>
          <?php foreach ($years as $y): ?>
            <option value="<?= (int)$y['id'] ?>"><?= e($y['year_label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label" for="filter_term">Term</label>
        <select id="filter_term" class="form-select" required>
          <option value="">-- Select Term --</option>
          <?php foreach ($terms as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= e($t['term_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label" for="filter_subject">Subject</label>
        <select id="filter_subject" class="form-select">
          <option value="all">All Subjects</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2 d-flex align-items-end">
        <button id="btnApply" class="btn btn-primary w-100">Apply Filters</button>
      </div>
    </form>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header">
      <strong>Students</strong>
      <small class="text-muted ms-2">Select a student to preview or generate a PDF</small>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table id="studentsTable" class="table table-striped table-hover w-100">
          <thead class="table-light">
            <tr>
              <th>#</th><th>Admission No</th><th>Photo</th><th>Student</th><th>Gender</th><th>DOB</th><th>Class</th><th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header"><strong>Saved Scores (Preview)</strong></div>
    <div class="card-body">
      <div class="table-responsive">
        <table id="scoresTable" class="table table-striped table-hover w-100">
          <thead class="table-light">
            <tr>
              <th>#</th><th>Student</th><th>Subject</th><th>Score</th><th>Position</th><th>Class</th><th>Term</th><th>Year</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

</div>


<!-- Toast container -->
<div class="top-right-toast" id="toastContainer" aria-live="polite" aria-atomic="true"></div>

<!-- Remarks / Attendance Modal (unchanged structure but kept IDs consistent for JS) -->
<div class="modal fade" id="metaModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="metaForm" onsubmit="return false;">
        <div class="modal-header">
          <h5 class="modal-title">Attendance & Remarks</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="meta_student_id" name="student_id">
          <input type="hidden" id="meta_class_id" name="class_id">
          <input type="hidden" id="meta_term_id" name="term_id">
          <input type="hidden" id="meta_year_id" name="year_id">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Present Days</label>
              <input type="number" id="present_days" name="present_days" class="form-control" min="0">
            </div>
            <div class="col-md-4">
              <label class="form-label">Total Days</label>
              <input type="number" id="total_days" name="total_days" class="form-control" min="0">
            </div>
            <div class="col-md-4">
              <label class="form-label">Attendance %</label>
              <input type="text" id="attendance_percent" class="form-control" readonly>
            </div>

            <div class="col-md-6">
              <label class="form-label">Class Teacher's Remark</label>
              <textarea id="class_teacher_remark" name="class_teacher_remark" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Headteacher's Remark</label>
              <textarea id="head_teacher_remark" name="head_teacher_remark" class="form-control" rows="3"></textarea>
            </div>

            <div class="col-md-4">
              <label class="form-label">Attitude / Conduct</label>
              <select id="attitude" name="attitude" class="form-select">
                <option value="">-- Select --</option>
                <option>Excellent</option><option>Very Good</option><option>Good</option><option>Fair</option><option>Poor</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Interest</label>
              <select id="interest" name="interest" class="form-select">
                <option value="">-- Select --</option>
                <option>High</option><option>Moderate</option><option>Low</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Promotion Status</label>
              <select id="promotion_status" name="promotion_status" class="form-select">
                <option value="">-- Select --</option>
                <option value="promoted">Promoted</option>
                <option value="repeat">Repeat</option>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">Vacation Date</label>
              <input type="date" id="vacation_date" name="vacation_date" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Next Term Begins</label>
              <input type="date" id="next_term_begins" name="next_term_begins" class="form-control">
            </div>

          </div>
        </div>
        <div class="modal-footer">
          <button id="saveMetaBtn" class="btn btn-primary">Save</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js" defer></script>

<!-- Keep reports.js separate so it can be optimized independently -->
<script src="reports.js" defer></script>

<?php include __DIR__ . '/../partials/footer_stub.php'; ?>

<?php include __DIR__.'/../../pwa-footer.php'; ?>\n</body>
</html>
