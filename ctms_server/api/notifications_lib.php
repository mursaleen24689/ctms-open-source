<?php
declare(strict_types=1);

function ctms_notifications_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctms_announcements (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(30) NOT NULL DEFAULT 'broadcast',
        title VARCHAR(200) NOT NULL,
        message TEXT NOT NULL,
        audience_roles TEXT NOT NULL,
        lot_id INT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_ctms_announcements_created (created_at),
        INDEX idx_ctms_announcements_lot (lot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ctms_announcement_receipts (
        announcement_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        delivered_at DATETIME NOT NULL,
        read_at DATETIME NULL,
        PRIMARY KEY (announcement_id,user_id),
        INDEX idx_ctms_announcement_user_unread (user_id,read_at,delivered_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

/** Create a durable lot-created alert for active, non-HOD staff who can see this lot. */
function ctms_notify_lot_created(PDO $pdo, int $lotId, string $lotNumber, string $lotType, int $creatorId): int
{
    ctms_notifications_ensure_schema($pdo);
    $roles = ['all_staff_except_hod'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO ctms_announcements (kind,title,message,audience_roles,lot_id,created_by,created_at)
            VALUES ('lot_created',?,?,?,?,?,NOW())")
            ->execute(["New lot created: {$lotNumber}", "A {$lotType} lot has been created. Open your work queue to review or begin the tasks available to your role.", json_encode($roles), $lotId, $creatorId]);
        $announcementId = (int)$pdo->lastInsertId();
        $recipients = $pdo->prepare("SELECT u.id FROM users u
            WHERE u.is_active=1 AND u.is_deleted=0 AND u.id<>?
              AND u.role NOT IN ('hod','chairman')
              AND (COALESCE(u.lot_access_restricted,0)=0 OR EXISTS (
                    SELECT 1 FROM user_lot_access ula WHERE ula.user_id=u.id AND ula.lot_id=?
              ))");
        $recipients->execute([$creatorId,$lotId]);
        $insert = $pdo->prepare('INSERT IGNORE INTO ctms_announcement_receipts (announcement_id,user_id,delivered_at) VALUES (?,?,NOW())');
        $count = 0;
        foreach ($recipients->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
            $insert->execute([$announcementId,(int)$recipientId]);
            $count += $insert->rowCount();
        }
        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Send a human-authored announcement to selected active role groups. */
function ctms_create_broadcast(PDO $pdo, int $authorId, string $title, string $message, array $requestedRoles, array $requestedExcludedRoles = []): array
{
    ctms_notifications_ensure_schema($pdo);
    $allowed = ['all','admin','superadmin','mapping','reception','pa','technician','deo'];
    $roles = array_values(array_unique(array_filter(array_map('strval',$requestedRoles), fn($r) => in_array($r,$allowed,true))));
    $excludedRoles = array_values(array_unique(array_filter(array_map('strval',$requestedExcludedRoles), fn($r) => in_array($r,['admin','superadmin','mapping','reception','pa','technician','deo'],true))));
    if (!$roles) throw new InvalidArgumentException('Select at least one employee role.');
    if (in_array('all',$roles,true)) $roles = ['all'];
    if (!in_array('all',$roles,true)) $excludedRoles = [];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO ctms_announcements (kind,title,message,audience_roles,created_by,created_at)
            VALUES ('broadcast',?,?,?,?,NOW())")
            ->execute([$title,$message,json_encode(['include'=>$roles,'exclude'=>$excludedRoles],JSON_UNESCAPED_UNICODE),$authorId]);
        $announcementId = (int)$pdo->lastInsertId();

        $conditions = ["u.is_active=1", "u.is_deleted=0", 'u.id<>?', "u.role NOT IN ('hod','chairman')"];
        $params = [$authorId];
        if (!in_array('all',$roles,true)) {
            $roleConditions = [];
            foreach ($roles as $role) {
                if ($role === 'pa') $roleConditions[] = "(u.role='reception' AND COALESCE(u.profile_pic,'')='pa')";
                elseif ($role === 'reception') $roleConditions[] = "(u.role='reception' AND COALESCE(u.profile_pic,'')<>'pa')";
                else { $roleConditions[] = 'u.role=?'; $params[] = $role; }
            }
            $conditions[] = '(' . implode(' OR ',$roleConditions) . ')';
        }
        if ($excludedRoles) {
            $excludeConditions = [];
            foreach ($excludedRoles as $role) {
                if ($role === 'pa') $excludeConditions[] = "(u.role='reception' AND COALESCE(u.profile_pic,'')='pa')";
                elseif ($role === 'reception') $excludeConditions[] = "(u.role='reception' AND COALESCE(u.profile_pic,'')<>'pa')";
                else { $excludeConditions[] = 'u.role=?'; $params[] = $role; }
            }
            $conditions[] = 'NOT (' . implode(' OR ',$excludeConditions) . ')';
        }
        $sql = 'SELECT u.id FROM users u WHERE ' . implode(' AND ',$conditions);
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $insert = $pdo->prepare('INSERT IGNORE INTO ctms_announcement_receipts (announcement_id,user_id,delivered_at) VALUES (?,?,NOW())');
        $count = 0;
        foreach ($recipients as $recipientId) { $insert->execute([$announcementId,(int)$recipientId]); $count += $insert->rowCount(); }
        if ($count < 1) throw new InvalidArgumentException('No active users match the selected roles.');
        $pdo->commit();
        return ['id'=>$announcementId,'recipient_count'=>$count,'roles'=>$roles,'exclude_roles'=>$excludedRoles];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
?>
