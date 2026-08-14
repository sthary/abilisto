<?php
// ============================================================
// greenloop/impact_api.php - Public API for impact stats
// No authentication required - public data only
// ============================================================

require_once __DIR__ . '/greenloop_db.php';

header('Content-Type: application/json');

$period = $_GET['period'] ?? 'today';

// Validate period
if (!in_array($period, ['today', 'week', 'month'])) {
    $period = 'today';
}

$dateCondition = '';
$trendCondition = '';

switch ($period) {
    case 'today':
        $dateCondition = "DATE(r.completed_at) = CURDATE()";
        $trendCondition = "DATE(r.completed_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        break;
    case 'week':
        $dateCondition = "YEARWEEK(r.completed_at, 1) = YEARWEEK(CURDATE(), 1)";
        $trendCondition = "YEARWEEK(r.completed_at, 1) = YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 1 WEEK), 1)";
        break;
    case 'month':
        $dateCondition = "DATE_FORMAT(r.completed_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
        $trendCondition = "DATE_FORMAT(r.completed_at, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')";
        break;
}

try {
    // Current period stats
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_scraps,
            COALESCE(SUM(r.quantity), 0) as total_quantity,
            COALESCE(SUM(r.actual_green_coins_awarded), 0) as total_coins
        FROM greenloop_reports r
        WHERE r.status = 'completed' AND {$dateCondition}
    ");
    $stmt->execute();
    $current = $stmt->fetch();
    
    // Previous period for trend
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as prev_total
        FROM greenloop_reports r
        WHERE r.status = 'completed' AND {$trendCondition}
    ");
    $stmt->execute();
    $prev = $stmt->fetch();
    
    // E-waste count
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT r.id) as ewaste_count,
            COALESCE(SUM(r.quantity), 0) as ewaste_qty
        FROM greenloop_reports r
        LEFT JOIN greenloop_accepted_items i ON r.item_id = i.id
        WHERE r.status = 'completed' 
            AND {$dateCondition}
            AND (
                i.category IN ('Electrical', 'Appliance', 'Automotive')
                OR r.item_name_custom LIKE '%electr%'
                OR r.item_name_custom LIKE '%motor%'
                OR r.item_name_custom LIKE '%battery%'
                OR r.item_name_custom LIKE '%wire%'
                OR r.item_name_custom LIKE '%circuit%'
            )
    ");
    $stmt->execute();
    $ewaste = $stmt->fetch();
    
    // Most recovered categories
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(i.category, 'Other') as category,
            COUNT(*) as count,
            COALESCE(SUM(r.quantity), 0) as total_qty
        FROM greenloop_reports r
        LEFT JOIN greenloop_accepted_items i ON r.item_id = i.id
        WHERE r.status = 'completed' AND {$dateCondition}
        GROUP BY i.category
        ORDER BY count DESC
        LIMIT 5
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll();
    
    // Calculate trend
    $prevTotal = $prev['prev_total'] ?? 0;
    $currentTotal = $current['total_scraps'] ?? 0;
    $trendPercent = $prevTotal > 0 ? round((($currentTotal - $prevTotal) / $prevTotal) * 100) : 0;
    
    // Estimate weight (rough estimate: average 2.8kg per item)
    $estimatedWeight = round(($current['total_quantity'] ?? 0) * 2.8);
    $ewasteWeight = round(($ewaste['ewaste_qty'] ?? 0) * 3.2);
    
    echo json_encode([
        'total_scraps' => (int)$currentTotal,
        'total_weight' => $estimatedWeight,
        'ewaste_count' => (int)($ewaste['ewaste_count'] ?? 0),
        'ewaste_weight' => $ewasteWeight,
        'coins_earned' => round($current['total_coins'] ?? 0),
        'trend_percent' => $trendPercent,
        'categories' => $categories
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch impact stats']);
}