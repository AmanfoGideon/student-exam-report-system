<?php
// admin/users/users.php
session_start();
require_once __DIR__ . '/../../includes/db.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

// fetch roles for select
$roles = [];
$res = $conn->query("SELECT id, role_name FROM roles ORDER BY role_name");
while ($r = $res->fetch_assoc()) $roles[] = $r;
?>
<!doctype html>
<html lang="en">
<head>
  <?php include __DIR__.'/../../pwa-head.php'; ?>

  <meta charset="utf-8">
  <title>Users - Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/png" href="../../favicon-96x96.png" sizes="96x96" />
  <link rel="icon" type="image/svg+xml" href="../../favicon.svg" />
  <link rel="shortcut icon" href="../../favicon.ico" />
  <link rel="apple-touch-icon" sizes="180x180" href="../../apple-touch-icon.png" />
  <link rel="manifest" href="../../site.webmanifest" />
  <!-- CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css" rel="stylesheet">

  <style>
  /* Make tables responsive */
  .table-responsive {
    overflow-x: auto;
  }

  table.dataTable thead th,
  table.dataTable tbody td {
    white-space: nowrap;
  }

  /* Top toolbar mobile layout fix */
  @media (max-width: 992px) {
    .d-flex.justify-content-between.align-items-center {
      flex-direction: column;
      align-items: flex-start !important;
      gap: 10px;
    }
    .d-flex > .form-select,
    .d-flex > #userSearch,
    .d-flex > #btnAddUser {
      width: 100% !important;
      margin-bottom: 10px;
    }
  }

  /* Card layout fixes */
  .card {
    box-shadow: rgba(0, 0, 0, 0.08) 0px 2px 6px;
    border-radius: 6px;
  }

  /* Modal responsiveness */
  @media (max-width: 768px) {
    .modal-dialog {
      width: 95% !important;
      margin: auto;
    }
    .row.g-3 > div {
      flex: 0 0 100%;
      max-width: 100%;
    }
  }

  /* Form input consistency */
  input.form-control,
  select.form-control {
    font-size: 14px;
  }

  /* Pagination responsiveness */
  @media (max-width: 576px) {
    .pagination {
      flex-wrap: wrap;
    }
  }

  /* Table small fix */
  @media (max-width: 576px) {
    .table td,
    .table th {
      font-size: 13px;
      padding: 6px;
    }
  }
</style>

</head>
<body>
<?php include __DIR__ . '/../partials/header_stub.php'; ?>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="m-0">User Management</h3>
    <div class="d-flex flex-wrap gap-2">
      <!-- role filter -->
      <select id="roleFilter" class="form-select form-select-sm me-2" style="width:180px">
        <option value="">-- All Roles --</option>
        <?php foreach ($roles as $r): ?>
          <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input id="userSearch" class="form-control d-inline-block me-2" style="width:260px" placeholder="Search users...">
      <button id="btnAddUser" class="btn btn-primary"><i class="fa fa-plus"></i> Add User</button>
    </div>
  </div>

  <div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table id="usersTable" class="table table-striped table-sm mb-0">
        <thead>
          <tr>
            <th>#</th>
            <th>Full Name</th>
            <th>Username</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Role</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr><td colspan="7" class="text-center py-3">Loading...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card-footer">
    <div id="userTableInfo" class="small text-muted"></div>
    <ul class="pagination justify-content-center mb-0" id="pagination"></ul>
  </div>
</div>

</div>

<!-- Add/Edit User Modal -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form id="userForm" class="modal-content" autocomplete="off">
      <div class="modal-header">
        <h5 class="modal-title" id="userModalTitle">Add User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="user_id">
        <div id="userFormAlert"></div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">First Name *</label>
            <input class="form-control" name="first_name" id="first_name" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Last Name</label>
            <input class="form-control" name="last_name" id="last_name">
          </div>
          <div class="col-md-6">
            <label class="form-label">Username *</label>
            <input class="form-control" name="username" id="username" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input class="form-control" name="email" id="email" type="email">
          </div>
          <div class="col-md-6">
            <label class="form-label">Phone</label>
            <input class="form-control" name="phone" id="phone">
          </div>
          <div class="col-md-6">
            <label class="form-label">Role *</label>
            <select class="form-control" name="role_id" id="role_id" required>
              <option value="">-- Select Role --</option>
              <?php foreach ($roles as $r): ?>
                <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Password <small>(leave blank if unchanged)</small></label>
            <input class="form-control" name="password" id="password" type="password">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-success" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer_stub.php'; ?>

<!-- JS libs -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

<!-- module JS -->
<script src="users.js" data-module="users" onload="window.__notifyModuleReady && window.__notifyModuleReady('users')"></script>

<?php include __DIR__.'/../../pwa-footer.php'; ?>\n</body>
</html>
