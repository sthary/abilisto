<?php
// client/api/quick_match_core.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

require_once '../../db.php';
require_once '../../includes/fcm_sender.php';

// --- AUTH CHECK ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$client_id = $_SESSION['user_id'];
$client_name = $_SESSION['full_name'] ?? 'A client';
$action = $_POST['action'] ?? '';

// --- ACTION: FIND WORKERS (UI Search / AJAX) ---
if ($action === 'find_workers') {
    try {
        $category = $conn->real_escape_string($_POST['category'] ?? '');
        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);

        if (empty($category)) throw new Exception('Category is required');

        // Fetch all workers in category within 10km, include availability + lat/lng for map
        $query = "SELECT u.id, u.full_name, u.avatar, u.latitude, u.longitude,
                    COALESCE(wp.average_rating, 0) AS average_rating,
                    COALESCE(wp.verification_status, 'Unverified') AS verification_status,
                    COALESCE(wp.availability_status, 'Unavailable') AS availability_status,
                    COALESCE(wp.is_tesda_verified, 0) AS is_tesda_verified,
                    COALESCE(wp.vouch_count, 0) AS vouch_count,
                    (6371 * acos(
                        cos(radians($lat)) * cos(radians(u.latitude)) *
                        cos(radians(u.longitude) - radians($lng)) +
                        sin(radians($lat)) * sin(radians(u.latitude))
                    )) AS distance
                  FROM users u
                  LEFT JOIN worker_profiles wp ON u.id = wp.user_id
                  WHERE u.role = 'worker'
                    AND u.latitude IS NOT NULL AND u.latitude != 0
                    AND u.longitude IS NOT NULL AND u.longitude != 0
                    AND wp.service_category = '$category'
                  HAVING distance <= 10
                  ORDER BY distance ASC
                  LIMIT 30";

        $result = $conn->query($query);
        $workers = [];
        while ($row = $result->fetch_assoc()) {
            $workers[] = $row;
        }

        // Score each worker
        $scored = scoreWorkers($workers, $lat, $lng);

        // Return top 5, flagged with availability
        $top5 = array_slice($scored, 0, 5);
        $out = [];
        foreach ($top5 as $w) {
            $out[] = [
                'id'                  => $w['id'],
                'full_name'           => $w['full_name'],
                'avatar'              => $w['avatar'] ?? null,
                'distance'            => round($w['distance'], 1),
                'latitude'            => floatval($w['latitude']),
                'longitude'           => floatval($w['longitude']),
                'average_rating'      => round(floatval($w['average_rating']), 1),
                'verification_status' => $w['verification_status'],
                'availability_status' => $w['availability_status'],
                'is_tesda_verified'   => (bool)$w['is_tesda_verified'],
                'score'               => round($w['score']),
            ];
        }

        echo json_encode(['status' => 'success', 'workers' => $out]);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }

// --- ACTION: CREATE BROADCAST ---
} elseif ($action === 'create_broadcast') {
    try {
        $category  = $conn->real_escape_string($_POST['category'] ?? '');
        $problems  = $conn->real_escape_string($_POST['problem'] ?? '[]');
        $urgency   = $conn->real_escape_string($_POST['urgency'] ?? 'Normal');
        $lat       = floatval($_POST['lat'] ?? 0);
        $lng       = floatval($_POST['lng'] ?? 0);
        $worker_ids = json_decode($_POST['worker_ids'] ?? '[]', true);

        if (empty($worker_ids)) throw new Exception('No workers selected');

        // Fee by urgency
        switch ($urgency) {
            case 'Emergency': $fee = 75; break;
            case 'High':      $fee = 60; break;
            default:          $fee = 50;
        }

        // Hard limit: max 5 workers
        $worker_ids = array_slice($worker_ids, 0, 5);

        $conn->begin_transaction();

        $expires_at        = date('Y-m-d H:i:s', strtotime('-60 minutes'));
        $candidate_workers = $conn->real_escape_string(json_encode($worker_ids));

        // Insert broadcast
        $sql = "INSERT INTO job_broadcasts
                (client_id, category, problem_tags, urgency, latitude, longitude,
                 status, expires_at, candidate_workers, is_quick_match, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'searching', ?, ?, 1, NOW())";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isssddss",
            $client_id, $category, $problems, $urgency,
            $lat, $lng, $expires_at, $candidate_workers
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to create broadcast: " . $stmt->error);
        }

        $broadcast_id    = $conn->insert_id;
        $booking_date    = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $created_bookings = [];

        foreach ($worker_ids as $index => $worker_id) {
            $score = 100 - ($index * 5);

            // job_candidates
            $cs = $conn->prepare("INSERT INTO job_candidates (broadcast_id, worker_id, score) VALUES (?, ?, ?)");
            $cs->bind_param("iid", $broadcast_id, $worker_id, $score);
            $cs->execute();

            // booking
            $bs = $conn->prepare(
                "INSERT INTO bookings
                 (client_id, worker_id, service_type, problem_desc, calculated_fee,
                  urgency_level, status, booking_date, payment_method, broadcast_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?, 'Cash', ?, NOW())"
            );
            $bs->bind_param("iissdssi",
                $client_id, $worker_id, $category, $problems,
                $fee, $urgency, $booking_date, $broadcast_id
            );

            if ($bs->execute()) {
                $created_bookings[] = $conn->insert_id;
                error_log("✅ Created booking #{$conn->insert_id} for worker #$worker_id from broadcast #$broadcast_id");
            } else {
                error_log("❌ Failed booking for worker #$worker_id: " . $bs->error);
            }
        }

        // --- FCM Notifications ---
        $placeholders = implode(',', array_fill(0, count($worker_ids), '?'));
        $types        = str_repeat('i', count($worker_ids));

        $tq = $conn->prepare("SELECT id, full_name, fcm_token FROM users WHERE id IN ($placeholders) AND fcm_token IS NOT NULL AND fcm_token != ''");
        $tq->bind_param($types, ...$worker_ids);
        $tq->execute();
        $token_result = $tq->get_result();

        $sent_count    = 0;
        $failed_workers = [];

        $urgency_icon = match ($urgency) {
            'Emergency' => '🚨',
            'High'      => '⚠️',
            default     => '🔔',
        };

        $problem_array = json_decode($problems, true);
        $problem_text  = is_array($problem_array)
            ? implode(', ', array_slice($problem_array, 0, 2)) . (count($problem_array) > 2 ? '...' : '')
            : $problems;

        $fcm = new FCMv1();

        while ($worker = $token_result->fetch_assoc()) {
            if (empty($worker['fcm_token'])) {
                $failed_workers[] = $worker['full_name'] . ' (no token)';
                continue;
            }
            try {
                $title = $urgency === 'Emergency'
                    ? "🚨 EMERGENCY: $category"
                    : "🔔 Quick Match: $category";
                $body = $urgency === 'Emergency'
                    ? "URGENT: $client_name needs immediate help with $problem_text"
                    : "$client_name needs help with $problem_text";

                $result = $fcm->send(
                    $worker['fcm_token'], $title, $body,
                    [
                        'type'         => 'quick_match',
                        'broadcast_id' => (string)$broadcast_id,
                        'urgency'      => (string)$urgency,
                        'client_name'  => (string)$client_name,
                        'category'     => (string)$category,
                        'click_url'    => '/abilisto/worker/dashboard.php',
                    ]
                );

                if ($result['success']) {
                    $sent_count++;
                    error_log("✅ Notified worker {$worker['id']} for broadcast #$broadcast_id");
                } else {
                    $failed_workers[] = $worker['full_name'];
                    error_log("❌ Failed worker {$worker['id']}: " . ($result['error'] ?? 'Unknown'));
                }
            } catch (Exception $e) {
                $failed_workers[] = $worker['full_name'];
                error_log("❌ Exception worker {$worker['id']}: " . $e->getMessage());
            }
        }

        // In-app notifications
        foreach ($worker_ids as $wid) {
            $msg  = "{$urgency_icon} Quick Match: $client_name needs $category help!";
            $link = "../worker/dashboard.php";
            $ns   = $conn->prepare("INSERT INTO notifications (user_id, message, link, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
            $ns->bind_param("iss", $wid, $msg, $link);
            $ns->execute();
        }

        // Save notification stats
        $stats = json_encode([
            'sent'             => $sent_count,
            'total'            => count($worker_ids),
            'failed'           => $failed_workers,
            'bookings_created' => count($created_bookings),
        ]);
        $us = $conn->prepare("UPDATE job_broadcasts SET notification_stats = ? WHERE id = ?");
        $us->bind_param("si", $stats, $broadcast_id);
        $us->execute();

        $conn->commit();

        $message = "Broadcast created! ";
        if ($sent_count > 0) $message .= "Notified $sent_count worker(s). ";
        $message .= count($created_bookings) . " booking(s) created.";
        if (!empty($failed_workers)) {
            $message .= " Failed: " . implode(', ', array_slice($failed_workers, 0, 2));
            if (count($failed_workers) > 2) $message .= " and " . (count($failed_workers) - 2) . " more";
        }

        echo json_encode([
            'status'          => 'success',
            'broadcast_id'    => $broadcast_id,
            'notified'        => $sent_count,
            'total_workers'   => count($worker_ids),
            'bookings_created' => count($created_bookings),
            'failed'          => $failed_workers,
            'message'         => $message,
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

$conn->close();

// ============================================================
// SCORING ALGORITHM — 5 Worker Selection
// Priority: Availability > Verification > Rating > Distance
// Hard cutoff: 10 km
// ============================================================
function scoreWorkers(array $workers, float $clientLat, float $clientLng): array
{
    foreach ($workers as &$w) {
        $score = 0;
        $dist  = floatval($w['distance']);

        // --- 1. AVAILABILITY (biggest weight — up to 50 pts) ---
        if ($w['availability_status'] === 'Available') {
            $score += 50;
        }
        // Unavailable workers still get scored but fall naturally to the bottom

        // --- 2. VERIFICATION STATUS (up to 30 pts) ---
        switch ($w['verification_status']) {
            case 'Gold':   $score += 30; break;
            case 'Silver': $score += 20; break;
            case 'Bronze': $score += 10; break;
        }
        if ($w['is_tesda_verified']) $score += 10; // Bonus: TESDA cert

        // --- 3. RATING (up to 20 pts) ---
        $score += floatval($w['average_rating']) * 4; // 5 stars = 20 pts

        // --- 4. VOUCH COUNT (up to 10 pts) ---
        $score += min(10, intval($w['vouch_count']) * 2);

        // --- 5. DISTANCE (up to 20 pts, inverse — closer = more points) ---
        if ($dist <= 2)       $score += 20;
        elseif ($dist <= 5)   $score += 14;
        elseif ($dist <= 7)   $score += 8;
        elseif ($dist <= 10)  $score += 3;

        $w['score'] = $score;
    }
    unset($w);

    // Sort: Available workers first (desc score), then unavailable (desc score) as fallback
    usort($workers, function ($a, $b) {
        $aAvail = $a['availability_status'] === 'Available' ? 1 : 0;
        $bAvail = $b['availability_status'] === 'Available' ? 1 : 0;
        if ($aAvail !== $bAvail) return $bAvail - $aAvail; // Available first
        return $b['score'] <=> $a['score'];                // Then by score
    });

    return $workers;
}