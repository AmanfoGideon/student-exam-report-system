<?php
// admin/reports/render_student_report.php
// Final integrated build — modern blue theme, centered logo watermark, horizontal student info, single A4 fit
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../includes/db.php';

use Mpdf\Mpdf;

/* -----------------------
   Small helpers
   ----------------------- */
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function fmt($v){ return is_numeric($v) ? rtrim(rtrim(number_format((float)$v,2,'.',''),'0'),'.') : $v; }

/* -----------------------
   Asset resolution helpers
   ----------------------- */
function resolve_asset(string $web_rel, bool $for_pdf=false, string $abs_override=''): string {
    if ($abs_override !== '' && file_exists($abs_override)) {
        if ($for_pdf) return $abs_override;
        $docroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? (__DIR__ . '/../../'));
        if ($docroot) {
            $abs = realpath($abs_override);
            if ($abs && str_starts_with(str_replace('\\','/',$abs), str_replace('\\','/',$docroot))) {
                return '/' . ltrim(str_replace('\\','/', substr($abs, strlen($docroot))), '/');
            }
        }
        return $web_rel;
    }
    if ($for_pdf) {
        $candidate = realpath(__DIR__ . '/../../' . ltrim($web_rel, '/'));
        if ($candidate && file_exists($candidate)) return $candidate;
    }
    return $web_rel;
}

function student_photo_path(string $photo_val, bool $for_pdf=false): string {
    $default_web = '/assets/images/default_user.png';
    $uploads_rel = '/uploads/';
    $uploads_abs_dir = realpath(__DIR__ . '/../../uploads') ?: (__DIR__ . '/../../uploads');
    $project_root = realpath(__DIR__ . '/../../');

    if (empty($photo_val)) {
        if ($for_pdf) {
            $maybe = realpath(__DIR__ . '/../../assets/images/default_user.png');
            return $maybe && file_exists($maybe) ? $maybe : $default_web;
        }
        return $default_web;
    }

    $photo_val = trim($photo_val);

    if (preg_match('~^https?://~i', $photo_val)) return $photo_val;

    if (preg_match('~^[A-Za-z]:[\\\\/]~', $photo_val) || str_starts_with($photo_val, '/')) {
        $abs = realpath($photo_val);
        if ($abs && file_exists($abs)) {
            if ($for_pdf) return $abs;
            $docroot = realpath($_SERVER['DOCUMENT_ROOT'] ?? $project_root);
            if ($docroot && str_starts_with(str_replace('\\','/',$abs), str_replace('\\','/',$docroot))) {
                return '/' . ltrim(str_replace('\\','/', substr($abs, strlen($docroot))), '/');
            }
            if (str_starts_with(str_replace('\\','/',$abs), str_replace('\\','/',$uploads_abs_dir))) {
                $rel = '/' . ltrim(str_replace('\\','/', substr($abs, strlen($uploads_abs_dir))), '/');
                return $uploads_rel . ltrim($rel, '/');
            }
            return $default_web;
        }
        return $for_pdf ? ($photo_val) : $default_web;
    }

    if (str_starts_with($photo_val, '/')) {
        if ($for_pdf) {
            $maybe = realpath(__DIR__ . '/../../' . ltrim($photo_val, '/'));
            if ($maybe && file_exists($maybe)) return $maybe;
        }
        return $photo_val;
    }

    $cleanName = ltrim($photo_val, '/\\');
    if ($for_pdf) {
        $abs = $uploads_abs_dir . DIRECTORY_SEPARATOR . $cleanName;
        if (file_exists($abs)) return $abs;
        return $uploads_rel . $cleanName;
    }

    return $uploads_rel . $cleanName;
}

/* -----------------------
   DB helpers
   ----------------------- */
function get_school_settings(mysqli $conn): array {
    $out = [];
    $res = $conn->query("SELECT setting_key,setting_value FROM system_settings");
    while ($r = $res->fetch_assoc()) $out[$r['setting_key']] = $r['setting_value'];
    return $out;
}

function get_scores(mysqli $conn, int $sid, int $tid, int $yid): array {
    $st = $conn->prepare("
        SELECT sub.id AS subject_id, sub.name AS subject_name, sc.class_score, sc.exam_score, sc.total, sc.grade, sc.remark
        FROM scores sc
        JOIN subjects sub ON sc.subject_id = sub.id
        WHERE sc.student_id = ? AND sc.term_id = ? AND sc.year_id = ?
        ORDER BY sub.name
    ");
    $st->bind_param('iii', $sid, $tid, $yid);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function get_summary(mysqli $conn, int $sid, int $cid, int $tid, int $yid): array {
    $q = $conn->prepare("SELECT * FROM scores_summary WHERE student_id=? AND class_id=? AND term_id=? AND year_id=? LIMIT 1");
    $q->bind_param('iiii', $sid, $cid, $tid, $yid);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();

    if ($row) {
        $total_marks = $row['total_marks'] ?? ($row['total'] ?? null);
        $average = $row['average'] ?? null;
        $position = null;
        foreach ($row as $k => $v) {
            $kl = strtolower($k);
            if ($v === null || $v === '') continue;
            if (strpos($kl, 'position') !== false || $kl === 'pos' || strpos($kl, 'rank') !== false || strpos($kl, 'class_position') !== false || strpos($kl, 'position_in_class') !== false) {
                $position = $v;
                break;
            }
        }
        return [
            'total_marks' => $total_marks !== null ? (is_numeric($total_marks) ? (float)$total_marks : $total_marks) : 0,
            'average' => $average !== null ? (is_numeric($average) ? (float)$average : $average) : 0,
            'position' => $position
        ];
    }

    $scores = get_scores($conn, $sid, $tid, $yid);
    $total = 0; $count = 0;
    foreach ($scores as $r) { $total += (float)($r['total'] ?? 0); $count++; }
    return ['total_marks' => $total, 'average' => $count ? round($total / $count, 2) : 0, 'position' => null];
}

function get_meta(mysqli $conn, int $sid, int $cid, int $tid, int $yid): array {
    $st = $conn->prepare("SELECT present_days, total_days, attendance_percent, class_teacher_remark, head_teacher_remark, attitude, interest, promotion_status, vacation_date, next_term_begins FROM report_meta WHERE student_id=? AND class_id=? AND term_id=? AND year_id=? LIMIT 1");
    $st->bind_param('iiii', $sid, $cid, $tid, $yid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: [];
}

/* -----------------------
   Aggregate computation (smaller grade is better)
   ----------------------- */
function compute_aggregate(array $scores): array {
    $cores = [
        'english language' => ['english language','english'],
        'mathematics'      => ['mathematics','math','maths'],
        'science'          => ['science'],
        'social studies'   => ['social studies','social','s.s','sst'],
    ];

    $map = [];
    foreach ($scores as $r) {
        $raw = mb_strtolower(trim((string)($r['subject_name'] ?? '')));
        $gp = 9.0; // default worst
        if (isset($r['grade']) && is_numeric($r['grade'])) {
            $gp = (float)$r['grade'];
        } else {
            if (!empty($r['grade']) && preg_match('/\d+(\.\d+)?/', (string)$r['grade'], $m)) {
                $gp = (float)$m[0];
            } else {
                if (!empty($r['total']) && is_numeric($r['total'])) {
                    $pct = (float)$r['total'];
                    if ($pct >= 90) $gp = 1;
                    elseif ($pct >= 80) $gp = 2;
                    elseif ($pct >= 70) $gp = 3;
                    elseif ($pct >= 60) $gp = 4;
                    elseif ($pct >= 50) $gp = 5;
                    elseif ($pct >= 40) $gp = 6;
                    else $gp = 7;
                }
            }
        }

        $matched = false;
        foreach ($cores as $canon => $syns) {
            foreach ($syns as $syn) {
                if ($syn !== '' && mb_strpos($raw, $syn) !== false) {
                    $map[$canon] = $gp;
                    $matched = true;
                    break 2;
                }
            }
        }
        if (!$matched) $map[$raw] = $gp;
    }

    $selected = [];
    $sum = 0.0;
    foreach (array_keys($cores) as $k) {
        if (isset($map[$k])) {
            $selected[$k] = $map[$k];
            $sum += $map[$k];
        }
    }

    $remaining = [];
    foreach ($map as $name => $gp) {
        if (!isset($selected[$name])) $remaining[$name] = $gp;
    }

    asort($remaining, SORT_NUMERIC);
    $added = 0;
    foreach ($remaining as $name => $gp) {
        if ($added >= 2) break;
        $selected[$name] = $gp;
        $sum += $gp;
        $added++;
    }

    $selected_nice = [];
    foreach ($selected as $name => $gp) $selected_nice[ucwords($name)] = $gp;

    return ['subjects' => $selected_nice, 'aggregate' => $sum];
}

/* -----------------------
   Render student report
   ----------------------- */
function render_student_html(mysqli $conn, int $student_id, int $class_id, int $term_id, int $year_id, array $opts = []): string {
    $for_pdf = (bool)($opts['for_pdf'] ?? false);

    $settings = get_school_settings($conn);
    $school_name = $settings['school_name'] ?? 'SCHOOL NAME';
    $motto = $settings['school_motto'] ?? '';
    $address = $settings['school_address'] ?? '';
    $phone = $settings['school_phone'] ?? '';

    // watermark/logo paths
    $logo_abs = 'D:\\wamp64\\www\\foase_exam_report_system\\assets\\images\\logo.png';
    $logo_web = '/assets/images/logo.png';
    $logo_src = resolve_asset($logo_web, $for_pdf, $logo_abs);

    // sig/stamp
    $sig_abs = 'D:\\wamp64\\www\\foase_exam_report_system\\assets\\images\\signature.png';
    $stamp_abs = 'D:\\wamp64\\www\\foase_exam_report_system\\assets\\images\\stamp.png';
    $sig_web = '/assets/images/signature.png';
    $stamp_web = '/assets/images/stamp.png';
    $sig_src = resolve_asset($sig_web, $for_pdf, $sig_abs);
    $stamp_src = resolve_asset($stamp_web, $for_pdf, $stamp_abs);

    // fetch student
    $st = $conn->prepare("SELECT id, admission_no, first_name, last_name, gender, dob, photo_path FROM students WHERE id=? LIMIT 1");
    $st->bind_param('i', $student_id);
    $st->execute();
    $student = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$student) return '<div style="font-family:Arial,Helvetica,sans-serif">Student not found</div>';

    $full_name = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    $photo_path = student_photo_path($student['photo_path'] ?? '', $for_pdf);

    $term_name = $conn->query("SELECT term_name FROM terms WHERE id=" . (int)$term_id)->fetch_assoc()['term_name'] ?? '';
    $year_label = $conn->query("SELECT year_label FROM academic_years WHERE id=" . (int)$year_id)->fetch_assoc()['year_label'] ?? '';
    $class_name = $conn->query("SELECT class_name FROM classes WHERE id=" . (int)$class_id)->fetch_assoc()['class_name'] ?? '';

    $scores = get_scores($conn, $student_id, $term_id, $year_id);
    $summary = get_summary($conn, $student_id, $class_id, $term_id, $year_id);
    $meta = get_meta($conn, $student_id, $class_id, $term_id, $year_id);

    $agg = compute_aggregate($scores);

    $attendance_pct = isset($meta['attendance_percent']) ? fmt($meta['attendance_percent']) . '%' : '—';
    $vac_date = !empty($meta['vacation_date']) ? date('d M Y', strtotime($meta['vacation_date'])) : '—';
    $next_term = !empty($meta['next_term_begins']) ? date('d M Y', strtotime($meta['next_term_begins'])) : '—';
    $position = $summary['position'] ?? '—';

    /* CSS — modern blue theme, border, watermark centered, drop shadow on screen only */
    $css = <<<CSS
    <style>
      @page { size:A4; margin:12mm 10mm; }
      :root{ --navy:#06204a; --blue:#0b2b66; --muted:#c6d5ef; --card:#f6fbff; --ink:#08253b; --frame:#bfcde0; }
      html,body{ margin:0;padding:0;font-family: 'Poppins', 'Nunito Sans', Arial, sans-serif;color:var(--ink); background:#f4f7fb; }
      .report-wrap{ padding:12px; }
      .report{ width:190mm; height:273mm; margin:0 auto; padding:14mm 14mm 32mm 14mm; background:#fff; box-sizing:border-box; position:relative; border-radius:6px; border:1px solid var(--frame); overflow:hidden; }
      /* drop shadow for screen, removed for print */
      @media screen { .report{ box-shadow:0 10px 30px rgba(4,24,63,0.08); } }
      @media print { .report{ box-shadow:none; border-radius:0; } }
      .watermark-img{ position:absolute; left:50%; top:48%; transform:translate(-50%,-50%) rotate(-12deg); opacity:0.05; width:320px; height:auto; pointer-events:none; z-index:0; }
      .header{ text-align:center; position:relative; z-index:2; }
      .crest{ width:74px; height:74px; margin:0 auto 6px; border-radius:10px; overflow:hidden; background:#fff; display:flex; align-items:center; justify-content:center; box-shadow:0 6px 18px rgba(2,22,60,0.04); }
      .crest img{ width:68px; height:68px; object-fit:contain; display:block; }
      .school-name{ font-weight:800; font-size:20px; color:var(--navy); text-transform:uppercase; margin-bottom:4px; }
      .school-meta{ font-size:12px; color:var(--blue); margin-bottom:6px; }
      .divider-line{ height:3px; background:var(--blue); opacity:0.12; border-radius:3px; margin:8px 0 10px 0; }

      /* Student info: three rows (horizontal) with photo at right */

    /* ===========================
    Student Info Section (4 rows)
    Layout: 4 text rows + photo on the right
    Clean & modern design
    =========================== */

    /* Use a smooth, modern font */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');

    :root {
    --font-main: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --ink: #1a1a1a;
    --blue: #0046b8;
    --soft-bg: #f9fafc;
    }

    /* Wrapper: student info container */
    .info-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-top: 8px;
    font-family: var(--font-main);
    z-index: 2;
    }

    /* Left: 4 text rows stacked */
    .info-left {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    }

    /* Each line (row) of information */
    .meta-line {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 18px;
    font-size: 14px;
    line-height: 1.5;
    color: var(--ink);
    }

    /* Label + value pair */
    .meta-item {
    white-space: nowrap;
    font-weight: 600;
    }

    .meta-item .label {
    color: var(--blue);
    font-weight: 700;
    margin-right: 6px;
    }

    /* Student photo on the right */
    .student-photo {
    flex: 0 0 110px;
    width: 110px;
    height: 135px;
    margin-left: 12px;
    border-radius: 10px;
    overflow: hidden;
    border: 3px solid #fff;
    box-shadow: 0 6px 18px rgba(2, 22, 60, 0.08);
    background-color: var(--soft-bg);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .student-photo:hover {
    transform: scale(1.03);
    box-shadow: 0 8px 22px rgba(2, 22, 60, 0.12);
    }

    .student-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    border-radius: inherit;
    }

    /* Responsive: stack photo below on small screens */
    @media (max-width: 600px) {
    .info-row {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }

    .student-photo {
        margin-left: 0;
        margin-top: 12px;
    }

    .meta-line {
        justify-content: center;
    }
    }


      /* Table (starts immediately below info) */
      .scores{ width:100%; border-collapse:collapse; margin-top:8px; font-size:13px; margin-bottom:12px; }
      .scores th{ background:var(--blue); color:#fff; padding:8px; font-weight:800; text-align:center; border:1px solid var(--muted); }
      .scores td{ border:1px solid var(--muted); padding:8px; text-align:center; vertical-align:middle; background:#fff; }
      .scores td.subject{ text-align:left; padding-left:12px; font-weight:700; }
      tr.zebra{ background:#f6fbff; }

      /* Attendance / attitudes / remarks (directly under the table) */
      .below-table{ display:flex; gap:12px; margin-top:8px; z-index:2; }
      .att-box{ min-width:140px; background:#fbfdff; border:1px solid var(--muted); padding:8px; border-radius:6px; text-align:center; font-weight:700; color:var(--navy); }
      .att-detail{ font-size:13px; margin-top:6px; font-weight:600; color:var(--ink); }
      .remarks-box{ flex:1; background:#fff; border:1px solid var(--muted); padding:8px; border-radius:6px; min-height:60px; font-size:13px; color:var(--ink); }

      /* signature & stamp boxed area pinned to bottom */
      .sig-stamp-box{ position:absolute; left:10mm; right:10mm; bottom:10mm; border:1px dashed rgba(11,43,102,0.12); background:#fbfdff; padding:6px 10px; border-radius:6px; display:flex; justify-content:space-between; align-items:center; gap:8px; z-index:3; }
      .signature img{ width:140px; display:block; }
      .stamp img{ width:96px; display:block; opacity:0.95; }
      /*.sig-label{ font-size:12px; color:var(--blue); margin-top:6px; font-weight:700; }*/

      .small{ font-size:11px; color:var(--blue); }

      @media (max-width:720px) {
        .info-row{ flex-direction:column; }
        .student-photo{ margin-left:0; align-self:center; }
        .sig-stamp-box{ position:static; margin-top:12px; }
      }
    </style>
    CSS;

    /* Build HTML */
    $html = '<div class="report-wrap"><div class="report">';

    // Watermark image (centered) — resolved path used (absolute for PDF)
    $html .= '<img class="watermark-img" src="' . e($logo_src) . '" alt="watermark">';

    // Header: crest and school title
    $html .= '<div class="header">';
    $html .= '<div class="crest"><img src="' . e($logo_src) . '" alt="crest"></div>';
    $html .= '<div class="school-name">' . e(mb_strtoupper($school_name)) . '</div>';
    if ($motto || $address || $phone) {
        $parts = [];
        if ($motto) $parts[] = e($motto);
        if ($address) $parts[] = e($address);
        if ($phone) $parts[] = e($phone);
        $html .= '<div class="school-meta">' . implode(' &nbsp; | &nbsp; ', $parts) . '</div>';
    }
    $html .= '<div class="divider-line"></div>';
    $html .= '</div>';

    // Info block: 3 rows with photo at right
    $html .= '<div class="info-row">';
    $html .= '<div class="info-left">';
    // Row 1
    $html .= '<div class="meta-line">';
    $html .= '<div class="meta-item"><span class="label">NAME</span>' . e($full_name) . '</div>';
    $html .= '<div class="meta-item"><span class="label">ADM NO:</span>' . e($student['admission_no'] ?? '—') . '</div>';
    $html .= '<div class="meta-item"><span class="label">CLASS:</span>' . e($class_name) . '</div>';
    $html .= '</div>';
    // Row 2
    $html .= '<div class="meta-line" style="margin-top:6px;">';
    $html .= '<div class="meta-item"><span class="label">TERM / YEAR</span>' . e($term_name) . ' / ' . e($year_label) . '</div>';
    $html .= '<div class="meta-item"><span class="label">GENDER:</span>' . e($student['gender'] ?? '—') . '</div>';
    $html .= '</div>';
    // Row 3 (Vac. Date, Next term, Position, Aggregate)
    $html .= '<div class="meta-line" style="margin-top:6px;">';
    $html .= '<div class="meta-item"><span class="label">Vac. Date:</span>' . e($vac_date) . '</div>';
    $html .= '<div class="meta-item"><span class="label">Next term begins:</span>' . e($next_term) . '</div>';
    $html .= '<div class="meta-item"><span class="label">POSITION:</span><strong>' . e($position) . '</strong></div>';
    $html .= '<div class="meta-item"><span class="label">AGGREGATE:</span><strong>' . e(fmt($agg['aggregate'] ?? 0)) . '</strong></div>';
    $html .= '</div>';

    $html .= '</div>'; // info-left

    // Photo right
    $html .= '<div class="student-photo"><img src="' . e($photo_path) . '" alt="' . e($full_name) . ' photo" loading="lazy" width="96" height="120" onerror="this.onerror=null;this.src=\'/assets/images/default_user.png\';"></div>';

    $html .= '</div>'; // info-row

    // Scores table (immediately below)
    $html .= '<table class="scores" role="table"><thead><tr>';
    $html .= '<th style="width:6%;">S/N</th>';
    $html .= '<th style="width:45%;text-align:left;">SUBJECT</th>';
    $html .= '<th style="width:12%;">CLASS</th>';
    $html .= '<th style="width:12%;">EXAM</th>';
    $html .= '<th style="width:10%;">TOTAL</th>';
    $html .= '<th style="width:8%;">GRADE</th>';
    $html .= '<th style="width:7%;">REMARK</th>';
    $html .= '</tr></thead><tbody>';

    if (empty($scores)) {
        $html .= '<tr><td colspan="7" style="text-align:center;padding:10px">No scores available</td></tr>';
    } else {
        $i = 1;
        foreach ($scores as $r) {
            $rowClass = ($i % 2 === 0) ? 'zebra' : '';
            $html .= '<tr class="' . $rowClass . '">';
            $html .= '<td>' . $i++ . '</td>';
            $html .= '<td class="subject" style="text-align:left;padding-left:10px;">' . e($r['subject_name']) . '</td>';
            $html .= '<td>' . e(fmt($r['class_score'])) . '</td>';
            $html .= '<td>' . e(fmt($r['exam_score'])) . '</td>';
            $html .= '<td><strong>' . e(fmt($r['total'])) . '</strong></td>';
            $html .= '<td>' . e($r['grade'] ?? '—') . '</td>';
            $html .= '<td>' . e($r['remark'] ?? '—') . '</td>';
            $html .= '</tr>';
        }
    }

    $html .= '</tbody></table>';

    // Attendance, Attitude, Interest and Remarks (directly under table)
    $html .= '<div class="below-table">';
    // Attendance box
    $present = $meta['present_days'] ?? '—';
    $total_days = $meta['total_days'] ?? '—';
    $html .= '<div class="att-box"><div class="small">ATTENDANCE</div><div class="att-detail">' . e($present) . ' / ' . e($total_days) . ' (' . e($attendance_pct) . ')</div></div>';
    // Interest & Attitude
    $html .= '<div class="att-box"><div class="small">INTEREST</div><div class="att-detail">' . e($meta['interest'] ?? '—') . '</div><div style="margin-top:6px" class="small">ATTITUDE</div><div class="att-detail">' . e($meta['attitude'] ?? '—') . '</div></div>';
    // Remarks
    $html .= '<div class="remarks-box"><div class="small">CLASS TEACHER\'S REMARK</div><div style="margin-top:6px">' . nl2br(e($meta['class_teacher_remark'] ?? '—')) . '</div><hr style="border:none;border-top:1px solid #eef5ff;margin:8px 0;"><div class="small">HEAD TEACHER\'S REMARK</div><div style="margin-top:6px">' . nl2br(e($meta['head_teacher_remark'] ?? '—')) . '</div></div>';
    $html .= '</div>'; // below-table

    // Signature & stamp boxed area pinned bottom (small)
    $html .= '<div class="sig-stamp-box">';
    $html .= '<div style="display:flex;flex-direction:column;gap:2px;">';
    $html .= '<div class="small" style="font-weight:800;color:var(--blue);">Signature</div>';
    if ($for_pdf && file_exists($sig_src)) {
        $html .= '<div class="signature"><img src="' . e($sig_src) . '" alt="signature"></div>';
    } else {
        $html .= '<div class="signature"><img src="' . e($sig_src) . '" alt="signature"></div>';
    }
    $html .= '<div class="sig-label">Headteacher</div>';
    $html .= '</div>';

    $html .= '<div style="text-align:right;">';
    if ($for_pdf && file_exists($stamp_src)) {
        $html .= '<div class="stamp"><img src="' . e($stamp_src) . '" alt="stamp"></div>';
    } else {
        $html .= '<div class="stamp"><img src="' . e($stamp_src) . '" alt="stamp"></div>';
    }
    $html .= '<div class="small" style="margin-top:6px;">Generated: ' . e(date('d M Y H:i')) . '</div>';
    $html .= '</div>';

    $html .= '</div>'; // sig-stamp-box

    $html .= '</div></div>'; // report + wrap

    return $css . $html;
}

/* -----------------------
   Direct access endpoints (preview/pdf/class)
   ----------------------- */
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    if (empty($_SESSION['user_id'])) { http_response_code(403); exit('Forbidden'); }

    $studentId = (int)($_GET['student_id'] ?? 0);
    $classId = (int)($_GET['class_id'] ?? 0);
    $termId = (int)($_GET['term_id'] ?? 0);
    $yearId = (int)($_GET['year_id'] ?? 0);

    if (isset($_GET['pdf']) && $_GET['pdf'] == '1' && $studentId) {
        require_once __DIR__ . '/../../vendor/autoload.php';
        $mpdf = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4','margin_top'=>12,'margin_bottom'=>12,'tempDir'=>__DIR__.'/../../tmp']);
        $mpdf->shrink_tables_to_fit = 1;
        $mpdf->packTableData = true;

        // Set watermark image (absolute path) if available
        $logo_abs_test = 'D:\\wamp64\\www\\foase_exam_report_system\\assets\\images\\logo.png';
        if (file_exists($logo_abs_test)) {
            try {
                $mpdf->SetWatermarkImage($logo_abs_test);
                $mpdf->showWatermarkImage = true;
                $mpdf->watermarkImageAlpha = 0.05;
            } catch (\Throwable $ex) {
                // ignore if method not supported
            }
        }

        $html = render_student_html($conn, $studentId, $classId, $termId, $yearId, ['for_pdf'=>true]);
        $mpdf->WriteHTML($html);
        $mpdf->Output('report_'.$studentId.'.pdf','I');
        exit;
    }

    if ($studentId) {
        echo '<!doctype html><html><head>';
        include __DIR__ . '/../../pwa-head.php'; 
        echo '<meta charset="utf-8"><title>Student Report</title>';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<style>@media print { body { -webkit-print-color-adjust: exact; color-adjust: exact; } }</style>';
        echo '</head><body>';

        echo render_student_html($conn, $studentId, $classId, $termId, $yearId, ['for_pdf'=>false]);

        include __DIR__ . '/../../pwa-footer.php'; 
        echo '</body></html>';
        exit;
    }


    if (isset($_GET['preview']) && $_GET['preview'] === 'class' && $classId) {
        $stmt = $conn->prepare("SELECT id FROM students WHERE class_id=? ORDER BY first_name,last_name");
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        echo '<!doctype html><html><head><meta charset="utf-8"><title>Class Reports</title>';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<style>@media print { body { -webkit-print-color-adjust: exact; color-adjust: exact; } }</style>';
        echo '</head><body>';
        foreach ($rows as $i => $r) {
            echo render_student_html($conn, (int)$r['id'], $classId, $termId, $yearId, ['for_pdf'=>false]);
            if ($i < count($rows)-1) echo '<div style="page-break-after:always;"></div>';
        }
        echo '</body></html>';
        exit;
    }

    http_response_code(400);
    echo 'Missing student_id or class_id';
}
   
