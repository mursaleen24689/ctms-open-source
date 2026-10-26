<?php
declare(strict_types=1);

/** Report workflow helpers. Requires db.php first. */
function ctms_report_review_enabled(PDO $pdo): bool
{
    try {
        return (bool)$pdo->query('SELECT require_technical_review FROM report_workflow_settings WHERE id = 1')->fetchColumn();
    } catch (Throwable $e) {
        // Fail closed when the workflow migration has not been installed.
        return true;
    }
}

function ctms_user_can_technical_review(PDO $pdo, int $userId): bool
{
    try {
        $stmt = $pdo->prepare('SELECT can_technical_review FROM users WHERE id = ? AND is_active = 1 AND is_deleted = 0');
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ctms_user_is_assigned_report_reviewer(PDO $pdo, int $userId): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM report_workflow_reviewers WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn() > 0 && ctms_user_can_technical_review($pdo, $userId);
    } catch (Throwable $e) {
        return false;
    }
}

function ctms_report_data_fingerprint(PDO $pdo, int $sampleId): string
{
    $snapshot = [];
    foreach (['test_tensile', 'test_xrf', 'test_spectroscopy', 'test_electronics'] as $table) {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE sample_id = ? AND is_deleted = 0 ORDER BY id");
        $stmt->execute([$sampleId]);
        $snapshot[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
