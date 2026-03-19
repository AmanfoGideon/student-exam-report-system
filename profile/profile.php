<?php
// admin/profile.php — Responsive Card layout with blue gradient theme + Toast Alerts
// + Username editing + CSRF + safer upload + delete old photo + server/client validation
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
require_once __DIR__ . '/../includes/db.php';

/**
 * Utility
 */
function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/**
 * CSRF token handling
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}
$csrf_token = $_SESSION['csrf_token'];

$user_id = (int)$_SESSION['user_id'];
$alert_html = '';
$school_name = 'School';

// fetch school name (defensive)
if ($m = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key='school_name' LIMIT 1")) {
    $m->execute();
    $r = $m->get_result()->fetch_row();
    if ($r) $school_name = $r[0];
    $m->close();
}

/**
 * Fetch user
 */
$user = [];
if ($stmt = $conn->prepare("SELECT id,username,first_name,last_name,email,phone,photo_path FROM users WHERE id=? LIMIT 1")) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
}

/**
 * Helper: validate username uniqueness
 */
function username_available($conn, $username, $user_id) {
    $username = trim($username);
    if ($q = $conn->prepare("SELECT COUNT(*) as c FROM users WHERE username=? AND id<>?")) {
        $q->bind_param('si', $username, $user_id);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $q->close();
        return ($r && $r['c'] == 0);
    }
    return false;
}

/**
 * Handle POST
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    $posted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$posted_csrf)) {
        $alert_html .= '<div class="alert alert-danger">Invalid session (CSRF). Please reload the page.</div>';
    } else {
        // Collect and validate inputs (server-side)
        $username   = trim($_POST['username'] ?? $user['username'] ?? '');
        $first_name = trim($_POST['first_name'] ?? $user['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? $user['last_name'] ?? '');
        $email      = trim($_POST['email'] ?? $user['email'] ?? '');
        $phone      = trim($_POST['phone'] ?? $user['phone'] ?? '');
        $delete_photo = !empty($_POST['delete_photo']);
        $errors = [];

        // Username validation
        if ($username === '') {
            $errors[] = "Username is required.";
        } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
            $errors[] = "Username must be 3-32 characters; letters, numbers, dot, underscore and dash allowed.";
        } elseif (!username_available($conn, $username, $user_id)) {
            $errors[] = "That username is already taken.";
        }

        // Names
        if ($first_name === '') $errors[] = "First name is required.";
        if ($last_name === '') $errors[] = "Last name is required.";

        // Email
        if ($email === '') {
            $errors[] = "Email is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email address.";
        }

        // Phone optional but normalize
        $phone = preg_replace('/\s+/', ' ', $phone);

        // File upload handling
        $uploaded_photo = null;
        $old_photo = $user['photo_path'] ?? '';

        if (!empty($_FILES['photo']['name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
            // Limits
            $max_bytes = 2 * 1024 * 1024; // 2MB
            if ($_FILES['photo']['size'] > $max_bytes) {
                $errors[] = "Profile image must be 2MB or smaller.";
            } else {
                // Use finfo to reliably detect mime
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['photo']['tmp_name']) ?: '';
                $allowed_map = [
                    'image/jpeg' => 'jpg',
                    'image/pjpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                ];
                if (!array_key_exists($mime, $allowed_map)) {
                    $errors[] = "Unsupported image type. Allowed: JPEG, PNG, GIF, WEBP.";
                } else {
                    // Create uploads directory
                    $upload_dir = __DIR__ . '/uploads/profile/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    // Sanitize and generate filename
                    $ext = $allowed_map[$mime];
                    $safe_name = 'profile_' . $user_id . '_' . time() . '.' . $ext;
                    $target = $upload_dir . $safe_name;

                    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                        $errors[] = "Failed to move uploaded file.";
                    } else {
                        // set permissions (no exec)
                        chmod($target, 0644);
                        // Store relative path (keep same pattern as original)
                        $uploaded_photo = 'uploads/profile/' . $safe_name;
                    }
                }
            }
        }

        // If requested to delete photo (and no new upload), delete old file
        if ($delete_photo && !$uploaded_photo && $old_photo) {
            $full_old = __DIR__ . '/' . $old_photo;
            if (file_exists($full_old) && is_file($full_old)) {
                @unlink($full_old);
            }
            $old_photo = '';
            $uploaded_photo = ''; // indicate to DB to clear field
        }

        // Password change handling
        $pw_changed = false;
        if (!empty($_POST['old_password']) || !empty($_POST['new_password'])) {
            $old_pw = $_POST['old_password'] ?? '';
            $new_pw = $_POST['new_password'] ?? '';
            if ($old_pw === '' || $new_pw === '') {
                $errors[] = "To change password provide both old and new password.";
            } else {
                // Fetch current hashed password
                if ($c = $conn->prepare('SELECT password FROM users WHERE id=?')) {
                    $c->bind_param('i', $user_id);
                    $c->execute();
                    $res = $c->get_result()->fetch_assoc();
                    $c->close();
                    if (!$res || !password_verify($old_pw, $res['password'])) {
                        $errors[] = "Old password is incorrect.";
                    } else {
                        // enforce basic strength server-side
                        $pw_strength_score = 0;
                        if (strlen($new_pw) >= 8) $pw_strength_score++;
                        if (preg_match('/[A-Z]/', $new_pw)) $pw_strength_score++;
                        if (preg_match('/[0-9]/', $new_pw)) $pw_strength_score++;
                        if (preg_match('/[^A-Za-z0-9]/', $new_pw)) $pw_strength_score++;

                        if ($pw_strength_score < 3) {
                            $errors[] = "New password is too weak. Use at least 8 chars, mix upper/lower, numbers or symbols.";
                        } else {
                            $h = password_hash($new_pw, PASSWORD_DEFAULT);
                            if ($u = $conn->prepare('UPDATE users SET password=? WHERE id=?')) {
                                $u->bind_param('si', $h, $user_id);
                                $u->execute();
                                $u->close();
                                $pw_changed = true;
                            } else {
                                $errors[] = "Failed to update password (DB error).";
                            }
                        }
                    }
                } else {
                    $errors[] = "Failed to validate current password (DB error).";
                }
            }
        }

        // If no errors, update user info
        if (empty($errors)) {
            // Build query depending on whether photo was uploaded/cleared
            if ($uploaded_photo === '') {
                // Clear photo_path column
                $q = $conn->prepare("UPDATE users SET username=?, first_name=?, last_name=?, email=?, phone=?, photo_path='' WHERE id=?");
                $q->bind_param('ssssi', $username, $first_name, $last_name, $email, $phone, $user_id);
                $q->execute();
                $q->close();
            } elseif ($uploaded_photo) {
                // New uploaded photo - delete old file if exists
                if ($old_photo) {
                    $full_old = __DIR__ . '/' . $old_photo;
                    if (file_exists($full_old) && is_file($full_old)) {
                        @unlink($full_old);
                    }
                }
                $q = $conn->prepare("UPDATE users SET username=?, first_name=?, last_name=?, email=?, phone=?, photo_path=? WHERE id=?");
                $q->bind_param('ssssssi', $username, $first_name, $last_name, $email, $phone, $uploaded_photo, $user_id);
                $q->execute();
                $q->close();
            } else {
                // Keep existing photo_path
                $q = $conn->prepare("UPDATE users SET username=?, first_name=?, last_name=?, email=?, phone=? WHERE id=?");
                $q->bind_param('sssssi', $username, $first_name, $last_name, $email, $phone, $user_id);
                $q->execute();
                $q->close();
            }

            // Refresh user data
            if ($r = $conn->prepare('SELECT id,username,first_name,last_name,email,phone,photo_path FROM users WHERE id=?')) {
                $r->bind_param('i', $user_id);
                $r->execute();
                $user = $r->get_result()->fetch_assoc();
                $r->close();
            }

            $alert_html .= '<div class="alert alert-success">Profile updated successfully.</div>';
            if ($pw_changed) $alert_html .= '<div class="alert alert-success">Password changed.</div>';
        } else {
            // accumulate errors
            foreach ($errors as $er) {
                $alert_html .= '<div class="alert alert-danger">' . e($er) . '</div>';
            }
            // If we uploaded a new file but had errors, remove the uploaded file to avoid orphaned files
            if (!empty($uploaded_photo) && isset($target) && file_exists($target)) {
                @unlink($target);
            }
        }
    }
}

/**
 * Decide photo src for preview
 */
$photo_src = '../assets/images/default_user.png';
if (!empty($user['photo_path'])) {
    // keep existing relative path as stored
    $photo_src = e($user['photo_path']);
}
?>
<!doctype html>
<html lang="en">
<head>\n<?php include __DIR__.'/../pwa-head.php'; ?>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Profile | <?= e($school_name) ?></title>
  <link rel="icon" type="image/png" href="../favicon-96x96.png" sizes="96x96" />
  <link rel="icon" type="image/svg+xml" href="../../favicon.svg" />
  <link rel="shortcut icon" href="../favicon.ico" />
  <link rel="apple-touch-icon" sizes="180x180" href="../apple-touch-icon.png" />
  <link rel="manifest" href="../site.webmanifest" />

  <!-- Bootstrap + icons + DataTables CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css"/>
  <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css"/>

  <style>
    :root{--g1:#0b63d6;--g2:#0059b3;--card-radius:14px}
    body{background:linear-gradient(180deg, #f6f9ff 0%, #eef4ff 100%);min-height:100vh}
    .profile-card{max-width:1100px;margin:20px auto;border-radius:var(--card-radius);overflow:hidden;background:#fff;box-shadow:0 10px 30px rgba(8,33,77,.08)}
    .profile-header{padding:20px 24px;background:linear-gradient(90deg,var(--g1),var(--g2));color:#fff;display:flex;align-items:center;gap:12px}
    .school-badge{font-weight:600;letter-spacing:.4px}
    .profile-photo{width:140px;height:140px;object-fit:cover;border-radius:12px;border:4px solid rgba(255,255,255,0.15);box-shadow:0 6px 18px rgba(11,99,214,.12)}
    .profile-body{padding:18px}
    /* responsive adjustments */
    @media (max-width:767px){
      .profile-photo{width:110px;height:110px}
      .profile-header{flex-direction:column;align-items:flex-start;gap:6px}
      .profile-body{padding:12px}
    }
    /* subtle "breathing" animation for header/logo */
    .breath {
      animation: breath 6s ease-in-out infinite;
      transform-origin:center;
    }
    @keyframes breath {
      0% { transform: translateY(0px); }
      50% { transform: translateY(-6px); }
      100% { transform: translateY(0px); }
    }
    /* toast container spacing */
    #toastContainer .toast { margin-top: 8px; }
    /* small helper */
    .muted-small{font-size:.85rem;color:#6c757d}
  </style>
</head>
<body>
  <?php include __DIR__ . '/../admin/partials/header_stub.php'; ?>

  <div class="container py-4">
    <div class="profile-card">
      <div class="profile-header">
        <div class="d-flex align-items-center">
          <div class="me-3 breath">
            <!-- placeholder square for logo if header stub doesn't provide a logo -->
            <div style="width:46px;height:46px;border-radius:8px;background:linear-gradient(135deg,#ffffff22,#ffffff11);display:flex;align-items:center;justify-content:center;">
              <i class="fa-solid fa-user text-white"></i>
            </div>
          </div>
          <div>
            <h4 class="mb-0">My Profile</h4>
            <div class="muted-small school-badge"><?= e($school_name) ?></div>
          </div>
        </div>
        <div class="ms-auto d-none d-md-block">
          <!-- action buttons (optional) -->
          <a href="../admin/dashboard/dashboard.php" class="btn btn-sm btn-light">Dashboard</a>
        </div>
      </div>

      <div class="profile-body">
        <div id="toastContainer" class="position-fixed top-0 end-0 p-3" style="z-index:9999"></div>

        <?php if ($alert_html): ?>
          <script>window.initialAlerts = `<?= str_replace("`", "\`", $alert_html) ?>`;</script>
        <?php endif; ?>

        <form id="profileForm" method="post" enctype="multipart/form-data" class="row g-4 needs-validation" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

          <div class="col-lg-4 text-center">
            <img id="photoPreview" src="<?= $photo_src ?>" class="profile-photo mb-2" alt="Profile photo">
            <div class="mb-2">
              <input type="file" name="photo" id="photoInput" class="form-control form-control-sm" accept="image/*">
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="deletePhoto" name="delete_photo" value="1">
              <label class="form-check-label muted-small" for="deletePhoto">Delete current photo</label>
            </div>
            <div class="muted-small mt-2">Allowed: JPG, PNG, GIF, WEBP. Max 2MB.</div>
          </div>

          <div class="col-lg-8">
            <div class="card p-3">
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label">Username</label>
                  <input name="username" id="username" class="form-control" minlength="3" maxlength="32" pattern="^[A-Za-z0-9_.-]{3,32}$" required value="<?= e($user['username'] ?? '') ?>">
                  <div class="invalid-feedback">Enter a valid username (3-32 chars, letters, numbers, . _ -).</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Email</label>
                  <input name="email" type="email" class="form-control" required value="<?= e($user['email'] ?? '') ?>">
                  <div class="invalid-feedback">Enter a valid email.</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">First</label>
                  <input name="first_name" class="form-control" required value="<?= e($user['first_name'] ?? '') ?>">
                  <div class="invalid-feedback">First name required.</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Last</label>
                  <input name="last_name" class="form-control" required value="<?= e($user['last_name'] ?? '') ?>">
                  <div class="invalid-feedback">Last name required.</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Phone</label>
                  <input name="phone" class="form-control" value="<?= e($user['phone'] ?? '') ?>">
                </div>

                <div class="col-12"><hr></div>

                <div class="col-md-6">
                  <label class="form-label">Old password</label>
                  <input type="password" name="old_password" class="form-control" placeholder="Enter old password if changing">
                </div>

                <div class="col-md-6">
                  <label class="form-label">New password</label>
                  <input type="password" name="new_password" id="new_password" class="form-control" placeholder="Enter new password">
                </div>

                <div class="col-12">
                  <div id="pw_strength" class="small text-muted px-2">Password strength: <span id="pw_strength_text">—</span></div>
                </div>

                <div class="col-12 text-end">
                  <button id="saveBtn" class="btn btn-primary">
                    <span id="saveSpinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                    Save
                  </button>
                </div>

              </div>
            </div>
          </div>

        </form>
      </div>
    </div>
  </div>

  <?php include __DIR__ . '/../admin/partials/footer_stub.php'; ?>

  <!-- scripts -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

  <script>
    // Toast helper
    function showToast(msg, type = 'info') {
      const t = document.createElement('div');
      t.className = `toast align-items-center text-white bg-${type} border-0`;
      t.role = 'alert';
      t.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
      document.getElementById('toastContainer').appendChild(t);
      new bootstrap.Toast(t, { delay: 4000 }).show();
    }

    // show initial alerts from server
    if (window.initialAlerts) {
      const tmp = document.createElement('div');
      tmp.innerHTML = initialAlerts;
      tmp.querySelectorAll('.alert').forEach(a => {
        let type = 'info';
        if (a.classList.contains('alert-success')) type = 'success';
        if (a.classList.contains('alert-danger')) type = 'danger';
        if (a.classList.contains('alert-warning')) type = 'warning';
        showToast(a.textContent.trim(), type);
      });
    }

    // image preview
    $('#photoInput').on('change', e => {
      const f = e.target.files[0];
      if (f) {
        const r = new FileReader();
        r.onload = v => $('#photoPreview').attr('src', v.target.result);
        r.readAsDataURL(f);
        // uncheck delete if selecting a new file
        $('#deletePhoto').prop('checked', false);
      }
    });

    // password strength (client)
    $('#new_password').on('input', function () {
      const v = this.value;
      let s = 0;
      if (v.length >= 8) s++;
      if (/[A-Z]/.test(v)) s++;
      if (/[0-9]/.test(v)) s++;
      if (/[^A-Za-z0-9]/.test(v)) s++;
      const texts = ['Very weak', 'Weak', 'Okay', 'Good', 'Strong'];
      $('#pw_strength_text').text(texts[s]);
    });

    // form validation + submit UX
    (function () {
      'use strict';
      const form = document.getElementById('profileForm');
      form.addEventListener('submit', function (event) {
        // native validation
        if (!form.checkValidity()) {
          event.preventDefault();
          event.stopPropagation();
          form.classList.add('was-validated');
          showToast('Please fix form errors before saving.', 'warning');
          return;
        }

        // disable submit while posting (prevent double submits)
        const btn = document.getElementById('saveBtn');
        const spinner = document.getElementById('saveSpinner');
        btn.disabled = true;
        spinner.classList.remove('d-none');

        // allow normal POST to server — the UX is handled here
      }, false);
    })();

    // Client-side small username uniqueness check (non-authoritative) via fetch (optional)
    // NOTE: This is optional — server enforces uniqueness. Omitted server endpoint here.
    // If you add an API endpoint /admin/ajax/check_username.php that returns JSON {available: true/false}
    // you can hook it here to give instant feedback.

  </script>

<?php include __DIR__.'/../pwa-footer.php'; ?>\n</body>
</html>
<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form id="userForm" class="modal-content needs-validation" novalidate>
      <div class="modal-header">
        <h5 class="modal-title" id="userModalLabel">Add / Edit User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <input type="hidden" name="user_id" id="user_id">

          <div class="col-md-6">
            <label class="form-label">First Name *</label>
            <input type="text" class="form-control" id="first_name" name="first_name" required>   