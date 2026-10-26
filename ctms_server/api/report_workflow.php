<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_workflow_lib.php';

$actor = require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$userId = (int)$actor['user_id'];
$role = (string)($actor['role'] ?? '');
$isSuperadmin = $role === 'superadmin';
$isReporter = in_array($role, ['superadmin', 'admin', 'deo'], true);
$isReportViewer = $isReporter || $role === 'hod';
$isReviewer = ctms_user_is_assigned_report_reviewer($pdo, $userId);

if ($method === 'GET') {
    try {
        $settings = ['require_technical_review' => ctms_report_review_enabled($pdo), 'reviewer_ids' => []];
        $reviewers = [];
        if ($isSuperadmin) {
            $settings['reviewer_ids'] = array_map('intval', $pdo->query('SELECT user_id FROM report_workflow_reviewers ORDER BY user_id')->fetchAll(PDO::FETCH_COLUMN));
            $reviewers = $pdo->query("SELECT id, full_name, employee_id FROM users WHERE is_deleted = 0 AND is_active = 1 AND can_technical_review = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        }

        $sql = "SELECT r.id AS report_id, r.sample_id, r.report_number, r.lot_number, r.sample_type,
                       r.identifier, r.generated_at, s.sd_number, s.reference_number,
                       w.stage, w.revision_no, w.submitted_at, w.reviewed_at, w.review_notes,
                       us.full_name AS submitted_by_name, ur.full_name AS reviewer_name,
                       ui.full_name AS issued_by_name
                FROM report_approvals w
                JOIN reports r ON r.id = w.report_id AND r.is_deleted = 0
                LEFT JOIN samples s ON s.id = r.sample_id
                LEFT JOIN users us ON us.id = w.submitted_by
                LEFT JOIN users ur ON ur.id = w.reviewed_by
                LEFT JOIN users ui ON ui.id = w.issued_by";
        $params = [];
        $conditions = [];
        if ($isReviewer && !$isReportViewer) {
            $conditions[] = "w.stage = 'technical_review'";
        } elseif (!$isSuperadmin && !$isReportViewer && !$isReviewer) {
            http_response_code(403);
            echo json_encode(['error' => 'Report workflow access is restricted.']);
            exit;
        }
        if (ctms_user_lot_access_restricted($pdo, $userId)) {
            $conditions[] = 'EXISTS (SELECT 1 FROM user_lot_access ula WHERE ula.user_id = ' . $userId . ' AND ula.lot_id = s.lot_id)';
        }
        if ($conditions) $sql .= ' WHERE ' . implode(' AND ', $conditions);
        $sql .= ' ORDER BY FIELD(w.stage, \'technical_review\', \'changes_requested\', \'draft\', \'issued\'), r.generated_at DESC LIMIT 500';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $ids = array_map(static fn($row) => (int)$row['report_id'], $rows);
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $eventsStmt = $pdo->prepare("SELECT e.report_id, e.action, e.from_stage, e.to_stage, e.notes, e.created_at, u.full_name AS actor_name FROM report_workflow_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.report_id IN ($marks) ORDER BY e.created_at, e.id");
            $eventsStmt->execute($ids);
            $byReport = [];
            foreach ($eventsStmt->fetchAll(PDO::FETCH_ASSOC) as $event) $byReport[(int)$event['report_id']][] = $event;
            foreach ($rows as &$row) $row['history'] = $byReport[(int)$row['report_id']] ?? [];
            unset($row);
        }
        echo json_encode(['success' => true, 'can_configure' => $isSuperadmin, 'can_review' => $isReviewer, 'settings' => $settings, 'reviewers' => $reviewers, 'reports' => $rows]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Report workflow is unavailable. Apply the report workflow database update first.']);
    }
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($data['action'] ?? '');

try {
    if ($action === 'configure') {
        if (!$isSuperadmin) {
            http_response_code(403);
            echo json_encode(['error' => 'Only the Super Administrator can configure report approvals.']);
            exit;
        }
        $required = !empty($data['require_technical_review']) ? 1 : 0;
        $reviewerIds = array_values(array_unique(array_filter(array_map('intval', $data['reviewer_ids'] ?? []), fn($id) => $id > 0)));
        if ($required && !$reviewerIds) {
            http_response_code(400);
            echo json_encode(['error' => 'Assign at least one Technical Reviewer before requiring review.']);
            exit;
        }
        if ($reviewerIds) {
            $marks = implode(',', array_fill(0, count($reviewerIds), '?'));
            $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE can_technical_review = 1 AND is_active = 1 AND is_deleted = 0 AND id IN ($marks)");
            $check->execute($reviewerIds);
            if ((int)$check->fetchColumn() !== count($reviewerIds)) {
                http_response_code(400);
                echo json_encode(['error' => 'Every selected reviewer must be an active user with the Technical Reviewer permission.']);
                exit;
            }
        }
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO report_workflow_settings (id, require_technical_review) VALUES (1, 1) ON DUPLICATE KEY UPDATE require_technical_review = VALUES(require_technical_review)');
        $pdo->exec('DELETE FROM report_workflow_reviewers');
        $insert = $pdo->prepare('INSERT INTO report_workflow_reviewers (user_id) VALUES (?)');
        foreach ($reviewerIds as $rid) $insert->execute([$rid]);
        $pdo->commit();
        try {
            $pdo->prepare("INSERT INTO audit_log (user_id, action, module, record_id, new_value, ip_address) VALUES (?, 'REPORT_WORKFLOW_CONFIGURED', 'reports', 1, ?, ?)")
                ->execute([$userId, json_encode(['require_technical_review' => (bool)$required, 'reviewer_ids' => $reviewerIds]), $_SERVER['REMOTE_ADDR'] ?? '']);
        } catch (Throwable $ignored) { /* the workflow configuration is already saved */ }
        echo json_encode(['success' => true, 'message' => 'Report workflow settings saved.']);
        exit;
    }

    $reportId = (int)($data['report_id'] ?? 0);
    if ($reportId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'report_id is required']);
        exit;
    }
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT w.*, r.sample_id, s.lot_id FROM report_approvals w JOIN reports r ON r.id = w.report_id JOIN samples s ON s.id = r.sample_id WHERE w.report_id = ? AND r.is_deleted = 0 FOR UPDATE');
    $st->execute([$reportId]);
    $record = $st->fetch(PDO::FETCH_ASSOC);
    if (!$record) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Report workflow record not found. Open the report once to register it.']);
        exit;
    }
    $minimumAccess = $action === 'approve' || $action === 'return' ? 'view' : 'edit';
    if ($action === 'reopen') $minimumAccess = 'manage';
    if (!ctms_user_has_lot_access($pdo, $userId, (int)$record['lot_id'], $minimumAccess)) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['error' => 'Your lot assignment does not allow this report workflow action.']);
        exit;
    }
    $oldStage = (string)$record['stage'];
    $note = trim((string)($data['notes'] ?? ''));

    if ($action === 'submit') {
        if (!$isReporter) {
            $pdo->rollBack();
            http_response_code(403);
            echo json_encode(['error' => 'Only authorized reporting staff can submit reports for review.']);
            exit;
        }
        if (!in_array($oldStage, ['draft', 'changes_requested'], true)) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'Only draft or returned reports can be submitted for review.']);
            exit;
        }
        $required = ctms_report_review_enabled($pdo);
        if ($required) {
            $reviewerCount = (int)$pdo->query('SELECT COUNT(*) FROM report_workflow_reviewers rw JOIN users u ON u.id = rw.user_id WHERE u.is_active = 1 AND u.is_deleted = 0 AND u.can_technical_review = 1')->fetchColumn();
            if (!$reviewerCount) throw new RuntimeException('No active Technical Reviewers are configured. Ask the Super Administrator to configure the workflow.');
        }
        $submissionHash = ctms_report_data_fingerprint($pdo, (int)$record['sample_id']);
        $next = $required ? 'technical_review' : 'issued';
        $up = $pdo->prepare('UPDATE report_approvals SET stage = ?, revision_no = revision_no + ?, submitted_by = ?, submitted_at = NOW(), submission_hash = ?, reviewed_by = NULL, reviewed_at = NULL, review_notes = NULL, issued_by = ?, issued_at = ? WHERE report_id = ?');
        $up->execute([$next, $oldStage === 'changes_requested' ? 1 : 0, $userId, $submissionHash, $required ? null : $userId, $required ? null : date('Y-m-d H:i:s'), $reportId]);
        $message = $required ? 'Report submitted for Technical Review.' : 'Report issued; Technical Review is disabled by the Super Administrator.';
    } elseif ($action === 'approve' || $action === 'return') {
        if (!$isReviewer || !ctms_user_can_technical_review($pdo, $userId)) {
            $pdo->rollBack();
            http_response_code(403);
            echo json_encode(['error' => 'An assigned Technical Reviewer is required for this action.']);
            exit;
        }
        $assigned = $pdo->prepare('SELECT COUNT(*) FROM report_workflow_reviewers WHERE user_id = ?');
        $assigned->execute([$userId]);
        if (!(int)$assigned->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(403);
            echo json_encode(['error' => 'You are not selected in the active Technical Review workflow.']);
            exit;
        }
        if ($oldStage !== 'technical_review') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'This report is not awaiting Technical Review.']);
            exit;
        }
        if ((int)$record['submitted_by'] === $userId) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'The person who submitted a report cannot technically review the same revision.']);
            exit;
        }
        if ($action === 'approve') {
            $currentHash = ctms_report_data_fingerprint($pdo, (int)$record['sample_id']);
            if (empty($record['submission_hash']) || !hash_equals((string)$record['submission_hash'], $currentHash)) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['error' => 'The laboratory data changed after submission. Return the report for correction and submit the updated revision for review.']);
                exit;
            }
        }
        if ($action === 'return' && $note === '') {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Enter the correction reason before returning a report.']);
            exit;
        }
        $next = $action === 'approve' ? 'issued' : 'changes_requested';
        $up = $pdo->prepare('UPDATE report_approvals SET stage = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?, issued_by = ?, issued_at = ? WHERE report_id = ?');
        $up->execute([$next, $userId, $note ?: null, $action === 'approve' ? $userId : null, $action === 'approve' ? date('Y-m-d H:i:s') : null, $reportId]);
        $message = $action === 'approve' ? 'Technical review approved. Report is now Issued.' : 'Report returned for correction.';
    } elseif ($action === 'reopen') {
        if (!in_array($role, ['superadmin', 'admin'], true)) {
            $pdo->rollBack();
            http_response_code(403);
            echo json_encode(['error' => 'Only CTMS administrators can reopen an Issued report for correction.']);
            exit;
        }
        if ($oldStage !== 'issued' || $note === '') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'An Issued report and a correction reason are required.']);
            exit;
        }
        $up = $pdo->prepare("UPDATE report_approvals SET stage = 'changes_requested', review_notes = ?, issued_by = NULL, issued_at = NULL WHERE report_id = ?");
        $up->execute([$note, $reportId]);
        $next = 'changes_requested';
        $message = 'Report reopened for a documented correction. The next issue will create a new revision.';
    } else {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported workflow action.']);
        exit;
    }

    $pdo->prepare('INSERT INTO report_workflow_events (report_id, actor_id, action, from_stage, to_stage, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$reportId, $userId, strtoupper($action), $oldStage, $next, $note ?: null]);
    if ($next === 'issued') {
        // A preview PDF can carry the Draft watermark. Force the official
        // archive/print path to regenerate it after approval.
        $pdo->prepare('UPDATE reports SET pdf_path = NULL WHERE id = ?')->execute([$reportId]);
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'stage' => $next, 'message' => $message]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof RuntimeException) {
        http_response_code(409);
        echo json_encode(['error' => $e->getMessage()]);
    } else {
        error_log('[CTMS report workflow] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not update report workflow. Check that the database update has been applied.']);
    }
}
