<?php
// admin/students/students.php
session_start();
require_once __DIR__ . '/../../includes/db.php';

// Auth: Admin or Teacher
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Admin','Teacher'])) {
    header('Location: ../../auth/login.php?error=Please+login');
    exit;
}

// Fetch classes for dropdowns (we'll also support fetching classes via AJAX)
$classes = [];
$res = $conn->query("SELECT id, class_name, stream FROM classes ORDER BY class_name, stream");
while ($row = $res->fetch_assoc()) {
    $row['label'] = trim($row['class_name'] . (!empty($row['stream']) ? ' ' . $row['stream'] : ''));
    $classes[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__.'/../../pwa-head.php'; ?>

  <meta charset="UTF-8">
  <title>Students Management</title>
   <link rel="icon" type="image/png" href="../../favicon-96x96.png" sizes="96x96" />
  <link rel="icon" type="image/svg+xml" href="../../favicon.svg" />
  <link rel="shortcut icon" href="../../favicon.ico" />
  <link rel="apple-touch-icon" sizes="180x180" href="../../apple-touch-icon.png" />
  <link rel="manifest" href="../../site.webmanifest" />
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap (kept CDN as before) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <!-- DataTables + Buttons -->
  <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css" rel="stylesheet">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css" rel="stylesheet">

  <style>
    :root{
      --brand: #0d6efd;          /* primary blue */
      --brand-600: #0b5ed7;
      --muted: #6c757d;
      --card-bg: #ffffff;
      --rounded-lg: 0.8rem;
    }

    /* Page base */
    body {
      background: #f6f8fb;
      color: #222;
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    h2 {
      color: var(--brand-600);
      font-weight: 600;
      margin-bottom: 0;
    }

    .card {
      border-radius: var(--rounded-lg);
      border: 0;
    }

    .card.shadow-sm {
      box-shadow: 0 6px 18px rgba(13, 110, 253, 0.06);
    }

    /* Compact icon buttons */
    .btn-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: .5rem;
      padding: .45rem .6rem;
      font-size: .95rem;
      border-radius: .6rem;
      min-width: 44px;
      transition: transform .06s ease, box-shadow .12s ease;
      box-shadow: none;
    }
    .btn-icon .label {
      display: none;
    }
    @media (min-width: 576px) {
      .btn-icon .label { display: inline-block; }
      .btn-icon { padding: .45rem .8rem; }
    }
    .btn-icon:active { transform: translateY(1px); }
    .btn-icon:focus { box-shadow: 0 0 0 .15rem rgba(13,110,253,.12); outline: none; }

    /* Specific colors */
    .btn-add { background: var(--brand); color: #fff; border: 0; }
    .btn-import { background: #ffffff; color: var(--brand); border: 1px solid rgba(13,110,253,.12); }
    .btn-promote { background: #198754; color: #fff; border: 0; }
    .btn-transfer { background: #fd7e14; color: #fff; border: 0; }

    /* Search width handling */
    .search-wrap { max-width: 360px; min-width: 180px; width: 100%; }
    @media (max-width: 576px) {
      .search-wrap { max-width: none; width: 100%; }
    }

    /* Table usability */
    .table-responsive { overflow: auto; -webkit-overflow-scrolling: touch; }
    table.dataTable td { white-space: nowrap; vertical-align: middle; }
    .img-small { height:40px; width:40px; object-fit:cover; border-radius:6px; }
    .dt-buttons .btn { margin-right: 6px; }

    /* Hover highlight row */
    #studentsTable tbody tr:hover {
      background: rgba(13,110,253,0.03);
    }

    /* Modal polish */
    .modal-header.bg-primary {
      background: linear-gradient(90deg, var(--brand), var(--brand-600));
    }
    .modal-title { font-weight:600; color:#fff; }
    .modal-body .form-text { color: var(--muted); }

    #preview { max-height: 110px; display:none; }

    /* Small-screen adjustments */
    @media (max-width: 768px) {
      .action-bar {
        width: 100%;
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
      }
      .action-bar .btn { flex: 1 1 auto; min-width: 0; }
      .btn-icon { justify-content: center; }
      .d-flex.align-items-center.mb-3 { gap: 8px; }
    }

    @media (max-width: 420px) {
      h2 { font-size: 1.05rem; }
      .btn { font-size: .86rem; padding: .36rem .5rem; }
    }

    /* subtle animations */
    .fade-in { animation: fadeIn .18s ease both; }
    @keyframes fadeIn { from { opacity:0; transform: translateY(3px);} to { opacity:1; transform:none; } }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/../partials/header_stub.php'; ?>

<div class="container-fluid p-3 p-md-4">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="me-2">Students Management</h2>

    <div class="action-bar d-flex gap-2 align-items-center">
      <!-- Compact icon buttons -->
      <button id="addStudentBtn" class="btn btn-add btn-icon" data-bs-toggle="modal" data-bs-target="#studentModal" title="Add student">
        <i class="fa fa-plus"></i><span class="label"> Add</span>
      </button>

      <button id="importBtn" class="btn btn-import btn-icon" data-bs-toggle="modal" data-bs-target="#importModal" title="Import students">
        <i class="fa fa-file-import"></i><span class="label"> Import</span>
      </button>

      <button id="promoteBtn" class="btn btn-promote btn-icon" data-bs-toggle="modal" data-bs-target="#promoteModal" title="Bulk promote">
        <i class="fa fa-level-up-alt"></i><span class="label"> Promote</span>
      </button>

      <button id="transferBtn" class="btn btn-transfer btn-icon" data-bs-toggle="modal" data-bs-target="#transferModal" title="Bulk transfer">
        <i class="fa fa-exchange-alt"></i><span class="label"> Transfer</span>
      </button>
    </div>
  </div>

  <div class="card shadow-sm fade-in">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <div class="search-wrap" style="flex: 1 1 320px;">
          <input id="studentSearch" class="form-control" placeholder="Live search...">
        </div>

        <div class="text-end ms-auto">
          <small class="text-muted">Select rows using checkboxes for bulk actions</small>
        </div>
      </div>

      <div class="table-responsive">
        <table id="studentsTable" class="table table-bordered table-hover w-100">
          <thead class="table-light">
            <tr>
              <th style="width:36px;"><input type="checkbox" id="selectAllRows" title="Select all"></th>
              <th style="width:56px;">Photo</th>
              <th>Admission No</th>
              <th>Full Name</th>
              <th>Gender</th>
              <th>DOB</th>
              <th>Address</th>
              <th>Class</th>
              <th>Guardian</th>
              <th>G. Phone</th>
              <th style="width:110px;">Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Student Modal (Add / Edit) -->
<div class="modal fade" id="studentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form id="studentForm" enctype="multipart/form-data" class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="studentModalTitle">Add / Edit Student</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="student_id">
        <input type="hidden" name="existing_photo" id="existing_photo">

        <div class="row g-3 mb-2">
          <div class="col-12 col-md-4">
            <label class="form-label">Admission No</label>
            <input type="text" name="admission_no" id="admission_no" class="form-control" placeholder="Leave blank to auto-generate">
            <div class="form-text">Auto-generated if blank</div>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">First Name *</label>
            <input type="text" name="first_name" id="first_name" class="form-control" required>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Last Name</label>
            <input type="text" name="last_name" id="last_name" class="form-control">
          </div>
        </div>

        <div class="row g-3 mb-2">
          <div class="col-12 col-md-4">
            <label class="form-label">Gender *</label>
            <select name="gender" id="gender" class="form-select" required>
              <option value="">--Select--</option>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">DOB *</label>
            <input type="date" name="dob" id="dob" class="form-control" required>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Class *</label>
            <select name="class_id" id="class_id" class="form-select" required>
              <option value="">--Select--</option>
              <?php foreach ($classes as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="row g-3 mb-2">
          <div class="col-12 col-md-8">
            <label class="form-label">Address</label>
            <input type="text" name="address" id="address" class="form-control">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Photo</label>
            <input type="file" name="photo" id="photo" class="form-control" accept="image/*">
            <img id="preview" src="#" class="img-thumbnail mt-2" style="display:none; max-height:120px;">
          </div>
        </div>

        <h5 class="mt-3">Guardian Information</h5>
        <div class="row g-3 mb-2">
          <div class="col-12 col-md-6">
            <label class="form-label">Guardian Name *</label>
            <input type="text" name="guardian_name" id="guardian_name" class="form-control" required>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Guardian Phone *</label>
            <input type="text" name="guardian_phone" id="guardian_phone" class="form-control" pattern="\d{6,15}" title="Digits only" required>
          </div>
        </div>

        <div id="formAlert"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" id="saveStudentBtn" class="btn btn-success btn-icon">
          <i class="fa fa-save"></i> <span class="label"> Save</span>
        </button>
        <button type="button" class="btn btn-secondary btn-icon" data-bs-dismiss="modal">
          <i class="fa fa-times"></i> <span class="label"> Close</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable">
    <form id="importForm" class="modal-content" enctype="multipart/form-data">
      <input type="hidden" name="action" value="bulk_import">
      <div class="modal-header"><h5 class="modal-title">Import Students (CSV / XLSX)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <button id="downloadTemplate" type="button" class="btn btn-outline-primary btn-sm">Download Template (.csv)</button>
        </div>
        <div class="mb-3">
          <label>Upload File</label>
          <input type="file" name="file" id="import_file" class="form-control" accept=".csv, .xlsx" required>
          <div class="form-text">Columns: admission_no (opt), first_name, last_name, gender, dob(YYYY-MM-DD), class_id, guardian_name, guardian_phone, address, photo_filename (optional)</div>
        </div>

        <div class="mb-3">
          <label>Optional: Upload images ZIP (photo files referenced by CSV)</label>
          <input type="file" name="images_zip" id="images_zip" class="form-control" accept=".zip">
          <div class="form-text">Include photos in ZIP with filenames matching CSV.</div>
        </div>

        <div id="importProgress" class="mt-3" style="display:none;">
          <div class="progress">
            <div id="importProgressBar" class="progress-bar" role="progressbar" style="width:0%">0%</div>
          </div>
          <div id="importStatus" class="small mt-2"></div>
        </div>

        <div id="importAlert"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" id="doImportBtn" class="btn btn-primary">Import</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Bulk Promote Modal -->
<div class="modal fade" id="promoteModal" tabindex="-1">
  <div class="modal-dialog">
    <form id="promoteForm" class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Bulk Promote</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Select target class to promote selected students into:</p>
        <select id="promoteTargetClass" class="form-select" required>
          <option value="">-- Select target class --</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text mt-2">Only selected rows will be promoted. Use the checkboxes in the table.</div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-success">Promote</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Bulk Transfer Modal -->
<div class="modal fade" id="transferModal" tabindex="-1">
  <div class="modal-dialog">
    <form id="transferForm" class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Bulk Transfer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Select destination class to transfer selected students into:</p>
        <select id="transferTargetClass" class="form-select" required>
          <option value="">-- Select destination class --</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text mt-2">Only selected rows will be transferred.</div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-warning">Transfer</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Bootstrap Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="confirmTitle">Confirm</h5></div>
      <div class="modal-body" id="confirmBody">Are you sure?</div>
      <div class="modal-footer">
        <button type="button" id="confirmCancel" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" id="confirmOk" class="btn btn-danger">Yes</button>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../partials/footer_stub.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- DataTables + Buttons -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

<!-- Optional: bootstrap-select -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>

<!-- Module JS (your existing module kept) -->
<script src="students.js"></script>

<!-- Inline enhancements: responsive + small UI behavior -->
<script>
  (function(){
    // Tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'))
    tooltipTriggerList.forEach(function (el) {
      new bootstrap.Tooltip(el, {boundary: 'viewport'});
    });


    // attach buttons to bootstrap styling
    table.buttons().container().addClass('dt-buttons mb-2');

    // Live search input (external)
    $('#studentSearch').on('input', function(){
      table.search(this.value).draw();
    });

    // Select all / individual checkboxes
    $(document).on('change', '#selectAllRows', function(){
      var checked = $(this).is(':checked');
      $('#studentsTable tbody input[type="checkbox"]').prop('checked', checked);
      // optional: highlight rows
      $('#studentsTable tbody tr').toggleClass('table-active', checked);
    });

    // Row checkbox -> highlight
    $(document).on('change', '#studentsTable tbody input[type="checkbox"]', function(){
      $(this).closest('tr').toggleClass('table-active', $(this).is(':checked'));
    });

    // Image preview
    $('#photo').on('change', function(e){
      var file = this.files && this.files[0];
      if (!file) { $('#preview').hide(); return; }
      var url = URL.createObjectURL(file);
      $('#preview').attr('src', url).show();
    });

    // Reset form when modal hides
    $('#studentModal').on('hidden.bs.modal', function () {
      $('#studentForm')[0].reset();
      $('#preview').hide();
      $('#formAlert').empty();
      $('#student_id').val('');
      $('#existing_photo').val('');
    });

    // Improve button accessibility on small screens
    function adjustActionBar(){
      if (window.innerWidth <= 576){
        $('.action-bar .btn').each(function(){
          // show icons only (labels are hidden by CSS), make them full width
          $(this).addClass('w-100');
        });
      } else {
        $('.action-bar .btn').removeClass('w-100');
      }
    }
    $(window).on('resize', adjustActionBar);
    adjustActionBar();

    // small helper: download template stub (you can wire to actual endpoint)
    $('#downloadTemplate').on('click', function(){
      // If you have a template file, change the URL below.
      var link = document.createElement('a');
      link.href = 'templates/students_template.csv'; // <-- adjust path if you host template
      link.download = 'students_template.csv';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    });

    // subtle entry animation for table once data loads (if students.js fills table later, call tableDrawn())
    window.tableDrawn = function(){
      $('#studentsTable').closest('.table-responsive').addClass('fade-in');
    }

    // If your students.js relies on global table variable, expose it:
    window.studentsDataTable = table;
  })();
</script>

<?php include __DIR__.'/../../pwa-footer.php'; ?>\n</body>
</html>
