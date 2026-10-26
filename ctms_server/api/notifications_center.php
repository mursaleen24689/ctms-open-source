<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications_lib.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$tokenData = require_auth();
ctms_notifications_ensure_schema($pdo);
$method = $_SERVER['REQUEST_METHOD'];
$userId = (int)$tokenData['user_id'];
$role = (string)$tokenData['role'];

if ($method === 'GET') {
    if (in_array($role,['hod','chairman'],true)) {
        echo json_encode(['success'=>true,'unread_count'=>0,'notifications'=>[]]); exit;
    }
    if (($_GET['admin'] ?? '') === '1') {
        ctms_require_role(['admin','superadmin'],$tokenData,'Administrator access is required to view announcement history.');
        $stmt = $pdo->query("SELECT a.id,a.kind,a.title,a.message,a.audience_roles,a.lot_id,a.created_at,u.full_name AS author_name,
            (SELECT COUNT(*) FROM ctms_announcement_receipts r WHERE r.announcement_id=a.id) AS recipient_count,
            (SELECT COUNT(*) FROM ctms_announcement_receipts r WHERE r.announcement_id=a.id AND r.read_at IS NOT NULL) AS read_count
            FROM ctms_announcements a LEFT JOIN users u ON u.id=a.created_by ORDER BY a.created_at DESC LIMIT 100");
        echo json_encode(['success'=>true,'announcements'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;
    }
    $stmt = $pdo->prepare("SELECT a.id,a.kind,a.title,a.message,a.lot_id,a.created_at,r.delivered_at,r.read_at,
        u.full_name AS author_name
        FROM ctms_announcement_receipts r JOIN ctms_announcements a ON a.id=r.announcement_id
        LEFT JOIN users u ON u.id=a.created_by
        WHERE r.user_id=? ORDER BY a.created_at DESC LIMIT 100");
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM ctms_announcement_receipts WHERE user_id=? AND read_at IS NULL');
    $unreadStmt->execute([$userId]);
    $unread = (int)$unreadStmt->fetchColumn();
    echo json_encode(['success'=>true,'unread_count'=>$unread,'notifications'=>$items]); exit;
}

if ($method !== 'POST') {
    http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit;
}
$data = json_decode(file_get_contents('php://input'),true) ?: [];
$action = (string)($data['action'] ?? '');

if ($action === 'mark_read') {
    if (in_array($role,['hod','chairman'],true)) { http_response_code(403); echo json_encode(['error'=>'Notifications are unavailable for this role.']); exit; }
    $id = (int)($data['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'Notification id is required.']); exit; }
    $stmt = $pdo->prepare('UPDATE ctms_announcement_receipts SET read_at=COALESCE(read_at,NOW()) WHERE announcement_id=? AND user_id=?');
    $stmt->execute([$id,$userId]);
    if ($stmt->rowCount() < 1) {
        $check = $pdo->prepare('SELECT 1 FROM ctms_announcement_receipts WHERE announcement_id=? AND user_id=?');
        $check->execute([$id,$userId]);
        if (!$check->fetchColumn()) { http_response_code(404); echo json_encode(['error'=>'Notification not found.']); exit; }
    }
    echo json_encode(['success'=>true]); exit;
}

if ($action === 'create_broadcast') {
    ctms_require_role(['admin','superadmin'],$tokenData,'Only administrators can publish an announcement.');
    $title = trim((string)($data['title'] ?? ''));
    $message = trim((string)($data['message'] ?? ''));
    $roles = $data['roles'] ?? [];
    $excludeRoles = $data['exclude_roles'] ?? [];
    $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
    $messageLength = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
    if ($title === '' || $titleLength > 200 || $message === '' || $messageLength > 5000 || !is_array($roles) || !is_array($excludeRoles)) {
        http_response_code(400); echo json_encode(['error'=>'Enter a title and message (up to 5,000 characters).']); exit;
    }
    try {
        $result = ctms_create_broadcast($pdo,$userId,$title,$message,$roles,$excludeRoles);
        try { $pdo->prepare("INSERT INTO audit_log (user_id,action,module,record_id,new_value,ip_address) VALUES (?,'ANNOUNCEMENT_PUBLISHED','notifications',?,?,?)")
            ->execute([$userId,$result['id'],json_encode(['roles'=>$result['roles'],'exclude_roles'=>$result['exclude_roles'],'recipient_count'=>$result['recipient_count']]),$_SERVER['REMOTE_ADDR'] ?? '']); } catch (Throwable $e) { }
        echo json_encode(['success'=>true]+$result); exit;
    } catch (InvalidArgumentException $e) {
        http_response_code(400); echo json_encode(['error'=>$e->getMessage()]); exit;
    } catch (Throwable $e) {
        error_log('[CTMS announcements] publish failed: '.$e->getMessage());
        http_response_code(500); echo json_encode(['error'=>'The announcement could not be published.']); exit;
    }
}

if ($action === 'receipts') {
    ctms_require_role(['admin','superadmin'],$tokenData,'Administrator access is required to view read receipts.');
    $id = (int)($data['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'Announcement id is required.']); exit; }
    $meta = $pdo->prepare('SELECT id,title,kind,created_at FROM ctms_announcements WHERE id=?');
    $meta->execute([$id]); $announcement = $meta->fetch(PDO::FETCH_ASSOC);
    if (!$announcement) { http_response_code(404); echo json_encode(['error'=>'Announcement not found.']); exit; }
    $stmt = $pdo->prepare("SELECT u.id AS user_id,u.full_name,u.employee_id,u.role,u.profile_pic,r.delivered_at,r.read_at
        FROM ctms_announcement_receipts r JOIN users u ON u.id=r.user_id
        WHERE r.announcement_id=? ORDER BY (r.read_at IS NULL) DESC,u.full_name ASC");
    $stmt->execute([$id]);
    $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'announcement'=>$announcement,'receipts'=>$receipts,
        'read_count'=>count(array_filter($receipts,fn($r)=>!empty($r['read_at']))),'recipient_count'=>count($receipts)]); exit;
}

http_response_code(400);
echo json_encode(['error'=>'Unknown notification action.']);
?>
