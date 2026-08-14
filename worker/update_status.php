<?php
// worker/update_status.php
// Handles both:
//   1. Manual toggle (POST action=manual)   — legacy form submit, sets Available/Busy
//   2. Schedule save  (POST action=schedule) — saves schedule rules; also applies
//      current availability right away based on the saved rules
//   3. Schedule clear (POST action=clear)   — reverts worker back to manual mode

include '../db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php");
    exit();
}

$worker_id = (int)$_SESSION['user_id'];
$action    = $_POST['action'] ?? 'manual';

// ── helpers ─────────────────────────────────────────────────
/**
 * Given an array of day numbers (0-6) and a start/end time string,
 * decide whether the schedule means "available right now".
 */
function scheduleIsActiveNow(array $days, ?string $timeStart, ?string $timeEnd): bool {
    if (empty($days)) return false;

    $now        = new DateTime();
    $currentDay = (int)$now->format('w');          // 0=Sun … 6=Sat
    $currentT   = $now->format('H:i:s');

    if (!in_array($currentDay, $days, true)) return false;

    // If no time window set, whole day counts
    if (!$timeStart || !$timeEnd) return true;

    return ($currentT >= $timeStart && $currentT < $timeEnd);
}

// ── MANUAL TOGGLE ────────────────────────────────────────────
if ($action === 'manual') {
    $new_status = isset($_POST['status']) ? 'Available' : 'Busy';

    $stmt = $conn->prepare(
        "UPDATE worker_profiles
         SET availability_status = ?,
             schedule_mode       = 'manual'
         WHERE user_id = ?"
    );
    $stmt->bind_param('si', $new_status, $worker_id);
    $stmt->execute();

    header("Location: dashboard.php");
    exit();
}

// ── SCHEDULE SAVE ─────────────────────────────────────────────
if ($action === 'schedule') {
    // days[] comes in as array of strings "0"-"6"
    $raw_days = $_POST['days'] ?? [];

    // Sanitise: keep only valid integers 0-6
    $clean_days = [];
    foreach ((array)$raw_days as $d) {
        $d = (int)$d;
        if ($d >= 0 && $d <= 6) $clean_days[] = $d;
    }
    sort($clean_days);
    $schedule_days = implode(',', $clean_days);

    // Times — validate HH:MM format
    $timeStart = null;
    $timeEnd   = null;
    if (!empty($_POST['time_start'])) {
        $ts = preg_match('/^\d{2}:\d{2}$/', trim($_POST['time_start']))
              ? trim($_POST['time_start']) . ':00'
              : null;
        $timeStart = $ts;
    }
    if (!empty($_POST['time_end'])) {
        $te = preg_match('/^\d{2}:\d{2}$/', trim($_POST['time_end']))
              ? trim($_POST['time_end']) . ':00'
              : null;
        $timeEnd = $te;
    }

    $label = trim(substr($_POST['label'] ?? '', 0, 100));

    // Decide what availability should be *right now*
    $isNowActive    = scheduleIsActiveNow($clean_days, $timeStart, $timeEnd);
    $new_status     = $isNowActive ? 'Available' : 'Busy';

    $stmt = $conn->prepare(
        "UPDATE worker_profiles
         SET schedule_mode       = 'scheduled',
             schedule_days       = ?,
             schedule_time_start = ?,
             schedule_time_end   = ?,
             schedule_label      = ?,
             availability_status = ?
         WHERE user_id = ?"
    );
    $stmt->bind_param(
        'sssssi',
        $schedule_days,
        $timeStart,
        $timeEnd,
        $label,
        $new_status,
        $worker_id
    );
    $stmt->execute();

    header("Location: dashboard.php?schedule_saved=1");
    exit();
}

// ── CLEAR SCHEDULE ────────────────────────────────────────────
if ($action === 'clear') {
    $stmt = $conn->prepare(
        "UPDATE worker_profiles
         SET schedule_mode       = 'manual',
             schedule_days       = NULL,
             schedule_time_start = NULL,
             schedule_time_end   = NULL,
             schedule_label      = NULL
         WHERE user_id = ?"
    );
    $stmt->bind_param('i', $worker_id);
    $stmt->execute();

    header("Location: dashboard.php");
    exit();
}

// Fallback
header("Location: dashboard.php");
exit();