<?php
// ============================================================
// api/analytics.php — Live HOD Dashboard Metrics
//
// Returns real-time counts from the database so the HOD
// dashboard shows actual numbers, not hardcoded ones.
// ============================================================

require_once 'db.php';

$tokenData = require_auth();

// Z-3: lab-wide statistics are management data — restricted to
// management/reporting roles. Technicians, reception and mapping
// officers have no analytical need for them.
ctms_require_role(['superadmin', 'admin', 'hod'], $tokenData,
    'Analytics are restricted to management roles.');
if (ctms_user_lot_access_restricted($pdo, (int)$tokenData['user_id'])) {
    http_response_code(403); echo json_encode(['error' => 'Lab-wide analytics are unavailable to accounts limited to selected lots.']); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit();
}

try {
    // Total lots created today
    $stmt = $pdo->query("SELECT COUNT(*) FROM lots WHERE DATE(created_at) = CURDATE() AND is_deleted = 0");
    $lotsToday = (int)$stmt->fetchColumn();

    // Total lots ever
    $stmt = $pdo->query("SELECT COUNT(*) FROM lots WHERE is_deleted = 0");
    $totalLots = (int)$stmt->fetchColumn();

    // Samples waiting for mapping (received / payment cleared / lot assigned)
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM samples
        WHERE status IN ('received','payment_pending','payment_cleared','lot_assigned','in_preparation')
        AND is_deleted = 0
    ");
    $pendingMapping = (int)$stmt->fetchColumn();

    // Samples currently in lab testing
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM samples
        WHERE status = 'in_testing'
        AND is_deleted = 0
    ");
    $inTesting = (int)$stmt->fetchColumn();

    // Samples in data entry / report ready
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM samples
        WHERE status IN ('data_entry','report_ready')
        AND is_deleted = 0
    ");
    $awaitingReport = (int)$stmt->fetchColumn();

    // Samples dispatched (completed)
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM samples
        WHERE status IN ('signed','dispatched')
        AND is_deleted = 0
    ");
    $dispatched = (int)$stmt->fetchColumn();

    // Recent lots for the tracking table (last 10)
    $stmt = $pdo->query("
        SELECT
            l.lot_number,
            l.agency_type,
            l.created_at,
            COUNT(s.id)                                           AS total_samples,
            SUM(s.status IN ('signed','dispatched'))              AS completed,
            SUM(s.status = 'in_testing')                          AS in_testing,
            SUM(s.status IN ('received','payment_pending',
                'payment_cleared','lot_assigned','in_preparation')) AS pending
        FROM lots l
        LEFT JOIN samples s ON s.lot_id = l.id AND s.is_deleted = 0
        WHERE l.is_deleted = 0
        GROUP BY l.id
        ORDER BY l.created_at DESC
        LIMIT 15
    ");
    $recentLots = $stmt->fetchAll();

    echo json_encode([
        "success"        => true,
        "lots_today"     => $lotsToday,
        "total_lots"     => $totalLots,
        "pending_mapping"=> $pendingMapping,
        "in_testing"     => $inTesting,
        "awaiting_report"=> $awaitingReport,
        "dispatched"     => $dispatched,
        "recent_lots"    => $recentLots,
    ]);

} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Analytics query failed", "details" => ctms_db_error($e)]);
}
?>
