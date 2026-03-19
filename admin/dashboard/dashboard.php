<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../../auth/login.php");
    exit();
}

?>
<!doctype html>
<html lang="en">
<head>
  <?php include __DIR__.'/../../pwa-head.php'; ?>

  <meta charset="utf-8">
  <title>Dashboard - Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/png" href="../../favicon-96x96.png" sizes="96x96" />
  <link rel="icon" type="image/svg+xml" href="../../favicon.svg" />
  <link rel="shortcut icon" href="../../favicon.ico" />
  <link rel="apple-touch-icon" sizes="180x180" href="../../apple-touch-icon.png" />
  <link rel="manifest" href="../../site.webmanifest" />

  <!-- CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <!-- DataTables + Buttons -->
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">
  <link href="../../assets/css/admin-dashboard.css" rel="stylesheet">

<style>

 :root{
  --brand-blue:#0b3d91;
  --muted:#6c757d;
  --card-bg:#fff;
  --accent:#00aaff;
}
.admin-dashboard-container{padding:1.5rem;}
.kpi-section{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;}
.kpi-card{background:var(--card-bg);border-radius:10px;padding:1rem;box-shadow:0 2px 8px rgba(0,0,0,.1);text-align:center;transition:.3s;}
.kpi-card:hover{transform:translateY(-4px);box-shadow:0 4px 12px rgba(0,0,0,.2);}
.kpi-card h3{color:var(--brand-blue);font-size:1.1rem;}
.kpi-card span{font-size:1.4rem;font-weight:bold;}
.card h5{color:var(--brand-blue);margin-bottom:.5rem;}

</style>
</head>
<body>
 <?php include '../partials/header_stub.php'; ?>
<div class="admin-dashboard-container">
  <div class="dashboard-header mb-3">
    <h1>Admin Dashboard</h1>
    <p>Welcome back, <?= htmlspecialchars($_SESSION['name'] ?? 'Admin') ?>!</p>
  </div>

  <section class="kpi-section">
    <div class="kpi-card"><h3>Total Students</h3><span id="kpi-students">—</span></div>
    <div class="kpi-card"><h3>Total Teachers</h3><span id="kpi-teachers">—</span></div>
    <div class="kpi-card"><h3>Classes</h3><span id="kpi-classes">—</span></div>
    <div class="kpi-card"><h3>Subjects</h3><span id="kpi-subjects">—</span></div>
    <div class="kpi-card"><h3>Exams Conducted</h3><span id="kpi-exams">—</span></div>
  </section>

  <div class="row mt-4">
    <div class="col-md-6">
      <div class="card p-3">
        <h5>Average Class Performance</h5>
        <canvas id="chartClassAvg" height="200"></canvas>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card p-3">
        <h5>Subject Performance Averages</h5>
        <canvas id="chartSubjectAvg" height="200"></canvas>
      </div>
    </div>
  </div>

  <div class="card mt-4 p-3">
    <h5>Recent Activity Log</h5>
    <ul id="activity-log" class="list-group list-group-flush"></ul>
  </div>

  <div class="card mt-4 p-3">
    <h5>Quick Links</h5>
    <div class="d-flex flex-wrap gap-2">
      <a href="../students/students.php" class="btn btn-primary">Add Student</a>
      <a href="../scores/scores.php" class="btn btn-success">Enter Scores</a>
      <a href="../reports/reports.php" class="btn btn-warning">Generate Reports</a>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="dashboard.js"></script>
<?php include '../partials/footer_stub.php'; ?>
<?php include __DIR__.'/../../pwa-footer.php'; ?>\n</body>
</html>