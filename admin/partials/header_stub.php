<?php
// admin/partials/header_stub.php
// Role-aware header + sidebar + topbar + global preloader
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/db.php';

// ===== Determine application base URL early so redirects & assets resolve correctly =====
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
if (str_contains($scriptName, '/foase_exam_report_system')) {
    $pos = strpos($scriptName, '/foase_exam_report_system');
    $baseUrl = substr($scriptName, 0, $pos + strlen('/foase_exam_report_system')) . '/';
} else {
    $baseUrl = rtrim(str_replace('\\', '/', dirname($scriptName)), '/') . '/';
    if ($baseUrl === '//') {
        $baseUrl = '/';
    }
}

// ===== Auth (Admin, Teacher, Student, Parent) =====
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Teacher', 'Student', 'Parent'])) {
    header('Location: ' . $baseUrl . 'auth/login.php?error=Please+login');
    exit;
}

$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'User';

// ===== Ensure photo_path column exists (non-blocking) =====
try {
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'photo_path'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN photo_path VARCHAR(255) NULL DEFAULT '/assets/images/default_user.png'");
    }
} catch (Throwable $e) {}

// ===== Fetch user info =====
$stmt = $conn->prepare("SELECT username, first_name, last_name, photo_path FROM users WHERE id=? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc() ?: [
    'username'    => $_SESSION['username'] ?? 'User',
    'first_name'  => $_SESSION['first_name'] ?? 'User',
    'last_name'   => $_SESSION['last_name'] ?? '',
    'photo_path'  => '/assets/images/default_user.png'
];
$stmt->close();

// ===== Role-specific dashboard title =====
$roleText = match ($role) {
    'Admin'   => 'Admin Dashboard | FOASE M/A JHS',
    'Teacher' => 'Teacher Dashboard | FOASE M/A JHS',
    'Student' => 'Student Dashboard | FOASE M/A JHS',
    'Parent'  => 'Parent Dashboard | FOASE M/A JHS',
    default   => 'Dashboard | FOASE M/A JHS',
};

// ===== Normalize navbar photo path and store in session =====
$navPhoto = $_SESSION['photo_path'] ?? $user['photo_path'] ?? '/assets/images/default_user.png';
if (empty($navPhoto)) {
    $navPhoto = $baseUrl . 'assets/images/default_user.png';
} elseif (!preg_match('#^https?://#i', $navPhoto)) {
    $p = str_replace('\\', '/', $navPhoto);
    $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
    if ($docRoot !== '' && str_starts_with($p, $docRoot)) {
        $web = substr($p, strlen($docRoot));
        $web = '/' . ltrim($web, '/');
        $navPhoto = $web;
    } elseif (str_starts_with($p, '/')) {
        if (str_starts_with($p, $baseUrl)) {
            $navPhoto = $p;
        } else {
            $navPhoto = $baseUrl . ltrim($p, '/');
        }
    } else {
        $navPhoto = $baseUrl . ltrim($p, '/');
    }
}
if (!preg_match('#^https?://#i', $navPhoto) && !str_starts_with($navPhoto, '/')) {
    $navPhoto = '/' . ltrim($navPhoto, '/');
}
$_SESSION['photo_path'] = $navPhoto;

$displayName = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
$displayRole = htmlspecialchars($role);
$appLogoPath = rtrim($baseUrl, '/') . '/assets/images/logo.png'; // uses your provided logo
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
  <?php include __DIR__.'/../../pwa-head.php'; ?>

  <meta charset="utf-8">
 
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="FOASE M/A JHS Exam Report System Administration Panel">
  <meta name="theme-color" content="#0d47a1">
  <link rel="icon" type="image/png" href="../../favicon-96x96.png" sizes="96x96" />
  <link rel="icon" type="image/svg+xml" href="../../favicon.svg" />
  <link rel="shortcut icon" href="../../favicon.ico" />
  <link rel="apple-touch-icon" sizes="180x180" href="../../apple-touch-icon.png" />
  <link rel="manifest" href="../../site.webmanifest" />

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <link href="<?= $baseUrl ?>assets/css/header_stub.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script>window.APP_BASE_URL = '<?= rtrim($baseUrl, "/") ?>';</script>
  <script src="<?= $baseUrl ?>assets/js/navbar.js" defer></script>
  <style>body{font-family:'Poppins',system-ui,-apple-system,sans-serif;background:#f4f7fb;}</style>
</head>
<body>
<!-- Preloader -->
<div id="adminPreloader" role="status" aria-live="polite">
  <div class="loader-box"><img class="spin-logo" src="<?= $appLogoPath ?>" alt="logo"></div>
  <div class="loader-text">FOASE M/A JHS – Exam Report System</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  setTimeout(()=>{ const p = document.getElementById('adminPreloader'); if(p){ p.style.transition='opacity .6s'; p.style.opacity=0; setTimeout(()=>p.remove(),700); } }, 800);
});
</script>

<!-- Sidebar -->
<div class="sidebar" id="sidebar" role="navigation" aria-label="Main Navigation">
  <!-- Top: logo + school name (bold uppercase) -->
  <div class="sidebar-header text-center py-4">
    <img src="<?= $appLogoPath ?>" alt="FOASE logo" class="school-logo-lg">
    <div class="school-title-lg">FOASE M/A JHS</div>
    <div class="school-subtitle">Exam Report System</div>
  </div>

  <!-- Search -->
  <div class="px-3 mb-2">
    <div class="input-group input-group-sm">
      <input id="sidebarSearch" type="search" class="form-control form-control-sm" placeholder="Search menu..." aria-label="Search navigation">
      <button id="sidebarSearchClear" class="btn btn-sm btn-outline-light" type="button" title="Clear search" aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button>
    </div>
  </div>

  <nav class="nav flex-column px-2" id="mainNav" aria-label="Sidebar menu">
    <?php if ($role === 'Admin'): ?>
      <a class="nav-link" title="Dashboard" data-bs-toggle="tooltip" href="<?= $baseUrl ?>admin/dashboard/dashboard.php"><i class="fa-solid fa-chart-simple"></i> <span class="nav-label">Dashboard</span></a>
      <div class="nav-section">Management</div>
      <a class="nav-link" title="Students" data-bs-toggle="tooltip" href="<?= $baseUrl ?>admin/students/students.php"><i class="fa-solid fa-users"></i> <span class="nav-label">Students</span></a>
      <a class="nav-link" title="Users" data-bs-toggle="tooltip" href="<?= $baseUrl ?>admin/users/users.php"><i class="fa-solid fa-user-gear"></i> <span class="nav-label">Users</span></a>
      <a class="nav-link" title="Teachers" data-bs-toggle="tooltip" href="<?= $baseUrl ?>admin/teachers/teachers.php"><i class="fa-solid fa-chalkboard-user"></i> <span class="nav-label">Teachers</span></a>
      <a class="nav-link" title="Subjects" data-bs-toggle="tooltip" href="<?= $baseUrl ?>admin/subjects/subjects.php"><i class="fa-solid fa-book-open"></i> <span class="nav-label">Subjects</span></a>
      <a class="nav-link" title="Classes" data-bs-toggle="tooltip" href="<?= $baseUrl ?>admin/classes/classes.php"><i class="fa-solid fa-school"></i> <span class="nav-label">Classes</span></a>
      <a class="nav-link" title="Settings" data-bs-toggle="tooltip" href="<?= $baseUrl ?>admin/settings/settings.php"><i class="fa-solid fa-gear"></i> <span class="nav-label">Settings</span></a>
      <div class="nav-section">Results</div>
      <a class="nav-link" title="Enter Scores" data-bs-toggle="tooltip" href="<?= $baseUrl ?>admin/scores/scores.php"><i class="fa-solid fa-pen-to-square"></i> <span class="nav-label">Enter Scores</span></a>
      <a class="nav-link" title="Reports" data-bs-toggle="tooltip" href="<?= $baseUrl ?>admin/reports/reports.php"><i class="fa-solid fa-file-export"></i> <span class="nav-label">Reports</span></a>
    <?php elseif ($role === 'Teacher'): ?>
      <a class="nav-link" title="Dashboard" data-bs-toggle="tooltip" href="<?= $baseUrl ?>teacher/modules/dashboard.php"><i class="fa-solid fa-chart-simple"></i> <span class="nav-label">Dashboard</span></a>
      <a class="nav-link" title="Classes" data-bs-toggle="tooltip" href="<?= $baseUrl ?>teacher/modules/classes.php"><i class="fa-solid fa-school"></i> <span class="nav-label">Classes</span></a>
      <a class="nav-link" title="Students" data-bs-toggle="tooltip" href="<?= $baseUrl ?>teacher/modules/students.php"><i class="fa-solid fa-users"></i> <span class="nav-label">Students</span></a>
      <a class="nav-link" title="Enter Scores" data-bs-toggle="tooltip" href="<?= $baseUrl ?>teacher/modules/enter_scores.php"><i class="fa-solid fa-pen-to-square"></i> <span class="nav-label">Enter Scores</span></a>
      <a class="nav-link" title="Reports" data-bs-toggle="tooltip" href="<?= $baseUrl ?>teacher/modules/reports.php"><i class="fa-solid fa-file-export"></i> <span class="nav-label">Reports</span></a>
    <?php elseif ($role === 'Student'): ?>
      <a class="nav-link" title="Dashboard" data-bs-toggle="tooltip" href="<?= $baseUrl ?>student/dashboard.php"><i class="fa-solid fa-chart-simple"></i> <span class="nav-label">Dashboard</span></a>
      <a class="nav-link" title="Reports" data-bs-toggle="tooltip" href="<?= $baseUrl ?>student/reports/reports.php"><i class="fa-solid fa-file-export"></i> <span class="nav-label">Reports</span></a>
    <?php elseif ($role === 'Parent'): ?>
      <a class="nav-link" title="Dashboard" data-bs-toggle="tooltip" href="<?= $baseUrl ?>parent/dashboard.php"><i class="fa-solid fa-chart-simple"></i> <span class="nav-label">Dashboard</span></a>
      <a class="nav-link" title="Reports" data-bs-toggle="tooltip" href="<?= $baseUrl ?>parent/reports/reports.php"><i class="fa-solid fa-file-export"></i> <span class="nav-label">Reports</span></a>
    <?php endif; ?>

    <div class="nav-section mt-2">Account</div>
    <a class="nav-link" title="Profile" data-bs-toggle="tooltip" href="<?= $baseUrl ?>profile/profile.php"><i class="fa-solid fa-user"></i> <span class="nav-label">Profile</span></a>
    <a class="nav-link" title="Logout" data-bs-toggle="tooltip" href="<?= $baseUrl ?>logout.php"><i class="fa-solid fa-right-from-bracket"></i> <span class="nav-label">Logout</span></a>
  </nav>
</div>

<!-- overlay -->
<div class="app-overlay" id="appOverlay" aria-hidden="true"></div>

<!-- Main -->
<div class="main" id="main">
  <div class="topbar">
    <div class="d-flex align-items-center gap-3">
      <button id="menuBtn" aria-controls="sidebar" aria-expanded="true" class="btn btn-light btn-sm" title="Toggle sidebar"><i class="fa-solid fa-bars"></i></button>
      <div class="d-flex align-items-center gap-2">
        <img src="<?= $appLogoPath ?>" alt="logo" class="topbar-logo">
        <div class="topbar-title">
          <div class="title-main">FOASE M/A JHS – Exam Report System</div>
          <div class="title-sub">FOASE M/A JUNIOR HIGH SCHOOL</div>
        </div>
      </div>
    </div>

    <div class="d-flex align-items-center gap-3">
      <div class="me-2 theme-toggle" title="Toggle theme" role="button" aria-pressed="false" id="themeToggle" tabindex="0">
        <i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i>
      </div>

      <div class="dropdown user-dropdown">
        <a class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false">
          <img id="navbarProfileImg" src="<?= htmlspecialchars($navPhoto) ?>" alt="Profile" class="profile-img">
          <div class="ms-2 d-none d-md-block text-start">
            <div style="font-weight:700;"><?= $displayName ?></div>
            <small style="color:rgba(255,255,255,0.9)"><?= $displayRole ?></small>
          </div>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?= $baseUrl ?>profile/profile.php"><i class="fa-solid fa-user me-2"></i> Profile</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="<?= $baseUrl ?>logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i> Logout</a></li>
        </ul>
      </div>
    </div>
  </div>

  <div class="container-fluid p-4">
