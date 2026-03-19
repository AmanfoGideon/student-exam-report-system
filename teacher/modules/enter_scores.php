<?php
// teacher/enter_scores.php
session_start();
require_once __DIR__ . '/../../includes/db.php';

// Teacher-only guard
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Teacher') {
    header('Location: ../auth/login.php?error=Please+login+as+Teacher');
    exit;
}
$user_id = (int)$_SESSION['user_id'];

// Create CSRF token
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = $_SESSION['csrf_token'];

// Resolve teacher.id from teachers.user_id
$teacherStmt = $conn->prepare("SELECT id, first_name, last_name FROM teachers WHERE user_id = ? LIMIT 1");
$teacherStmt->bind_param('i', $user_id);
$teacherStmt->execute();
$teacher = $teacherStmt->get_result()->fetch_assoc() ?: [];
$teacherStmt->close();
$teacherId = (int)($teacher['id'] ?? 0);
$teacherName = trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? ''));

// Fetch classes assigned to the teacher (via subject_teachers -> subject_classes)
$clsSql = "SELECT DISTINCT c.id, c.class_name
           FROM subject_teachers st
           JOIN subject_classes sc ON st.subject_id = sc.subject_id
           JOIN classes c ON sc.class_id = c.id
           WHERE st.teacher_id = ?
           ORDER BY c.class_name";
$clsStmt = $conn->prepare($clsSql);
$clsStmt->bind_param('i', $teacherId);
$clsStmt->execute();
$classes = $clsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$clsStmt->close();

// Fetch subjects assigned to the teacher
$subSql = "SELECT DISTINCT s.id, s.name
           FROM subject_teachers st
           JOIN subjects s ON st.subject_id = s.id
           WHERE st.teacher_id = ?
           ORDER BY s.name";
$subStmt = $conn->prepare($subSql);
$subStmt->bind_param('i', $teacherId);
$subStmt->execute();
$subjects = $subStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$subStmt->close();
?>
<!doctype html>
<html lang="en">
<head>
  <?php include __DIR__.'/../../pwa-head.php'; ?>

  <meta charset="utf-8">
  <title>Enter Scores | <?= htmlspecialchars($teacherName ?: 'Teacher') ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/png" href="../../favicon-96x96.png" sizes="96x96" />
  <link rel="icon" type="image/svg+xml" href="../../favicon.svg" />
  <link rel="shortcut icon" href="../../favicon.ico" />
  <link rel="apple-touch-icon" sizes="180x180" href="../../apple-touch-icon.png" />
  <link rel="manifest" href="../../site.webmanifest" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <style>
    :root{--deep:#0d47a1;--deep2:#1565c0}
    body{background:#f5f7fb}
    .page-header{display:flex;justify-content:space-between;align-items:center;background:#fff;padding:1rem;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.04);margin-bottom:1rem}
    .card{border-radius:10px}
    .small-muted{font-size:.85rem;color:#6c757d}
    .spinner-border-sm{width:1.25rem;height:1.25rem}
  </style>
</head>
<body>
<?php include __DIR__ . '/../../admin/partials/header_stub.php'; ?>

<div class="container-fluid p-4">
  <div class="page-header">
    <div>
      <h4 class="mb-0">Enter Scores</h4>
      <small class="text-muted">Welcome, <?= htmlspecialchars($teacherName ?: ($_SESSION['username'] ?? 'Teacher')) ?></small>
    </div>
    <div>
      <a href="classes.php" class="btn btn-outline-primary btn-sm"><i class="fa fa-arrow-left me-1"></i> Back</a>
    </div>
  </div>

  <!-- Filters -->
  <div class="card mb-4 p-3">
    <div class="row g-3 align-items-end">
      <input type="hidden" id="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
      <div class="col-md-3">
        <label class="form-label">Class</label>
        <select id="class_id" class="form-select">
          <option value="">-- Select Class --</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Subject</label>
        <select id="subject_id" class="form-select">
          <option value="">-- Select Subject --</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Academic Year</label>
        <select id="year_id" class="form-select">
          <option value="">-- Select Year --</option>
          <?php
            $rs = $conn->query("SELECT id, year_label FROM academic_years ORDER BY year_label DESC");
            while ($r = $rs->fetch_assoc()) {
                echo '<option value="'.(int)$r['id'].'">'.htmlspecialchars($r['year_label']).'</option>';
            }
          ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Term</label>
        <select id="term_id" class="form-select">
          <option value="">-- Select Term --</option>
          <?php
            $rs = $conn->query("SELECT id, term_name FROM terms ORDER BY position ASC");
            while ($r = $rs->fetch_assoc()) {
                echo '<option value="'.(int)$r['id'].'">'.htmlspecialchars($r['term_name']).'</option>';
            }
          ?>
        </select>
      </div>
    </div>
  </div>

  <!-- Bulk entry -->
  <div id="scoresEntrySection" class="card mb-4" style="display:none">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0">Bulk Score Entry</h5>
        <div id="bulkSpinner" class="spinner-border spinner-border-sm text-primary d-none" role="status" aria-hidden="true"></div>
      </div>

      <form id="bulkScoresForm" autocomplete="off">
        <div class="table-responsive">
          <table id="scoresEntryTable" class="table table-sm table-bordered align-middle">
            <thead class="table-light">
              <tr>
                <th>Student</th>
                <th style="width:160px">Class Score<br><small class="text-muted">max 50</small></th>
                <th style="width:160px">Exam Score<br><small class="text-muted">max 50</small></th>
                <th style="width:100px">Total</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

        <div class="text-end mt-2">
          <button id="saveBulkBtn" type="submit" class="btn btn-success">
            <i class="fa fa-save me-1"></i> Save & Recalculate Positions
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Saved scores -->
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Saved Scores</h5>
        <div class="d-flex gap-2">
          <button id="exportScoresBtn" class="btn btn-outline-success btn-sm">Export Scores</button>
          <!-- Import intentionally omitted for teachers; if you want it, I can add it -->
        </div>
      </div>

      <div class="table-responsive">
        <table id="scoresTable" class="table table-striped table-bordered w-100">
          <thead class="table-light">
            <tr>
              <th>Student</th><th>Class</th><th>Subject</th><th>Term</th><th>Year</th>
              <th>Class Score</th><th>Exam Score</th><th>Total</th><th>Grade</th><th>Remark</th><th>Action</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Edit modal -->
<div class="modal fade" id="editScoreModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form id="editScoreForm" class="modal-content" autocomplete="off">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">Edit Score</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit_score_id" name="id">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="mb-3">
          <label class="form-label">Class Score (0–50)</label>
          <input id="edit_class_score" name="class_score" type="number" min="0" max="50" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Exam Score (0–50)</label>
          <input id="edit_exam_score" name="exam_score" type="number" min="0" max="50" class="form-control" required>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Update</button>
      </div>
    </form>
  </div>
</div>

<!-- Toast container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1080">
  <div id="liveToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="toast-body"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js" defer></script>

<!-- ✨ NEW: DataTables Buttons + Dependencies -->
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js" defer></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js" defer></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js" defer></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js" defer></script>

<script defer>
document.addEventListener('DOMContentLoaded', () => {
  const MAX_CA = 50, MAX_EXAM = 50;
  let selectedClass = '', selectedSubject = '', selectedYear = '', selectedTerm = '';
  let scoresTable = null;

  const toastEl = document.getElementById('liveToast');
  const toastBody = document.getElementById('toast-body');
  const bsToast = toastEl && bootstrap && bootstrap.Toast ? new bootstrap.Toast(toastEl, { delay: 5000 }) : null;

  function showToast(msg, type='info') {
    if (!toastBody) return console.log(type, msg);
    toastBody.textContent = msg;
    toastEl.classList.remove('bg-success','bg-danger','bg-info','text-dark','text-white');
    if (type==='success') toastEl.classList.add('bg-success','text-white');
    else if (type==='error') toastEl.classList.add('bg-danger','text-white');
    else toastEl.classList.add('bg-info','text-dark');
    try { bsToast.show(); } catch(e){ console.warn(e); }
  }

  function getCsrf() { return document.getElementById('csrf_token')?.value || ''; }

  function updateSelectionsFromUI() {
    selectedClass = document.getElementById('class_id')?.value || '';
    selectedSubject = document.getElementById('subject_id')?.value || '';
    selectedYear = document.getElementById('year_id')?.value || '';
    selectedTerm = document.getElementById('term_id')?.value || '';
  }

  // Load saved scores DataTable (Ajax + Export buttons)
  function loadScoresTable() {
    if (!selectedClass || !selectedSubject || !selectedYear || !selectedTerm) {
      $('#scoresTable').DataTable()?.clear?.().draw?.();
      return;
    }

    if (scoresTable) {
      try { scoresTable.destroy(); } catch(e) {}
      $('#scoresTable tbody').empty();
    }

    scoresTable = $('#scoresTable').DataTable({
      ajax: {
        url: 'score_action.php',
        type: 'POST',
        data: function(){ return {
          action: 'load_scores',
          class_id: selectedClass,
          subject_id: selectedSubject,
          year_id: selectedYear,
          term_id: selectedTerm,
          csrf_token: getCsrf()
        }},
        dataSrc: function(json){
          if (!json) { showToast('Empty response', 'error'); return []; }
          if (json.status === 'error') { showToast(json.message || 'Server error','error'); return []; }
          return json.data || [];
        },
        error: function(xhr){ showToast('Failed to load saved scores','error'); console.error(xhr); }
      },
      columns: [
        { data: 'student' }, { data: 'class' }, { data: 'subject' },
        { data: 'term' }, { data: 'year' },
        { data: 'class_score' }, { data: 'exam_score' },
        { data: 'total' }, { data: 'grade' }, { data: 'remark' },
        { data: 'action' }
      ],
      order: [[0,'asc']],
      responsive: true,
      destroy: true,
      paging: true,
      pageLength: 25,
      dom: 'Bfrtip',
      buttons: [
        { extend: 'excelHtml5', title: 'Teacher Scores', className: 'btn btn-outline-success btn-sm' },
        { extend: 'pdfHtml5', title: 'Teacher Scores', orientation: 'landscape', pageSize: 'A4', className: 'btn btn-outline-danger btn-sm' },
        { extend: 'print', title: 'Teacher Scores', className: 'btn btn-outline-secondary btn-sm' }
      ],
      initComplete: function(){
        const btns = $('.dt-buttons').addClass('mb-2');
        $('#scoresTable_wrapper .dataTables_length').after(btns);
      }
    });
  }

  // Load students for entry (bulk)
  function loadEntryTable(){
    updateSelectionsFromUI();
    if (!selectedClass || !selectedSubject || !selectedYear || !selectedTerm) {
      document.getElementById('scoresEntrySection').style.display = 'none';
      return;
    }
    document.getElementById('bulkSpinner').classList.remove('d-none');
    fetch('score_action.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        action: 'fetch_students',
        class_id: selectedClass,
        subject_id: selectedSubject,
        year_id: selectedYear,
        term_id: selectedTerm,
        csrf_token: getCsrf()
      })
    }).then(async res => {
      const txt = await res.text();
      try {
        const json = JSON.parse(txt);
        if (json.status === 'error') { showToast(json.message || 'Error', 'error'); document.getElementById('bulkSpinner').classList.add('d-none'); return; }
        const tbody = document.querySelector('#scoresEntryTable tbody');
        tbody.innerHTML = '';
        json.students.forEach(s => {
          const ca = Number.isFinite(Number(s.class_score)) ? Number(s.class_score) : 0;
          const ex = Number.isFinite(Number(s.exam_score)) ? Number(s.exam_score) : 0;
          const total = ca + ex;
          const tr = document.createElement('tr');
          tr.dataset.id = s.id;
          tr.innerHTML = `<td>${escapeHtml(String(s.name||''))}</td>
                          <td><input type="number" min="0" max="${MAX_CA}" class="form-control class_score" value="${ca}"></td>
                          <td><input type="number" min="0" max="${MAX_EXAM}" class="form-control exam_score" value="${ex}"></td>
                          <td class="total-cell">${total}</td>`;
          tbody.appendChild(tr);
        });
        document.getElementById('scoresEntrySection').style.display = 'block';
      } catch(e) {
        showToast('Invalid server response while loading students','error'); console.error(e, txt);
      }
      document.getElementById('bulkSpinner').classList.add('d-none');
    }).catch(err => {
      showToast('Network error while fetching students','error'); console.error(err);
      document.getElementById('bulkSpinner').classList.add('d-none');
    });
  }

  function escapeHtml(s=''){ return String(s).replace(/[&<>"'`=\/]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;'})[c]); }

  // Input listener: recalc totals on change
  document.addEventListener('input', e=>{
    const t = e.target;
    if (!t) return;
    const row = t.closest('tr');
    if (!row) return;
    const caEl = row.querySelector('.class_score'), exEl = row.querySelector('.exam_score');
    if (!caEl || !exEl) return;
    let ca = parseInt(caEl.value) || 0, ex = parseInt(exEl.value) || 0;
    if (ca < 0) { ca = 0; caEl.value = 0; }
    if (ex < 0) { ex = 0; exEl.value = 0; }
    if (ca > MAX_CA) { ca = MAX_CA; caEl.value = MAX_CA; showToast(`Class score max ${MAX_CA}`,'error'); }
    if (ex > MAX_EXAM) { ex = MAX_EXAM; exEl.value = MAX_EXAM; showToast(`Exam score max ${MAX_EXAM}`,'error'); }
    row.querySelector('.total-cell').textContent = (ca + ex);
  });

  // Bulk save
  document.getElementById('bulkScoresForm')?.addEventListener('submit', e=>{
    e.preventDefault();
    updateSelectionsFromUI();
    if (!selectedClass || !selectedSubject || !selectedYear || !selectedTerm) { showToast('Please select filters','info'); return; }
    const rows = Array.from(document.querySelectorAll('#scoresEntryTable tbody tr'));
    const payload = [];
    for (const tr of rows) {
      const id = tr.dataset.id;
      const ca = parseInt(tr.querySelector('.class_score')?.value) || 0;
      const ex = parseInt(tr.querySelector('.exam_score')?.value) || 0;
      if (ca > MAX_CA || ex > MAX_EXAM) { showToast('Scores exceed allowed limits','error'); return; }
      payload.push({ student_id: id, class_score: ca, exam_score: ex });
    }
    if (!payload.length) { showToast('No scores to save','info'); return; }

    document.getElementById('saveBulkBtn').disabled = true;
    document.getElementById('bulkSpinner').classList.remove('d-none');

    const body = new URLSearchParams({
      action: 'save_bulk_scores',
      class_id: selectedClass,
      subject_id: selectedSubject,
      year_id: selectedYear,
      term_id: selectedTerm,
      scores: JSON.stringify(payload),
      csrf_token: getCsrf()
    }).toString();

    fetch('score_action.php', { method:'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body })
      .then(async r => { const t = await r.text(); try { return JSON.parse(t); } catch(e){ throw new Error('Invalid JSON'); } })
      .then(json => {
        document.getElementById('saveBulkBtn').disabled = false;
        document.getElementById('bulkSpinner').classList.add('d-none');
        if (json.status === 'success') {
          showToast('Scores saved successfully','success');
          loadEntryTable(); loadScoresTable();
        } else showToast(json.message||'Failed to save','error');
      }).catch(err=>{ document.getElementById('saveBulkBtn').disabled = false; document.getElementById('bulkSpinner').classList.add('d-none'); showToast(err.message||'Save failed','error'); console.error(err); });
  });

  // Edit handler (from table action)
  document.addEventListener('click', e=>{
    const el = e.target;
    if (!el) return;
    if (el.classList.contains('editScoreBtn')) {
      const rowData = el.getAttribute('data-row');
      let row = null;
      try { row = JSON.parse(rowData); } catch(e){ showToast('Invalid row','error'); return; }
      document.getElementById('edit_score_id').value = row.id;
      document.getElementById('edit_class_score').value = row.class_score ?? 0;
      document.getElementById('edit_exam_score').value = row.exam_score ?? 0;
      const modal = new bootstrap.Modal(document.getElementById('editScoreModal'));
      modal.show();
    }
  });

  // Edit submit
  document.getElementById('editScoreForm')?.addEventListener('submit', e=>{
    e.preventDefault();
    const id = document.getElementById('edit_score_id').value;
    const ca = parseInt(document.getElementById('edit_class_score').value) || 0;
    const ex = parseInt(document.getElementById('edit_exam_score').value) || 0;
    if (ca > MAX_CA || ex > MAX_EXAM) { showToast('Scores exceed allowed limits','error'); return; }
    const body = new URLSearchParams({
      action: 'edit_score',
      id, class_score: ca, exam_score: ex, csrf_token: getCsrf()
    }).toString();
    fetch('score_action.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body })
      .then(async r => { const t = await r.text(); try { return JSON.parse(t); } catch(e){ throw new Error('Invalid JSON'); } })
      .then(json => {
        if (json.status === 'success') {
          showToast('Score updated','success');
          loadEntryTable(); loadScoresTable();
          const inst = bootstrap.Modal.getInstance(document.getElementById('editScoreModal'));
          if (inst) inst.hide();
        } else showToast(json.message||'Update failed','error');
      }).catch(err=>{ showToast('Update failed','error'); console.error(err); });
  });

  // Export CSV (backend also enforces teacher ownership)
  document.getElementById('exportScoresBtn')?.addEventListener('click', ()=>{
    updateSelectionsFromUI();
    if (!selectedClass || !selectedSubject || !selectedYear || !selectedTerm) {
      showToast('Select filters to export','info'); return;
    }
    const url = `score_action.php?action=export_scores&class_id=${encodeURIComponent(selectedClass)}&subject_id=${encodeURIComponent(selectedSubject)}&year_id=${encodeURIComponent(selectedYear)}&term_id=${encodeURIComponent(selectedTerm)}`;
    window.open(url, '_blank');
  });

  // Select change hooks
  ['class_id','subject_id','year_id','term_id'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', () => {
      updateSelectionsFromUI();
      loadEntryTable(); loadScoresTable();
    });
  });

  // initial
  updateSelectionsFromUI();
  loadScoresTable(); // empty until filters selected
});
</script>

<?php include __DIR__ . '/../../admin/partials/footer_stub.php'; ?>

<?php include __DIR__.'/../../pwa-footer.php'; ?>\n</body>
</html>
