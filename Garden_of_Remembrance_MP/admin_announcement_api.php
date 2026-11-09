<?php

header('Content-Type: application/json; charset=utf-8');

function announcement_json_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole = strtoupper(trim((string)($_SESSION['role'] ?? '')));
$currentUid = (int)($_SESSION['user_id'] ?? 0);
$sessionCsrf = (string)($_SESSION['announcement_csrf'] ?? '');
session_write_close();

if ($currentUid <= 0 || $currentRole !== 'ADMIN') {
    announcement_json_error('Administrator privileges required', 403);
}

if (file_exists(__DIR__ . '/connection.php')) {
    require_once __DIR__ . '/connection.php';
} elseif (file_exists(__DIR__ . '/../connection.php')) {
    require_once __DIR__ . '/../connection.php';
}

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
    announcement_json_error('Database connection is unavailable', 503);
}

if (file_exists(__DIR__ . '/includes/notification_schema.php')) {
    require_once __DIR__ . '/includes/notification_schema.php';
} elseif (file_exists(__DIR__ . '/../includes/notification_schema.php')) {
    require_once __DIR__ . '/../includes/notification_schema.php';
}

if (!function_exists('ensure_notifications_table')) {
    function ensure_notifications_table(mysqli $conn): bool {
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

if (file_exists(__DIR__ . '/includes/notification_helper.php')) {
    require_once __DIR__ . '/includes/notification_helper.php';
} elseif (file_exists(__DIR__ . '/../includes/notification_helper.php')) {
    require_once __DIR__ . '/../includes/notification_helper.php';
}

if (!function_exists('notify_announcement')) {
    function notify_announcement(mysqli $conn, string $title, string $message, string $recipientGroup = 'all', ?int $specificUserId = null, bool $isPromotion = false): array {
        $stats = [
            'total_target'   => 0,
            'in_system_sent' => 0,
            'emails_sent'    => 0,
            'emails_failed'  => 0
        ];
        $type = $isPromotion ? 'promotion' : 'announcement';
        $targetUsers = [];

        if ($specificUserId && $specificUserId > 0) {
            $stmt = @$conn->prepare("SELECT user_id, first_name, last_name, email FROM tbl_user WHERE user_id = ? AND UPPER(TRIM(COALESCE(role, 'USER'))) <> 'ADMIN' LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $specificUserId);
                $stmt->execute();
                if ($u = $stmt->get_result()->fetch_assoc()) {
                    $targetUsers[] = $u;
                }
                $stmt->close();
            }
        } elseif ($recipientGroup === 'active_bookings') {
            $sql = "SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email 
                    FROM tbl_bookings b 
                    JOIN tbl_user u ON u.user_id = b.user_id 
                    WHERE b.status IN ('pending', 'approved')";
            $res = @$conn->query($sql);
            if ($res) {
                while ($u = $res->fetch_assoc()) $targetUsers[] = $u;
            }
        } elseif ($recipientGroup === 'active_installments') {
            $sql = "SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email 
                    FROM payment_schedules ps 
                    JOIN tbl_user u ON u.user_id = ps.user_id 
                    WHERE ps.status IN ('pending', 'overdue') AND ps.remaining_balance > 0";
            $res = @$conn->query($sql);
            if ($res) {
                while ($u = $res->fetch_assoc()) $targetUsers[] = $u;
            }
        } else {
            $sql = "SELECT user_id, first_name, last_name, email FROM tbl_user WHERE UPPER(TRIM(COALESCE(role, 'USER'))) <> 'ADMIN'";
            $res = @$conn->query($sql);
            if ($res) {
                while ($u = $res->fetch_assoc()) $targetUsers[] = $u;
            }
        }

        $stats['total_target'] = count($targetUsers);
        if ($stats['total_target'] === 0) {
            return $stats;
        }

        ensure_notifications_table($conn);
        $insStmt = @$conn->prepare("INSERT INTO notifications (user_id, booking_id, type, title, message, link, is_read, created_at) VALUES (?, NULL, ?, ?, ?, 'user_dashboard.php', 0, NOW())");

        foreach ($targetUsers as $u) {
            $uid = (int)$u['user_id'];
            if ($uid <= 0) continue;
            if ($insStmt) {
                $insStmt->bind_param('isss', $uid, $type, $title, $message);
                if ($insStmt->execute()) {
                    $stats['in_system_sent']++;
                }
            }
        }
        if ($insStmt) {
            $insStmt->close();
        }

        return $stats;
    }
}

$action = $_REQUEST['action'] ?? '';

if ($action === 'csrf_token') {
    if (empty($_SESSION['announcement_csrf'])) {
        @session_start();
        $_SESSION['announcement_csrf'] = bin2hex(random_bytes(32));
        session_write_close();
    }
    echo json_encode(['success' => true, 'csrf_token' => (string)($_SESSION['announcement_csrf'] ?? '')]);
    exit;
}

if (!in_array($action, ['preview_recipients', 'get_users_list', 'send'], true)) {
    announcement_json_error('Invalid action');
}

if ($action === 'preview_recipients') {
    $group = trim((string)($_GET['recipient_group'] ?? 'all'));
    $specificId = (int)($_GET['specific_user_id'] ?? 0);

    $count = 0;
    $sampleNames = [];

    if ($specificId > 0) {
        $stmt = $conn->prepare("SELECT user_id, first_name, last_name, email FROM tbl_user WHERE user_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $specificId);
            $stmt->execute();
            if ($r = $stmt->get_result()->fetch_assoc()) {
                $count = 1;
                $sampleNames[] = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) . ' (' . ($r['email'] ?? '') . ')';
            }
            $stmt->close();
        }
    } elseif ($group === 'active_bookings') {
        $sql = "SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email 
                FROM tbl_bookings b 
                JOIN tbl_user u ON u.user_id = b.user_id 
                WHERE b.status IN ('pending', 'approved')";
        $res = $conn->query($sql);
        if ($res) {
            $count = $res->num_rows;
            $i = 0;
            while ($r = $res->fetch_assoc()) {
                if ($i++ < 5) {
                    $sampleNames[] = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) . ' (' . ($r['email'] ?? '') . ')';
                }
            }
        }
    } elseif ($group === 'active_installments') {
        $sql = "SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email 
                FROM payment_schedules ps 
                JOIN tbl_user u ON u.user_id = ps.user_id 
                WHERE ps.status IN ('pending', 'overdue') AND ps.remaining_balance > 0";
        $res = $conn->query($sql);
        if ($res) {
            $count = $res->num_rows;
            $i = 0;
            while ($r = $res->fetch_assoc()) {
                if ($i++ < 5) {
                    $sampleNames[] = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) . ' (' . ($r['email'] ?? '') . ')';
                }
            }
        }
    } else {

        $sql = "SELECT user_id, first_name, last_name, email FROM tbl_user WHERE (UPPER(TRIM(COALESCE(role, 'USER'))) <> 'ADMIN')";
        $res = $conn->query($sql);
        if ($res) {
            $count = $res->num_rows;
            $i = 0;
            while ($r = $res->fetch_assoc()) {
                if ($i++ < 5) {
                    $sampleNames[] = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) . ' (' . ($r['email'] ?? '') . ')';
                }
            }
        }
    }

    echo json_encode([
        'success'      => true,
        'count'        => $count,
        'sample_names' => $sampleNames
    ]);
    exit;
}

if ($action === 'get_users_list') {
    $search = trim((string)($_GET['q'] ?? ''));
    $users = [];
    $sql = "SELECT user_id, first_name, last_name, email FROM tbl_user WHERE (UPPER(TRIM(COALESCE(role, 'USER'))) <> 'ADMIN')";
    if (!empty($search)) {
        $escaped = $conn->real_escape_string($search);
        $sql .= " AND (first_name LIKE '%$escaped%' OR last_name LIKE '%$escaped%' OR email LIKE '%$escaped%')";
    }
    $sql .= " ORDER BY first_name ASC LIMIT 50";
    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $users[] = [
                'user_id' => (int)$r['user_id'],
                'name'    => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'email'   => $r['email'] ?? ''
            ];
        }
    }
    echo json_encode(['success' => true, 'users' => $users]);
    exit;
}

if ($action === 'send') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        announcement_json_error('POST is required for sending notifications', 405);
    }
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if ($sessionCsrf === '' || $postedCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
        http_response_code(419);
        echo json_encode([
            'success' => false,
            'message' => 'Security token expired. Retrying with a fresh token.',
            'csrf_token' => $sessionCsrf
        ]);
        exit;
    }
    $title = trim((string)($_POST['title'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $type = trim((string)($_POST['type'] ?? 'announcement')); 
    $recipientGroup = trim((string)($_POST['recipient_group'] ?? 'all'));
    $specificUserId = isset($_POST['specific_user_id']) && (int)$_POST['specific_user_id'] > 0 ? (int)$_POST['specific_user_id'] : null;

    if (empty($title) || empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Please provide both title and message.']);
        exit;
    }

    $isPromotion = ($type === 'promotion');
    try {
        $stats = notify_announcement($conn, $title, $message, $recipientGroup, $specificUserId, $isPromotion);
    } catch (Throwable $e) {
        error_log('Admin announcement error: ' . $e->getMessage());
        announcement_json_error('Unable to send notification. Please try again.', 500);
    }

    if (($stats['in_system_sent'] ?? 0) < 1) {
        announcement_json_error('No notification was saved. Check the recipient group and notification table.', 422);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Broadcast dispatch completed successfully.',
        'stats'   => $stats
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
