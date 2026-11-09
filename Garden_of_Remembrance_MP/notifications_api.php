<?php

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$userRole = strtoupper(trim((string)($_SESSION['role'] ?? 'USER')));
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (php_sapi_name() === 'cli') {
    if (isset($argv[1])) {
        parse_str($argv[1], $cliArgs);
        $_GET = array_merge($_GET, $cliArgs);
        $_REQUEST = array_merge($_REQUEST, $cliArgs);
    }
    if ($userId <= 0 && !empty($_REQUEST['user_id'])) {
        $userId = (int)$_REQUEST['user_id'];
    }
}

if ($userId <= 0) {
    echo json_encode([
        'success'      => false,
        'logged_in'    => false,
        'unread_count' => 0,
        'notifications'=> [],
        'message'      => 'Authentication required'
    ]);
    exit;
}

if (file_exists(__DIR__ . '/connection.php')) {
    require_once __DIR__ . '/connection.php';
} elseif (file_exists(__DIR__ . '/../connection.php')) {
    require_once __DIR__ . '/../connection.php';
}

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
    echo json_encode([
        'success'      => false,
        'unread_count' => 0,
        'notifications'=> [],
        'message'      => 'Database connection error'
    ]);
    exit;
}

if (file_exists(__DIR__ . '/includes/notification_schema.php')) {
    require_once __DIR__ . '/includes/notification_schema.php';
} elseif (file_exists(__DIR__ . '/../includes/notification_schema.php')) {
    require_once __DIR__ . '/../includes/notification_schema.php';
}

if (!function_exists('ensure_notifications_table')) {
    function ensure_notifications_table(mysqli $conn): bool {
        static $checked = false;
        if ($checked) return true;
        $checked = true;
        $sql = "CREATE TABLE IF NOT EXISTS `notifications` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `booking_id` int(11) DEFAULT NULL,
            `type` varchar(50) NOT NULL DEFAULT 'system',
            `title` varchar(255) NOT NULL,
            `message` text NOT NULL,
            `link` varchar(255) DEFAULT NULL,
            `is_read` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `read_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_notifications_user_unread` (`user_id`,`is_read`),
            KEY `idx_notifications_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        return (bool)@$conn->query($sql);
    }
}


if (!ensure_notifications_table($conn)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'unread_count' => 0, 'notifications' => [], 'message' => 'Notification storage is unavailable']);
    exit;
}

$action = $_REQUEST['action'] ?? 'get_notifications';

if ($action === 'get_notifications' || $action === 'poll' || $action === 'get_latest_unread') {
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 15;
    $unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === '1';

    $unreadCount = 0;
    $cntStmt = $conn->prepare("SELECT COUNT(*) AS unread_cnt FROM notifications WHERE user_id = ? AND is_read = 0");
    if ($cntStmt) {
        $cntStmt->bind_param('i', $userId);
        $cntStmt->execute();
        if ($cRow = $cntStmt->get_result()->fetch_assoc()) {
            $unreadCount = (int)($cRow['unread_cnt'] ?? 0);
        }
        $cntStmt->close();
    }

    $notifications = [];
    $sql = "SELECT id, user_id, booking_id, type, title, message, link, is_read, created_at, read_at 
            FROM notifications 
            WHERE user_id = ?" . ($unreadOnly ? " AND is_read = 0" : "") . " 
            ORDER BY created_at DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $createdTs = strtotime($row['created_at']);
            $isToday = (date('Y-m-d', $createdTs) === date('Y-m-d'));
            $timeFormatted = $isToday ? date('g:i A', $createdTs) : date('M j, g:i A', $createdTs);

            $type = strtolower((string)($row['type'] ?? ''));
            $title = (string)($row['title'] ?? '');
            $msg = (string)($row['message'] ?? '');

            $isRejection = (
                strpos($type, 'reject') !== false ||
                stripos($title, 'reject') !== false ||
                stripos($msg, 'rejected') !== false
            );

            $reason = '';
            if ($isRejection) {
                if (preg_match('/Reason:\s*(.+?)(?:\.\s*(?:The unverified|Please contact|You may review|Record removed)|$)/is', $msg, $m)) {
                    $reason = trim($m[1], " \t\n\r\0\x0B.");
                } elseif (preg_match('/Reason:\s*([^\r\n]+)/i', $msg, $m)) {
                    $reason = trim($m[1], " \t\n\r\0\x0B.");
                }
            }

            $notifications[] = [
                'id'            => (int)$row['id'],
                'booking_id'    => $row['booking_id'] ? (int)$row['booking_id'] : null,
                'type'          => $row['type'],
                'title'         => $row['title'],
                'message'       => $row['message'],
                'link'          => $row['link'],
                'is_read'       => (bool)$row['is_read'],
                'created_at'    => $row['created_at'],
                'time_formatted'=> $timeFormatted,
                'is_rejection'  => $isRejection,
                'reason'        => $reason
            ];
        }
        $stmt->close();
    }

    echo json_encode([
        'success'      => true,
        'logged_in'    => true,
        'unread_count' => $unreadCount,
        'notifications'=> $notifications
    ]);
    exit;
}

if ($action === 'mark_as_read') {
    $notifId = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    if ($notifId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
    if ($stmt) {
        $stmt->bind_param('ii', $notifId, $userId);
        $stmt->execute();
        $stmt->close();
    }

    $unreadCount = 0;
    $cntStmt = $conn->prepare("SELECT COUNT(*) AS unread_cnt FROM notifications WHERE user_id = ? AND is_read = 0");
    if ($cntStmt) {
        $cntStmt->bind_param('i', $userId);
        $cntStmt->execute();
        if ($cRow = $cntStmt->get_result()->fetch_assoc()) {
            $unreadCount = (int)($cRow['unread_cnt'] ?? 0);
        }
        $cntStmt->close();
    }

    echo json_encode(['success' => true, 'unread_count' => $unreadCount]);
    exit;
}

if ($action === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['success' => true, 'unread_count' => 0]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
