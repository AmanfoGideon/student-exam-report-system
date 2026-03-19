<?php
// Simple admin utility: normalize students.photo_path to filenames in uploads/
// Usage: visit in browser as an admin user or run CLI (but this script checks session role).
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../includes/db.php';

// Simple authorization: require logged-in admin
if (empty($_SESSION['user_id']) || (strtolower($_SESSION['role'] ?? '') !== 'admin' && PHP_SAPI !== 'cli')) {
    http_response_code(403);
    echo "Forbidden: admin only\n";
    exit;
}

$projectRoot = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
$uploadsDir = realpath(__DIR__ . '/../../uploads') ?: ($projectRoot . '/uploads');
if (!is_dir($uploadsDir)) {
    if (!@mkdir($uploadsDir, 0755, true)) {
        echo "Failed to create uploads directory: {$uploadsDir}\n";
        exit;
    }
}

$log = [];
$updated = 0;
$skipped = 0;
$errors = 0;

$res = $conn->query("SELECT id, photo_path FROM students");
while ($row = $res->fetch_assoc()) {
    $id = (int)$row['id'];
    $orig = trim((string)$row['photo_path']);
    if ($orig === '') { $skipped++; continue; }

    // Derive safe filename
    $basename = basename($orig);
    // sanitize filename: allow letters, numbers, dash, underscore, dot
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename);
    if ($safe === '') { $errors++; $log[] = "[$id] invalid basename: $orig"; continue; }

    $target = $uploadsDir . DIRECTORY_SEPARATOR . $safe;

    // If photo_path already a simple filename and exists under uploads, just update DB if needed
    if (file_exists($target)) {
        // Update DB to safe filename if different
        if ($orig !== $safe) {
            $stmt = $conn->prepare("UPDATE students SET photo_path=? WHERE id=?");
            $stmt->bind_param('si', $safe, $id);
            if ($stmt->execute()) { $updated++; $log[] = "[$id] updated to existing uploads/$safe"; }
            $stmt->close();
        } else {
            $skipped++;
        }
        continue;
    }

    // Try to locate source:
    $found = false;
    // 1) If orig is absolute path and file exists
    if (preg_match('~^[A-Za-z]:[\\\\/]~', $orig) || str_starts_with($orig, '/')) {
        $real = realpath($orig);
        if ($real && file_exists($real) && str_starts_with(str_replace('\\','/',$real), str_replace('\\','/',$projectRoot))) {
            $found = $real;
        }
    }

    // 2) If orig is web path under project (e.g. /uploads/..)
    if (!$found && str_starts_with($orig, '/')) {
        $maybe = realpath($projectRoot . DIRECTORY_SEPARATOR . ltrim($orig, '/'));
        if ($maybe && file_exists($maybe)) $found = $maybe;
    }

    // 3) If orig is bare filename in some other folder inside project, try common locations
    if (!$found) {
        $candidates = [
            $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $orig,
            $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $orig,
            $projectRoot . DIRECTORY_SEPARATOR . ltrim($orig, '/'),
        ];
        foreach ($candidates as $c) {
            if (file_exists($c)) { $found = realpath($c); break; }
        }
    }

    if (!$found) {
        $errors++;
        $log[] = "[$id] source not found for: $orig";
        continue;
    }

    // Copy source into uploads
    if (@copy($found, $target)) {
        // set permissions
        @chmod($target, 0644);
        // Update DB to safe filename
        $stmt = $conn->prepare("UPDATE students SET photo_path=? WHERE id=?");
        $stmt->bind_param('si', $safe, $id);
        if ($stmt->execute()) {
            $updated++;
            $log[] = "[$id] copied to uploads/$safe";
        } else {
            $errors++;
            $log[] = "[$id] copied but DB update failed for $safe";
        }
        $stmt->close();
    } else {
        $errors++;
        $log[] = "[$id] failed to copy from $found to $target";
    }
}

echo "Normalize Photos - Summary\n";
echo "Updated: $updated\n";
echo "Skipped: $skipped\n";
echo "Errors: $errors\n\n";
echo "Details:\n";
foreach ($log as $l) echo $l . "\n";

exit;
