<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Unauthorized. Please log in.');
}

include_once __DIR__ . '/connection.php';

$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
$filename = isset($_GET['file']) ? basename(trim($_GET['file'])) : '';
$user_id = (int)$_SESSION['user_id'];
$is_admin = isset($_SESSION['role']) && strtoupper($_SESSION['role']) === 'ADMIN';
$request_id = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;

if (empty($filename) || (!$is_admin && $booking_id <= 0 && $request_id <= 0)) {
    http_response_code(400);
    die('Invalid request parameters.');
}

if (!$is_admin) {
    $hasAccess = false;

    if ($booking_id > 0) {
        $stmt = $conn->prepare("SELECT b.id, b.user_id, b.payment_proof_filename, pr.proof_filename AS pr_proof
            FROM tbl_bookings b
            LEFT JOIN payment_receipts pr ON pr.booking_id = b.id
            WHERE b.id = ? AND (pr.proof_filename = ? OR b.payment_proof_filename = ?)");

        if ($stmt) {
            $stmt->bind_param('iss', $booking_id, $filename, $filename);
            $stmt->execute();
            $result = $stmt->get_result();
            $booking = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if ($booking && (int)$booking['user_id'] === $user_id) {
                $hasAccess = true;
            }
        }
    }

    if (!$hasAccess) {
        $grCheck = $conn->prepare("SELECT id, user_id FROM guest_reservation_requests WHERE proof_filename = ? OR payment_reference = ? OR id = ? LIMIT 1");
        if ($grCheck) {
            $grCheck->bind_param('ssi', $filename, $filename, $request_id);
            $grCheck->execute();
            $grRow = $grCheck->get_result()->fetch_assoc();
            $grCheck->close();
            if ($grRow) {
                $hasAccess = true;
            }
        }
    }

    if (!$hasAccess) {
        http_response_code(403);
        die('Access denied.');
    }
}

$filepath = __DIR__ . '/uploads/payment-proofs/' . $filename;

$realpath = realpath($filepath);
$upload_dir = realpath(__DIR__ . '/uploads/payment-proofs');

if ($realpath === false || strpos($realpath, $upload_dir) !== 0) {
    http_response_code(404);
    die('File not found.');
}

if (!file_exists($filepath) || !is_file($filepath)) {
    http_response_code(404);
    die('Payment proof image file not found on disk.');
}

$mime_types = [
    'jpg'   => 'image/jpeg',
    'jpeg'  => 'image/jpeg',
    'png'   => 'image/png',
    'webp'  => 'image/webp',
    'pdf'   => 'application/pdf'
];

$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mime_type = isset($mime_types[$ext]) ? $mime_types[$ext] : 'image/jpeg';
$is_pdf = ($ext === 'pdf');

$is_raw = isset($_GET['raw']) && ($_GET['raw'] == '1' || $_GET['raw'] === 'true');
if (!$is_raw && isset($_SERVER['HTTP_SEC_FETCH_DEST']) && ($_SERVER['HTTP_SEC_FETCH_DEST'] === 'image' || $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe')) {
    $is_raw = true;
}
if (!$is_raw && !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'text/html') === false && (strpos($_SERVER['HTTP_ACCEPT'], 'image/') !== false || strpos($_SERVER['HTTP_ACCEPT'], 'application/pdf') !== false)) {
    $is_raw = true;
}

if ($is_raw || isset($_GET['download'])) {
    header('Content-Type: ' . $mime_type);
    $disposition = isset($_GET['download']) ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
    header('Cache-Control: public, max-age=3600');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

$filesize_formatted = number_format(filesize($filepath) / 1024, 1) . ' KB';
$download_url = 'view_payment_proof.php?booking_id=' . $booking_id . '&file=' . urlencode($filename) . '&download=1';
$raw_url = 'view_payment_proof.php?booking_id=' . $booking_id . '&file=' . urlencode($filename) . '&raw=1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Proof - Booking #<?php echo $booking_id; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(8px);
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .proof-modal-card {
            background: #ffffff;
            border-radius: 1.25rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            max-width: 820px;
            width: 100%;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: modalFadeIn 0.25s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .proof-modal-header {
            background: linear-gradient(135deg, #7b1113 0%, #5a0c0e 100%);
            color: #ffffff;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .proof-modal-body {
            padding: 1.5rem;
            background: #f8fafc;
            text-align: center;
            max-height: 72vh;
            overflow-y: auto;
        }
        .proof-modal-image {
            max-width: 100%;
            max-height: 62vh;
            object-fit: contain;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
            border: 1px solid #e2e8f0;
            background: #ffffff;
        }
        .proof-modal-footer {
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .btn-close-custom {
            background: #e2e8f0;
            border: none;
            color: #1e293b;
            font-weight: 600;
            border-radius: 8px;
            padding: 8px 20px;
            transition: all 0.2s;
        }
        .btn-close-custom:hover {
            background: #cbd5e1;
            color: #0f172a;
        }
        .btn-download-custom {
            background: #7b1113;
            border: none;
            color: #ffffff;
            font-weight: 600;
            border-radius: 8px;
            padding: 8px 20px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-download-custom:hover {
            background: #5a0c0e;
            color: #ffffff;
        }
        .backdrop-click-area {
            position: fixed;
            inset: 0;
            z-index: 1;
        }
        .proof-modal-card {
            position: relative;
            z-index: 2;
        }
    </style>
</head>
<body>

    <div class="backdrop-click-area" onclick="closeViewer()" title="Click outside to close"></div>

    <div class="proof-modal-card" role="dialog" aria-modal="true">

        <div class="proof-modal-header">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-receipt text-warning fs-5"></i>
                <div>
                    <h6 class="mb-0 fw-bold text-white">Payment Proof Screenshot</h6>
                    <small class="text-white-50">Booking Reference #<?php echo $booking_id; ?></small>
                </div>
            </div>
            <button type="button" class="btn-close btn-close-white" onclick="closeViewer()" aria-label="Close" title="Close Proof Viewer"></button>
        </div>

        <div class="proof-modal-body">
            <?php if ($is_pdf): ?>
                <iframe src="<?php echo htmlspecialchars($raw_url); ?>" style="width: 100%; height: 60vh; border-radius: 8px; border: 1px solid #e2e8f0;"></iframe>
            <?php else: ?>
                <img src="<?php echo htmlspecialchars($raw_url); ?>" alt="Payment Proof for Booking #<?php echo $booking_id; ?>" class="proof-modal-image">
            <?php endif; ?>
            <div class="mt-2 text-muted small">
                <i class="fas <?php echo $is_pdf ? 'fa-file-pdf text-danger' : 'fa-file-image text-primary'; ?> me-1"></i> <?php echo htmlspecialchars($filename); ?> &bull; <?php echo $filesize_formatted; ?>
            </div>
        </div>

        <div class="proof-modal-footer">
            <span class="text-muted small">
                <kbd style="background:#e2e8f0;color:#334155;padding:2px 6px;border-radius:4px;">ESC</kbd> or click outside to close
            </span>
            <div class="d-flex align-items-center gap-2">
                <a href="<?php echo htmlspecialchars($download_url); ?>" class="btn-download-custom">
                    <i class="fas fa-download me-1"></i> Download
                </a>
                <button type="button" id="closeProofBtn" class="btn-close-custom" onclick="closeViewer()">
                    <i class="fas fa-times me-1"></i> Close
                </button>
            </div>
        </div>
    </div>

    <script>
    function closeViewer() {
        if (window.opener && !window.opener.closed) {
            window.close();
        } else if (window.history.length > 1) {
            window.history.back();
        } else {
            window.close();
            // Fallback for direct URL tabs
            setTimeout(function() {
                window.location.href = 'dashboard.php';
            }, 250);
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeViewer();
        }
    });
    </script>

</body>
</html>
<?php exit; ?>
