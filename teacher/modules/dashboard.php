<?php
// teacher/dashboard.php — interactive teacher dashboard (AJAX-driven charts + Enter Marks links)
session_start();
require_once __DIR__ . '/../../includes/db.php';

// Auth
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php?error=Please+login');
    exit;
}
$user_id = (int)$_SESSION['user_id'];

// Base URL detection (same approach as other files)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
if (str_contains($scriptName, '/foase_exam_report_system')) {
    $pos = strpos($scriptName, '/foase_exam_report_system');
    $baseUrl = substr($scriptName, 0, $pos + strlen('/foase_exam_report_system')) . '/';
} else {
    $baseUrl = rtrim(str_replace('\\', '/', dirname($scriptName)), '/') . '/';
    if ($baseUrl === '//') $baseUrl = '/';
}

/* -------------------------
   Load teacher & assignments
   ------------------------- */
$teacher = null;
if ($stmt = $conn->prepare("SELECT id, user_id, first_name, last_name, username FROM teachers WHERE user_id = ? LIMIT 1")) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$teacher_display = trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? '')) ?: ($teacher['username'] ?? 'Teacher');

/* assigned classes & subjects (use int casting for safety) */
$assigned_classes = [];
$assigned_subjects = [];
// teachers_subjects references teacher_id (which in your dump references users.id). We'll query by user_id.
if ($res = $conn->query("SELECT ts.class_id, c.class_name, ts.subject_id, s.name AS subject_name
                         FROM teachers_subjects ts
                         LEFT JOIN classes c ON ts.class_id = c.id
                         LEFT JOIN subjects s ON ts.subject_id = s.id
                         WHERE ts.teacher_id = {$user_id}
                         ORDER BY c.class_name, s.name")) {
    while ($r = $res->fetch_assoc()) {
        $cid = (int)$r['class_id'];
        $sid = (int)$r['subject_id'];
        if ($cid) $assigned_classes[$cid] = $r['class_name'] ?? "Class {$cid}";
        if ($sid) $assigned_subjects[$sid] = $r['subject_name'] ?? "Subject {$sid}";
    }
}

/* fallback: if none found using teacher_id = users.id, try teachers.id */
if (empty($assigned_classes) && !empty($teacher['id'])) {
    $tid = (int)$teacher['id'];
    if ($res = $conn->query("SELECT ts.class_id, c.class_name, ts.subject_id, s.name AS subject_name
                             FROM teachers_subjects ts
                             LEFT JOIN classes c ON ts.class_id = c.id
                             LEFT JOIN subjects s ON ts.subject_id = s.id
                             WHERE ts.teacher_id = {$tid}
                             ORDER BY c.class_name, s.name")) {
        while ($r = $res->fetch_assoc()) {
            $cid = (int)$r['class_id'];
            $sid = (int)$r['subject_id'];
            if ($cid) $assigned_classes[$cid] = $r['class_name'] ?? "Class {$cid}";
            if ($sid) $assigned_subjects[$sid] = $r['subject_name'] ?? "Subject {$sid}";
        }
    }
}

/* Load terms for filter */
$filter_terms = [];
if ($r = $conn->query("SELECT id, term_name FROM terms ORDER BY position")) {
    while ($t = $r->fetch_assoc()) $filter_terms[(int)$t['id']] = $t['term_name'];
}

/* determine current term (system_settings.default_term or first by position) */
$current_term_id = null;
$default_term_name = null;
if ($s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key='default_term' LIMIT 1")) {
    $s->execute();
    $default_term_name = $s->get_result()->fetch_row()[0] ?? null;
    $s->close();
}
if ($default_term_name) {
    $p = $conn->prepare("SELECT id FROM terms WHERE term_name = ? LIMIT 1");
    if ($p) {
        $p->bind_param('s', $default_term_name);
        $p->execute();
        $rr = $p->get_result()->fetch_row();
        if ($rr) $current_term_id = (int)$rr[0];
        $p->close();
    }
}
if (!$current_term_id) {
    if ($r = $conn->query("SELECT id FROM terms ORDER BY position LIMIT 1")) {
        $row = $r->fetch_row();
        if ($row) $current_term_id = (int)$row[0];
    }
}

/* Avatar */
$avatar = $baseUrl . 'assets/images/default_user.png';
if ($s = $conn->prepare("SELECT photo_path FROM users WHERE id = ? LIMIT 1")) {
    $s->bind_param('i', $user_id);
    $s->execute();
    $pp = $s->get_result()->fetch_row()[0] ?? '';
    $s->close();
    if (!empty($pp)) {
        if (preg_match('#^https?://#i', $pp)) $avatar = $pp;
        else $avatar = $baseUrl . ltrim(str_replace('\\', '/', $pp), '/');
    }
}

// Render static page — charts load via AJAX endpoint below.
?><!doctype html>
<html lang="en">
<head>
  <?php include __DIR__.'/../../pwa-head.php'; ?>

  <meta charset="utf-8">
  <title>Teacher Dashboard | <?= htmlspecialchars($teacher_display) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" type="image/png" href="../../favicon-96x96.png" sizes="96x96" />
  <link rel="icon" type="image/svg+xml" href="../../favicon.svg" />
  <link rel="shortcut icon" href="../../favicon.ico" />
  <link rel="apple-touch-icon" sizes="180x180" href="../../apple-touch-icon.png" />
  <link rel="manifest" href="../../site.webmanifest" />

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    :root{--deep:#0d47a1;--muted:#6b7280}
    body{background:#f5f7fb}
    .kpi-card{border-left:6px solid var(--deep);border-radius:10px;padding:12px;box-shadow:0 2px 6px rgba(0,0,0,.06)}
    .enter-links .btn{margin:3px}
    .chart-card{height:320px}
    @media(max-width:768px){.kpi-card{text-align:center}}
  </style>
</head>
<body>

<?php include __DIR__ . '/../../admin/partials/header_stub.php'; ?>

<div class="container-fluid p-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Teacher Dashboard</h3>
    
  </div>

  <!-- KPIs (will be updated by AJAX too) -->
  <div class="row g-3 mb-4" id="kpiRow">
    <div class="col-sm-6 col-md-3"><div class="kpi-card bg-white"><div class="small text-muted">Total Students</div><h4 class="mb-0" id="k_totalStudents">—</h4></div></div>
    <div class="col-sm-6 col-md-3"><div class="kpi-card bg-white"><div class="small text-muted">Reports Generated</div><h4 class="mb-0" id="k_reportsGenerated">—</h4></div></div>
    <div class="col-sm-6 col-md-3"><div class="kpi-card bg-white"><div class="small text-muted">Pending Entries</div><h4 class="mb-0" id="k_pendingEntries">—</h4></div></div>
    <div class="col-sm-6 col-md-3"><div class="kpi-card bg-white"><div class="small text-muted">Overall Pass Rate</div><h4 class="mb-0" id="k_passRate">—</h4></div></div>
  </div>

  <!-- Filters + Enter marks quick links -->
  <div class="card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
      <div class="me-2"><strong>Filters</strong></div>
      <select id="filterClass" class="form-select form-select-sm" style="width:auto">
        <option value="">All Classes</option>
        <?php foreach ($assigned_classes as $id => $name): ?>
          <option value="<?= (int)$id ?>"><?= htmlspecialchars($name) ?></option>
        <?php endforeach; ?>
      </select>

      <select id="filterSubject" class="form-select form-select-sm" style="width:auto">
        <option value="">All Subjects</option>
        <?php foreach ($assigned_subjects as $id => $name): ?>
          <option value="<?= (int)$id ?>"><?= htmlspecialchars($name) ?></option>
        <?php endforeach; ?>
      </select>

      <select id="filterTerm" class="form-select form-select-sm" style="width:auto">
        <option value="<?= (int)$current_term_id ?>">Current Term (<?= htmlspecialchars($filter_terms[$current_term_id] ?? 'Current') ?>)</option>
        <?php foreach ($filter_terms as $tid => $tname): if ($tid == $current_term_id) continue; ?>
          <option value="<?= (int)$tid ?>"><?= htmlspecialchars($tname) ?></option>
        <?php endforeach; ?>
      </select>

      <button id="refreshBtn" class="btn btn-sm btn-outline-primary ms-auto">Refresh</button>
    </div>

    <div class="card-body border-top">
      <div class="d-flex align-items-center justify-content-between">
        <div><strong>Quick links — Enter Marks</strong></div>
        <div class="text-muted small">Click to open the marks entry page for that class/subject</div>
      </div>
      <div class="enter-links mt-2">
        <?php
        // Display quick links grouped by class then subject
        if (empty($assigned_classes) || empty($assigned_subjects)) {
            echo '<span class="text-muted">No assignments found.</span>';
        } else {
            // Build cross-product of class x assigned subjects but only for actual teacher assignments
            // Query teachers_subjects to get actual pairs
            $pairs = [];
            $tidParam = $user_id;
            $sql = "SELECT class_id, subject_id FROM teachers_subjects WHERE teacher_id = ?";
            if ($p = $conn->prepare($sql)) {
                $p->bind_param('i', $tidParam);
                $p->execute();
                $res = $p->get_result();
                while ($row = $res->fetch_assoc()) {
                    $pairs[] = [(int)$row['class_id'], (int)$row['subject_id']];
                }
                $p->close();
            }
            if (empty($pairs)) {
                // fallback: cross product
                foreach ($assigned_classes as $cid => $cname) {
                    foreach ($assigned_subjects as $sid => $sname) {
                        $url = "teacher/enter_scores.php?class_id={$cid}&subject_id={$sid}";
                        echo '<a class="btn btn-outline-secondary btn-sm" href="'.$url.'">'.htmlspecialchars($cname).' • '.htmlspecialchars($sname).'</a> ';
                    }
                }
            } else {
                foreach ($pairs as [$cid, $sid]) {
                    $cname = $assigned_classes[$cid] ?? "Class {$cid}";
                    $sname = $assigned_subjects[$sid] ?? "Subject {$sid}";
                    $url = "teacher/enter_scores.php?class_id={$cid}&subject_id={$sid}";
                    echo '<a class="btn btn-outline-primary btn-sm" href="'.$url.'">'.htmlspecialchars($cname).' • '.htmlspecialchars($sname).'</a> ';
                }
            }
        }
        ?>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <div class="card chart-card p-3">
        <h6>Class Average per Subject</h6>
        <canvas id="chart1" style="max-height:270px"></canvas>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card chart-card p-3">
        <h6>Pass / Fail Trend (last terms)</h6>
        <canvas id="chart2" style="max-height:270px"></canvas>
      </div>
    </div>
  </div>

  <!-- Alerts & Recent Reports -->
  <div class="row g-3">
    <div class="col-md-6">
      <div class="card mb-4">
        <div class="card-body" id="alertsContainer">
          <h5>Alerts & Notifications</h5>
          <div class="text-muted">Loading...</div>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card mb-4">
        <div class="card-body" id="recentReportsContainer">
          <h5>Recent Reports</h5>
          <div class="text-muted">Loading...</div>
        </div>
      </div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Setup charts
  let chart1 = null, chart2 = null;

  function makeChart1(labels, data) {
    const ctx = document.getElementById('chart1').getContext('2d');
    if (chart1) chart1.destroy();
    chart1 = new Chart(ctx, {
      type: 'bar',
      data: { labels: labels, datasets: [{ label: 'Average (%)', data: data, backgroundColor: '#1565c0' }] },
      options: { responsive:true, maintainAspectRatio:false, scales: { y: { beginAtZero:true, max:100 } } }
    });
  }
  function makeChart2(labels, pass, fail) {
    const ctx = document.getElementById('chart2').getContext('2d');
    if (chart2) chart2.destroy();
    chart2 = new Chart(ctx, {
      type: 'line',
      data: { labels: labels, datasets: [
          { label:'Pass %', data: pass, borderColor:'green', fill:false, tension:0.2 },
          { label:'Fail %', data: fail, borderColor:'red', fill:false, tension:0.2 }
        ] },
      options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{ beginAtZero:true, max:100 } } }
    });
  }

  // Helper to fetch dashboard data
  async function fetchDashboardData() {
    const cls = document.getElementById('filterClass').value;
    const subj = document.getElementById('filterSubject').value;
    const term = document.getElementById('filterTerm').value;

    const fd = new FormData();
    if (cls) fd.append('class_id', cls);
    if (subj) fd.append('subject_id', subj);
    if (term) fd.append('term_id', term);

    try {
      const res = await fetch('dashboard_data.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd,
        headers: { 'Accept': 'application/json' }
      });
      if (!res.ok) throw new Error('Network response not ok');
      const json = await res.json();
      if (json.error) {
        console.error(json.error);
        alert('Error: ' + json.error);
        return;
      }

      // KPIs
      document.getElementById('k_totalStudents').textContent = json.kpis.totalStudents ?? '0';
      document.getElementById('k_reportsGenerated').textContent = json.kpis.reportsGenerated ?? '0';
      document.getElementById('k_pendingEntries').textContent = json.kpis.pendingEntries ?? '0';
      document.getElementById('k_passRate').textContent = (json.kpis.passRate ?? 0) + '%';

      // Charts
      makeChart1(json.chart1.labels, json.chart1.data);
      makeChart2(json.chart2.labels, json.chart2.pass, json.chart2.fail);

      // Alerts
      const alertsRoot = document.getElementById('alertsContainer');
      alertsRoot.innerHTML = '<h5>Alerts & Notifications</h5>';
      if (json.alerts && json.alerts.length) {
        json.alerts.forEach(a => {
          const div = document.createElement('div');
          div.className = 'alert ' + (a.type === 'danger' ? 'alert-danger' : (a.type === 'warning' ? 'alert-warning' : 'alert-info'));
          div.textContent = a.msg;
          alertsRoot.appendChild(div);
        });
      } else {
        const none = document.createElement('div');
        none.className = 'text-muted';
        none.textContent = 'No alerts.';
        alertsRoot.appendChild(none);
      }

      // Recent reports
      const rrRoot = document.getElementById('recentReportsContainer');
      rrRoot.innerHTML = '<h5>Recent Reports</h5>';
      if (json.recentReports && json.recentReports.length) {
        const table = document.createElement('table');
        table.className = 'table table-sm';
        table.innerHTML = '<thead><tr><th>Student</th><th>Type</th><th>Generated</th><th>Action</th></tr></thead>';
        const tbody = document.createElement('tbody');
        json.recentReports.forEach(r => {
          const tr = document.createElement('tr');
          tr.innerHTML = `<td>${r.student}</td><td>${r.type.toUpperCase()}</td><td>${r.generated_at}</td><td>${r.download ? '<a href="'+r.download+'">Download</a>' : '-' }</td>`;
          tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        rrRoot.appendChild(table);
      } else {
        const none = document.createElement('div');
        none.className = 'text-muted';
        none.textContent = 'No recent reports.';
        rrRoot.appendChild(none);
      }

    } catch (err) {
      console.error(err);
      alert('Failed to load dashboard data.');
    }
  }

  // Events
  document.getElementById('filterClass').addEventListener('change', fetchDashboardData);
  document.getElementById('filterSubject').addEventListener('change', fetchDashboardData);
  document.getElementById('filterTerm').addEventListener('change', fetchDashboardData);
  document.getElementById('refreshBtn').addEventListener('click', fetchDashboardData);

  // Initial load
  fetchDashboardData();

</script>

<?php include __DIR__ . '/../../admin/partials/footer_stub.php'; ?>

<?php include __DIR__.'/../../pwa-footer.php'; ?>\n</body>
</html>
