<?php

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Asia/Manila');
}

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/mailer.php';

$active_nav = 'guest_reservations';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

$user_id = (int)($_SESSION['user_id'] ?? ($_SESSION['admin_id'] ?? 0));
$role = strtoupper(trim((string)($_SESSION['role'] ?? '')));

if ($user_id > 0 && $role !== 'ADMIN' && isset($conn) && function_exists('is_db_connected') && is_db_connected()) {
    $rCheck = $conn->prepare("SELECT role FROM tbl_user WHERE user_id = ? LIMIT 1");
    if ($rCheck) {
        $rCheck->bind_param('i', $user_id);
        $rCheck->execute();
        $rRes = $rCheck->get_result()->fetch_assoc();
        $rCheck->close();
        if ($rRes && strtoupper(trim((string)$rRes['role'])) === 'ADMIN') {
            $_SESSION['role'] = 'ADMIN';
            $role = 'ADMIN';
        }
    }
}

if ($user_id <= 0 || $role !== 'ADMIN') {
    if ($isPost) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'session_expired' => true,
            'message' => 'Your administrator session has ended. Please sign in.'
        ]);
        exit;
    }
    header('Location: login.php');
    exit;
}

$admin_id = $user_id;

if (function_exists('ensure_booking_table_integrity')) {
    ensure_booking_table_integrity($conn);
}
if (function_exists('ensure_guest_reservation_columns')) {
    ensure_guest_reservation_columns($conn);
}

if (!function_exists('ensure_guest_reservations_data_integrity')) {
    function ensure_guest_reservations_data_integrity($conn) {
        if (!($conn instanceof mysqli) || $conn->connect_errno) return;
        static $executed = false;
        if ($executed) return;
        $executed = true;

        try {
            // 1. Ensure primary table exists
            $tCheck = @$conn->query("SHOW TABLES LIKE 'guest_reservation_requests'");
            if (!$tCheck || $tCheck->num_rows === 0) {
                if (function_exists('ensure_guest_reservation_columns')) {
                    ensure_guest_reservation_columns($conn);
                }
            }

            // 2. If legacy tbl_guest_reservation_requests exists, sync missing rows
            $tAlt = @$conn->query("SHOW TABLES LIKE 'tbl_guest_reservation_requests'");
            if ($tAlt && $tAlt->num_rows > 0) {
                @$conn->query("INSERT IGNORE INTO `guest_reservation_requests` 
                    (`id`, `user_id`, `plot_id`, `booking_id`, `full_name`, `email`, `contact_number`, `address`, `section`, `plot_x`, `plot_y`, `plot_code`, `purpose`, `preferred_date`, `notes`, `status`, `created_at`, `updated_at`)
                    SELECT `id`, `user_id`, `plot_id`, `booking_id`, `full_name`, `email`, `contact_number`, `address`, `section`, `plot_x`, `plot_y`, `plot_code`, `purpose`, `preferred_date`, `notes`, `status`, `created_at`, `updated_at` 
                    FROM `tbl_guest_reservation_requests`");
            }

            // 3. Ensure essential columns exist in guest_reservation_requests
            $grrCols = [];
            $cRes = @$conn->query("SHOW COLUMNS FROM `guest_reservation_requests`");
            if ($cRes) {
                while ($c = $cRes->fetch_assoc()) {
                    $grrCols[strtolower($c['Field'])] = true;
                }
            }
            $colsToVerify = [
                'request_type'           => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `request_type` VARCHAR(50) NOT NULL DEFAULT 'guest' AFTER `booking_id`",
                'deceased_first_name'    => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_first_name` VARCHAR(100) NULL AFTER `notes`",
                'deceased_middle_name'   => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_middle_name` VARCHAR(100) NULL AFTER `deceased_first_name`",
                'deceased_last_name'     => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_last_name` VARCHAR(100) NULL AFTER `deceased_middle_name`",
                'deceased_suffix'        => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_suffix` VARCHAR(20) NULL AFTER `deceased_last_name`",
                'deceased_bday'          => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_bday` DATE NULL AFTER `deceased_suffix`",
                'deceased_date_died'     => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_date_died` DATE NULL AFTER `deceased_bday`",
                'deceased_relationship'  => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_relationship` VARCHAR(50) NULL AFTER `deceased_date_died`",
                'funeral_home'           => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `funeral_home` VARCHAR(150) NULL AFTER `deceased_relationship`",
                'matched_deceased_id'    => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `matched_deceased_id` INT(11) NULL AFTER `funeral_home`",
                'deceased_full_name'     => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `deceased_full_name` VARCHAR(255) NULL AFTER `matched_deceased_id`",
                'payment_nonce'          => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_nonce` VARCHAR(64) NULL AFTER `deceased_full_name`",
                'payment_status'         => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_status` VARCHAR(20) NOT NULL DEFAULT 'UNPAID' AFTER `payment_nonce`",
                'payment_amount'         => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `payment_status`",
                'payment_date'           => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_date` DATETIME NULL AFTER `payment_amount`",
                'gcash_reference'        => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `gcash_reference` VARCHAR(100) NULL AFTER `payment_date`",
                'payment_receipt_id'     => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_receipt_id` INT(11) NULL AFTER `gcash_reference`",
                'proof_filename'         => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `proof_filename` VARCHAR(255) NULL AFTER `payment_receipt_id`",
                'payment_reference'      => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_reference` VARCHAR(100) NULL AFTER `proof_filename`",
                'cancellation_reason'    => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `cancellation_reason` TEXT NULL AFTER `admin_notes`",
                'cancellation_requested' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `cancellation_requested` TINYINT(1) NOT NULL DEFAULT 0 AFTER `cancellation_reason`",
                'cancellation_requested_at' => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `cancellation_requested_at` DATETIME NULL AFTER `cancellation_requested`",
                'cancelled_by'           => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `cancelled_by` VARCHAR(100) NULL AFTER `cancelled_at`",
                'payment_plan'           => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_plan` VARCHAR(20) NOT NULL DEFAULT 'installment' AFTER `cancelled_by`",
                'payment_term'           => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `payment_term` INT(11) NOT NULL DEFAULT 36 AFTER `payment_plan`",
                'downpayment_amount'     => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `downpayment_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `payment_term`",
                'downpayment'            => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `downpayment` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `downpayment_amount`",
                'monthly_due'            => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `monthly_due` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `downpayment`",
                'first_due_date'         => "ALTER TABLE `guest_reservation_requests` ADD COLUMN `first_due_date` DATE NULL DEFAULT NULL AFTER `monthly_due`"
            ];
            foreach ($colsToVerify as $ckey => $csql) {
                if (!isset($grrCols[strtolower($ckey)])) {
                    @$conn->query($csql);
                }
            }

            // 4. Automatically sync unmapped guest reservations from tbl_bookings if any exist
            $chkGuestBk = @$conn->query("
                SELECT b.* FROM `tbl_bookings` b
                LEFT JOIN `guest_reservation_requests` grr ON grr.booking_id = b.id
                WHERE grr.id IS NULL 
                  AND (
                      b.is_guest_booking = 1 
                      OR b.booking_type = 'guest_reservation' 
                      OR LOWER(b.source) = 'guest' 
                      OR b.user_id IS NULL 
                      OR b.user_id = 0
                      OR LOWER(b.customer_name) LIKE '%automated%'
                      OR LOWER(b.full_name) LIKE '%automated%'
                      OR LOWER(b.customer_name) LIKE '%test%'
                      OR LOWER(b.full_name) LIKE '%test%'
                      OR (b.email IS NOT NULL AND b.email != '')
                  )
                ORDER BY b.id ASC
            ");
            if ($chkGuestBk && $chkGuestBk->num_rows > 0) {
                while ($bRow = $chkGuestBk->fetch_assoc()) {
                    $bId = (int)$bRow['id'];
                    $bName = trim((string)($bRow['customer_name'] ?? ($bRow['full_name'] ?? 'Guest Booker')));
                    $bEmail = trim((string)($bRow['customer_email'] ?? ($bRow['email'] ?? '')));
                    $bContact = trim((string)($bRow['contact_number'] ?? ''));
                    $bAddress = trim((string)($bRow['booker_location'] ?? 'Memorial Park Office'));
                    $bSec = strtoupper(trim((string)($bRow['section'] ?? 'A')));
                    $bPlot = trim((string)($bRow['plot'] ?? '1'));
                    $bPurpose = trim((string)($bRow['purpose'] ?? 'Future Memorial Lot'));
                    $bPrefDate = !empty($bRow['preferred_date']) ? $bRow['preferred_date'] : null;
                    $bNotes = trim((string)($bRow['remarks'] ?? ($bRow['special_requests'] ?? '')));
                    $bRawStatus = strtolower(trim((string)($bRow['status'] ?? 'pending')));
                    $bStatus = 'Pending';
                    if (in_array($bRawStatus, ['approved', 'confirmed'])) $bStatus = 'Approved';
                    elseif (in_array($bRawStatus, ['rejected', 'declined'])) $bStatus = 'Rejected';
                    elseif (in_array($bRawStatus, ['cancelled'])) $bStatus = 'Cancelled';

                    $bDecName = trim((string)($bRow['deceased_name'] ?? ''));
                    $bDownpayment = floatval($bRow['amount_paid'] ?? ($bRow['downpayment'] ?? 0));
                    $bProof = trim((string)($bRow['payment_proof_filename'] ?? ''));
                    $bRef = trim((string)($bRow['gcash_reference_number'] ?? ($bRow['reference_number'] ?? '')));
                    $bPayStat = (!empty($bProof) || $bDownpayment > 0) ? 'PAID' : 'UNPAID';
                    if ($bPayStat === 'UNPAID' && $bStatus === 'Pending') {
                        $bStatus = 'Awaiting Payment';
                    }
                    $bCreated = !empty($bRow['created_at']) ? $bRow['created_at'] : date('Y-m-d H:i:s');

                    $px = 1; $py = 1;
                    if (preg_match('/(\d+)[-_](\d+)/', $bPlot, $pm)) {
                        $px = (int)$pm[1]; $py = (int)$pm[2];
                    } elseif (preg_match('/(\d+)/', $bPlot, $pm)) {
                        $px = (int)$pm[1];
                    }

                    $syncStmt = $conn->prepare("INSERT INTO `guest_reservation_requests` 
                        (booking_id, full_name, email, contact_number, address, section, plot_x, plot_y, plot_code, purpose, preferred_date, notes, status, deceased_full_name, payment_status, payment_amount, downpayment_amount, downpayment, gcash_reference, payment_reference, proof_filename, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($syncStmt) {
                        $syncStmt->bind_param(
                            'isssssiisssssssdddsssss',
                            $bId, $bName, $bEmail, $bContact, $bAddress, $bSec, $px, $py, $bPlot, $bPurpose, $bPrefDate, $bNotes, $bStatus, $bDecName,
                            $bPayStat, $bDownpayment, $bDownpayment, $bDownpayment, $bRef, $bRef, $bProof, $bCreated, $bCreated
                        );
                        $syncStmt->execute();
                        $syncStmt->close();
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('ensure_guest_reservations_data_integrity notice: ' . $e->getMessage());
        }
    }
}

ensure_guest_reservations_data_integrity($conn);

if ($isPost) {
    header('Content-Type: application/json; charset=utf-8');
    $action = trim((string)($_POST['action'] ?? ''));
    $requestId = (int)($_POST['request_id'] ?? 0);

    try {
        if ($requestId <= 0 && $action !== 'filter') {
            throw new Exception("Invalid request reference.");
        }

        if ($action === 'get_details') {
            $stmt = $conn->prepare("SELECT grr.*, 
                u.first_name AS linked_first, u.last_name AS linked_last, u.email AS linked_email
                FROM guest_reservation_requests grr
                LEFT JOIN tbl_user u ON u.user_id = grr.user_id
                WHERE grr.id = ? LIMIT 1");
            if (!$stmt) throw new Exception("Database error: " . $conn->error);
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$data) {
                // Fallback 1: lookup by booking_id in guest_reservation_requests
                $stmt2 = $conn->prepare("SELECT grr.*, 
                    u.first_name AS linked_first, u.last_name AS linked_last, u.email AS linked_email
                    FROM guest_reservation_requests grr
                    LEFT JOIN tbl_user u ON u.user_id = grr.user_id
                    WHERE grr.booking_id = ? LIMIT 1");
                if ($stmt2) {
                    $stmt2->bind_param('i', $requestId);
                    $stmt2->execute();
                    $data = $stmt2->get_result()->fetch_assoc();
                    $stmt2->close();
                }
            }

            if (!$data) {
                // Fallback 2: Direct lookup in tbl_bookings
                $bStmt = $conn->prepare("SELECT b.*, 
                    u.first_name AS user_first, u.last_name AS user_last, u.email AS user_email, u.phone_no AS user_phone
                    FROM tbl_bookings b
                    LEFT JOIN tbl_user u ON u.user_id = b.user_id
                    WHERE b.id = ? LIMIT 1");
                if ($bStmt) {
                    $bStmt->bind_param('i', $requestId);
                    $bStmt->execute();
                    $bRow = $bStmt->get_result()->fetch_assoc();
                    $bStmt->close();

                    if ($bRow) {
                        $fName = trim($bRow['full_name'] ?? '');
                        if ($fName === '' && !empty($bRow['user_first'])) {
                            $fName = trim($bRow['user_first'] . ' ' . ($bRow['user_last'] ?? ''));
                        }
                        $bEmail = !empty($bRow['email']) ? $bRow['email'] : ($bRow['user_email'] ?? '');
                        $bPhone = !empty($bRow['contact_number']) ? $bRow['contact_number'] : ($bRow['user_phone'] ?? '');

                        $data = [
                            'id' => (int)$bRow['id'],
                            'booking_id' => (int)$bRow['id'],
                            'user_id' => (int)($bRow['user_id'] ?? 0),
                            'full_name' => $fName ?: ('Customer #' . $bRow['user_id']),
                            'email' => $bEmail,
                            'contact_number' => $bPhone,
                            'address' => $bRow['booker_location'] ?? '',
                            'section' => strtoupper(trim((string)$bRow['section'])),
                            'plot_code' => $bRow['plot'] ?: ($bRow['plot_x'] . '-' . $bRow['plot_y']),
                            'purpose' => $bRow['purpose'] ?? 'Memorial Plot Reservation',
                            'notes' => $bRow['special_requests'] ?? ($bRow['notes'] ?? ''),
                            'admin_notes' => $bRow['admin_notes'] ?? '',
                            'created_at' => $bRow['created_at'] ?? date('Y-m-d H:i:s'),
                            'preferred_date' => $bRow['booking_date'] ?? '',
                            'request_type' => 'reservation',
                            'deceased_full_name' => $bRow['deceased_name'] ?? '',
                            'deceased_first_name' => '',
                            'deceased_middle_name' => '',
                            'deceased_last_name' => '',
                            'deceased_suffix' => '',
                            'deceased_relationship' => $bRow['relationship_to_deceased'] ?? '',
                            'deceased_bday' => '',
                            'deceased_date_died' => '',
                            'funeral_home' => '',
                            'status' => strtolower(trim((string)$bRow['status'])),
                            'cancelled_by' => $bRow['cancelled_by'] ?? '',
                            'cancellation_reason' => $bRow['cancellation_reason'] ?? '',
                            'cancellation_requested' => (int)($bRow['cancellation_requested'] ?? 0),
                            'booking_status' => $bRow['status'],
                            'plot_price' => floatval($bRow['plot_price'] ?? ($bRow['total_amount'] ?? 0)),
                            'grave_location' => $bRow['grave_location'] ?? null,
                            'payment_proof_filename' => $bRow['payment_proof_filename'] ?? null,
                            'live_plot_available' => true,
                            'linked_first' => $bRow['user_first'] ?? null,
                            'linked_last' => $bRow['user_last'] ?? null,
                            'linked_email' => $bRow['user_email'] ?? null,
                        ];
                    }
                }
            }

            if (!$data) throw new Exception("Reservation request #{$requestId} not found.");

            // Safely fetch booking details if linked
            $data['booking_status'] = $data['booking_status'] ?? null;
            $data['plot_price'] = $data['plot_price'] ?? 0.00;
            $data['grave_location'] = $data['grave_location'] ?? null;
            $data['payment_proof_filename'] = $data['payment_proof_filename'] ?? null;
            $bId = (int)($data['booking_id'] ?? 0);

            if ($bId > 0 && empty($data['booking_status'])) {
                $bStmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
                if ($bStmt) {
                    $bStmt->bind_param('i', $bId);
                    $bStmt->execute();
                    $bRow = $bStmt->get_result()->fetch_assoc();
                    $bStmt->close();
                    if ($bRow) {
                        $data['booking_status'] = $bRow['status'] ?? null;
                        $data['plot_price'] = $bRow['plot_price'] ?? ($bRow['total_amount'] ?? 0.00);
                        $data['grave_location'] = $bRow['grave_location'] ?? null;
                        $data['payment_proof_filename'] = $bRow['payment_proof_filename'] ?? null;
                        if (empty($data['cancellation_reason']) && !empty($bRow['cancellation_reason'])) {
                            $data['cancellation_reason'] = $bRow['cancellation_reason'];
                            $data['cancelled_by'] = $bRow['cancelled_by'] ?? '';
                        }
                    }
                }
            }

            // Resolve payment details from payment_receipts or guest_reservation_requests
            $data['payment_info'] = null;
            $prId = (int)($data['payment_receipt_id'] ?? 0);

            if ($prId > 0) {
                $prStmt = @$conn->prepare("SELECT * FROM payment_receipts WHERE id = ? LIMIT 1");
                if ($prStmt) {
                    $prStmt->bind_param('i', $prId);
                    $prStmt->execute();
                    $prRaw = $prStmt->get_result()->fetch_assoc();
                    $prStmt->close();
                    if ($prRaw) {
                        $data['payment_info'] = [
                            'id' => (int)$prRaw['id'],
                            'amount_paid' => floatval($prRaw['amount_paid'] ?? ($prRaw['amount'] ?? 0)),
                            'payment_method' => $prRaw['payment_method'] ?? 'GCash',
                            'gcash_reference_number' => $prRaw['gcash_reference_number'] ?? ($prRaw['transaction_reference'] ?? ($prRaw['reference_number'] ?? '')),
                            'transaction_reference' => $prRaw['transaction_reference'] ?? '',
                            'payment_date' => $prRaw['payment_date'] ?? ($prRaw['created_at'] ?? ''),
                            'payment_status' => $prRaw['payment_status'] ?? 'PAID',
                            'proof_filename' => $prRaw['proof_filename'] ?? ''
                        ];
                    }
                }
            }
            if (!$data['payment_info'] && $bId > 0) {
                $prStmt = @$conn->prepare("SELECT * FROM payment_receipts WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
                if ($prStmt) {
                    $prStmt->bind_param('i', $bId);
                    $prStmt->execute();
                    $prRaw = $prStmt->get_result()->fetch_assoc();
                    $prStmt->close();
                    if ($prRaw) {
                        $data['payment_info'] = [
                            'id' => (int)$prRaw['id'],
                            'amount_paid' => floatval($prRaw['amount_paid'] ?? ($prRaw['amount'] ?? 0)),
                            'payment_method' => $prRaw['payment_method'] ?? 'GCash',
                            'gcash_reference_number' => $prRaw['gcash_reference_number'] ?? ($prRaw['transaction_reference'] ?? ($prRaw['reference_number'] ?? '')),
                            'transaction_reference' => $prRaw['transaction_reference'] ?? '',
                            'payment_date' => $prRaw['payment_date'] ?? ($prRaw['created_at'] ?? ''),
                            'payment_status' => $prRaw['payment_status'] ?? 'PAID',
                            'proof_filename' => $prRaw['proof_filename'] ?? ''
                        ];
                    }
                }
            }

            // Fallback to columns directly on guest_reservation_requests or tbl_bookings
            if (!$data['payment_info']) {
                $pAmt = floatval($data['payment_amount'] ?? ($data['downpayment_amount'] ?? ($data['downpayment'] ?? 0)));
                $pStat = trim((string)($data['payment_status'] ?? ''));
                $pRef = trim((string)($data['gcash_reference'] ?? ($data['payment_reference'] ?? '')));
                $pDate = trim((string)($data['payment_date'] ?? ''));
                $pProof = trim((string)($data['payment_proof_filename'] ?? ($data['proof_filename'] ?? '')));

                if ($pAmt > 0 || !empty($pRef) || !empty($pProof) || !empty($pStat)) {
                    $data['payment_info'] = [
                        'id' => 0,
                        'amount_paid' => $pAmt,
                        'payment_method' => 'GCash',
                        'gcash_reference_number' => $pRef,
                        'transaction_reference' => '',
                        'payment_date' => $pDate,
                        'payment_status' => $pStat ?: 'PAID',
                        'proof_filename' => $pProof
                    ];
                }
            }

            $data['matched_deceased_record'] = null;
            if (!empty($data['matched_deceased_id']) && (int)$data['matched_deceased_id'] > 0) {
                $mDecId = (int)$data['matched_deceased_id'];
                $mStmt = $conn->prepare("SELECT deceased_id, first_name, middle_name, last_name, suffix, bday, date_died, section, plot_x, plot_y, user_id, relationship_to_account_holder FROM tbl_deceased WHERE deceased_id = ? LIMIT 1");
                if ($mStmt) {
                    $mStmt->bind_param('i', $mDecId);
                    $mStmt->execute();
                    $data['matched_deceased_record'] = $mStmt->get_result()->fetch_assoc();
                    $mStmt->close();
                }
            }

            $data['existing_account'] = null;
            $gEmail = strtolower(trim((string)($data['email'] ?? '')));
            if (!empty($gEmail)) {
                $uStmt = $conn->prepare("SELECT user_id, first_name, last_name, email, phone FROM tbl_user WHERE LOWER(TRIM(email)) = ? LIMIT 1");
                if ($uStmt) {
                    $uStmt->bind_param('s', $gEmail);
                    $uStmt->execute();
                    $uRes = $uStmt->get_result();
                    if ($uRes && $uRes->num_rows > 0) {
                        $data['existing_account'] = $uRes->fetch_assoc();
                    }
                    $uStmt->close();
                }
            }

            $sec = strtoupper(trim($data['section']));
            $px = (int)$data['plot_x'];
            $py = (int)$data['plot_y'];
            $plotCode = trim($data['plot_code']);

            $isOccupied = false;
            $isOwned = false;

            $oChk = $conn->prepare("SELECT id, owner_name FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND active = 1 LIMIT 1");
            if ($oChk) {
                $oChk->bind_param('sii', $sec, $px, $py);
                $oChk->execute();
                $oRes = $oChk->get_result();
                if ($oRes && $oRes->num_rows > 0) {
                    $isOwned = true;
                    $data['owner_info'] = $oRes->fetch_assoc();
                }
                $oChk->close();
            }

            $dChk = $conn->prepare("SELECT deceased_id, first_name, last_name FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
            if ($dChk) {
                $dChk->bind_param('sii', $sec, $px, $py);
                $dChk->execute();
                $dRes = $dChk->get_result();
                if ($dRes && $dRes->num_rows > 0) {
                    $isOccupied = true;
                    $data['deceased_info'] = $dRes->fetch_assoc();
                }
                $dChk->close();
            }

            $data['live_plot_available'] = (!$isOccupied && !$isOwned);
            $data['live_plot_status_label'] = $isOccupied ? 'Occupied' : ($isOwned ? 'Reserved / Owned' : 'Available');

            $tokenHash = trim((string)($data['account_creation_token_hash'] ?? ''));
            if (!empty($tokenHash)) {
                $baseApp = function_exists('get_app_base_url') ? get_app_base_url() : (function_exists('get_email_asset_base_url') ? get_email_asset_base_url() : 'http://localhost/Garden_of_Remembrance_MP');
                $data['registration_url'] = rtrim($baseApp, '/\\') . '/complete_registration.php?token=' . urlencode($tokenHash);
            } else {
                $data['registration_url'] = '';
            }

            echo json_encode(['success' => true, 'request' => $data]);
            exit;
        }

        if ($action === 'approve_request') {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT * FROM guest_reservation_requests WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmt->bind_param('i', $requestId);
                $stmt->execute();
                $req = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$req) throw new Exception("Reservation request not found.");

                $currentStatus = strtolower(trim($req['status']));
                if ($currentStatus === 'approved') {
                    throw new Exception("This reservation request has already been approved.");
                }
                if ($currentStatus === 'account created') {
                    throw new Exception("An account has already been created for this reservation.");
                }

                $sec = strtoupper(trim($req['section']));
                $px = (int)$req['plot_x'];
                $py = (int)$req['plot_y'];
                $plotCode = trim($req['plot_code']);
                $email = strtolower(trim($req['email']));
                $fullName = trim($req['full_name']);
                $reqType = trim((string)($req['request_type'] ?? 'guest'));
                $purpose = trim((string)($req['purpose'] ?? ''));

                $existingUserId = 0;
                $userChk = $conn->prepare("SELECT user_id, first_name, last_name FROM tbl_user WHERE LOWER(TRIM(email)) = ? LIMIT 1");
                if ($userChk) {
                    $userChk->bind_param('s', $email);
                    $userChk->execute();
                    $uRes = $userChk->get_result()->fetch_assoc();
                    $userChk->close();
                    if ($uRes) {
                        $existingUserId = (int)$uRes['user_id'];
                        $confirmLink = (int)($_POST['confirm_link_existing_user'] ?? 0);
                        if (!$confirmLink) {
                            $conn->rollback();
                            echo json_encode([
                                'success' => false,
                                'requires_account_link_confirmation' => true,
                                'existing_user' => $uRes,
                                'message' => "An account with email '{$email}' already exists for {$uRes['first_name']} {$uRes['last_name']} (User ID #{$existingUserId}). Approving will link this reservation to that account. Do you wish to proceed?"
                            ]);
                            exit;
                        }
                    }
                }

                $isAdditionalDeceased = (stripos($purpose, 'Additional') !== false || $reqType === 'deceased_verification');
                if (!$isAdditionalDeceased) {

                    $plotStatusChk = $conn->prepare("SELECT status FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
                    if ($plotStatusChk) {
                        $plotStatusChk->bind_param('sii', $sec, $px, $py);
                        $plotStatusChk->execute();
                        $pRow = $plotStatusChk->get_result()->fetch_assoc();
                        $plotStatusChk->close();
                        if (!$pRow) {
                            throw new Exception("Plot Section {$sec} Plot {$plotCode} was not found in the cemetery database.");
                        }
                        if (strtolower(trim((string)$pRow['status'])) !== 'available') {
                            throw new Exception("This plot (Section {$sec} Plot {$plotCode}) is no longer available (current status: {$pRow['status']}).");
                        }
                    }

                    $ownChk = $conn->prepare("SELECT id, owner_name, reserved_by FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND active = 1 LIMIT 1");
                    if ($ownChk) {
                        $ownChk->bind_param('sii', $sec, $px, $py);
                        $ownChk->execute();
                        $ownRow = $ownChk->get_result()->fetch_assoc();
                        $ownChk->close();
                        if ($ownRow && ($existingUserId <= 0 || (int)($ownRow['reserved_by'] ?? 0) !== $existingUserId)) {
                            throw new Exception("This plot (Section {$sec} Plot {$plotCode}) is already owned or reserved by {$ownRow['owner_name']}.");
                        }
                    }
                }

                $matchedDecId = (int)($req['matched_deceased_id'] ?? 0);
                if ($reqType === 'no_deceased') {

                    $matchedDecId = 0;
                } else if ($matchedDecId > 0) {

                    if ($existingUserId > 0) {
                        $linkDec = $conn->prepare("UPDATE tbl_deceased SET user_id = ?, relationship_to_account_holder = COALESCE(NULLIF(relationship_to_account_holder, ''), ?) WHERE deceased_id = ?");
                        if ($linkDec) {
                            $rel = trim((string)($req['deceased_relationship'] ?? ''));
                            $linkDec->bind_param('isi', $existingUserId, $rel, $matchedDecId);
                            $linkDec->execute();
                            $linkDec->close();
                        }
                    }
                } else {

                    $dFirst = trim((string)($req['deceased_first_name'] ?? ''));
                    $dMiddle = trim((string)($req['deceased_middle_name'] ?? ''));
                    $dLast = trim((string)($req['deceased_last_name'] ?? ''));
                    $dSuffix = trim((string)($req['deceased_suffix'] ?? ''));
                    if ($dFirst === '' && $dLast === '' && !empty($req['deceased_full_name'])) {
                        $parts = preg_split('/\s+/', trim($req['deceased_full_name']));
                        $dFirst = $parts[0] ?? '';
                        $dLast = count($parts) > 1 ? end($parts) : '';
                        $dMiddle = count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : '';
                    }

                    if (!empty($dFirst) || !empty($dLast)) {
                        $dBday = !empty($req['deceased_bday']) ? $req['deceased_bday'] : null;
                        $dDied = !empty($req['deceased_date_died']) ? $req['deceased_date_died'] : null;
                        $dRel = trim((string)($req['deceased_relationship'] ?? ''));
                        $dHome = trim((string)($req['funeral_home'] ?? ''));
                        $uIdForDec = $existingUserId > 0 ? $existingUserId : null;

                        $insDec = $conn->prepare("INSERT INTO tbl_deceased (first_name, middle_name, last_name, suffix, bday, date_died, funeral_service, section, plot_x, plot_y, user_id, relationship_to_account_holder, added_by, edited_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        if ($insDec) {
                            $insDec->bind_param('ssssssssiiisii', $dFirst, $dMiddle, $dLast, $dSuffix, $dBday, $dDied, $dHome, $sec, $px, $py, $uIdForDec, $dRel, $admin_id, $admin_id);
                            $insDec->execute();
                            $matchedDecId = (int)$insDec->insert_id;
                            $insDec->close();
                            if ($matchedDecId > 0) {
                                $conn->query("UPDATE guest_reservation_requests SET matched_deceased_id = {$matchedDecId} WHERE id = {$requestId}");
                            }
                        }
                    }
                }

                $token = ($existingUserId > 0) ? '' : bin2hex(random_bytes(32));
                $expiresAt = ($existingUserId > 0) ? null : date('Y-m-d H:i:s', time() + (48 * 3600));
                $newStatus = ($existingUserId > 0) ? 'Account Created' : 'Approved';

                $updStmt = $conn->prepare("UPDATE guest_reservation_requests 
                    SET status = ?, approved_at = NOW(), approved_by = ?, user_id = ?, account_creation_token_hash = ?, token_expires_at = ?, token_used_at = NULL 
                    WHERE id = ?");
                if (!$updStmt) throw new Exception("Database error: " . $conn->error);
                $uIdParam = $existingUserId > 0 ? $existingUserId : null;
                $updStmt->bind_param('siissi', $newStatus, $admin_id, $uIdParam, $token, $expiresAt, $requestId);
                $updStmt->execute();
                $updStmt->close();

                $bookingId = (int)($req['booking_id'] ?? 0);
                $decIdForBooking = ($matchedDecId > 0) ? $matchedDecId : null;
                if ($bookingId > 0) {
                    $tUsed = ($existingUserId > 0) ? 1 : 0;
                    $updBk = $conn->prepare("UPDATE tbl_bookings 
                        SET status = 'approved', registration_token = ?, token_expires_at = ?, token_used = ?, user_id = ?, deceased_id = ? 
                        WHERE id = ?");
                    if ($updBk) {
                        $updBk->bind_param('ssiiii', $token, $expiresAt, $tUsed, $uIdParam, $decIdForBooking, $bookingId);
                        $updBk->execute();
                        $updBk->close();
                    } else {

                        $updBkFallback = $conn->prepare("UPDATE tbl_bookings 
                            SET status = 'approved', registration_token = ?, token_expires_at = ?, token_used = ?, user_id = ? 
                            WHERE id = ?");
                        if ($updBkFallback) {
                            $updBkFallback->bind_param('ssiii', $token, $expiresAt, $tUsed, $uIdParam, $bookingId);
                            $updBkFallback->execute();
                            $updBkFallback->close();
                        }
                    }
                } else {
                    $graveLoc = "Section {$sec} Plot {$plotCode}";
                    $purp = $req['purpose'] ?: 'Reserve New Grave';
                    $tUsed = ($existingUserId > 0) ? 1 : 0;
                    $bkIns = $conn->prepare("INSERT INTO tbl_bookings 
                        (customer_name, full_name, customer_email, email, contact_number, booker_location, section, plot, grave_location, plot_type, purpose, service_type, booking_type, source, status, is_guest_booking, registration_token, token_expires_at, token_used, user_id, deceased_id, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Lawn (Regular)', ?, 'Future Memorial Lot', 'guest_reservation', 'guest', 'approved', 1, ?, ?, ?, ?, ?, NOW())");
                    if ($bkIns) {
                        $bkIns->bind_param('ssssssssssssiii', $fullName, $fullName, $email, $email, $req['contact_number'], $req['address'], $sec, $plotCode, $graveLoc, $purp, $token, $expiresAt, $tUsed, $uIdParam, $decIdForBooking);
                        if ($bkIns->execute()) {
                            $bookingId = (int)$bkIns->insert_id;
                            $conn->query("UPDATE guest_reservation_requests SET booking_id = $bookingId WHERE id = $requestId");
                            if ($bookingId > 0 && function_exists('record_booking_log')) {
                                record_booking_log($conn, $bookingId, $sec, $req['contact_number'], $req['address'], 85000.00);
                            }
                        }
                        $bkIns->close();
                    } else {

                        $bkInsFallback = $conn->prepare("INSERT INTO tbl_bookings 
                            (customer_name, full_name, customer_email, email, contact_number, booker_location, section, plot, grave_location, plot_type, purpose, service_type, booking_type, source, status, is_guest_booking, registration_token, token_expires_at, token_used, user_id, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Lawn (Regular)', ?, 'Future Memorial Lot', 'guest_reservation', 'guest', 'approved', 1, ?, ?, ?, ?, NOW())");
                        if ($bkInsFallback) {
                            $bkInsFallback->bind_param('ssssssssssssii', $fullName, $fullName, $email, $email, $req['contact_number'], $req['address'], $sec, $plotCode, $graveLoc, $purp, $token, $expiresAt, $tUsed, $uIdParam);
                            if ($bkInsFallback->execute()) {
                                $bookingId = (int)$bkInsFallback->insert_id;
                                $conn->query("UPDATE guest_reservation_requests SET booking_id = $bookingId WHERE id = $requestId");
                                if ($bookingId > 0 && function_exists('record_booking_log')) {
                                    record_booking_log($conn, $bookingId, $sec, $req['contact_number'], $req['address'], 85000.00);
                                }
                            }
                            $bkInsFallback->close();
                        }
                    }
                }

                if ($px > 0 && $py > 0 && !empty($sec)) {
                    $plotUp = $conn->prepare("UPDATE tbl_plots SET status = 'reserved' WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND LOWER(status) = 'available'");
                    if ($plotUp) {
                        $plotUp->bind_param('sii', $sec, $px, $py);
                        $plotUp->execute();
                        $plotUp->close();
                    }

                    $conn->query("CREATE TABLE IF NOT EXISTS tbl_plot_owners (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        section VARCHAR(10) NOT NULL,
                        plot_x INT NOT NULL,
                        plot_y INT NOT NULL,
                        owner_name VARCHAR(100) NOT NULL,
                        first_name VARCHAR(50) DEFAULT NULL,
                        middle_name VARCHAR(50) DEFAULT NULL,
                        last_name VARCHAR(50) DEFAULT NULL,
                        suffix VARCHAR(20) DEFAULT NULL,
                        bday DATE DEFAULT NULL,
                        phone_no VARCHAR(15) DEFAULT NULL,
                        address VARCHAR(255) DEFAULT NULL,
                        reserved_by INT DEFAULT NULL,
                        active TINYINT(1) DEFAULT 1,
                        reserved_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    $deact = $conn->prepare("UPDATE tbl_plot_owners SET active = 0 WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND active = 1");
                    if ($deact) {
                        $deact->bind_param('sii', $sec, $px, $py);
                        $deact->execute();
                        $deact->close();
                    }

                    $nameParts = preg_split('/\s+/', $fullName);
                    $fName = $nameParts[0] ?? '';
                    $lName = count($nameParts) > 1 ? end($nameParts) : '';
                    $mName = count($nameParts) > 2 ? implode(' ', array_slice($nameParts, 1, -1)) : '';
                    $contactNo = trim((string)($req['contact_number'] ?? ''));
                    $loc = trim((string)($req['address'] ?? ''));

                    $insOwner = $conn->prepare("INSERT INTO tbl_plot_owners (section, plot_x, plot_y, owner_name, first_name, middle_name, last_name, suffix, bday, phone_no, address, reserved_by, active, reserved_at) VALUES (?, ?, ?, ?, ?, ?, ?, '', '1970-01-01', ?, ?, ?, 1, NOW())");
                    if ($insOwner) {
                        $insOwner->bind_param('siissssssi', $sec, $px, $py, $fullName, $fName, $mName, $lName, $contactNo, $loc, $uIdParam);
                        $insOwner->execute();
                        $insOwner->close();
                    }
                }

                if ($bookingId > 0) {
                    if (function_exists('finalize_approved_booking_payments')) {
                        finalize_approved_booking_payments($conn, $bookingId, $admin_id);
                    }
                }
                $conn->query("UPDATE guest_reservation_requests SET payment_status = 'PAID' WHERE id = $requestId");

                $emailSent = false;
                $emailErr = null;
                if ($existingUserId > 0) {
                    $emailSent = true; 
                } else if ($bookingId > 0 && !empty($token)) {
                    $emailSent = send_guest_account_creation_email($bookingId, $token, $conn);
                    $emailErr = $emailSent ? null : (function_exists('get_mail_error') ? get_mail_error() : 'Email delivery failed');
                }

                $conn->query("UPDATE guest_reservation_requests SET email_sent = " . ($emailSent ? 1 : 0) . ", email_error = " . ($emailErr ? "'" . $conn->real_escape_string($emailErr) . "'" : "NULL") . " WHERE id = $requestId");

                $purpLog = !empty($req['purpose']) ? $req['purpose'] : 'Reserve New Grave';
                $decLogDesc = ($matchedDecId > 0) ? "Record #{$matchedDecId}" : "Not Assigned";
                $logText = "Admin approved Guest Request #GR-{$requestId}. Purpose: {$purpLog}. Plot: {$sec}-{$plotCode}. Deceased: {$decLogDesc}." . ($existingUserId > 0 ? " Linked to existing user ID #{$existingUserId}." : " Account creation token dispatched to {$email}.");
                $lStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('GUEST_RESERVATION_APPROVED', ?, ?, NOW())");
                if ($lStmt) {
                    $lStmt->bind_param('si', $logText, $admin_id);
                    $lStmt->execute();
                    $lStmt->close();
                }

                $conn->commit();

                $msg = ($existingUserId > 0)
                    ? "Reservation request #{$requestId} approved and linked to existing user account ({$fullName})."
                    : "Guest reservation #{$requestId} approved successfully!" . ($emailSent ? " Account creation link dispatched to {$email}." : " Email delivery failed ({$emailErr}).");

                echo json_encode([
                    'success' => true,
                    'email_sent' => $emailSent,
                    'is_linked_account' => ($existingUserId > 0),
                    'message' => $msg
                ]);
                exit;
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }
        }

        if ($action === 'reject_request') {
            $reason = trim((string)($_POST['rejection_reason'] ?? ''));

            $stmt = $conn->prepare("SELECT * FROM guest_reservation_requests WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $req = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$req) throw new Exception("Reservation request not found.");

            $updStmt = $conn->prepare("UPDATE guest_reservation_requests 
                SET status = 'Rejected', rejected_at = NOW(), rejected_by = ?, admin_notes = ? 
                WHERE id = ?");
            if (!$updStmt) throw new Exception("Database error: " . $conn->error);
            $updStmt->bind_param('isi', $admin_id, $reason, $requestId);
            $updStmt->execute();
            $updStmt->close();

            if (file_exists(__DIR__ . '/config/cancellation_helper.php')) {
                require_once __DIR__ . '/config/cancellation_helper.php';
            }
            $conn->query("UPDATE guest_reservation_requests SET payment_status = 'REJECTED' WHERE id = $requestId");
            if (!empty($req['booking_id'])) {
                $bId = (int)$req['booking_id'];
                $conn->query("UPDATE tbl_bookings SET status = 'rejected', payment_status = 'REJECTED' WHERE id = $bId");
                $upPR = $conn->prepare("UPDATE payment_receipts SET payment_status = 'REJECTED', rejection_reason = ?, verified_by = ?, verified_at = NOW() WHERE booking_id = ? AND (payment_status IS NULL OR UPPER(payment_status) IN ('PENDING', 'FOR VERIFICATION'))");
                if ($upPR) {
                    $upPR->bind_param('sii', $reason, $admin_id, $bId);
                    $upPR->execute();
                    $upPR->close();
                }
                if (function_exists('release_booking_plots')) {
                    release_booking_plots($conn, $bId);
                }
            }

            $rSec = strtoupper(trim((string)($req['section'] ?? '')));
            $cleanSec = strtoupper(trim(preg_replace('/^SECTION\s*/i', '', $rSec)));
            $rPx = (int)($req['plot_x'] ?? 0);
            $rPy = (int)($req['plot_y'] ?? 0);
            if (!empty($cleanSec) && $rPx > 0 && $rPy > 0) {
                $eSec = $conn->real_escape_string($cleanSec);
                $hasDec = false;
                $decChk = $conn->prepare("SELECT COUNT(*) AS c FROM tbl_deceased WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = ? OR UPPER(TRIM(section)) = ?) AND ((plot_x = ? AND plot_y = ?) OR (plot_x = ? AND plot_y = ?))");
                if ($decChk) {
                    $decChk->bind_param('ssiiii', $cleanSec, $cleanSec, $rPx, $rPy, $rPy, $rPx);
                    $decChk->execute();
                    $hasDec = ((int)($decChk->get_result()->fetch_assoc()['c'] ?? 0)) > 0;
                    $decChk->close();
                }
                if (!$hasDec) {
                    $conn->query("UPDATE tbl_plots SET status = 'available' WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$eSec}' OR UPPER(TRIM(section)) = '{$eSec}') AND ((plot_x = {$rPx} AND plot_y = {$rPy}) OR (plot_x = {$rPy} AND plot_y = {$rPx})) AND LOWER(status) != 'occupied'");
                    $chkPlots = @$conn->query("SHOW TABLES LIKE 'plots'");
                    if ($chkPlots && $chkPlots->num_rows > 0) {
                        $conn->query("UPDATE plots SET status = 'Available' WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$eSec}' OR UPPER(TRIM(section)) = '{$eSec}') AND ((plot_x = {$rPx} AND plot_y = {$rPy}) OR (plot_x = {$rPy} AND plot_y = {$rPx})) AND LOWER(status) != 'occupied'");
                    }
                }
                $conn->query("UPDATE tbl_plot_owners SET active = 0 WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$eSec}' OR UPPER(TRIM(section)) = '{$eSec}') AND ((plot_x = {$rPx} AND plot_y = {$rPy}) OR (plot_x = {$rPy} AND plot_y = {$rPx})) AND active = 1");
                $conn->query("DELETE FROM user_plot_ownership WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$eSec}' OR UPPER(TRIM(section)) = '{$eSec}') AND ((plot_x = {$rPx} AND plot_y = {$rPy}) OR (plot_x = {$rPy} AND plot_y = {$rPx})) AND (ownership_status IN ('RESERVED', 'CANCELLED') OR verification_status IN ('REJECTED', 'PENDING'))");
            }

            $logText = "Rejected guest reservation request #{$requestId} for {$req['full_name']} ({$req['email']}). Reason: {$reason}";
            $lStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('GUEST_RESERVATION_REJECTED', ?, ?, NOW())");
            if ($lStmt) {
                $lStmt->bind_param('si', $logText, $admin_id);
                $lStmt->execute();
                $lStmt->close();
            }

            if (function_exists('send_guest_reservation_rejected_email')) {
                try {
                    @send_guest_reservation_rejected_email($requestId, $reason, $conn);
                } catch (Throwable $eMail) {
                    error_log("Failed to send rejection email: " . $eMail->getMessage());
                }
            }

            echo json_encode(['success' => true, 'message' => "Reservation request #{$requestId} has been marked as Rejected and the applicant was notified."]);
            exit;
        }

        if ($action === 'cancel_request' || $action === 'accept_cancellation') {
            $reason = trim((string)($_POST['cancellation_reason'] ?? ''));

            $stmt = $conn->prepare("SELECT * FROM guest_reservation_requests WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $req = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$req) {
                // Fallback 1: lookup by booking_id
                $stmt2 = $conn->prepare("SELECT * FROM guest_reservation_requests WHERE booking_id = ? LIMIT 1");
                if ($stmt2) {
                    $stmt2->bind_param('i', $requestId);
                    $stmt2->execute();
                    $req = $stmt2->get_result()->fetch_assoc();
                    $stmt2->close();
                    if ($req) {
                        $requestId = (int)$req['id'];
                    }
                }
            }

            if (!$req) {
                // Fallback 2: Direct cancellation in tbl_bookings
                $bStmt = $conn->prepare("SELECT * FROM tbl_bookings WHERE id = ? LIMIT 1");
                if ($bStmt) {
                    $bStmt->bind_param('i', $requestId);
                    $bStmt->execute();
                    $bRow = $bStmt->get_result()->fetch_assoc();
                    $bStmt->close();
                    if ($bRow) {
                        $adminName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
                        if (empty($adminName)) $adminName = 'Administrator';
                        if (empty($reason)) $reason = 'Cancelled by administrative action (No refund issued).';

                        if (file_exists(__DIR__ . '/config/cancellation_helper.php')) {
                            require_once __DIR__ . '/config/cancellation_helper.php';
                        }
                        if (function_exists('release_booking_plots')) {
                            release_booking_plots($conn, $requestId);
                        }
                        $conn->query("UPDATE tbl_bookings SET status = 'cancelled', cancelled_by = 'Admin " . $conn->real_escape_string($adminName) . "', cancellation_reason = '" . $conn->real_escape_string($reason) . "' WHERE id = " . (int)$requestId);
                        echo json_encode(['success' => true, 'message' => "Booking / Reservation #{$requestId} cancelled and plot released back to available."]);
                        exit;
                    }
                }
            }

            if (!$req) throw new Exception("Reservation request not found.");

            if (strtolower(trim($req['status'])) === 'cancelled') {
                throw new Exception("This reservation request has already been cancelled.");
            }

            if (empty($reason) && !empty($req['cancellation_reason'])) {
                $reason = $req['cancellation_reason'];
            }
            if (empty($reason)) {
                $reason = 'Cancelled by administrative action (No refund issued).';
            }

            $adminName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
            if (empty($adminName)) $adminName = 'Administrator';
            
            $cancelledBy = ((int)($req['cancellation_requested'] ?? 0) === 1)
                ? ($req['full_name'] . ' (Accepted by Admin ' . $adminName . ')')
                : ('Admin ' . $adminName);

            $updStmt = $conn->prepare("UPDATE guest_reservation_requests 
                SET status = 'Cancelled', cancelled_at = NOW(), cancelled_by = ?, cancellation_reason = ? 
                WHERE id = ?");
            if (!$updStmt) throw new Exception("Database error: " . $conn->error);
            $updStmt->bind_param('ssi', $cancelledBy, $reason, $requestId);
            $updStmt->execute();
            $updStmt->close();

            if (file_exists(__DIR__ . '/config/cancellation_helper.php')) {
                require_once __DIR__ . '/config/cancellation_helper.php';
            }
            if (!empty($req['booking_id'])) {
                $bId = (int)$req['booking_id'];
                $upBk = $conn->prepare("UPDATE tbl_bookings SET status = 'cancelled', cancellation_reason = ?, cancelled_by = ?, cancelled_at = NOW() WHERE id = ?");
                if ($upBk) {
                    $upBk->bind_param('ssi', $reason, $cancelledBy, $bId);
                    $upBk->execute();
                    $upBk->close();
                }
                if (function_exists('release_booking_plots')) {
                    release_booking_plots($conn, $bId);
                }
            }

            $rSec = strtoupper(trim((string)($req['section'] ?? '')));
            $cleanSec = strtoupper(trim(preg_replace('/^SECTION\s*/i', '', $rSec)));
            $rPx = (int)($req['plot_x'] ?? 0);
            $rPy = (int)($req['plot_y'] ?? 0);
            $plotCode = trim($req['plot_code'] ?: ($rPx . '-' . $rPy));
            if (!empty($cleanSec) && $rPx > 0 && $rPy > 0) {
                $eSec = $conn->real_escape_string($cleanSec);
                $hasDec = false;
                $decChk = $conn->prepare("SELECT COUNT(*) AS c FROM tbl_deceased WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = ? OR UPPER(TRIM(section)) = ?) AND ((plot_x = ? AND plot_y = ?) OR (plot_x = ? AND plot_y = ?))");
                if ($decChk) {
                    $decChk->bind_param('ssiiii', $cleanSec, $cleanSec, $rPx, $rPy, $rPy, $rPx);
                    $decChk->execute();
                    $hasDec = ((int)($decChk->get_result()->fetch_assoc()['c'] ?? 0)) > 0;
                    $decChk->close();
                }
                if (!$hasDec) {
                    $conn->query("UPDATE tbl_plots SET status = 'available' WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$eSec}' OR UPPER(TRIM(section)) = '{$eSec}') AND ((plot_x = {$rPx} AND plot_y = {$rPy}) OR (plot_x = {$rPy} AND plot_y = {$rPx})) AND LOWER(status) != 'occupied'");
                    $chkPlots = @$conn->query("SHOW TABLES LIKE 'plots'");
                    if ($chkPlots && $chkPlots->num_rows > 0) {
                        $conn->query("UPDATE plots SET status = 'Available' WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$eSec}' OR UPPER(TRIM(section)) = '{$eSec}') AND ((plot_x = {$rPx} AND plot_y = {$rPy}) OR (plot_x = {$rPy} AND plot_y = {$rPx})) AND LOWER(status) != 'occupied'");
                    }
                }
                $conn->query("UPDATE tbl_plot_owners SET active = 0 WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$eSec}' OR UPPER(TRIM(section)) = '{$eSec}') AND ((plot_x = {$rPx} AND plot_y = {$rPy}) OR (plot_x = {$rPy} AND plot_y = {$rPx})) AND active = 1");
                $conn->query("DELETE FROM user_plot_ownership WHERE (REPLACE(UPPER(TRIM(section)), 'SECTION ', '') = '{$eSec}' OR UPPER(TRIM(section)) = '{$eSec}') AND ((plot_x = {$rPx} AND plot_y = {$rPy}) OR (plot_x = {$rPy} AND plot_y = {$rPx})) AND (ownership_status IN ('RESERVED', 'CANCELLED') OR verification_status IN ('REJECTED', 'PENDING'))");
            }

            $logText = "Cancelled guest reservation request #{$requestId} for {$req['full_name']} ({$req['email']}) on Section {$rSec} Plot {$plotCode}. Cancelled by: {$cancelledBy}. Reason: {$reason}. Strictly no refund issued.";
            $lStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('GUEST_RESERVATION_CANCELLED', ?, ?, NOW())");
            if ($lStmt) {
                $lStmt->bind_param('si', $logText, $admin_id);
                $lStmt->execute();
                $lStmt->close();
            }

            if (function_exists('send_reservation_cancellation_confirmed_email')) {
                try {
                    @send_reservation_cancellation_confirmed_email($requestId, $reason, $cancelledBy, $conn);
                } catch (Throwable $eMail) {
                    error_log("Failed to send cancellation email: " . $eMail->getMessage());
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Reservation request #{$requestId} has been marked as Cancelled. The plot has been released. (No refund issued)"
            ]);
            exit;
        }

        if ($action === 'resend_email') {
            $stmt = $conn->prepare("SELECT * FROM guest_reservation_requests WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $req = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$req) throw new Exception("Reservation request not found.");
            if (strtolower(trim($req['status'])) !== 'approved') {
                throw new Exception("Only approved requests can receive an account creation email.");
            }

            $token = $req['account_creation_token_hash'];

            if (empty($token) || (strtotime($req['token_expires_at']) < time())) {
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + (48 * 3600));
                $conn->query("UPDATE guest_reservation_requests SET account_creation_token_hash = '$token', token_expires_at = '$expiresAt' WHERE id = $requestId");
                if (!empty($req['booking_id'])) {
                    $bId = (int)$req['booking_id'];
                    $conn->query("UPDATE tbl_bookings SET registration_token = '$token', token_expires_at = '$expiresAt', token_used = 0 WHERE id = $bId");
                }
            }

            $bookingId = (int)$req['booking_id'];
            $sent = send_guest_account_creation_email($bookingId, $token, $conn);
            $err = $sent ? null : (function_exists('get_mail_error') ? get_mail_error() : 'Failed to send');

            $conn->query("UPDATE guest_reservation_requests SET email_sent = " . ($sent ? 1 : 0) . ", email_error = " . ($err ? "'" . $conn->real_escape_string($err) . "'" : "NULL") . " WHERE id = $requestId");

            if ($sent) {
                echo json_encode(['success' => true, 'message' => "Complete profile email resent successfully to {$req['email']}."]);
            } else {
                throw new Exception("Email delivery failed: " . $err);
            }
            exit;
        }

        if ($action === 'save_note') {
            $notes = trim((string)($_POST['admin_notes'] ?? ''));
            $stmt = $conn->prepare("UPDATE guest_reservation_requests SET admin_notes = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $notes, $requestId);
                $stmt->execute();
                $stmt->close();
            }
            echo json_encode(['success' => true, 'message' => "Admin note saved successfully."]);
            exit;
        }

        if ($action === 'delete_request') {
            $stmt = $conn->prepare("SELECT * FROM guest_reservation_requests WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $req = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$req) throw new Exception("Reservation request not found.");

            $conn->begin_transaction();
            try {
                $sec = strtoupper(trim((string)$req['section']));
                $px = (int)$req['plot_x'];
                $py = (int)$req['plot_y'];
                $plotCode = trim((string)$req['plot_code']);
                $fullName = trim((string)$req['full_name']);
                $email = strtolower(trim((string)$req['email']));
                $bId = (int)($req['booking_id'] ?? 0);
                $uId = (int)($req['user_id'] ?? 0);

                $delGrr = $conn->prepare("DELETE FROM guest_reservation_requests WHERE id = ?");
                if ($delGrr) {
                    $delGrr->bind_param('i', $requestId);
                    $delGrr->execute();
                    $delGrr->close();
                }

                if ($bId > 0) {
                    $conn->query("DELETE FROM tbl_bookings WHERE id = $bId");
                }
                if ($email !== '') {
                    $escEmail = $conn->real_escape_string($email);
                    $conn->query("DELETE FROM tbl_bookings WHERE (LOWER(TRIM(customer_email)) = '{$escEmail}' OR LOWER(TRIM(email)) = '{$escEmail}') AND UPPER(TRIM(section)) = '{$sec}'");
                }
                if ($sec !== '' && $px > 0 && $py > 0) {
                    $conn->query("DELETE FROM tbl_bookings WHERE UPPER(TRIM(section)) = '{$sec}' AND (plot = '{$px}-{$py}' OR plot = '{$sec}-{$px}-{$py}' OR plot = '{$plotCode}')");
                }

                if ($sec !== '' && $px > 0 && $py > 0) {
                    $conn->query("DELETE FROM tbl_plot_owners WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
                }
                if ($uId > 0) {
                    $conn->query("DELETE FROM tbl_plot_owners WHERE reserved_by = {$uId}");
                }

                if ($conn->query("SHOW TABLES LIKE 'user_plot_ownership'")->num_rows > 0) {
                    if ($sec !== '' && $px > 0 && $py > 0) {
                        $conn->query("DELETE FROM user_plot_ownership WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
                    }
                    if ($uId > 0) {
                        $conn->query("DELETE FROM user_plot_ownership WHERE user_id = {$uId}");
                    }
                }

                if ($conn->query("SHOW TABLES LIKE 'tbl_plots'")->num_rows > 0 && $sec !== '' && $px > 0 && $py > 0) {
                    $checkDec = $conn->query("SELECT deceased_id FROM tbl_deceased WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py} LIMIT 1");
                    if (!$checkDec || $checkDec->num_rows === 0) {
                        $conn->query("UPDATE tbl_plots SET status = 'available' WHERE UPPER(TRIM(section)) = '{$sec}' AND plot_x = {$px} AND plot_y = {$py}");
                    }
                }

                if ($conn->query("SHOW TABLES LIKE 'tbl_logs'")->num_rows > 0) {
                    if ($bId > 0) {
                        $conn->query("DELETE FROM tbl_logs WHERE details LIKE '%#{$bId}%' OR details LIKE '%Booking ID: #{$bId}%'");
                    }
                    if ($email !== '') {
                        $escEmail = $conn->real_escape_string($email);
                        $conn->query("DELETE FROM tbl_logs WHERE (action LIKE '%GUEST_RESERVATION%' OR action LIKE '%Reservation%') AND details LIKE '%{$escEmail}%'");
                    }
                    if ($sec !== '' && $px > 0 && $py > 0) {
                        $conn->query("DELETE FROM tbl_logs WHERE (action LIKE '%Reservation%' OR action = 'Reserved By') AND details LIKE '%Section {$sec}%' AND (details LIKE '%{$px}-{$py}%' OR details LIKE '%Plot {$px}-{$py}%')");
                    }

                    $auditText = "Deleted guest reservation request #{$requestId} for {$fullName} ({$email}) on Section {$sec} Plot {$plotCode}. Associated plot reservation released back to available.";
                    $lStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, user_id, timestamp) VALUES ('GUEST_RESERVATION_DELETED', ?, ?, NOW())");
                    if ($lStmt) {
                        $lStmt->bind_param('si', $auditText, $admin_id);
                        $lStmt->execute();
                        $lStmt->close();
                    }
                }

                $conn->commit();
                echo json_encode([
                    'success' => true,
                    'message' => "Reservation request #{$requestId} for {$fullName} and associated plot reservation on Section {$sec} Plot {$plotCode} have been permanently deleted and released back to available."
                ]);
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
        }

        throw new Exception("Unsupported action '{$action}'.");

    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$searchTerm = trim((string)($_GET['search'] ?? ''));

$counts = [
    'all' => 0,
    'pending' => 0,
    'approved' => 0,
    'account_created' => 0,
    'rejected' => 0,
    'cancelled' => 0,
    'cancellation_requested' => 0
];

try {
    $cRes = @$conn->query("SELECT grr.status, COUNT(*) AS cnt 
        FROM guest_reservation_requests grr
        LEFT JOIN tbl_bookings b ON b.id = grr.booking_id
        LEFT JOIN payment_receipts pr ON pr.id = (
            SELECT MAX(pr_sub.id) FROM payment_receipts pr_sub 
            WHERE (grr.booking_id > 0 AND pr_sub.booking_id = grr.booking_id) OR (grr.payment_receipt_id > 0 AND pr_sub.id = grr.payment_receipt_id)
        )
        WHERE grr.status NOT IN ('Draft', 'Awaiting Payment')
          AND NOT (
              LOWER(grr.status) = 'pending' 
              AND (grr.payment_status = 'UNPAID' OR grr.payment_status IS NULL OR grr.payment_status = '') 
              AND (grr.proof_filename IS NULL OR grr.proof_filename = '') 
              AND (pr.id IS NULL OR pr.id = 0) 
              AND (b.amount_paid IS NULL OR b.amount_paid <= 0)
          )
        GROUP BY grr.status");
    if ($cRes) {
        while ($cRow = $cRes->fetch_assoc()) {
            $stNorm = strtolower(str_replace(' ', '_', trim((string)$cRow['status'])));
            $counts['all'] += (int)$cRow['cnt'];
            if (isset($counts[$stNorm])) {
                $counts[$stNorm] += (int)$cRow['cnt'];
            }
        }
    }
    $chkCanCol = @$conn->query("SHOW COLUMNS FROM `guest_reservation_requests` LIKE 'cancellation_requested'");
    if ($chkCanCol && $chkCanCol->num_rows > 0) {
        $cReqRes = @$conn->query("SELECT COUNT(*) AS cnt FROM guest_reservation_requests WHERE cancellation_requested = 1 AND LOWER(status) != 'cancelled' AND status NOT IN ('Draft', 'Awaiting Payment')");
        if ($cReqRes && ($cReqRow = $cReqRes->fetch_assoc())) {
            $counts['cancellation_requested'] = (int)$cReqRow['cnt'];
        }
    }
} catch (Throwable $e) {}

// 1. Inspect tbl_bookings columns dynamically
$bCols = [];
$bColRes = @$conn->query("SHOW COLUMNS FROM `tbl_bookings`");
if ($bColRes) {
    while ($bc = $bColRes->fetch_assoc()) {
        $bCols[strtolower($bc['Field'])] = true;
    }
}
$bPlotPrice = isset($bCols['plot_price']) ? "b.plot_price" : "0.00 AS plot_price";
$bTotalAmt = isset($bCols['total_amount']) ? "b.total_amount" : "0.00 AS total_amount";
$bPayStat = isset($bCols['payment_status']) ? "b.payment_status AS b_payment_status" : "'' AS b_payment_status";
$bAmtPaid = isset($bCols['amount_paid']) ? "b.amount_paid AS b_amount_paid" : "0.00 AS b_amount_paid";
$bGcashRef = isset($bCols['gcash_reference_number']) ? "b.gcash_reference_number AS b_gcash_ref" : "'' AS b_gcash_ref";
$bProof = isset($bCols['payment_proof_filename']) ? "b.payment_proof_filename AS b_proof_filename" : "'' AS b_proof_filename";

// 2. Inspect payment_receipts columns dynamically
$prCols = [];
$checkPr = @$conn->query("SHOW TABLES LIKE 'payment_receipts'");
$hasPrTbl = ($checkPr && $checkPr->num_rows > 0);
if ($hasPrTbl) {
    $prColRes = @$conn->query("SHOW COLUMNS FROM `payment_receipts`");
    if ($prColRes) {
        while ($prc = $prColRes->fetch_assoc()) {
            $prCols[strtolower($prc['Field'])] = true;
        }
    }
}

$hasPrAmtPaid = isset($prCols['amount_paid']);
$hasPrGcash = isset($prCols['gcash_reference_number']);
$hasPrTx = isset($prCols['transaction_reference']);
$hasPrDate = isset($prCols['payment_date']);
$hasPrStat = isset($prCols['payment_status']);
$hasPrProof = isset($prCols['proof_filename']);

$prSelect = "";
$prJoin = "";
if ($hasPrTbl && ($hasPrAmtPaid || isset($prCols['amount']))) {
    $prAmtCol = $hasPrAmtPaid ? "pr.amount_paid" : "pr.amount";
    $prGcashCol = $hasPrGcash ? "pr.gcash_reference_number" : ($hasPrTx ? "pr.transaction_reference" : "pr.reference_number");
    $prTxCol = $hasPrTx ? "pr.transaction_reference" : "''";
    $prDateCol = $hasPrDate ? "pr.payment_date" : "pr.created_at";
    $prStatCol = $hasPrStat ? "pr.payment_status" : "'PAID'";
    $prProofCol = $hasPrProof ? "pr.proof_filename" : "''";

    $prSelect = ", pr.id AS pr_id, {$prAmtCol} AS pr_amount_paid, {$prGcashCol} AS pr_gcash_ref,
                   {$prTxCol} AS pr_tx_ref, {$prDateCol} AS pr_payment_date,
                   {$prStatCol} AS pr_payment_status, {$prProofCol} AS pr_proof_filename";
    
    $prJoin = "LEFT JOIN payment_receipts pr ON pr.id = (
                  SELECT MAX(pr_sub.id) FROM payment_receipts pr_sub 
                  WHERE (grr.booking_id > 0 AND pr_sub.booking_id = grr.booking_id) OR (grr.payment_receipt_id > 0 AND pr_sub.id = grr.payment_receipt_id)
              )";
} else {
    $prSelect = ", 0 AS pr_id, 0.00 AS pr_amount_paid, '' AS pr_gcash_ref, '' AS pr_tx_ref, NULL AS pr_payment_date, '' AS pr_payment_status, '' AS pr_proof_filename";
    $prJoin = "";
}

$whereClauses = [];
$params = [];
$paramTypes = '';

// Filter out unsubmitted checkout attempts / awaiting payment
$whereClauses[] = "grr.status NOT IN ('Draft', 'Awaiting Payment')";
$whereClauses[] = "NOT (LOWER(grr.status) = 'pending' AND (grr.payment_status = 'UNPAID' OR grr.payment_status IS NULL OR grr.payment_status = '') AND (grr.proof_filename IS NULL OR grr.proof_filename = '') AND (pr.id IS NULL OR pr.id = 0) AND (b.amount_paid IS NULL OR b.amount_paid <= 0))";

if ($statusFilter === 'cancellation_requested') {
    $whereClauses[] = "grr.cancellation_requested = 1 AND LOWER(grr.status) != 'cancelled'";
} elseif ($statusFilter !== 'all' && !empty($statusFilter)) {
    $whereStatus = str_replace('_', ' ', $statusFilter);
    $whereClauses[] = "LOWER(grr.status) = LOWER(?)";
    $params[] = $whereStatus;
    $paramTypes .= 's';
}

if (!empty($searchTerm)) {
    $like = '%' . $searchTerm . '%';
    $searchFields = [
        "grr.full_name LIKE ?",
        "grr.email LIKE ?",
        "grr.contact_number LIKE ?",
        "grr.plot_code LIKE ?",
        "grr.section LIKE ?",
        "grr.deceased_full_name LIKE ?",
        "grr.deceased_first_name LIKE ?",
        "grr.deceased_last_name LIKE ?",
        "grr.address LIKE ?",
        "grr.purpose LIKE ?",
        "grr.notes LIKE ?",
        "grr.gcash_reference LIKE ?",
        "grr.payment_reference LIKE ?",
        "u.first_name LIKE ?",
        "u.last_name LIKE ?",
        "CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) LIKE ?"
    ];
    if (isset($bCols['customer_name'])) $searchFields[] = "b.customer_name LIKE ?";
    if (isset($bCols['full_name']))     $searchFields[] = "b.full_name LIKE ?";
    if (isset($bCols['deceased_name'])) $searchFields[] = "b.deceased_name LIKE ?";

    $fullWhere = "(" . implode(" OR ", $searchFields) . ")";

    $words = array_filter(preg_split('/\s+/', trim($searchTerm)));
    if (count($words) > 1) {
        $wordClauses = [];
        $wordParams = [];
        foreach ($words as $w) {
            $wLike = '%' . $w . '%';
            $wSub = [
                "grr.full_name LIKE ?",
                "grr.email LIKE ?",
                "grr.deceased_full_name LIKE ?",
                "u.first_name LIKE ?",
                "u.last_name LIKE ?"
            ];
            $wP = [$wLike, $wLike, $wLike, $wLike, $wLike];
            if (isset($bCols['customer_name'])) {
                $wSub[] = "b.customer_name LIKE ?";
                $wP[] = $wLike;
            }
            if (isset($bCols['full_name'])) {
                $wSub[] = "b.full_name LIKE ?";
                $wP[] = $wLike;
            }
            $wordClauses[] = "(" . implode(" OR ", $wSub) . ")";
            foreach ($wP as $p) {
                $wordParams[] = $p;
            }
        }
        $whereClauses[] = "({$fullWhere} OR (" . implode(" AND ", $wordClauses) . "))";
        foreach ($searchFields as $sf) {
            $params[] = $like;
            $paramTypes .= 's';
        }
        foreach ($wordParams as $wp) {
            $params[] = $wp;
            $paramTypes .= 's';
        }
    } else {
        $whereClauses[] = $fullWhere;
        foreach ($searchFields as $sf) {
            $params[] = $like;
            $paramTypes .= 's';
        }
    }
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

$querySql = "SELECT grr.*, 
                    u.first_name AS user_first, u.last_name AS user_last,
                    {$bPlotPrice}, {$bTotalAmt}, {$bPayStat},
                    {$bAmtPaid}, {$bGcashRef}, {$bProof}
                    {$prSelect}
             FROM guest_reservation_requests grr
             LEFT JOIN tbl_user u ON u.user_id = grr.user_id
             LEFT JOIN tbl_bookings b ON b.id = grr.booking_id
             {$prJoin}
             {$whereSql}
             ORDER BY grr.id DESC LIMIT 300";

$requests = [];
$querySuccess = false;

if (!empty($params)) {
    $stmt = @$conn->prepare($querySql);
    if ($stmt) {
        $stmt->bind_param($paramTypes, ...$params);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $requests[] = $row;
                }
                $querySuccess = true;
            }
        }
        $stmt->close();
    }
} else {
    $res = @$conn->query($querySql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $requests[] = $row;
        }
        $querySuccess = true;
    }
}

// Resilient Fallback 1: Query without payment_receipts or advanced joins if primary failed
if (!$querySuccess) {
    error_log("guest_reservations: primary query failed, running clean fallback. SQL Error: " . $conn->error);
    $fbSql = "SELECT grr.*, 
                     u.first_name AS user_first, u.last_name AS user_last,
                     {$bPlotPrice}, {$bTotalAmt}, {$bPayStat},
                     {$bAmtPaid}, {$bGcashRef}, {$bProof},
                     0 AS pr_id, 0.00 AS pr_amount_paid, '' AS pr_gcash_ref, '' AS pr_tx_ref, NULL AS pr_payment_date, '' AS pr_payment_status, '' AS pr_proof_filename
              FROM guest_reservation_requests grr
              LEFT JOIN tbl_user u ON u.user_id = grr.user_id
              LEFT JOIN tbl_bookings b ON b.id = grr.booking_id
              {$whereSql}
              ORDER BY grr.id DESC LIMIT 300";

    if (!empty($params)) {
        $fbStmt = @$conn->prepare($fbSql);
        if ($fbStmt) {
            $fbStmt->bind_param($paramTypes, ...$params);
            if ($fbStmt->execute()) {
                $fbRes = $fbStmt->get_result();
                if ($fbRes) {
                    while ($row = $fbRes->fetch_assoc()) {
                        $requests[] = $row;
                    }
                    $querySuccess = true;
                }
            }
            $fbStmt->close();
        }
    } else {
        $fbRes = @$conn->query($fbSql);
        if ($fbRes) {
            while ($row = $fbRes->fetch_assoc()) {
                $requests[] = $row;
            }
            $querySuccess = true;
        }
    }
}

// Resilient Fallback 2: Absolute simplest direct query on guest_reservation_requests
if (!$querySuccess) {
    $dirSql = "SELECT grr.*, 
                      u.first_name AS user_first, u.last_name AS user_last,
                      0.00 AS plot_price, 0.00 AS total_amount, '' AS b_payment_status,
                      0.00 AS b_amount_paid, '' AS b_gcash_ref, '' AS b_proof_filename,
                      0 AS pr_id, 0.00 AS pr_amount_paid, '' AS pr_gcash_ref, '' AS pr_tx_ref, NULL AS pr_payment_date, '' AS pr_payment_status, '' AS pr_proof_filename
               FROM guest_reservation_requests grr
               LEFT JOIN tbl_user u ON u.user_id = grr.user_id
               LEFT JOIN tbl_bookings b ON b.id = grr.booking_id
               {$whereSql}
               ORDER BY grr.id DESC LIMIT 300";

    if (!empty($params)) {
        $dStmt = @$conn->prepare($dirSql);
        if ($dStmt) {
            $dStmt->bind_param($paramTypes, ...$params);
            if ($dStmt->execute()) {
                $dRes = $dStmt->get_result();
                if ($dRes) {
                    while ($row = $dRes->fetch_assoc()) {
                        $requests[] = $row;
                    }
                    $querySuccess = true;
                }
            }
            $dStmt->close();
        }
    } else {
        $dRes = @$conn->query($dirSql);
        if ($dRes) {
            while ($row = $dRes->fetch_assoc()) {
                $requests[] = $row;
            }
            $querySuccess = true;
        }
    }
}

// Resilient Fallback 3: If query completely failed (e.g. invalid search input syntax), show all without where clause
if (!$querySuccess && empty($requests)) {
    $fb3Res = @$conn->query("SELECT grr.*, '' AS user_first, '' AS user_last, 0.00 AS plot_price, 0.00 AS total_amount, '' AS b_payment_status, 0.00 AS b_amount_paid, '' AS b_gcash_ref, '' AS b_proof_filename, 0 AS pr_id, 0.00 AS pr_amount_paid, '' AS pr_gcash_ref, '' AS pr_tx_ref, NULL AS pr_payment_date, '' AS pr_payment_status, '' AS pr_proof_filename FROM guest_reservation_requests grr ORDER BY grr.id DESC LIMIT 300");
    if ($fb3Res) {
        while ($row = $fb3Res->fetch_assoc()) {
            $requests[] = $row;
        }
    }
}

function renderStatusBadge($status, $row = null) {
    $s = trim($status);
    switch (strtolower($s)) {
        case 'pending':
            return '<span class="badge" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; font-weight:600;"><i class="fas fa-clock me-1"></i> Pending</span>';
        case 'approved':
            return '<span class="badge" style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0; font-weight:600;"><i class="fas fa-check-circle me-1"></i> Approved</span>';
        case 'account created':
            $extra = '';
            if (is_array($row) && !empty($row['user_id']) && empty($row['user_first']) && empty($row['user_last'])) {
                $extra = '<div class="mt-1"><span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size:0.7rem; font-weight:600;"><i class="fas fa-user-slash me-1"></i> User Deleted Account</span></div>';
            }
            return '<span class="badge" style="background:#e0f2fe; color:#075985; border:1px solid #bae6fd; font-weight:600;"><i class="fas fa-user-check me-1"></i> Account Created</span>' . $extra;
        case 'rejected':
            return '<span class="badge" style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca; font-weight:600;"><i class="fas fa-times-circle me-1"></i> Rejected</span>';
        case 'cancelled':
            $canBy = '';
            if (is_array($row) && !empty($row['cancelled_by'])) {
                $canBy = '<div class="mt-1 small text-muted" style="font-size:0.72rem;"><strong>By:</strong> ' . htmlspecialchars($row['cancelled_by']) . '</div>';
                if (!empty($row['cancellation_reason'])) {
                    $canBy .= '<div class="text-muted" style="font-size:0.68rem; max-width:180px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="' . htmlspecialchars($row['cancellation_reason']) . '">Reason: ' . htmlspecialchars($row['cancellation_reason']) . '</div>';
                }
            }
            return '<span class="badge" style="background:#f3f4f6; color:#4b5563; border:1px solid #e5e7eb; font-weight:600;"><i class="fas fa-ban me-1"></i> Cancelled</span>' . $canBy;
        case 'expired':
            return '<span class="badge" style="background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; font-weight:600;"><i class="fas fa-hourglass-end me-1"></i> Expired</span>';
        default:
            $extraBadge = '';
            if (is_array($row) && !empty($row['cancellation_requested']) && strtolower($s) !== 'cancelled') {
                $extraBadge = '<div class="mt-1"><span class="badge bg-warning text-dark border border-warning" style="font-size:0.70rem;"><i class="fas fa-exclamation-triangle me-1"></i> Cancellation Requested</span></div>';
            }
            return '<span class="badge bg-secondary">' . htmlspecialchars($s) . '</span>' . $extraBadge;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Guest Reserve Requests - Admin Portal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <link rel="stylesheet" href="includes/admin_header.css">
  <style>
    :root {
      --bs-primary: #7b1113;
      --bs-primary-hover: #580c0d;
    }
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background-color: #f8fafc;
      color: #1e293b;
      margin: 0;
      padding: 0;
    }
    .page-container {
      width: 100%;
      max-width: 100% !important;
      margin: 0;
      padding: 1.25rem 1.25rem 4rem;
      box-sizing: border-box;
    }
    .card {
      border: 1px solid rgba(0,0,0,0.06);
      border-radius: 16px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.03);
      overflow: hidden;
    }
    .stat-card {
      background: #ffffff;
      border-radius: 14px;
      padding: 1.25rem 1.5rem;
      border: 1px solid rgba(0,0,0,0.06);
      box-shadow: 0 2px 10px rgba(0,0,0,0.02);
      transition: transform 0.2s, box-shadow 0.2s;
    }
    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0,0,0,0.06);
    }
    .stat-val {
      font-size: 1.85rem;
      font-weight: 700;
      line-height: 1.1;
    }
    .stat-label {
      font-size: 0.8rem;
      color: #64748b;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .nav-tabs-custom .nav-link {
      color: #64748b;
      font-weight: 600;
      font-size: 0.9rem;
      border: none;
      padding: 0.75rem 1.25rem;
      border-radius: 8px;
      transition: all 0.2s;
    }
    .nav-tabs-custom .nav-link:hover {
      color: #7b1113;
      background: rgba(123, 17, 19, 0.05);
    }
    .nav-tabs-custom .nav-link.active {
      color: #ffffff;
      background: #7b1113;
    }
    .table thead th {
      background: #f8fafc;
      color: #475569;
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.03em;
      font-weight: 700;
      padding: 0.5rem 0.4rem;
      border-bottom: 1px solid #e2e8f0;
      white-space: nowrap;
    }
    .table tbody td {
      padding: 0.5rem 0.4rem;
      vertical-align: middle;
      font-size: 0.78rem;
      border-bottom: 1px solid #f1f5f9;
    }
    .col-actions {
      text-align: right !important;
      padding-right: 0.75rem !important;
      white-space: nowrap;
      min-width: 125px;
      width: 125px;
    }
    .table tbody tr:hover {
      background: #fafafa;
    }
    .btn-maroon {
      background: #7b1113;
      border-color: #7b1113;
      color: #ffffff;
      font-weight: 600;
      border-radius: 8px;
    }
    .btn-maroon:hover {
      background: #580c0d;
      border-color: #580c0d;
      color: #ffffff;
    }
    .modal-content {
      border-radius: 20px;
      border: none;
      overflow: hidden;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }
  </style>
</head>
<body>

  <?php include __DIR__ . '/includes/admin_header.php'; ?>

  <main class="page-container">

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
      <div>
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="badge px-3 py-1" style="background: rgba(123, 17, 19, 0.1); color: #7b1113; font-weight: 600; font-size: 0.78rem; border-radius: 6px;">
            <i class="fas fa-bookmark me-1"></i> CEMETERY PUBLIC MAP
          </span>
          <span class="text-muted small">•</span>
          <span class="text-muted small">Future Memorial Lot Inquiries</span>
        </div>
        <h3 class="fw-bold m-0" style="color: #0f172a; letter-spacing: -0.01em;">Guest Reserve Requests</h3>
        <p class="text-muted small m-0 mt-1">Review, approve, or reject unauthenticated guest reserve submissions from the public cemetery map.</p>
      </div>

      <div class="d-flex gap-2">
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm px-3 fw-semibold" style="border-radius: 8px;">
          <i class="fas fa-arrow-left me-1"></i> Dashboard
        </a>
        <a href="map.php" class="btn btn-maroon btn-sm px-3" style="border-radius: 8px;">
          <i class="fas fa-map-marked-alt me-1"></i> Public Map
        </a>
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-6 col-lg-3">
        <div class="stat-card">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="stat-label">Total Requests</span>
            <div class="rounded-circle p-2" style="background: #f1f5f9; color: #475569;"><i class="fas fa-list"></i></div>
          </div>
          <div class="stat-val text-dark"><?php echo number_format($counts['all']); ?></div>
          <div class="small text-muted mt-1">All guest inquiries</div>
        </div>
      </div>

      <div class="col-6 col-lg-3">
        <div class="stat-card" style="border-left: 4px solid #f59e0b;">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="stat-label" style="color: #b45309;">Pending Review</span>
            <div class="rounded-circle p-2" style="background: #fef3c7; color: #b45309;"><i class="fas fa-clock"></i></div>
          </div>
          <div class="stat-val text-warning" style="color: #b45309 !important;"><?php echo number_format($counts['pending']); ?></div>
          <div class="small text-muted mt-1">Awaiting admin review</div>
        </div>
      </div>

      <div class="col-6 col-lg-3">
        <div class="stat-card" style="border-left: 4px solid #10b981;">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="stat-label" style="color: #047857;">Approved</span>
            <div class="rounded-circle p-2" style="background: #d1fae5; color: #047857;"><i class="fas fa-check-circle"></i></div>
          </div>
          <div class="stat-val text-success"><?php echo number_format($counts['approved']); ?></div>
          <div class="small text-muted mt-1">Email link dispatched</div>
        </div>
      </div>

      <div class="col-6 col-lg-3">
        <div class="stat-card" style="border-left: 4px solid #3b82f6;">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="stat-label" style="color: #1d4ed8;">Account Created</span>
            <div class="rounded-circle p-2" style="background: #dbeafe; color: #1d4ed8;"><i class="fas fa-user-check"></i></div>
          </div>
          <div class="stat-val text-primary"><?php echo number_format($counts['account_created']); ?></div>
          <div class="small text-muted mt-1">Converted to normal user</div>
        </div>
      </div>
    </div>

    <div class="card">

      <div class="card-header bg-white p-3 border-bottom d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">

        <ul class="nav nav-pills nav-tabs-custom gap-1 m-0">
          <li class="nav-item">
            <a class="nav-link <?php echo ($statusFilter === 'all') ? 'active' : ''; ?>" href="guest_reservations.php?status=all">
              All (<?php echo $counts['all']; ?>)
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo ($statusFilter === 'pending') ? 'active' : ''; ?>" href="guest_reservations.php?status=pending">
              Pending (<?php echo $counts['pending']; ?>)
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo ($statusFilter === 'approved') ? 'active' : ''; ?>" href="guest_reservations.php?status=approved">
              Approved (<?php echo $counts['approved']; ?>)
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo ($statusFilter === 'account_created') ? 'active' : ''; ?>" href="guest_reservations.php?status=account_created">
              Account Created (<?php echo $counts['account_created']; ?>)
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo ($statusFilter === 'rejected') ? 'active' : ''; ?>" href="guest_reservations.php?status=rejected">
              Rejected (<?php echo $counts['rejected']; ?>)
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo ($statusFilter === 'cancelled') ? 'active' : ''; ?>" href="guest_reservations.php?status=cancelled">
              Cancelled (<?php echo $counts['cancelled']; ?>)
            </a>
          </li>
          <?php if ($counts['cancellation_requested'] > 0): ?>
            <li class="nav-item">
              <a class="nav-link text-danger <?php echo ($statusFilter === 'cancellation_requested') ? 'active' : ''; ?>" href="guest_reservations.php?status=cancellation_requested" style="font-weight: 600;">
                <i class="fas fa-exclamation-triangle me-1"></i>Cancel Requests (<?php echo $counts['cancellation_requested']; ?>)
              </a>
            </li>
          <?php endif; ?>
        </ul>

        <form method="GET" action="guest_reservations.php" class="d-flex gap-2" style="max-width: 340px;">
          <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
            <input type="text" name="search" class="form-control border-start-0" placeholder="Search name, email, plot..." value="<?php echo htmlspecialchars($searchTerm); ?>" style="border-radius: 0 8px 8px 0;">
          </div>
          <?php if (!empty($searchTerm)): ?>
            <a href="guest_reservations.php?status=<?php echo htmlspecialchars($statusFilter); ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center" title="Clear Search">
              <i class="fas fa-times"></i>
            </a>
          <?php endif; ?>
        </form>
      </div>

      <?php if (!empty($searchTerm)): ?>
        <div class="px-3 pt-3 pb-0">
          <div class="alert alert-warning py-2 px-3 d-flex flex-wrap align-items-center justify-content-between gap-2 m-0" style="border-radius: 8px; border-left: 4px solid #f59e0b;">
            <div class="d-flex align-items-center gap-2">
              <i class="fas fa-filter text-warning"></i>
              <span>Active Search Filter: <strong>"<?php echo htmlspecialchars($searchTerm); ?>"</strong> &mdash; Showing <strong><?php echo count($requests); ?></strong> of <strong><?php echo $counts['all']; ?></strong> total requests</span>
            </div>
            <a href="guest_reservations.php?status=all" class="btn btn-sm btn-outline-dark" style="font-weight: 500;">
              <i class="fas fa-times me-1"></i> Clear Search Filter
            </a>
          </div>
        </div>
      <?php endif; ?>

      <div class="table-responsive">
        <table class="table table-hover align-middle m-0" style="width: 100%;">
          <thead>
            <tr>
              <th style="width: 15%;">Guest Name</th>
              <th style="width: 15%;">Email &amp; Contact</th>
              <th style="width: 13%;">Selected Plot</th>
              <th style="width: 16%;">Deceased &amp; Purpose</th>
              <th style="width: 13%;">Downpayment</th>
              <th style="width: 11%;">Reservation Date</th>
              <th style="width: 7%; text-align: center;">Status</th>
              <th class="col-actions" style="width: 10%;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($requests)): ?>
              <tr>
                <td colspan="8" class="text-center py-5 text-muted">
                  <div class="py-4">
                    <?php if (!empty($searchTerm)): ?>
                      <i class="fas fa-search-minus fa-3x text-warning opacity-75 mb-3"></i>
                      <h5 class="fw-semibold text-dark mb-1">No requests matching "<?php echo htmlspecialchars($searchTerm); ?>"</h5>
                      <p class="text-muted mb-3" style="max-width: 480px; margin: 0 auto;">
                        There are <strong><?php echo $counts['all']; ?> total reserve requests</strong> recorded in the database, but none match this search keyword.
                      </p>
                      <div class="d-flex justify-content-center gap-2">
                        <a href="guest_reservations.php?status=all" class="btn btn-sm px-3 py-2 text-white" style="background:#5c0606; font-weight:600; border-radius: 6px;">
                          <i class="fas fa-list me-1"></i> Show All <?php echo $counts['all']; ?> Requests
                        </a>
                        <a href="guest_reservations.php?status=pending" class="btn btn-sm btn-outline-secondary px-3 py-2" style="border-radius: 6px;">
                          <i class="fas fa-clock me-1"></i> View Pending (<?php echo $counts['pending']; ?>)
                        </a>
                      </div>
                    <?php else: ?>
                      <i class="fas fa-inbox fa-3x text-muted opacity-50 mb-3"></i>
                      <p class="m-0 fw-semibold">No guest reserve requests found.</p>
                      <small class="text-muted">Requests submitted from the public map will appear here.</small>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($requests as $r): ?>
                <?php 
                  $sec = strtoupper(trim($r['section']));
                  $plot = trim($r['plot_code']);
                  $st = trim($r['status']);
                  $decName = trim((string)($r['deceased_full_name'] ?? ''));
                  if (empty($decName)) {
                      $dParts = array_filter([$r['deceased_first_name'] ?? '', $r['deceased_middle_name'] ?? '', $r['deceased_last_name'] ?? '', $r['deceased_suffix'] ?? '']);
                      $decName = implode(' ', $dParts);
                  }
                  $reqType = trim((string)($r['request_type'] ?? 'guest'));
                  $hasMatch = !empty($r['matched_deceased_id']) && (int)$r['matched_deceased_id'] > 0;
                  
                  // Payment resolution
                  $isPaid = false;
                  $payAmt = 0.00;
                  $payRef = '';
                  $proofFile = '';
                  $bId = (int)($r['booking_id'] ?? 0);

                  if (!empty($r['pr_id'])) {
                      $payAmt = floatval($r['pr_amount_paid']);
                      $payRef = $r['pr_gcash_ref'] ?: $r['pr_tx_ref'];
                      $proofFile = $r['pr_proof_filename'];
                      $isPaid = true;
                  } elseif (!empty($r['payment_status']) && strtoupper($r['payment_status']) === 'PAID') {
                      $payAmt = floatval($r['payment_amount'] ?? ($r['downpayment_amount'] ?? ($r['downpayment'] ?? 0)));
                      $payRef = $r['gcash_reference'] ?? ($r['payment_reference'] ?? '');
                      $proofFile = $r['payment_proof_filename'] ?? ($r['proof_filename'] ?? '');
                      $isPaid = true;
                  } elseif (!empty($r['b_payment_status']) && in_array(strtoupper($r['b_payment_status']), ['PAID', 'VERIFIED'])) {
                      $payAmt = floatval($r['b_amount_paid'] ?? ($r['downpayment_amount'] ?? 0));
                      $payRef = $r['b_gcash_ref'] ?? '';
                      $proofFile = $r['b_proof_filename'] ?? '';
                      $isPaid = true;
                  } elseif (!empty($r['proof_filename']) || !empty($r['payment_proof_filename'])) {
                      $proofFile = $r['payment_proof_filename'] ?? $r['proof_filename'];
                  }
                  if ($payAmt <= 0) {
                      $payAmt = floatval($r['payment_amount'] ?? ($r['downpayment_amount'] ?? ($r['downpayment'] ?? 0)));
                  }
                  if (empty($payRef)) {
                      $payRef = $r['gcash_reference'] ?? ($r['payment_reference'] ?? ($r['b_gcash_ref'] ?? ''));
                  }
                  // Fallback to booking / request proof (e.g. proof attached on finalize after gateway payment).
                  if (empty($proofFile)) {
                      $proofFile = $r['b_proof_filename'] ?? ($r['proof_filename'] ?? '');
                  }
                ?>
                <tr>
                  <td>
                    <div class="fw-bold text-dark text-truncate" style="max-width: 135px;" title="<?php echo htmlspecialchars($r['full_name']); ?>"><?php echo htmlspecialchars($r['full_name']); ?></div>
                    <div class="text-muted small text-truncate" style="max-width: 135px; font-size: 0.70rem;" title="<?php echo htmlspecialchars($r['address']); ?>"><i class="fas fa-map-marker-alt me-1 opacity-75"></i><?php echo htmlspecialchars($r['address']); ?></div>
                  </td>
                  <td>
                    <div class="text-dark text-truncate" style="max-width: 140px; font-size: 0.74rem;" title="<?php echo htmlspecialchars($r['email']); ?>"><i class="fas fa-envelope text-muted me-1 small"></i><?php echo htmlspecialchars($r['email']); ?></div>
                    <div class="text-muted small font-monospace text-truncate" style="max-width: 140px; font-size: 0.70rem;"><i class="fas fa-phone text-muted me-1 small"></i><?php echo htmlspecialchars($r['contact_number']); ?></div>
                  </td>
                  <td>
                    <div class="fw-bold text-dark font-monospace text-nowrap" style="font-size: 0.76rem;">Sec <?php echo $sec; ?> &bull; Plot <?php echo $plot; ?></div>
                    <div class="text-muted small text-nowrap" style="font-size: 0.70rem;">Standard Memorial Lawn</div>
                  </td>
                  <td>
                    <?php if (!empty($decName)): ?>
                      <div class="fw-bold text-dark text-truncate" style="max-width: 145px; font-size: 0.80rem;" title="<?php echo htmlspecialchars($decName); ?>">
                        <i class="fas fa-cross text-secondary me-1"></i><?php echo htmlspecialchars($decName); ?>
                      </div>
                      <?php if (!empty($r['deceased_relationship'])): ?>
                        <div class="text-muted small text-truncate" style="max-width: 145px; font-size: 0.72rem;">Rel: <?php echo htmlspecialchars($r['deceased_relationship']); ?></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <div class="text-secondary fw-semibold small" style="font-size: 0.78rem;">
                        <i class="fas fa-user-slash me-1"></i>Deceased: <span class="badge bg-secondary text-white" style="font-size: 0.65rem;">NOT ASSIGNED</span>
                      </div>
                      <div class="text-muted small" style="font-size: 0.70rem;">Pre-need / Future</div>
                    <?php endif; ?>
                    <div class="mt-1 d-flex flex-wrap gap-1 align-items-center">
                      <span class="badge bg-light text-dark border px-1 py-0.5" style="font-size: 0.68rem;">
                        <?php echo htmlspecialchars($r['purpose'] ?: 'Reserve New Grave'); ?>
                      </span>
                      <?php if ($reqType === 'no_deceased' || empty($decName)): ?>
                        <span class="badge px-1 py-0.5" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-size: 0.68rem;" title="Reserved without deceased record for future use">
                          <i class="fas fa-bookmark me-1"></i>Future Use
                        </span>
                      <?php endif; ?>
                      <?php if ($hasMatch): ?>
                        <span class="badge px-1 py-0.5" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 0.68rem;" title="Matched with existing cemetery deceased record">
                          <i class="fas fa-check-double me-1"></i>Matched #<?php echo (int)$r['matched_deceased_id']; ?>
                        </span>
                      <?php elseif ($reqType === 'deceased_verification'): ?>
                        <span class="badge px-1 py-0.5" style="background: #fef9c3; color: #854d0e; border: 1px solid #fef08a; font-size: 0.68rem;">
                          <i class="fas fa-search me-1"></i>Verification
                        </span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <?php if ($isPaid): ?>
                      <div>
                        <span class="badge" style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0; font-size: 0.72rem; padding: 2px 5px;">
                          <i class="fas fa-check-circle me-1"></i>PAID ₱<?php echo number_format($payAmt, 2); ?>
                        </span>
                      </div>
                      <?php if (!empty($payRef)): ?>
                        <div class="text-muted small font-monospace mt-1" style="font-size: 0.68rem;">Ref: <?php echo htmlspecialchars($payRef); ?></div>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="badge bg-light text-muted border" style="font-size: 0.70rem; padding: 2px 5px;">
                        <i class="fas fa-hourglass-start me-1"></i>Unpaid / Manual
                      </span>
                    <?php endif; ?>
                    <?php if (!empty($proofFile)): ?>
                      <div class="mt-1">
                        <?php 
                          $cleanProof = ltrim(trim($proofFile), '/');
                          $isTableImg = preg_match('/\.(jpe?g|png|webp|gif)$/i', $cleanProof);
                          $tProofSrc = (strpos($cleanProof, 'uploads/') === 0) ? $cleanProof : ('uploads/payment-proofs/' . $cleanProof);
                          $tProofUrl = 'view_payment_proof.php?booking_id=' . $bId . '&file=' . urlencode(basename($cleanProof));
                        ?>
                        <?php if ($isTableImg): ?>
                          <button type="button" onclick="openProofModal('<?php echo htmlspecialchars($tProofSrc, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($r['full_name'], ENT_QUOTES); ?>', '<?php echo $r['id']; ?>')" class="btn btn-xs btn-outline-primary py-0 px-1" style="font-size: 0.68rem; border-radius: 4px;" title="View uploaded proof of payment">
                            <i class="fas fa-search-plus me-1"></i>View Proof
                          </button>
                        <?php else: ?>
                          <a href="<?php echo $tProofUrl; ?>" target="_blank" class="btn btn-xs btn-outline-primary py-0 px-1" style="font-size: 0.68rem; border-radius: 4px;" title="View uploaded proof of payment">
                            <i class="fas fa-external-link-alt me-1"></i>View Proof
                          </a>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($r['preferred_date'])): ?>
                      <div class="fw-bold text-primary small"><i class="fas fa-calendar-check me-1"></i><?php echo date('M d, Y', strtotime($r['preferred_date'])); ?></div>
                      <div class="text-muted" style="font-size: 0.68rem;">Req: <?php echo date('M d, Y', strtotime($r['created_at'])); ?></div>
                    <?php else: ?>
                      <div class="text-dark small fw-semibold"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></div>
                      <div class="text-muted" style="font-size: 0.70rem;"><?php echo date('h:i A', strtotime($r['created_at'])); ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?php echo renderStatusBadge($st, $r); ?>
                  </td>
                  <td class="col-actions">
                    <div class="d-flex align-items-center justify-content-end gap-1">
                      <button type="button" class="btn btn-sm btn-outline-dark fw-semibold px-2 py-1" onclick="openViewModal(<?php echo $r['id']; ?>)" style="border-radius: 6px; font-size: 0.78rem;">
                        <i class="fas fa-eye me-1"></i> View
                      </button>
                      <?php if (strtolower($st) === 'pending'): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger fw-semibold px-2 py-1" onclick="openRejectModal(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['full_name'])); ?>')" title="Reject Request" style="border-radius: 6px; font-size: 0.78rem;">
                          <i class="fas fa-times"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-success fw-semibold px-2 py-1" onclick="quickApprove(<?php echo $r['id']; ?>)" title="Approve Request" style="border-radius: 6px; font-size: 0.78rem;">
                          <i class="fas fa-check"></i>
                        </button>
                      <?php endif; ?>
                      <?php if (in_array(strtolower($st), ['pending', 'approved', 'account created'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary fw-semibold px-2 py-1" onclick="openCancelModal(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['full_name'])); ?>', '<?php echo htmlspecialchars(addslashes($r['cancellation_reason'] ?? '')); ?>', <?php echo (int)($r['cancellation_requested'] ?? 0); ?>)" title="<?php echo ((int)($r['cancellation_requested'] ?? 0) === 1) ? 'Review / Accept Cancellation' : 'Cancel Reservation'; ?>" style="border-radius: 6px; font-size: 0.78rem;">
                          <i class="fas fa-ban text-danger"></i>
                        </button>
                      <?php endif; ?>
                      <?php if (in_array(strtolower($st), ['rejected', 'cancelled'])): ?>
                        <button type="button" class="btn btn-sm btn-danger fw-semibold px-2 py-1" onclick="deleteGuestRequest(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['full_name'])); ?>')" title="Delete Request" style="border-radius: 6px; font-size: 0.78rem;">
                          <i class="fas fa-trash-alt"></i>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="card-footer bg-white p-3 border-top text-muted small d-flex justify-content-between align-items-center">
        <span>Showing <strong><?php echo count($requests); ?></strong> request(s)</span>
        <span class="text-muted">Garden of Remembrance CMIS</span>
      </div>

    </div>

  </main>

  <div class="modal fade" id="viewRequestModal" tabindex="-1" aria-labelledby="viewRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header py-3 px-4 text-white" style="background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important;">
          <div class="d-flex align-items-center gap-2">
            <div style="background: rgba(255,255,255,0.18); width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
              <i class="fas fa-user-clock text-white"></i>
            </div>
            <div>
              <h5 class="modal-title fw-bold text-white m-0" id="viewRequestModalLabel" style="font-size: 1.15rem;">
                Guest Reservation Request<span id="m_req_id_badge" style="display: none;">#—</span>
              </h5>
              <div style="font-size: 0.78rem; color: rgba(255,255,255,0.85);">Review & Approval Portal</div>
            </div>
          </div>
          <button type="button" class="btn-close custom-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body p-4" style="background: #faf8f5;">

          <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 p-3 mb-3 rounded border bg-white" style="border-color: #e5dbcf !important;">
            <div>
              <div class="text-muted small text-uppercase fw-bold" style="font-size: 0.7rem;">Request Status</div>
              <div id="m_status_badge" class="mt-1">—</div>
            </div>
            <div class="text-md-end">
              <div class="text-muted small text-uppercase fw-bold" style="font-size: 0.7rem;">Live Plot Verification</div>
              <div id="m_live_plot_badge" class="mt-1">—</div>
            </div>
          </div>

          <div class="row g-3">

            <div class="col-md-6">
              <div class="p-3 rounded border bg-white h-100" style="border-color: #e5dbcf !important;">
                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3" style="font-size: 0.88rem;">
                  <i class="fas fa-user text-primary me-2"></i>Guest Information
                </h6>
                <div class="mb-2">
                  <div class="text-muted small" style="font-size: 0.72rem;">FULL NAME</div>
                  <div class="fw-bold text-dark" id="m_full_name">—</div>
                </div>
                <div class="mb-2">
                  <div class="text-muted small" style="font-size: 0.72rem;">EMAIL ADDRESS</div>
                  <div class="text-dark font-monospace small" id="m_email">—</div>
                </div>
                <div class="mb-2">
                  <div class="text-muted small" style="font-size: 0.72rem;">CONTACT NUMBER</div>
                  <div class="text-dark font-monospace fw-semibold" id="m_contact">—</div>
                </div>
                <div>
                  <div class="text-muted small" style="font-size: 0.72rem;">ADDRESS</div>
                  <div class="text-dark small" id="m_address">—</div>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="p-3 rounded border bg-white h-100" style="border-color: #e5dbcf !important;">
                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3" style="font-size: 0.88rem;">
                  <i class="fas fa-bookmark text-primary me-2"></i>Reservation Details
                </h6>
                <div class="mb-2">
                  <div class="text-muted small" style="font-size: 0.72rem;">SELECTED PLOT</div>
                  <div class="fw-bold text-dark font-monospace fs-6" id="m_plot_disp">—</div>
                </div>
                <div class="mb-2">
                  <div class="text-muted small" style="font-size: 0.72rem;">PURPOSE</div>
                  <div class="fw-semibold text-dark" id="m_purpose">—</div>
                </div>
                <div class="mb-2">
                  <div class="text-muted small" style="font-size: 0.72rem;">OFFICIAL RATE</div>
                  <div class="text-success fw-bold" id="m_price">₱85,000.00</div>
                </div>
                <div class="mb-2">
                  <div class="text-muted small" style="font-size: 0.72rem;">RESERVATION DATE</div>
                  <div class="fw-bold text-primary" id="m_preferred_date">—</div>
                </div>
                <div>
                  <div class="text-muted small" style="font-size: 0.72rem;">SUBMISSION DATE (PHILIPPINE TIME)</div>
                  <div class="text-dark small" id="m_dates">—</div>
                </div>
              </div>
            </div>

            <div class="col-12" id="m_deceased_section">
              <div class="p-3 rounded border bg-white" style="border-color: #e5dbcf !important;">
                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                  <h6 class="fw-bold text-dark m-0" style="font-size: 0.88rem;">
                    <i class="fas fa-cross text-primary me-2"></i>Deceased Record Information
                  </h6>
                  <span id="m_deceased_match_badge"></span>
                </div>
                <div class="row g-3">
                  <div class="col-md-4">
                    <div class="text-muted small" style="font-size: 0.72rem;">DECEASED NAME</div>
                    <div class="fw-bold text-dark" id="m_dec_name">—</div>
                  </div>
                  <div class="col-md-4">
                    <div class="text-muted small" style="font-size: 0.72rem;">RELATIONSHIP TO REQUESTER</div>
                    <div class="fw-semibold text-dark" id="m_dec_rel">—</div>
                  </div>
                  <div class="col-md-4">
                    <div class="text-muted small" style="font-size: 0.72rem;">DATE OF BIRTH / DEATH</div>
                    <div class="text-dark small" id="m_dec_dates">—</div>
                  </div>
                  <div class="col-12">
                    <div class="text-muted small" style="font-size: 0.72rem;">FUNERAL HOME / CEMETERY SERVICE</div>
                    <div class="text-dark small" id="m_dec_funeral">—</div>
                  </div>
                </div>

                <div id="m_matched_deceased_card" class="mt-3 p-3 rounded border" style="background: #fffdf5; border-color: #fde68a !important; display: none;">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="fas fa-check-double text-warning fs-5"></i>
                    <strong style="font-size: 0.88rem; color: #92400e;">Possible Existing Deceased Record Matched in Cemetery Database</strong>
                  </div>
                  <div class="small text-muted mb-2">
                    This request was matched to an existing record in <code>tbl_deceased</code>. Approving will automatically link this reservation to the existing record without creating a duplicate.
                  </div>
                  <div class="p-2 rounded bg-white border small font-monospace" id="m_matched_deceased_details">

                  </div>
                </div>

                <div id="m_existing_account_card" class="mt-3 p-3 rounded border" style="background: #eff6ff; border-color: #bfdbfe !important; display: none;">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="fas fa-user-check text-primary fs-5"></i>
                    <strong style="font-size: 0.88rem; color: #1e40af;">Existing Account Detected</strong>
                  </div>
                  <div class="small text-muted" id="m_existing_account_details">

                  </div>
                </div>

              </div>
            </div>

            <div class="col-12" id="m_payment_section">
              <div class="p-3 rounded border bg-white" style="border-color: #e5dbcf !important;">
                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                  <h6 class="fw-bold text-dark m-0" style="font-size: 0.88rem;">
                    <i class="fas fa-money-check-alt text-primary me-2"></i>20% Downpayment & Payment Proof
                  </h6>
                  <span id="m_payment_badge"></span>
                </div>
                <div class="row g-3">
                  <div class="col-md-3">
                    <div class="text-muted small" style="font-size: 0.72rem;">AMOUNT PAID</div>
                    <div class="fw-bold text-dark fs-6" id="m_pay_amount">—</div>
                  </div>
                  <div class="col-md-3">
                    <div class="text-muted small" style="font-size: 0.72rem;">PAYMENT METHOD</div>
                    <div class="text-dark small fw-semibold" id="m_pay_method">GCash</div>
                  </div>
                  <div class="col-md-3">
                    <div class="text-muted small" style="font-size: 0.72rem;">GCASH REFERENCE</div>
                    <div class="text-dark font-monospace small fw-bold" id="m_pay_ref">—</div>
                  </div>
                  <div class="col-md-3">
                    <div class="text-muted small" style="font-size: 0.72rem;">PAYMENT DATE</div>
                    <div class="text-dark small" id="m_pay_date">—</div>
                  </div>
                  <div class="col-12" id="m_proof_box" style="display:none;">
                    <div class="rounded border p-3" style="background:#f8fafc; border-color:#e2e8f0 !important;">
                      <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="d-flex align-items-center gap-2">
                          <i class="fas fa-image text-success fs-5"></i>
                          <div>
                            <div class="fw-semibold small text-dark" id="m_proof_filename_disp">payment_proof.jpg</div>
                            <div class="text-muted" style="font-size: 0.72rem;">GCash Payment Proof Screenshot</div>
                          </div>
                        </div>
                        <button type="button" id="m_proof_view_btn" onclick="openProofModal()" class="btn btn-sm fw-semibold" style="background-color: #7b1113; border-color: #7b1113; color:#fff; font-size: 0.78rem; border-radius: 6px;">
                          <i class="fas fa-search-plus me-1"></i> View Full Image
                        </button>
                      </div>
                      <!-- Inline proof image preview -->
                      <div id="m_proof_inline_wrap" style="display:none; text-align:center;">
                        <a href="javascript:void(0);" id="m_proof_inline_link" onclick="openProofModal()">
                          <img id="m_proof_inline_img" src="" alt="Payment Proof" class="img-thumbnail shadow-sm" style="max-height: 220px; max-width: 100%; object-fit: contain; border-radius: 8px; border: 1.5px solid #e2e8f0;">
                        </a>
                        <div class="text-muted mt-1" style="font-size: 0.70rem;">Click image to enlarge</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-12">
              <div class="p-3 rounded border bg-white" style="border-color: #e5dbcf !important;">
                <div class="text-muted small fw-bold text-uppercase mb-1" style="font-size: 0.72rem;">Guest Special Requests / Remarks:</div>
                <div id="m_guest_notes" class="small text-muted fst-italic p-2 rounded bg-light border">None provided.</div>
              </div>
            </div>

            <div class="col-12">
              <div class="p-3 rounded border bg-white" style="border-color: #e5dbcf !important;">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.72rem;">Admin Internal Notes:</span>
                  <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" onclick="saveAdminNote()">
                    <i class="fas fa-save me-1"></i>Save Note
                  </button>
                </div>
                <textarea id="m_admin_notes" class="form-control form-control-sm" rows="2" placeholder="Add administrative notes or remarks..."></textarea>
              </div>
            </div>

            <div class="col-12" id="m_token_section" style="display: none;">
              <div class="alert alert-light border p-3 small mb-0" style="background: #ffffff; border-color: #e5dbcf !important;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <div>
                    <span class="fw-bold text-dark"><i class="fas fa-envelope-open-text text-primary me-2"></i>Complete Profile Link</span>
                    <div class="text-muted mt-1" id="m_token_expires_info" style="font-size: 0.76rem;">—</div>
                  </div>
                  <button type="button" class="btn btn-outline-primary btn-sm" id="m_resend_email_btn" onclick="resendApprovalEmail()" style="border-radius: 6px;">
                    <i class="fas fa-paper-plane me-1"></i> Resend Email
                  </button>
                </div>
                <div class="input-group input-group-sm mt-2" id="m_reg_link_group" style="display: none;">
                  <input type="text" class="form-control font-monospace" id="m_reg_url_input" readonly>
                  <button class="btn btn-outline-secondary" type="button" onclick="copyRegLink()" title="Copy Link">
                    <i class="fas fa-copy me-1"></i> Copy Link
                  </button>
                  <a href="#" target="_blank" class="btn btn-primary" id="m_reg_open_link" style="background-color: #7b1113; border-color: #7b1113;">
                    <i class="fas fa-external-link-alt me-1"></i> Open Page
                  </a>
                </div>
              </div>
            </div>

          </div>

        </div>

        <div class="modal-footer p-3 bg-white border-top d-flex justify-content-between">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border-radius: 8px;">Close</button>
          <div class="d-flex gap-2" id="m_action_buttons">

          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
      <div class="modal-content">
        <div class="modal-header py-3 px-4 bg-danger text-white border-0">
          <h5 class="modal-title fw-bold text-white m-0" id="rejectModalLabel" style="font-size: 1.1rem;">
            <i class="fas fa-times-circle me-2"></i>Reject Reservation Request
          </h5>
          <button type="button" class="btn-close custom-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <p class="small text-muted mb-3">
            Are you sure you want to reject the reservation request for <strong id="rej_guest_name" class="text-dark">—</strong>?
          </p>
          <label class="form-label small fw-bold text-muted text-uppercase mb-1">Reason for Rejection <span class="text-danger">*</span></label>
          <textarea id="rej_reason" class="form-control form-control-sm" rows="3" placeholder="State the reason for rejection (e.g. invalid contact information, plot unavailable, requested cancellation)..." required></textarea>
        </div>
        <div class="modal-footer p-3 border-top bg-light d-flex justify-content-between">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-danger px-3" id="confirmRejectBtn" onclick="confirmReject()">
            <i class="fas fa-ban me-1"></i> Confirm Rejection
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 480px;">
      <div class="modal-content">
        <div class="modal-header py-3 px-4 bg-danger text-white border-0">
          <h5 class="modal-title fw-bold text-white m-0" id="cancelModalLabel" style="font-size: 1.1rem;">
            <i class="fas fa-ban me-2"></i>Cancel Reservation (No Refund)
          </h5>
          <button type="button" class="btn-close custom-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="alert alert-danger p-2 small mb-3">
            <i class="fas fa-exclamation-triangle me-1"></i> <strong>Strictly NO REFUND:</strong> All downpayments and fees are non-refundable upon cancellation. The plot will be released back to available status.
          </div>
          <p class="small text-muted mb-2">
            Cancelling reservation request for: <strong id="cancel_guest_name" class="text-dark">—</strong>
          </p>
          <div id="cancel_guest_reason_box" class="p-2 mb-3 bg-light border rounded small" style="display:none;">
            <strong class="text-muted" style="font-size:0.7rem; text-transform:uppercase;">Guest Stated Reason:</strong>
            <div id="cancel_guest_reason_disp" class="fst-italic text-dark mt-1"></div>
          </div>
          <label class="form-label small fw-bold text-muted text-uppercase mb-1">Cancellation Reason / Notes <span class="text-danger">*</span></label>
          <textarea id="cancel_reason_input" class="form-control form-control-sm" rows="3" placeholder="Provide the reason for cancellation (e.g. Guest requested cancellation, family changed plans, etc.)..." required></textarea>
        </div>
        <div class="modal-footer p-3 border-top bg-light d-flex justify-content-between">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-danger px-3" id="confirmCancelBtn" onclick="confirmCancelAction()">
            <i class="fas fa-ban me-1"></i> Confirm Cancellation (No Refund)
          </button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Proof of Payment Viewer Modal -->
<div class="modal fade" id="proofViewerModal" tabindex="-1" aria-labelledby="proofViewerModalLabel" aria-hidden="true" style="z-index: 1070;">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content shadow-lg border-0" style="border-radius: 16px; overflow: hidden; background: #ffffff;">
      <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #4a0709 100%); color: #ffffff;">
        <div class="d-flex align-items-center gap-2">
          <div class="rounded-circle p-2 d-flex align-items-center justify-content-center" style="background: rgba(255,255,255,0.15); width: 36px; height: 36px;">
            <i class="fas fa-receipt text-warning"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold text-white mb-0" id="proofViewerModalLabel" style="font-size: 1.05rem;">Proof of Payment</h5>
            <small class="text-white-50" id="proofViewerSubtitle" style="font-size: 0.75rem;">Viewing uploaded transaction receipt</small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-3 text-center position-relative" style="background: #0f172a; min-height: 420px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
        <!-- Loading Spinner -->
        <div id="proofViewerLoader" class="text-white text-center py-5">
          <div class="spinner-border text-light mb-2" role="status" style="width: 2.5rem; height: 2.5rem;"></div>
          <div class="small opacity-75">Loading proof of payment...</div>
        </div>
        <!-- Error State -->
        <div id="proofViewerError" class="text-center p-4" style="display: none; max-width: 400px;">
          <i class="fas fa-exclamation-triangle text-warning fa-3x mb-3"></i>
          <h6 class="text-white fw-bold">Image Not Available</h6>
          <p class="text-white-50 small mb-3">The proof image could not be loaded or the file is missing from the server.</p>
          <span id="proofViewerErrorFile" class="badge bg-dark border text-light font-monospace small px-3 py-2 text-wrap" style="word-break: break-all;"></span>
        </div>
        <!-- Proof Image -->
        <img id="proofViewerImg" src="" alt="Proof Viewer" class="img-fluid rounded shadow-sm" style="display: none; max-width: 100%; max-height: 72vh; object-fit: contain; margin: 0 auto; transition: opacity 0.2s ease-in-out;">
      </div>
      <div class="modal-footer py-2 px-3 bg-light border-top d-flex justify-content-between align-items-center">
        <div class="small text-muted font-monospace" id="proofViewerFilename" style="font-size: 0.75rem; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></div>
        <div class="d-flex gap-2">
          <a id="proofViewerOpenTab" href="#" target="_blank" class="btn btn-sm btn-outline-secondary" style="border-radius: 6px;">
            <i class="fas fa-external-link-alt me-1"></i> Open in New Tab
          </a>
          <a id="proofViewerDownloadBtn" href="#" download class="btn btn-sm btn-outline-primary" style="border-radius: 6px;">
            <i class="fas fa-download me-1"></i> Download
          </a>
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal" style="border-radius: 6px;">
            Close
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

  <script>
    var currentActiveRequestId = 0;

    function openViewModal(id) {
      currentActiveRequestId = id;
      var modalEl = document.getElementById('viewRequestModal');
      var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

      // Loading state
      document.getElementById('m_req_id_badge').textContent = '#' + id;
      document.getElementById('m_status_badge').innerHTML = '<span class="spinner-border spinner-border-sm"></span> Loading...';
      document.getElementById('m_full_name').textContent = 'Loading...';
      document.getElementById('m_email').textContent = 'Loading...';
      document.getElementById('m_contact').textContent = 'Loading...';
      document.getElementById('m_address').textContent = 'Loading...';
      document.getElementById('m_plot_disp').textContent = 'Loading...';
      document.getElementById('m_action_buttons').innerHTML = '';
      document.getElementById('m_token_section').style.display = 'none';

      modal.show();

      var formData = new FormData();
      formData.append('action', 'get_details');
      formData.append('request_id', id);

      fetch('guest_reservations.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (!res.success) {
          throw new Error(res.message || 'Could not fetch request details.');
        }
        var d = res.request;
        document.getElementById('m_req_id_badge').textContent = '#' + d.id;
        document.getElementById('m_full_name').textContent = d.full_name;
        document.getElementById('m_email').textContent = d.email;
        document.getElementById('m_contact').textContent = d.contact_number;
        document.getElementById('m_address').textContent = d.address || '—';
        document.getElementById('m_plot_disp').textContent = 'Section ' + (d.section || '').toUpperCase() + ' · Plot ' + d.plot_code;
        document.getElementById('m_purpose').textContent = d.purpose || 'Future Memorial Lot';
        document.getElementById('m_guest_notes').textContent = d.notes ? d.notes : 'No special requests provided.';
        document.getElementById('m_admin_notes').value = d.admin_notes || '';
        var createdStr = d.created_at || '';
        document.getElementById('m_dates').textContent = createdStr ? (createdStr + ' (PST)') : '—';
        document.getElementById('m_preferred_date').textContent = d.preferred_date ? d.preferred_date : (createdStr ? createdStr.split(' ')[0] : '—');

        // Deceased Information
        var isNoDeceased = (d.request_type === 'no_deceased') || (!d.deceased_full_name && !d.deceased_first_name && !d.deceased_last_name && !d.matched_deceased_id);
        var decName = d.deceased_full_name || [d.deceased_first_name, d.deceased_middle_name, d.deceased_last_name, d.deceased_suffix].filter(Boolean).join(' ');

        if (isNoDeceased) {
          document.getElementById('m_dec_name').innerHTML = '<span class="text-secondary fw-bold">NOT ASSIGNED</span> <span class="badge bg-secondary ms-1">Pre-need / Future Use</span>';
          document.getElementById('m_dec_rel').textContent = 'Not Applicable (No Deceased)';
          document.getElementById('m_dec_dates').textContent = 'Not Applicable';
          document.getElementById('m_dec_funeral').textContent = 'Not Applicable';
        } else {
          document.getElementById('m_dec_name').textContent = decName || 'Pre-need reservation';
          document.getElementById('m_dec_rel').textContent = d.deceased_relationship || '—';
          var bdayStr = d.deceased_bday || '—';
          var diedStr = d.deceased_date_died || '—';
          document.getElementById('m_dec_dates').textContent = (bdayStr !== '—' || diedStr !== '—') ? (bdayStr + ' to ' + diedStr) : '—';
          document.getElementById('m_dec_funeral').textContent = d.funeral_home || '—';
        }

        // Matched Deceased Record
        var matchBadgeEl = document.getElementById('m_deceased_match_badge');
        var matchedCardEl = document.getElementById('m_matched_deceased_card');
        var matchedDetailsEl = document.getElementById('m_matched_deceased_details');
        if (d.matched_deceased_record) {
          matchBadgeEl.innerHTML = '<span class="badge" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a;"><i class="fas fa-check-double me-1"></i>Existing Record Match Found</span>';
          matchedCardEl.style.display = 'block';
          var m = d.matched_deceased_record;
          var mName = [m.first_name, m.middle_name, m.last_name, m.suffix].filter(Boolean).join(' ');
          matchedDetailsEl.innerHTML = '<strong>Record ID:</strong> #' + m.deceased_id + ' &nbsp;|&nbsp; <strong>Name:</strong> ' + escapeQuotes(mName) + '<br>' +
            '<strong>Location:</strong> Section ' + (m.section || '—') + ' Plot ' + (m.plot_x || '—') + '-' + (m.plot_y || '—') + ' &nbsp;|&nbsp; <strong>Born:</strong> ' + (m.bday || '—') + ' &nbsp;|&nbsp; <strong>Died:</strong> ' + (m.date_died || '—');
        } else {
          matchedCardEl.style.display = 'none';
          if (isNoDeceased) {
            matchBadgeEl.innerHTML = '<span class="badge" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;"><i class="fas fa-bookmark me-1"></i>No Deceased (Future Use)</span>';
          } else if (d.request_type === 'deceased_verification') {
            matchBadgeEl.innerHTML = '<span class="badge" style="background:#fef9c3; color:#854d0e; border:1px solid #fef08a;"><i class="fas fa-search me-1"></i>Verification Requested</span>';
          } else if (decName) {
            matchBadgeEl.innerHTML = '<span class="badge" style="background:#e0f2fe; color:#075985; border:1px solid #bae6fd;"><i class="fas fa-user-plus me-1"></i>New Deceased Submission</span>';
          } else {
            matchBadgeEl.innerHTML = '<span class="badge bg-light text-dark border">Pre-Need</span>';
          }
        }

        // Existing Account Card
        var existCardEl = document.getElementById('m_existing_account_card');
        var existDetailsEl = document.getElementById('m_existing_account_details');
        if (d.existing_account) {
          existCardEl.style.display = 'block';
          existDetailsEl.innerHTML = 'An account with email <strong>' + escapeQuotes(d.existing_account.email) + '</strong> already exists for <strong>' + escapeQuotes(d.existing_account.first_name + ' ' + d.existing_account.last_name) + '</strong> (User ID #' + d.existing_account.user_id + '). Approving this request will link directly to this existing user without creating a duplicate account.';
        } else {
          existCardEl.style.display = 'none';
        }

        // Payment Information
        var pInfo = d.payment_info;
        var pBadge = document.getElementById('m_payment_badge');
        var pAmtEl = document.getElementById('m_pay_amount');
        var pMethodEl = document.getElementById('m_pay_method');
        var pRefEl = document.getElementById('m_pay_ref');
        var pDateEl = document.getElementById('m_pay_date');
        var proofBox = document.getElementById('m_proof_box');
        var proofBtn = document.getElementById('m_proof_view_btn');
        var proofDisp = document.getElementById('m_proof_filename_disp');

        if (pInfo && (pInfo.amount_paid > 0 || pInfo.gcash_reference_number || (pInfo.payment_status && pInfo.payment_status.toUpperCase() === 'PAID'))) {
          pBadge.innerHTML = '<span class="badge" style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0;"><i class="fas fa-check-circle me-1"></i>Downpayment Paid</span>';
          pAmtEl.textContent = '₱' + Number(pInfo.amount_paid || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
          pMethodEl.textContent = pInfo.payment_method || 'GCash';
          pRefEl.textContent = pInfo.gcash_reference_number || pInfo.transaction_reference || '—';
          pDateEl.textContent = pInfo.payment_date || '—';
        } else {
          pBadge.innerHTML = '<span class="badge bg-light text-muted border"><i class="fas fa-hourglass-start me-1"></i>Unpaid / Manual</span>';
          pAmtEl.textContent = '₱0.00';
          pMethodEl.textContent = 'GCash';
          pRefEl.textContent = '—';
          pDateEl.textContent = '—';
        }

        var proofName = (pInfo && pInfo.proof_filename) ? pInfo.proof_filename : (d.payment_proof_filename || d.proof_filename || '');
        if (proofName) {
          proofBox.style.display = 'block';
          proofDisp.textContent = proofName;
          
          // Only show for image files. For PDFs, we still need a way to view, so maybe we fallback to opening in new tab or show a specific message.
          var isImg = /\.(jpe?g|png|webp|gif)$/i.test(proofName);
          var inlineWrap = document.getElementById('m_proof_inline_wrap');
          var inlineImg = document.getElementById('m_proof_inline_img');
          var proofBtn = document.getElementById('m_proof_view_btn');

          if (isImg) {
            var cleanProof = proofName.replace(/^\/+/, '');
            var imgSrc = cleanProof.startsWith('uploads/') ? cleanProof : ('uploads/payment-proofs/' + cleanProof);
            
            // Set image source and make things visible
            if (inlineImg) inlineImg.src = imgSrc;
            if (inlineWrap) inlineWrap.style.display = 'block';
            
            // Store the full image source in a global variable or dataset for the modal to use
            window.currentProofImageSrc = imgSrc;
            window.currentProofGuestName = d.full_name;
            window.currentProofReqId = d.id;
            
            if (proofBtn) {
              proofBtn.style.display = 'inline-block';
              proofBtn.removeAttribute('href');
              proofBtn.removeAttribute('target');
              proofBtn.onclick = function() {
                openProofModal(imgSrc, d.full_name, d.id);
              };
            }
            var inlineLink = document.getElementById('m_proof_inline_link');
            if (inlineLink) {
              inlineLink.onclick = function() {
                openProofModal(imgSrc, d.full_name, d.id);
              };
            }
          } else {
            // It's not an image (e.g. PDF), hide inline image and revert button to standard link
            if (inlineWrap) inlineWrap.style.display = 'none';
            if (proofBtn) {
              proofBtn.style.display = 'inline-block';
              proofBtn.href = 'view_payment_proof.php?booking_id=' + (d.booking_id || 0) + '&file=' + encodeURIComponent(proofName);
              proofBtn.target = '_blank';
              proofBtn.onclick = null;
            }
          }
        } else {
          proofBox.style.display = 'none';
          var inlineWrapHide = document.getElementById('m_proof_inline_wrap');
          if (inlineWrapHide) inlineWrapHide.style.display = 'none';
        }

        // Status badge
        var stNorm = (d.status || '').toLowerCase();
        var stHtml = '';
        if (stNorm === 'pending') {
          stHtml = '<span class="badge" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a;"><i class="fas fa-clock me-1"></i> Pending Review</span>';
        } else if (stNorm === 'approved') {
          stHtml = '<span class="badge" style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0;"><i class="fas fa-check-circle me-1"></i> Approved</span>';
        } else if (stNorm === 'account created') {
          stHtml = '<span class="badge" style="background:#e0f2fe; color:#075985; border:1px solid #bae6fd;"><i class="fas fa-user-check me-1"></i> Account Created</span>';
        } else if (stNorm === 'rejected') {
          stHtml = '<span class="badge" style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca;"><i class="fas fa-times-circle me-1"></i> Rejected</span>';
        } else if (stNorm === 'cancelled') {
          stHtml = '<span class="badge" style="background:#f3f4f6; color:#4b5563; border:1px solid #e5e7eb; font-weight:600;"><i class="fas fa-ban me-1"></i> Cancelled</span>';
          if (d.cancelled_by) {
            stHtml += '<div class="mt-1 small text-muted" style="font-size:0.75rem;"><strong>Cancelled By:</strong> ' + escapeQuotes(d.cancelled_by) + '</div>';
          }
          if (d.cancellation_reason) {
            stHtml += '<div class="text-muted small mt-1"><strong>Reason:</strong> ' + escapeQuotes(d.cancellation_reason) + '</div>';
          }
        } else {
          stHtml = '<span class="badge bg-secondary">' + d.status + '</span>';
        }

        if (d.cancellation_requested == 1 && stNorm !== 'cancelled') {
          stHtml += '<div class="mt-2 p-2 rounded border bg-warning-subtle text-dark small"><i class="fas fa-exclamation-triangle text-warning me-1"></i> <strong>Cancellation Requested:</strong> ' + escapeQuotes(d.cancellation_reason || 'No reason specified') + '</div>';
        }
        document.getElementById('m_status_badge').innerHTML = stHtml;

        // Live Plot Badge
        if (d.live_plot_available) {
          document.getElementById('m_live_plot_badge').innerHTML = '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Plot Available for Reserve</span>';
        } else {
          document.getElementById('m_live_plot_badge').innerHTML = '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i> ' + (d.live_plot_status_label || 'Unavailable') + '</span>';
        }

        // Action buttons
        var actHtml = '';
        if (stNorm === 'pending') {
          actHtml += '<button type="button" class="btn btn-outline-danger" onclick="openRejectModal(' + d.id + ', \'' + escapeQuotes(d.full_name) + '\')"><i class="fas fa-times me-1"></i> Reject Request</button>';
          actHtml += '<button type="button" class="btn btn-success px-4" onclick="confirmApprove(' + d.id + ')"><i class="fas fa-check me-1"></i> Approve & Send Account Link</button>';
        } else if (stNorm === 'approved') {
          actHtml += '<button type="button" class="btn btn-outline-primary" onclick="resendApprovalEmail()"><i class="fas fa-paper-plane me-1"></i> Resend Email</button>';
        }

        if (stNorm !== 'cancelled' && stNorm !== 'rejected') {
          var cancelBtnText = (d.cancellation_requested == 1) ? 'Review / Accept Cancellation' : 'Cancel Reservation';
          actHtml += '<button type="button" class="btn btn-outline-secondary" onclick="openCancelModal(' + d.id + ', \'' + escapeQuotes(d.full_name) + '\', \'' + escapeQuotes(d.cancellation_reason || '') + '\', ' + (d.cancellation_requested || 0) + ')"><i class="fas fa-ban text-danger me-1"></i> ' + cancelBtnText + '</button>';
        }
        document.getElementById('m_action_buttons').innerHTML = actHtml;

        // Token section
        if (stNorm === 'approved' || stNorm === 'account created') {
          document.getElementById('m_token_section').style.display = 'block';
          var expText = d.token_expires_at ? ('Token expires: ' + d.token_expires_at) : 'Token generated';
          if (d.email_sent == 1) {
            expText += ' · <span class="text-success"><i class="fas fa-check"></i> Email dispatched</span>';
          } else if (d.email_error) {
            expText += ' · <span class="text-danger"><i class="fas fa-times"></i> Email failed (' + escapeQuotes(d.email_error) + ')</span>';
          }
          document.getElementById('m_token_expires_info').innerHTML = expText;

          if (d.registration_url) {
            document.getElementById('m_reg_link_group').style.display = 'flex';
            document.getElementById('m_reg_url_input').value = d.registration_url;
            document.getElementById('m_reg_open_link').href = d.registration_url;
          } else {
            document.getElementById('m_reg_link_group').style.display = 'none';
          }
        } else {
          document.getElementById('m_token_section').style.display = 'none';
        }

      })
      .catch(function(err) {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: err.message || 'Could not load details.',
          confirmButtonColor: '#7b1113'
        });
      });
    }

    function copyRegLink() {
      var inp = document.getElementById('m_reg_url_input');
      if (!inp || !inp.value) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(inp.value).then(function() {
          Swal.fire({
            icon: 'success',
            title: 'Link Copied!',
            text: 'Complete profile link copied to clipboard.',
            timer: 1800,
            showConfirmButton: false
          });
        }).catch(function() {
          inp.select();
          document.execCommand('copy');
          Swal.fire({
            icon: 'success',
            title: 'Link Copied!',
            timer: 1500,
            showConfirmButton: false
          });
        });
      } else {
        inp.select();
        document.execCommand('copy');
        Swal.fire({
          icon: 'success',
          title: 'Link Copied!',
          timer: 1500,
          showConfirmButton: false
        });
      }
    }

    function escapeQuotes(str) {
      if (!str) return '';
      return String(str).replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }

    function quickApprove(id) {
      currentActiveRequestId = id;
      confirmApprove(id);
    }

    function confirmApprove(id, forceLink) {
      var isForce = (forceLink === 1 || forceLink === true);
      var confirmMsg = isForce 
        ? 'Please confirm that you want to approve this reservation and link it to the existing user account.'
        : 'This will approve the reservation, reserve the plot, and send a Complete Profile Link (or link to an existing account if verified).';

      Swal.fire({
        title: isForce ? 'Confirm Account Link & Approve?' : 'Approve Reservation Request?',
        text: confirmMsg,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        cancelButtonColor: '#64748b',
        confirmButtonText: isForce ? 'Yes, Link & Approve' : 'Yes, Approve Request'
      }).then(function(result) {
        if (result.isConfirmed) {
          Swal.fire({
            title: 'Processing Approval...',
            text: 'Verifying plot and processing reservation...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
          });

          var formData = new FormData();
          formData.append('action', 'approve_request');
          formData.append('request_id', id);
          if (isForce) {
            formData.append('confirm_link_existing_user', '1');
          }

          fetch('guest_reservations.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
          })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (res.success) {
              Swal.fire({
                icon: 'success',
                title: 'Reservation Approved!',
                text: res.message,
                confirmButtonColor: '#7b1113'
              }).then(function() {
                window.location.reload();
              });
            } else if (res.requires_account_link_confirmation) {
              Swal.fire({
                icon: 'info',
                title: 'Existing Account Found',
                text: res.message,
                showCancelButton: true,
                confirmButtonText: 'Yes, Link & Approve',
                confirmButtonColor: '#7b1113',
                cancelButtonText: 'Cancel'
              }).then(function(cRes) {
                if (cRes.isConfirmed) {
                  confirmApprove(id, 1);
                }
              });
            } else {
              Swal.fire({
                icon: 'error',
                title: 'Approval Failed',
                text: res.message || 'Plot is unavailable or request cannot be approved.',
                confirmButtonColor: '#7b1113'
              });
            }
          })
          .catch(function(err) {
            Swal.fire({
              icon: 'error',
              title: 'Error',
              text: 'A server or network error occurred.',
              confirmButtonColor: '#7b1113'
            });
          });
        }
      });
    }

    var pendingRejectId = 0;
    function openRejectModal(id, name) {
      pendingRejectId = id;
      document.getElementById('rej_guest_name').textContent = name || ('Request #' + id);
      document.getElementById('rej_reason').value = '';
      var detailsModalEl = document.getElementById('detailsModal');
      if (detailsModalEl) {
        var dm = bootstrap.Modal.getInstance(detailsModalEl);
        if (dm) dm.hide();
      }
      var m = bootstrap.Modal.getOrCreateInstance(document.getElementById('rejectModal'));
      m.show();
    }

    function confirmReject() {
      var reason = document.getElementById('rej_reason').value.trim();
      if (!reason) {
        Swal.fire({
          icon: 'warning',
          title: 'Rejection Reason Required',
          text: 'Please specify a rejection reason.',
          confirmButtonColor: '#7b1113'
        });
        return;
      }

      var btn = document.getElementById('confirmRejectBtn');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Rejecting...';

      var formData = new FormData();
      formData.append('action', 'reject_request');
      formData.append('request_id', pendingRejectId);
      formData.append('rejection_reason', reason);

      fetch('guest_reservations.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          bootstrap.Modal.getInstance(document.getElementById('rejectModal')).hide();
          Swal.fire({
            icon: 'info',
            title: 'Request Rejected',
            text: res.message,
            confirmButtonColor: '#7b1113'
          }).then(function() {
            window.location.reload();
          });
        } else {
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: res.message || 'Failed to reject request.',
            confirmButtonColor: '#7b1113'
          });
        }
      })
      .catch(function(err) {
        Swal.fire({
          icon: 'error',
          title: 'Network Error',
          text: 'A network or server error occurred.',
          confirmButtonColor: '#7b1113'
        });
      })
      .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-ban me-1"></i> Confirm Rejection';
      });
    }

    var pendingCancelId = 0;
    function openCancelModal(id, name, guestReason, isRequested) {
      pendingCancelId = id;
      document.getElementById('cancel_guest_name').textContent = name || ('Request #' + id);
      var detailsModalEl = document.getElementById('detailsModal');
      if (detailsModalEl) {
        var dm = bootstrap.Modal.getInstance(detailsModalEl);
        if (dm) dm.hide();
      }
      var reasonBox = document.getElementById('cancel_guest_reason_box');
      var reasonDisp = document.getElementById('cancel_guest_reason_disp');
      var reasonInput = document.getElementById('cancel_reason_input');
      
      if (guestReason && guestReason.trim() !== '') {
        reasonBox.style.display = 'block';
        reasonDisp.textContent = '"' + guestReason + '"';
        reasonInput.value = guestReason;
      } else {
        reasonBox.style.display = 'none';
        reasonDisp.textContent = '';
        reasonInput.value = isRequested ? 'Guest requested cancellation' : '';
      }

      var btnLabel = document.getElementById('confirmCancelBtn');
      if (btnLabel) {
        btnLabel.innerHTML = isRequested 
          ? '<i class="fas fa-check-circle me-1"></i> Accept Cancellation (No Refund)'
          : '<i class="fas fa-ban me-1"></i> Confirm Cancellation (No Refund)';
      }

      var mEl = document.getElementById('cancelModal');
      var m = bootstrap.Modal.getOrCreateInstance(mEl);
      m.show();
    }

    function confirmCancelAction() {
      if (!pendingCancelId) return;
      var reason = document.getElementById('cancel_reason_input').value.trim();
      if (!reason) {
        Swal.fire({
          icon: 'warning',
          title: 'Cancellation Reason Required',
          text: 'Please provide a reason for cancellation.',
          confirmButtonColor: '#7b1113'
        });
        return;
      }

      Swal.fire({
        title: 'Confirm Cancellation?',
        html: '<div class="text-start small"><p>Are you sure you want to cancel this reserve and release the plot back to available?</p><p class="text-danger fw-bold mb-0"><i class="fas fa-exclamation-triangle me-1"></i> Strictly NO REFUND will be issued.</p></div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Cancel Reservation',
        cancelButtonText: 'No, Keep It',
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d'
      }).then(function(result) {
        if (result.isConfirmed) {
          var btn = document.getElementById('confirmCancelBtn');
          if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';
          }

          var fd = new FormData();
          fd.append('action', 'cancel_request');
          fd.append('request_id', pendingCancelId);
          fd.append('cancellation_reason', reason);

          fetch('guest_reservations.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
          })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-ban me-1"></i> Confirm Cancellation (No Refund)'; }
            var mEl = document.getElementById('cancelModal');
            var m = bootstrap.Modal.getInstance(mEl);
            if (m) m.hide();

            if (res.success) {
              Swal.fire({
                icon: 'success',
                title: 'Reservation Cancelled',
                text: res.message || 'Reservation cancelled and plot released.',
                confirmButtonColor: '#7b1113'
              }).then(function() {
                window.location.reload();
              });
            } else {
              Swal.fire({
                icon: 'error',
                title: 'Error',
                text: res.message || 'Could not cancel reservation.',
                confirmButtonColor: '#7b1113'
              });
            }
          })
          .catch(function(err) {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-ban me-1"></i> Confirm Cancellation (No Refund)'; }
            Swal.fire({
              icon: 'error',
              title: 'Error',
              text: 'Server or network error occurred while cancelling.',
              confirmButtonColor: '#7b1113'
            });
          });
        }
      });
    }

    function resendApprovalEmail() {
      if (currentActiveRequestId <= 0) return;

      Swal.fire({
        title: 'Resend Complete Profile Email?',
        text: 'A fresh email with the secure account setup link will be dispatched to the guest.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#7b1113',
        confirmButtonText: 'Yes, Resend Email'
      }).then(function(result) {
        if (result.isConfirmed) {
          Swal.fire({
            title: 'Sending Email...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
          });

          var formData = new FormData();
          formData.append('action', 'resend_email');
          formData.append('request_id', currentActiveRequestId);

          fetch('guest_reservations.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
          })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (res.success) {
              Swal.fire({
                icon: 'success',
                title: 'Email Sent!',
                text: res.message,
                confirmButtonColor: '#7b1113'
              });
            } else {
              Swal.fire({
                icon: 'error',
                title: 'Delivery Failed',
                text: res.message,
                confirmButtonColor: '#7b1113'
              });
            }
          })
          .catch(function(err) {
            Swal.fire({
              icon: 'error',
              title: 'Error',
              text: 'Failed to resend email.',
              confirmButtonColor: '#7b1113'
            });
          });
        }
      });
    }

    function saveAdminNote() {
      if (currentActiveRequestId <= 0) return;
      var note = document.getElementById('m_admin_notes').value;

      var formData = new FormData();
      formData.append('action', 'save_note');
      formData.append('request_id', currentActiveRequestId);
      formData.append('admin_notes', note);

      fetch('guest_reservations.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          Swal.fire({
            icon: 'success',
            title: 'Saved',
            text: 'Admin note updated.',
            timer: 1500,
            showConfirmButton: false
          });
        } else {
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: res.message || 'Failed to update note.',
            confirmButtonColor: '#7b1113'
          });
        }
      });
    }

    document.addEventListener('DOMContentLoaded', function() {
      var urlParams = new URLSearchParams(window.location.search);
      var reqId = urlParams.get('request_id') || urlParams.get('id') || urlParams.get('view');
      if (reqId) {
        var num = parseInt(reqId, 10);
        if (!isNaN(num) && num > 0) {
          openViewModal(num);
        }
      }
    });

    function deleteGuestRequest(requestId, guestName) {
      Swal.fire({
        title: 'Delete Request?',
        html: 'Are you sure you want to permanently delete the request from <strong>' + guestName + '</strong>?<br><small class="text-muted">This will also release the reserved plot and remove associated book records.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: '<i class="fas fa-trash-alt me-1"></i> Yes, Delete',
        cancelButtonText: 'Cancel'
      }).then(function(result) {
        if (!result.isConfirmed) return;

        Swal.fire({
          title: 'Deleting...',
          allowOutsideClick: false,
          didOpen: function() { Swal.showLoading(); }
        });

        var formData = new FormData();
        formData.append('action', 'delete_request');
        formData.append('request_id', requestId);

        fetch('guest_reservations.php', {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.success) {
            Swal.fire({
              icon: 'success',
              title: 'Deleted!',
              text: res.message || 'The request has been deleted and the plot has been released.',
              timer: 2000,
              showConfirmButton: false
            }).then(function() {
              window.location.reload();
            });
          } else {
            Swal.fire({
              icon: 'error',
              title: 'Error',
              text: res.message || 'Could not delete the request.',
              confirmButtonColor: '#7b1113'
            });
          }
        })
        .catch(function() {
          Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: 'A network error occurred. Please try again.',
            confirmButtonColor: '#7b1113'
          });
        });
      });
    }

    function openProofModal(imgSrc, guestName, reqId) {
      var srcToUse = imgSrc || window.currentProofImageSrc;
      if (!srcToUse) return;

      var nameToUse = guestName || window.currentProofGuestName || '';
      var idToUse = reqId || window.currentProofReqId || '';

      // Ensure clean, correct relative path
      var cleanSrc = srcToUse.replace(/^\/+/, '');
      if (!cleanSrc.startsWith('http') && !cleanSrc.startsWith('uploads/')) {
        cleanSrc = 'uploads/payment-proofs/' + cleanSrc;
      }

      var modalEl = document.getElementById('proofViewerModal');
      var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

      var imgEl = document.getElementById('proofViewerImg');
      var loaderEl = document.getElementById('proofViewerLoader');
      var errorEl = document.getElementById('proofViewerError');
      var subtitleEl = document.getElementById('proofViewerSubtitle');
      var filenameEl = document.getElementById('proofViewerFilename');
      var openTabBtn = document.getElementById('proofViewerOpenTab');
      var downloadBtn = document.getElementById('proofViewerDownloadBtn');
      var errFileEl = document.getElementById('proofViewerErrorFile');

      // Set title and subtitle
      if (subtitleEl) {
        var subText = '';
        if (nameToUse) subText += 'Guest: ' + nameToUse;
        if (idToUse) subText += (subText ? ' • ' : '') + 'Request #' + idToUse;
        subtitleEl.textContent = subText || 'Viewing uploaded transaction receipt';
      }

      var fileNameOnly = cleanSrc.substring(cleanSrc.lastIndexOf('/') + 1);
      if (filenameEl) filenameEl.textContent = fileNameOnly;

      var fallbackUrl = 'view_payment_proof.php?file=' + encodeURIComponent(fileNameOnly) + '&raw=1';
      var downloadFallbackUrl = 'view_payment_proof.php?file=' + encodeURIComponent(fileNameOnly) + '&download=1';

      if (openTabBtn) openTabBtn.href = fallbackUrl;
      if (downloadBtn) {
        downloadBtn.href = downloadFallbackUrl;
        downloadBtn.download = fileNameOnly;
      }

      // Reset states
      if (loaderEl) loaderEl.style.display = 'block';
      if (errorEl) errorEl.style.display = 'none';
      if (imgEl) {
        imgEl.style.display = 'none';
        var hasTriedFallback = false;
        imgEl.onload = function() {
          if (loaderEl) loaderEl.style.display = 'none';
          if (errorEl) errorEl.style.display = 'none';
          imgEl.style.display = 'block';
        };
        imgEl.onerror = function() {
          if (!hasTriedFallback) {
            hasTriedFallback = true;
            imgEl.src = fallbackUrl;
            return;
          }
          if (loaderEl) loaderEl.style.display = 'none';
          if (imgEl) imgEl.style.display = 'none';
          if (errorEl) errorEl.style.display = 'block';
          if (errFileEl) errFileEl.textContent = fileNameOnly;
        };
        imgEl.src = cleanSrc;
      }

      modal.show();
    }

  </script>
</body>
</html>
