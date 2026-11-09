<?php

if (function_exists('ob_start')) {
    @ob_start();
}
ini_set('display_errors', '0');
error_reporting(0);

function json_out($arr) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

session_start();

if (empty($_SESSION['user_id'])) {
    json_out(['success' => false, 'message' => 'Please log in to submit a booking.']);
}
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_role = strtolower(trim($_SESSION['role'] ?? ($_SESSION['user_role'] ?? '')));
$isAdmin = in_array($user_role, ['admin', 'administrator'], true);
session_write_close();

include_once __DIR__ . '/connection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Invalid request method.']);
}

// User-initiated booking cancellation (strict NO REFUND policy).
if (isset($_POST['action']) && $_POST['action'] === 'cancel_booking') {
    if (file_exists(__DIR__ . '/config/cancellation_helper.php')) {
        require_once __DIR__ . '/config/cancellation_helper.php';
    }
    $cancel_id = (int)($_POST['booking_id'] ?? 0);
    if ($cancel_id <= 0) {
        json_out(['success' => false, 'message' => 'Booking ID is required.']);
    }
    if (!function_exists('cancel_booking_by_user')) {
        json_out(['success' => false, 'message' => 'Cancellation service is unavailable right now.']);
    }
    json_out(cancel_booking_by_user($conn, $cancel_id, $user_id, $isAdmin));
}

try {

$section           = strtoupper(trim($_POST['section'] ?? ''));
$row               = (int)($_POST['row'] ?? 0);
$plot              = trim($_POST['plot'] ?? '');
$plot_x            = (int)($_POST['plot_x'] ?? 0);
$plot_y            = (int)($_POST['plot_y'] ?? 0);
$service_type      = trim($_POST['service_type'] ?? '');
$preferred_date    = trim($_POST['preferred_date'] ?? '');
$contact_number    = trim($_POST['contact_number'] ?? '');
$booker_location   = trim($_POST['booker_location'] ?? '');
$special_requests  = trim($_POST['special_requests'] ?? '');
$full_name         = trim($_POST['full_name'] ?? '');
$client_suffix     = strtoupper(trim($_POST['client_suffix'] ?? ($_POST['customer_suffix'] ?? '')));
if ($client_suffix === 'OTHER') {
    $client_suffix = strtoupper(trim($_POST['client_suffix_other'] ?? ($_POST['customer_suffix_other'] ?? '')));
}
$client_suffix = strip_tags($client_suffix);

if (!empty($client_suffix) && !empty($full_name)) {
    if (!preg_match('/\b' . preg_quote($client_suffix, '/') . '$/i', $full_name)) {
        $full_name .= ' ' . $client_suffix;
    }
}
$email             = trim($_POST['email'] ?? '');
$user_id           = (int)($_SESSION['user_id'] ?? 0);
$purpose           = strtolower(trim($_POST['purpose'] ?? 'reserve_grave'));
$payment_method    = strtolower(trim($_POST['payment_method'] ?? 'cash'));
$deceased_id       = (int)($_POST['deceased_id'] ?? 0);
$booking_type      = strtolower(trim($_POST['booking_type'] ?? ''));
if ($purpose === 'reserve_grave') {
    $booking_type = 'another_plot';
    $deceased_id = 0;
} elseif (!in_array($booking_type, ['existing_plot_service', 'another_plot', 'other_service'], true)) {
    $booking_type = 'existing_plot_service';
}
if (!in_array($purpose, ['reserve_grave', 'add_deceased', 'cemetery_service'], true)) {
    $purpose = 'reserve_grave';
}
if ($payment_method === 'gcash') {
    $payment_method = 'GCash';
} elseif (in_array($payment_method, ['cash', 'cash on site', 'cash on hand'], true)) {
    if (!$isAdmin) {
        json_out(['success' => false, 'message' => 'Cash payment is only accepted in person at the cemetery office. Please select an online payment method.']);
    }
    $payment_method = 'Cash on Site';
} else {
    json_out(['success' => false, 'message' => 'Please select a valid payment method.']);
}

$dec_first_name     = strtoupper(trim($_POST['dec_first_name'] ?? ''));
$dec_middle_name    = strtoupper(trim($_POST['dec_middle_name'] ?? ''));
$dec_last_name      = strtoupper(trim($_POST['dec_last_name'] ?? ''));
$dec_suffix         = strtoupper(trim($_POST['dec_suffix'] ?? ($_POST['deceased_suffix'] ?? '')));
if ($dec_suffix === 'OTHER') {
    $dec_suffix = strtoupper(trim($_POST['dec_suffix_other'] ?? ($_POST['deceased_suffix_other'] ?? '')));
}
$dec_suffix         = strip_tags($dec_suffix);
$dec_full_name      = strtoupper(trim($_POST['dec_full_name'] ?? ''));

if (empty($dec_first_name) && !empty($dec_full_name)) {
    $parts = preg_split('/\s+/', $dec_full_name);
    $dec_first_name = $parts[0] ?? '';
    $dec_last_name = count($parts) > 1 ? end($parts) : '';
    $dec_middle_name = count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : '';
}
if (!empty($dec_suffix) && !empty($dec_full_name)) {
    if (!preg_match('/\b' . preg_quote($dec_suffix, '/') . '$/i', $dec_full_name)) {
        $dec_full_name .= ' ' . $dec_suffix;
    }
}
if (empty($dec_full_name)) {
    $dec_full_name = trim(implode(' ', array_filter([$dec_first_name, $dec_middle_name, $dec_last_name, $dec_suffix])));
}

$dec_bday           = trim($_POST['dec_bday'] ?? '');
$dec_date_died      = trim($_POST['dec_date_died'] ?? '');
$dec_interment_date = trim($_POST['dec_interment_date'] ?? ($_POST['preferred_date'] ?? ''));
$dec_relationship   = strtoupper(trim($_POST['dec_relationship'] ?? ($_POST['relationship'] ?? '')));
$dec_notes          = trim($_POST['dec_notes'] ?? ($_POST['special_requests'] ?? ''));

$today = date('Y-m-d');
if (!empty($dec_bday)) {
    if ($dec_bday > $today) {
        json_out(['success' => false, 'message' => 'Deceased date of birth cannot be in the future.']);
    }
}
if (!empty($dec_date_died)) {
    if ($dec_date_died > $today) {
        json_out(['success' => false, 'message' => 'Deceased date of death cannot be in the future.']);
    }
    if (!empty($dec_bday) && $dec_date_died < $dec_bday) {
        json_out(['success' => false, 'message' => 'Date of death cannot be earlier than date of birth.']);
    }
}

if ($purpose === 'add_deceased') {
    if (empty($dec_full_name)) {
        json_out(['success' => false, 'message' => 'Please provide the full name of the new deceased person.']);
    }
    if (empty($dec_interment_date)) {
        json_out(['success' => false, 'message' => 'Please select the date of interment for the new deceased.']);
    }
    $preferred_date = $dec_interment_date;
    if (!empty($dec_notes) && empty($special_requests)) {
        $special_requests = $dec_notes;
    }
}

$targetPlotX = (int)($_POST['plot_x'] ?? 0);
$targetPlotY = (int)($_POST['plot_y'] ?? 0);
if ($targetPlotX <= 0 || $targetPlotY <= 0) {
    $plotRaw = trim($plot);
    if (preg_match('/^[A-Z0-9]+[-_ ]?(\d+)[-_ ](\d+)$/i', $plotRaw, $m)) {
        $targetPlotX = (int)$m[1];
        $targetPlotY = (int)$m[2];
    } elseif (preg_match('/(\d+)[-_ ]+(\d+)/', $plotRaw, $m)) {
        $targetPlotX = (int)$m[1];
        $targetPlotY = (int)$m[2];
    }
}
if ($targetPlotX <= 0 || $targetPlotY <= 0) {
    $locRaw = trim($_POST['grave_location'] ?? '');
    if (preg_match('/(\d+)[-_ ]+(\d+)/', $locRaw, $m)) {
        $targetPlotX = (int)$m[1];
        $targetPlotY = (int)$m[2];
    }
}
$targetRow = $row;

$selected_plots_raw = trim((string)($_POST['selected_plots_json'] ?? ($_POST['selected_plots'] ?? '')));
$selected_plots = [];
if (!empty($selected_plots_raw)) {
    $decoded = json_decode($selected_plots_raw, true);
    if (is_array($decoded) && !empty($decoded)) {
        $selected_plots = $decoded;
    }
}
$num_lots = isset($_POST['num_lots']) ? (int)$_POST['num_lots'] : (isset($_POST['numLots']) ? (int)$_POST['numLots'] : (count($selected_plots) > 0 ? count($selected_plots) : 1));

// Multi-plot reservation rule: allow at least 2 plots, or up to the number of linked accounts/deceased records
$userLinkedCount = 0;
if ($user_id > 0 && function_exists('get_user_linked_deceased_records')) {
    $userLinkedCount = count(get_user_linked_deceased_records($conn, $user_id));
}
$maxAllowedPlots = 2; // Strict limit: Maximum 2 plots for new grave reservation
$actualPlotsCount = !empty($selected_plots) ? count($selected_plots) : (int)$num_lots;

if ($purpose === 'reserve_grave' && $actualPlotsCount > $maxAllowedPlots) {
    json_out(['success' => false, 'message' => "You can reserve up to a maximum of 2 plots only for a new grave reservation."]);
}

// Requirement A.5: Prevent adding the same plot twice in the same booking
if (!empty($selected_plots)) {
    $seen_plots = [];
    foreach ($selected_plots as $sp) {
        $sp_sec = strtoupper(trim((string)($sp['section'] ?? '')));
        $sp_x = (int)($sp['plot_x'] ?? ($sp['plotX'] ?? 0));
        $sp_y = (int)($sp['plot_y'] ?? ($sp['plotY'] ?? 0));
        $sp_id = (int)($sp['plot_id'] ?? ($sp['plotId'] ?? 0));
        $key = ($sp_id > 0) ? "id_{$sp_id}" : "{$sp_sec}_{$sp_x}_{$sp_y}";
        if (isset($seen_plots[$key])) {
            json_out(['success' => false, 'message' => 'Cannot add the same plot twice in the same booking.']);
        }
        $seen_plots[$key] = true;
    }
    if ($purpose === 'reserve_grave') {
        $targetPlotX = (int)($selected_plots[0]['plot_x'] ?? ($selected_plots[0]['plotX'] ?? 0));
        $targetPlotY = (int)($selected_plots[0]['plot_y'] ?? ($selected_plots[0]['plotY'] ?? 0));
        if (!empty($selected_plots[0]['section'])) {
            $section = strtoupper(trim((string)$selected_plots[0]['section']));
            $secUp = $section;
        }
        $deceased_id = 0;
    } elseif ($targetPlotX <= 0 || $targetPlotY <= 0) {
        $targetPlotX = (int)($selected_plots[0]['plot_x'] ?? ($selected_plots[0]['plotX'] ?? 0));
        $targetPlotY = (int)($selected_plots[0]['plot_y'] ?? ($selected_plots[0]['plotY'] ?? 0));
        if (empty($section) && !empty($selected_plots[0]['section'])) {
            $section = strtoupper(trim((string)$selected_plots[0]['section']));
        }
    }
}

// Requirement B.5: Cemetery service must strictly validate submitted plot(s) match user's registered plot(s)
if ($purpose === 'cemetery_service') {
    if (!empty($selected_plots)) {
        foreach ($selected_plots as $sp) {
            $sp_sec = strtoupper(trim((string)($sp['section'] ?? $section)));
            $sp_x = (int)($sp['plot_x'] ?? ($sp['plotX'] ?? 0));
            $sp_y = (int)($sp['plot_y'] ?? ($sp['plotY'] ?? 0));
            if ($sp_x > 0 && $sp_y > 0) {
                $plotAuth = is_user_authorized_for_plot($conn, $user_id, $sp_sec, $sp_x, $sp_y);
                if (!$plotAuth) {
                    json_out(['success' => false, 'message' => "Plot {$sp_sec} {$sp_x}-{$sp_y} is not registered or linked to your account."]);
                }
            }
        }
    } else {
        $plotAuthorized = ($targetPlotX > 0 && $targetPlotY > 0) ? is_user_authorized_for_plot($conn, $user_id, $section, $targetPlotX, $targetPlotY) : false;
        if (!$plotAuthorized) {
            json_out(['success' => false, 'message' => 'Cemetery services can only be booked for your registered plot.']);
        }
    }
}

$isNewPlotReservation = ($purpose === 'reserve_grave');
$requiresLinkedDeceased = ($purpose === 'cemetery_service');
if ($deceased_id <= 0 && $requiresLinkedDeceased) {
    if ($targetPlotX > 0 && $targetPlotY > 0) {
        $chkDec = $conn->prepare("SELECT deceased_id FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? ORDER BY deceased_id DESC LIMIT 1");
        if ($chkDec) {
            $sUpper = strtoupper(trim($section));
            $chkDec->bind_param('sii', $sUpper, $targetPlotX, $targetPlotY);
            $chkDec->execute();
            if ($dr = $chkDec->get_result()->fetch_assoc()) {
                $deceased_id = (int)$dr['deceased_id'];
            }
            $chkDec->close();
        }
    }
}
if ($deceased_id <= 0 && $requiresLinkedDeceased) {
    $isPlotAuth = ($targetPlotX > 0 && $targetPlotY > 0) ? is_user_authorized_for_plot($conn, $user_id, $section, $targetPlotX, $targetPlotY) : false;
    if (!$isPlotAuth) {
        json_out(['success' => false, 'message' => 'Cemetery services can only be booked for your registered plot.']);
    }
}

if ($deceased_id > 0) {
    if (!is_user_authorized_for_deceased($conn, $user_id, $deceased_id)) {
        $dPlotCheck = $conn->query("SELECT section, plot_x, plot_y FROM tbl_deceased WHERE deceased_id = $deceased_id LIMIT 1");
        $dPlot = $dPlotCheck ? $dPlotCheck->fetch_assoc() : null;
        $isPlotOwner = ($dPlot && is_user_authorized_for_plot($conn, $user_id, $dPlot['section'], (int)$dPlot['plot_x'], (int)$dPlot['plot_y']));
        if (!$isPlotOwner) {
            json_out(['success' => false, 'message' => 'You are not authorized to book this deceased record.']);
        }
    }
}

$payment_plan = strtolower(trim($_POST['payment_plan'] ?? 'full'));
if (!in_array($payment_plan, ['full', 'installment'], true) || $purpose !== 'reserve_grave') {
    $payment_plan = 'full';
}
$payment_term = (int)($_POST['payment_term'] ?? 0);
if ($payment_term < 1) { $payment_term = 0; }

if ($section === '') {
    json_out(['success' => false, 'message' => 'Please select a section.']);
}
if (!preg_match('/^[A-Z0-9]{1,10}$/', $section)) {
    json_out(['success' => false, 'message' => 'Invalid section selected.']);
}
if (in_array($section, ['G', 'H', 'I', 'J'], true)) {
    json_out(['success' => false, 'message' => 'Section ' . $section . ' is currently unavailable for booking.']);
}

$isCemeteryService = ($purpose === 'cemetery_service');
if ($isCemeteryService && $service_type === '') {
    json_out(['success' => false, 'message' => 'Please select a service type.']);
}
if ($isCemeteryService) {
    if ($preferred_date === '') {
        json_out(['success' => false, 'message' => 'Please choose a preferred date.']);
    }
    
    $dt = DateTime::createFromFormat('Y-m-d', $preferred_date);
    if (!$dt || $dt->format('Y-m-d') !== $preferred_date) {
        json_out(['success' => false, 'message' => 'Invalid date format.']);
    }
    $today = (new DateTime('today'))->setTime(0, 0, 0);
    if ($dt < $today) {
        json_out(['success' => false, 'message' => 'Preferred date cannot be in the past.']);
    }

    
    $contact_number = preg_replace('/\D+/', '', $contact_number);
    if (!preg_match('/^09\d{9}$/', $contact_number)) {
        json_out(['success' => false, 'message' => 'Contact number must be exactly 11 digits and start with 09.']);
    }

    if ($booker_location === '') {
        json_out(['success' => false, 'message' => 'Please provide your complete address.']);
    }
} else {
    if ($preferred_date === '') {
        $preferred_date = date('Y-m-d');
    }
    if ($contact_number === '') {
        $contact_number = 'N/A';
    }
    if ($booker_location === '') {
        $booker_location = 'N/A';
    }
}
if (empty($service_type)) {
    if ($purpose === 'reserve_grave') {
        $service_type = 'Grave Reservation';
    } elseif ($purpose === 'add_deceased') {
        $service_type = 'Additional Deceased';
    } else {
        $service_type = 'Cemetery Service';
    }
}

if ($user_id > 0 && (empty($full_name) || empty($email) || empty($contact_number) || $contact_number === 'N/A' || empty($booker_location) || $booker_location === 'N/A')) {
    $uRes = $conn->query("SELECT first_name, last_name, email, phone_no FROM tbl_user WHERE user_id = $user_id LIMIT 1");
    if ($uRes && ($uData = $uRes->fetch_assoc())) {
        if (empty($full_name)) {
            $full_name = trim(($uData['first_name'] ?? '') . ' ' . ($uData['last_name'] ?? ''));
        }
        if (empty($email)) {
            $email = trim($uData['email'] ?? '');
        }
        if (empty($contact_number) || $contact_number === 'N/A') {
            $uPhone = trim($uData['phone_no'] ?? '');
            if (!empty($uPhone)) {
                $contact_number = $uPhone;
            }
        }
    }
    if (empty($booker_location) || $booker_location === 'N/A') {
        $locChk = $conn->query("SELECT booker_location FROM tbl_bookings WHERE user_id = $user_id AND booker_location != 'N/A' AND booker_location != '' ORDER BY id DESC LIMIT 1");
        if ($locChk && ($lr = $locChk->fetch_assoc()) && !empty($lr['booker_location'])) {
            $booker_location = trim($lr['booker_location']);
        }
    }
}

if ($deceased_id > 0) {
    if (!is_user_authorized_for_deceased($conn, $user_id, $deceased_id)) {
        json_out(['success' => false, 'message' => 'You are not authorized to book this deceased record.']);
    }
    $dStmt = $conn->prepare("SELECT section, plot_x, plot_y FROM tbl_deceased WHERE deceased_id = ? LIMIT 1");
    $ownedDeceased = null;
    if ($dStmt) {
        $dStmt->bind_param('i', $deceased_id);
        $dStmt->execute();
        $ownedDeceased = $dStmt->get_result()->fetch_assoc();
        $dStmt->close();
    }
    if ($booking_type === 'existing_plot_service' && $ownedDeceased && (
        strtoupper(trim($ownedDeceased['section'])) !== $section ||
        (int)$ownedDeceased['plot_x'] !== $targetPlotX ||
        (int)$ownedDeceased['plot_y'] !== $targetPlotY
    )) {
        
        $targetPlotAuthorized = is_user_authorized_for_plot($conn, $user_id, $section, $targetPlotX, $targetPlotY);
        if ($targetPlotAuthorized) {
            $correctDecStmt = $conn->prepare("SELECT deceased_id FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? ORDER BY deceased_id DESC LIMIT 1");
            if ($correctDecStmt) {
                $secUp = strtoupper(trim($section));
                $correctDecStmt->bind_param('sii', $secUp, $targetPlotX, $targetPlotY);
                $correctDecStmt->execute();
                $cdRes = $correctDecStmt->get_result();
                if ($cdRow = $cdRes->fetch_assoc()) {
                    $deceased_id = (int)$cdRow['deceased_id'];
                } else {
                    $deceased_id = 0;
                }
                $correctDecStmt->close();
            }
        } else {
            json_out(['success' => false, 'message' => 'Existing-plot service must use the deceased person\'s current plot.']);
        }
    }
}

if ($purpose !== 'reserve_grave' && ($purpose === 'cemetery_service' || $purpose === 'add_deceased' || $booking_type === 'existing_plot_service') && $targetPlotX > 0 && $targetPlotY > 0) {
    $role = strtolower(trim((string)($_SESSION['role'] ?? ($_SESSION['user_type'] ?? ''))));
    $isAdmin = ($role === 'admin' || $role === 'administrator');
    if (!$isAdmin) {
        $plotAuthorized = is_user_authorized_for_plot($conn, $user_id, $section, $targetPlotX, $targetPlotY);
        if (!$plotAuthorized) {
            $chkPlotDec = $conn->prepare("SELECT deceased_id FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?");
            if ($chkPlotDec) {
                $secUp = strtoupper(trim($section));
                $chkPlotDec->bind_param('sii', $secUp, $targetPlotX, $targetPlotY);
                $chkPlotDec->execute();
                $resPD = $chkPlotDec->get_result();
                while ($resPD && ($rpd = $resPD->fetch_assoc())) {
                    if (is_user_authorized_for_deceased($conn, $user_id, (int)$rpd['deceased_id'])) {
                        $plotAuthorized = true;
                        break;
                    }
                }
                $chkPlotDec->close();
            }
        }
        if (!$plotAuthorized) {
            json_out(['success' => false, 'message' => 'You are only authorized to add deceased or book services for plots linked to your account.']);
        }
    }
}

if ($purpose === 'add_deceased' && $targetPlotX > 0 && $targetPlotY > 0) {
    $intervalCheck = check_plot_interment_interval_eligibility($conn, $section, $targetPlotX, $targetPlotY, $dec_interment_date ?: $preferred_date);
    if (!$intervalCheck['is_eligible']) {
        json_out(['success' => false, 'message' => $intervalCheck['message']]);
    }
    if (($intervalCheck['occupant_count'] ?? 0) >= 4) {
        json_out(['success' => false, 'message' => 'Maximum occupants reached for this plot (maximum 4 deceased persons).']);
    }
}

$grave_price = 0.00;   
$interment_fee = 0.00; 
$service_price = 0.00; 
$total_amount = 0.00;

$plot_type = 'Lawn (Regular)';

if ($purpose === 'reserve_grave') {
    if (!empty($selected_plots)) {
        $grave_price = 0.00;
        foreach ($selected_plots as &$sp) {
            $sp_sec = strtoupper(trim((string)($sp['section'] ?? $section)));
            $sp_x = (int)($sp['plot_x'] ?? ($sp['plotX'] ?? 0));
            $sp_y = (int)($sp['plot_y'] ?? ($sp['plotY'] ?? 0));
            $pPrice = 0.00;
            $pType = 'Lawn (Regular)';
            $pStmt = $conn->prepare("SELECT price, lawn_type FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
            if ($pStmt) {
                $pStmt->bind_param('sii', $sp_sec, $sp_x, $sp_y);
                $pStmt->execute();
                $pRes = $pStmt->get_result();
                if ($pRes && ($pRow = $pRes->fetch_assoc())) {
                    $pPrice = (float)$pRow['price'];
                    if (!empty($pRow['lawn_type'])) $pType = $pRow['lawn_type'];
                }
                $pStmt->close();
            }
            if ($pPrice <= 0) {
                require_once __DIR__ . '/config/pricing_policy.php';
                $official = get_plot_official_pricing($sp_sec, $sp_x, $sp_y);
                $pPrice = (float)$official['lot_price'];
                $pType = $official['lawn_type'];
            }
            $sp['price'] = $pPrice;
            $sp['lawn_type'] = $pType;
            $grave_price += $pPrice;
        }
        unset($sp);
        $plot_type = $selected_plots[0]['lawn_type'] ?? 'Lawn (Regular)';
    } else {
        $priceStmt = $conn->prepare("SELECT price, lawn_type FROM tbl_plots WHERE section = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
        if ($priceStmt) {
            $priceStmt->bind_param('sii', $section, $targetPlotX, $targetPlotY);
            $priceStmt->execute();
            $priceRes = $priceStmt->get_result();
            if ($priceRes && ($priceRow = $priceRes->fetch_assoc())) {
                $grave_price = (float)$priceRow['price'];
                if (!empty($priceRow['lawn_type'])) {
                    $plot_type = $priceRow['lawn_type'];
                }
            }
            $priceStmt->close();
        }
        if ($grave_price <= 0) {
            require_once __DIR__ . '/config/pricing_policy.php';
            $official = get_plot_official_pricing($section, $targetPlotX, $targetPlotY);
            $grave_price = (float)$official['lot_price'];
            $plot_type = $official['lawn_type'];
        }
    }
} elseif ($purpose === 'add_deceased') {
    
    $grave_price = 0.00;
    
    if ($targetPlotX > 0 && $targetPlotY > 0) {
        $pTbl = 'tbl_plots';
        $pChk = @$conn->query("SHOW TABLES LIKE 'tbl_plots'");
        if (!$pChk || $pChk->num_rows === 0) {
            $pTbl = 'plots';
        }
        $ifStmt = $conn->prepare("SELECT COALESCE(interment_fee,0) AS interment_fee FROM {$pTbl} WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
        if ($ifStmt) {
            $ifStmt->bind_param('sii', $section, $targetPlotX, $targetPlotY);
            $ifStmt->execute();
            $ifRes = $ifStmt->get_result();
            if ($ifRes && ($ifRow = $ifRes->fetch_assoc()) && (float)$ifRow['interment_fee'] > 0) {
                $interment_fee = (float)$ifRow['interment_fee'];
            }
            $ifStmt->close();
        }
    }
    if ($interment_fee <= 0 && function_exists('get_plot_official_pricing')) {
        require_once __DIR__ . '/config/pricing_policy.php';
        $official = get_plot_official_pricing($section, $targetPlotX, $targetPlotY);
        $interment_fee = (float)($official['interment_fee'] ?? 38000.00);
    }
    if ($interment_fee <= 0) {
        $interment_fee = 38000.00; 
    }
} elseif ($purpose === 'cemetery_service') {
    $submittedServices = [];
    if (!empty($_POST['service_types']) && is_array($_POST['service_types'])) {
        $submittedServices = $_POST['service_types'];
    } elseif (!empty($_POST['service_types']) && is_string($_POST['service_types'])) {
        $submittedServices = explode(',', $_POST['service_types']);
    } elseif (!empty($service_type)) {
        $submittedServices = explode(',', $service_type);
    }
    $submittedServices = array_unique(array_filter(array_map('trim', $submittedServices)));

    if (empty($submittedServices)) {
        json_out(['success' => false, 'message' => 'Please select at least one cemetery service.']);
    }

    $matchedServices = [];
    $service_price = 0.00;
    $svcStmt = $conn->prepare("SELECT id, service_name, price FROM services WHERE UPPER(TRIM(service_name)) = UPPER(TRIM(?)) LIMIT 1");
    if ($svcStmt) {
        foreach ($submittedServices as $sName) {
            $svcStmt->bind_param('s', $sName);
            $svcStmt->execute();
            $svcRes = $svcStmt->get_result();
            if ($svcRes && ($sRow = $svcRes->fetch_assoc())) {
                $p = (float)$sRow['price'];
                $service_price += $p;
                $matchedServices[] = [
                    'id' => (int)$sRow['id'],
                    'name' => $sRow['service_name'],
                    'price' => $p
                ];
            } else {
                $svcStmt->close();
                json_out(['success' => false, 'message' => 'Invalid cemetery service: ' . htmlspecialchars($sName)]);
            }
        }
        $svcStmt->close();
    } else {
        json_out(['success' => false, 'message' => 'Database error loading cemetery services.']);
    }

    $service_type = implode(', ', array_column($matchedServices, 'name'));
    $numServicePlots = (!empty($selected_plots)) ? count($selected_plots) : 1;
    if ($numServicePlots > 1) {
        $service_price = $service_price * $numServicePlots;
    }
}

// Safe loader for installment plan helper with fallbacks for live server
if (file_exists(__DIR__ . '/config/installment_helper.php')) {
    require_once __DIR__ . '/config/installment_helper.php';
} elseif (file_exists(__DIR__ . '/installment_helper.php')) {
    require_once __DIR__ . '/installment_helper.php';
}

if (!function_exists('calculate_installment_plan')) {
    function calculate_installment_plan(int $term_months, int $num_plots = 1, float $plot_price = 85000.00): array {
        $term_months = max(1, $term_months);
        $num_plots = max(1, $num_plots);
        $total_plot_price = round($plot_price * $num_plots, 2);
        $downpayment = round($total_plot_price * 0.20, 2);
        $remaining = round($total_plot_price - $downpayment, 2);
        $monthly = round($remaining / $term_months, 2);
        return [
            'term_months' => $term_months,
            'num_lots' => $num_plots,
            'downpayment' => $downpayment,
            'monthly_fee' => $monthly,
            'total_plan_cost' => $total_plot_price,
            'initial_down_payment' => $downpayment,
            'projected_remaining_balance' => $remaining,
        ];
    }
}
if (!function_exists('generate_contract_payment_schedule')) {
    function generate_contract_payment_schedule(int $booking_id, $conn, bool $force = false): array {
        return [];
    }
}

$total_amount = $grave_price + $interment_fee + $service_price;
$initial_payment = $total_amount;
$monthly_fee = 0.00;
$downpayment = 0.00;

if ($payment_plan === 'installment' && $payment_term > 0 && $purpose === 'reserve_grave') {
    $planLots = max(1, (int)($actual_num_lots ?? ($num_lots ?? 1)));
    $planCalc = calculate_installment_plan($payment_term, $planLots, (float)($grave_price ?? 85000.00));
    $total_amount = $planCalc['total_plan_cost'];
    $downpayment = $planCalc['downpayment'];
    $initial_payment = $planCalc['initial_down_payment'];
    $monthly_fee = $planCalc['monthly_fee'];
}

$price = ($payment_plan === 'installment') ? $initial_payment : $total_amount;

$frontendLoc = trim($_POST['grave_location'] ?? '');
$grave_location = $frontendLoc !== '' ? $frontendLoc : ($section . ' - Auto Assigned');

if ($targetPlotX <= 0 || $targetPlotY <= 0) {
    $plotRaw = trim($plot);
    if (preg_match('/^[A-Z0-9]+[-_ ]?(\d+)[-_ ](\d+)$/i', $plotRaw, $m)) {
        $targetPlotX = (int)$m[1];
        $targetPlotY = (int)$m[2];
    }
}

if ($targetPlotX > 0 && $targetPlotY > 0) {
    $secUp = strtoupper(trim($section));
    $plotRow = null;
    if ($conn->query("SHOW TABLES LIKE 'tbl_plots'")->num_rows > 0) {
        $check = $conn->prepare(
            "SELECT plot_id, status FROM tbl_plots
             WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1"
        );
        if ($check) {
            $check->bind_param('sii', $secUp, $targetPlotX, $targetPlotY);
            $check->execute();
            $res = $check->get_result();
            $plotRow = $res ? $res->fetch_assoc() : null;
            $check->close();
        }
    }
    
    if (!$plotRow && ($chkPlotsTbl = @$conn->query("SHOW TABLES LIKE 'plots'")) && $chkPlotsTbl->num_rows > 0) {
        $checkPlots = $conn->prepare(
            "SELECT id AS plot_id, status FROM plots
             WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1"
        );
        if ($checkPlots) {
            $checkPlots->bind_param('sii', $secUp, $targetPlotX, $targetPlotY);
            $checkPlots->execute();
            $resPlots = $checkPlots->get_result();
            $plotRow = $resPlots ? $resPlots->fetch_assoc() : null;
            $checkPlots->close();
        }
    }
    
    if (!$plotRow && in_array($secUp, ['A','B','C','D','E','F'], true) && $targetPlotX > 0 && $targetPlotY > 0) {
        $plotRow = ['plot_id' => 0, 'status' => 'available'];
        if ($conn->query("SHOW TABLES LIKE 'tbl_plots'")->num_rows > 0) {
            @$conn->query("INSERT IGNORE INTO tbl_plots (section, plot_x, plot_y, status, price, lawn_type) VALUES ('{$secUp}', {$targetPlotX}, {$targetPlotY}, 'available', 85000.00, 'Lawn (Regular)')");
        }
    }
    if ($plotRow) {
        $st = strtolower($plotRow['status'] ?? '');
        $occCount = 0;
        if ($conn->query("SHOW TABLES LIKE 'tbl_deceased'")->num_rows > 0) {
            $occStmt = $conn->prepare("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?");
            if ($occStmt) {
                $occStmt->bind_param('sii', $secUp, $targetPlotX, $targetPlotY);
                $occStmt->execute();
                $occRes = $occStmt->get_result();
                $occCount = ($occRes && ($r = $occRes->fetch_assoc())) ? (int)$r['c'] : 0;
                $occStmt->close();
            }
        }

        if ($purpose === 'reserve_grave') {
            $hasActiveOwnerOrBooking = false;
            $activeBkStmt = $conn->prepare("SELECT id FROM tbl_bookings WHERE UPPER(TRIM(section)) = ? AND (plot = ? OR plot = ? OR grave_location LIKE ?) AND LOWER(TRIM(status)) IN ('approved', 'confirmed', 'reserved', 'completed') LIMIT 1");
            if ($activeBkStmt) {
                $pStr1 = "{$targetPlotX}-{$targetPlotY}";
                $pStr2 = "{$targetPlotX}, {$targetPlotY}";
                $pStr3 = "%{$targetPlotX}-{$targetPlotY}%";
                $activeBkStmt->bind_param('ssss', $secUp, $pStr1, $pStr2, $pStr3);
                $activeBkStmt->execute();
                if ($activeBkStmt->get_result()->fetch_assoc()) {
                    $hasActiveOwnerOrBooking = true;
                }
                $activeBkStmt->close();
            }
            if (!$hasActiveOwnerOrBooking && $conn->query("SHOW TABLES LIKE 'user_plot_ownership'")->num_rows > 0) {
                $upoChk = $conn->prepare("SELECT id FROM user_plot_ownership WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND verification_status = 'VERIFIED' AND ownership_status IN ('OWNED', 'PURCHASED') LIMIT 1");
                if ($upoChk) {
                    $upoChk->bind_param('sii', $secUp, $targetPlotX, $targetPlotY);
                    $upoChk->execute();
                    if ($upoChk->get_result()->fetch_assoc()) {
                        $hasActiveOwnerOrBooking = true;
                    }
                    $upoChk->close();
                }
            }

            // Auto-heal stale occupied/reserved status in tbl_plots if no deceased and no active booking/owner
            if (($st === 'occupied' || $st === 'reserved') && $occCount === 0 && !$hasActiveOwnerOrBooking) {
                $st = 'available';
                @$conn->query("UPDATE tbl_plots SET status = 'available' WHERE UPPER(TRIM(section)) = '{$secUp}' AND plot_x = {$targetPlotX} AND plot_y = {$targetPlotY}");
            }

            if ($occCount > 0 || ($st === 'occupied' && $hasActiveOwnerOrBooking)) {
                json_out(['success' => false, 'message' => "Plot {$secUp} {$targetPlotX}-{$targetPlotY} is already occupied and cannot be reserved."]);
            }
            if ($st === 'reserved' && $hasActiveOwnerOrBooking) {
                json_out(['success' => false, 'message' => "Plot {$secUp} {$targetPlotX}-{$targetPlotY} has already been reserved. Please choose another plot."]);
            }
        }

        $isOccupied = ($st === 'occupied' || $occCount > 0);

        if ($isOccupied) {
            if ($purpose === 'add_deceased') {
                if (!$isAdmin) {
                    json_out(['success' => false, 'message' => 'Only administrators can add additional deceased records to occupied plots.']);
                }
                if ($occCount < 1) {
                    json_out(['success' => false, 'message' => 'Add Additional Deceased requires an existing occupant in this grave (minimum 2 deceased total).']);
                }
                if ($occCount >= 4) {
                    json_out(['success' => false, 'message' => 'Maximum occupants reached for this plot (maximum 4 deceased persons).']);
                }
            }
        } else {
            if ($purpose === 'add_deceased') {
                if (!$isAdmin) {
                    json_out(['success' => false, 'message' => 'Only administrators can add additional deceased records.']);
                }
                json_out(['success' => false, 'message' => 'Add Additional Deceased is only available for occupied plots with an existing deceased record.']);
            }
        }
        if ($st === 'reserved' && $purpose === 'reserve_grave') {
            json_out(['success' => false, 'message' => 'This plot has already been reserved. Please choose another plot.']);
        }
        
    } else {
        json_out(['success' => false, 'message' => 'The selected plot does not exist. Please choose another plot.']);
    }
}

$claimedPlot = false;
if ($purpose === 'reserve_grave' && $targetPlotX > 0 && $targetPlotY > 0) {
    
    $chkPlot = $conn->prepare("SELECT status FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
    if ($chkPlot) {
        $secUp = strtoupper(trim($section));
        $chkPlot->bind_param('sii', $secUp, $targetPlotX, $targetPlotY);
        $chkPlot->execute();
        $resPlot = $chkPlot->get_result()->fetch_assoc();
        $chkPlot->close();
        if ($resPlot && in_array(strtolower(trim($resPlot['status'] ?? '')), ['reserved', 'occupied', 'unavailable'])) {
            $pSt = strtolower(trim($resPlot['status'] ?? ''));
            $isGenuinelyTaken = false;
            $chkDCount = 0;
            if ($conn->query("SHOW TABLES LIKE 'tbl_deceased'")->num_rows > 0) {
                $cDs = $conn->prepare("SELECT COUNT(*) AS c FROM tbl_deceased WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ?");
                if ($cDs) {
                    $cDs->bind_param('sii', $secUp, $targetPlotX, $targetPlotY);
                    $cDs->execute();
                    $cDr = $cDs->get_result()->fetch_assoc();
                    $chkDCount = $cDr ? (int)$cDr['c'] : 0;
                    $cDs->close();
                }
            }
            if ($chkDCount > 0) {
                $isGenuinelyTaken = true;
            } else {
                $cBs = $conn->prepare("SELECT id FROM tbl_bookings WHERE UPPER(TRIM(section)) = ? AND (plot = ? OR plot = ? OR grave_location LIKE ?) AND LOWER(TRIM(status)) IN ('approved', 'confirmed', 'reserved', 'completed') LIMIT 1");
                if ($cBs) {
                    $p1 = "{$targetPlotX}-{$targetPlotY}";
                    $p2 = "{$targetPlotX}, {$targetPlotY}";
                    $p3 = "%{$targetPlotX}-{$targetPlotY}%";
                    $cBs->bind_param('ssss', $secUp, $p1, $p2, $p3);
                    $cBs->execute();
                    if ($cBs->get_result()->fetch_assoc()) $isGenuinelyTaken = true;
                    $cBs->close();
                }
                if (!$isGenuinelyTaken && $conn->query("SHOW TABLES LIKE 'user_plot_ownership'")->num_rows > 0) {
                    $cOs = $conn->prepare("SELECT id FROM user_plot_ownership WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? AND verification_status = 'VERIFIED' AND ownership_status IN ('OWNED', 'PURCHASED') LIMIT 1");
                    if ($cOs) {
                        $cOs->bind_param('sii', $secUp, $targetPlotX, $targetPlotY);
                        $cOs->execute();
                        if ($cOs->get_result()->fetch_assoc()) $isGenuinelyTaken = true;
                        $cOs->close();
                    }
                }
            }
            if ($isGenuinelyTaken) {
                json_out(['success' => false, 'message' => "Plot {$secUp} {$targetPlotX}-{$targetPlotY} is already reserved or occupied. Please choose another plot."]);
            } else {
                @$conn->query("UPDATE tbl_plots SET status = 'available' WHERE UPPER(TRIM(section)) = '{$secUp}' AND plot_x = {$targetPlotX} AND plot_y = {$targetPlotY}");
            }
        }
    }

    
    $plotHyp = "{$targetPlotX}-{$targetPlotY}";
    $plotAlt = "{$targetPlotX}, {$targetPlotY}";
    $chkApp = $conn->prepare("SELECT id FROM tbl_bookings WHERE UPPER(TRIM(section)) = ? AND (plot IN (?, ?) OR grave_location IN (?, ?)) AND LOWER(TRIM(status)) IN ('approved', 'confirmed', 'reserved') LIMIT 1");
    if ($chkApp) {
        $chkApp->bind_param('sssss', $section, $plotHyp, $plotAlt, $plotHyp, $plotAlt);
        $chkApp->execute();
        if ($chkApp->get_result()->num_rows > 0) {
            $chkApp->close();
            json_out(['success' => false, 'message' => 'This plot has already been approved for another reservation. Please choose another plot.']);
        }
        $chkApp->close();
    }
}

$add_columns = [
    'full_name'      => "VARCHAR(255) DEFAULT NULL",
    'email'          => "VARCHAR(255) DEFAULT NULL",
    'deceased_name'  => "VARCHAR(255) DEFAULT NULL",
    'plot_type'      => "VARCHAR(50) DEFAULT NULL",
    'plot_price'     => "DECIMAL(10,2) DEFAULT NULL",
    'purpose'        => "VARCHAR(30) DEFAULT 'reserve_grave'",
    'payment_plan'   => "VARCHAR(20) DEFAULT 'full'",
    'payment_term'   => "INT DEFAULT NULL",
    'interment_fee'  => "DECIMAL(10,2) DEFAULT 0.00",
    'service_price'  => "DECIMAL(10,2) DEFAULT 0.00",
    'total_amount'   => "DECIMAL(10,2) DEFAULT 0.00",
    'deceased_id'    => "INT DEFAULT NULL",
    'booking_type'   => "VARCHAR(40) NOT NULL DEFAULT 'general'",
    'plot_id'        => "INT DEFAULT NULL",
    'customer_name'  => "VARCHAR(255) DEFAULT NULL",
    'customer_email' => "VARCHAR(255) DEFAULT NULL",
    'remarks'        => "TEXT DEFAULT NULL",
    'source'         => "VARCHAR(20) DEFAULT 'user'",
    'num_lots'       => "INT DEFAULT 1",
    'area'           => "VARCHAR(50) DEFAULT 'Lawn'",
    'section_plot'   => "VARCHAR(20) DEFAULT NULL"
];
foreach ($add_columns as $col => $def) {
    try {
        $chk = $conn->query("SHOW COLUMNS FROM `tbl_bookings` LIKE '" . $col . "'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE `tbl_bookings` ADD COLUMN `" . $col . "` " . $def);
        }
    } catch (Throwable $e) {}
}

$conn->query("CREATE TABLE IF NOT EXISTS `tbl_bookings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `service_type` VARCHAR(50) NOT NULL,
    `grave_location` VARCHAR(100) NOT NULL,
    `section` VARCHAR(10) DEFAULT NULL,
    `plot` VARCHAR(20) DEFAULT NULL,
    `plot_type` VARCHAR(50) DEFAULT NULL,
    `plot_price` DECIMAL(10,2) DEFAULT 0.00,
    `preferred_date` DATE NOT NULL,
    `contact_number` VARCHAR(20) NOT NULL,
    `booker_location` VARCHAR(255) DEFAULT NULL,
    `special_requests` TEXT DEFAULT NULL,
    `payment_type` VARCHAR(50) DEFAULT NULL,
    `amount_paid` DECIMAL(10,2) DEFAULT 0.00,
    `reference_number` VARCHAR(100) DEFAULT NULL,
    `bank_name` VARCHAR(100) DEFAULT NULL,
    `status` VARCHAR(20) DEFAULT 'pending',
    `user_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `full_name` VARCHAR(255) DEFAULT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `deceased_name` VARCHAR(255) DEFAULT NULL,
    `burial_time` VARCHAR(10) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `purpose` VARCHAR(30) DEFAULT 'reserve_grave',
    `interment_fee` DECIMAL(10,2) DEFAULT 0.00,
    `service_price` DECIMAL(10,2) DEFAULT 0.00,
    `total_amount` DECIMAL(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$targetDeceasedName = !empty($dec_full_name) ? $dec_full_name : '';
if (empty($targetDeceasedName) && $deceased_id > 0) {
    $dCheck = $conn->query("SELECT first_name, middle_name, last_name, suffix FROM tbl_deceased WHERE deceased_id = $deceased_id LIMIT 1");
    if ($dCheck && ($dr = $dCheck->fetch_assoc())) {
        $targetDeceasedName = trim(implode(' ', array_filter([$dr['first_name'], $dr['middle_name'], $dr['last_name'], $dr['suffix']])));
    }
}
$targetPlotFormatted = (!empty($section) && $targetPlotX > 0 && $targetPlotY > 0) ? "{$targetPlotX}-{$targetPlotY}" : $plot;
if (!empty($selected_plots) && count($selected_plots) > 1) {
    $plotNums = [];
    foreach ($selected_plots as $sp) {
        $plotNums[] = $sp['plot_number'] ?? (($sp['section'] ?? $section) . '-' . ($sp['plot_x'] ?? 0) . '-' . ($sp['plot_y'] ?? 0));
    }
    $targetPlotFormatted = implode(', ', $plotNums);
}
$actual_num_lots = (!empty($selected_plots) && count($selected_plots) > 0) ? count($selected_plots) : max(1, (int)$num_lots);
if ($purpose === 'cemetery_service' && !empty($selected_plots) && count($selected_plots) > 1) {
    $plotLocs = [];
    foreach ($selected_plots as $sp) {
        $sp_sec = strtoupper(trim((string)($sp['section'] ?? $section)));
        $sp_x = (int)($sp['plot_x'] ?? ($sp['plotX'] ?? 0));
        $sp_y = (int)($sp['plot_y'] ?? ($sp['plotY'] ?? 0));
        $plotLocs[] = "Section {$sp_sec} Plot {$sp_x}-{$sp_y}";
    }
    $grave_location = implode(', ', $plotLocs) . ' (' . count($selected_plots) . ' plots)';
}

$conn->begin_transaction();

if (function_exists('ensure_booking_table_integrity')) {
    ensure_booking_table_integrity($conn);
}

$prepErr = '';
$booking_id = 0;

$existing_booking_id = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
if ($existing_booking_id > 0) {
    $chkExist = $conn->query("SELECT id FROM tbl_bookings WHERE id = {$existing_booking_id} AND user_id = {$user_id} LIMIT 1");
    if ($chkExist && $chkExist->num_rows > 0) {
        $booking_id = $existing_booking_id;
        $upStmt = $conn->prepare("UPDATE tbl_bookings SET 
            service_type = ?, grave_location = ?, section = ?, plot = ?, plot_type = ?, plot_price = ?,
            preferred_date = ?, contact_number = ?, booker_location = ?, special_requests = ?,
            payment_type = ?, full_name = ?, deceased_name = ?, email = ?,
            purpose = ?, payment_plan = ?, payment_term = ?, interment_fee = ?, service_price = ?, total_amount = ?, num_lots = ?
            WHERE id = ? AND user_id = ?");
        if ($upStmt) {
            $upStmt->bind_param(
                'sssssdsssssssssidddiii',
                $service_type,
                $grave_location,
                $section,
                $targetPlotFormatted,
                $plot_type,
                $grave_price,
                $preferred_date,
                $contact_number,
                $booker_location,
                $special_requests,
                $payment_method,
                $full_name,
                $targetDeceasedName,
                $email,
                $purpose,
                $payment_plan,
                $payment_term,
                $interment_fee,
                $service_price,
                $total_amount,
                $actual_num_lots,
                $booking_id,
                $user_id
            );
            $upStmt->execute();
            $upStmt->close();
        }
    }
}

if ($booking_id <= 0) {
    $stmt = $conn->prepare(
        "INSERT INTO tbl_bookings
            (service_type, grave_location, section, plot, plot_type, plot_price,
             preferred_date, contact_number, booker_location, special_requests,
             payment_type, user_id, deceased_id, booking_type, status, full_name, deceased_name, email,
             purpose, payment_plan, payment_term, interment_fee, service_price, total_amount, num_lots, payment_status, service_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'UNPAID', 'PENDING')"
    );
    if (!$stmt) {
        $prepErr = $conn->error ?: 'Database prepare failed';
        if (function_exists('ensure_booking_table_integrity')) {
            ensure_booking_table_integrity($conn);
        }
        // Retry once after ensuring integrity
        $stmt = $conn->prepare(
            "INSERT INTO tbl_bookings
                (service_type, grave_location, section, plot, plot_type, plot_price,
                 preferred_date, contact_number, booker_location, special_requests,
                 payment_type, user_id, deceased_id, booking_type, status, full_name, deceased_name, email,
                 purpose, payment_plan, payment_term, interment_fee, service_price, total_amount, num_lots, payment_status, service_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'UNPAID', 'PENDING')"
        );
    }
}

if ($stmt) {
    $bind = $stmt->bind_param(
        'sssssdsssssiissssssidddi',
        $service_type,
        $grave_location,
        $section,
        $targetPlotFormatted,
        $plot_type,
        $grave_price,
        $preferred_date,
        $contact_number,
        $booker_location,
        $special_requests,
        $payment_method,
        $user_id,
        $deceased_id,
        $booking_type,
        $full_name,
        $targetDeceasedName,
        $email,
        $purpose,
        $payment_plan,
        $payment_term,
        $interment_fee,
        $service_price,
        $total_amount,
        $actual_num_lots
    );
    if ($bind && $stmt->execute()) {
        $booking_id = (int)$conn->insert_id;
        $stmt->close();
    } else {
        $prepErr = $stmt->error ?: ($conn->error ?: 'Execution failed');
        $stmt->close();
        $stmt = null;
    }
}

// Resilient Fallback: If prepare/execute failed, dynamically match existing columns in tbl_bookings
if ($booking_id <= 0) {
    $bookingData = [
        'service_type'    => $service_type,
        'grave_location'  => $grave_location,
        'section'         => $section,
        'plot'            => $targetPlotFormatted,
        'section_plot'    => $targetPlotFormatted,
        'plot_type'       => $plot_type,
        'plot_price'      => (float)$grave_price,
        'preferred_date'  => $preferred_date,
        'contact_number'  => $contact_number,
        'booker_location' => $booker_location,
        'special_requests'=> $special_requests,
        'payment_type'    => $payment_method,
        'user_id'         => (int)$user_id,
        'deceased_id'     => $deceased_id > 0 ? (int)$deceased_id : null,
        'booking_type'    => $booking_type,
        'status'          => 'pending',
        'customer_name'   => $full_name,
        'full_name'       => $full_name,
        'deceased_name'   => $targetDeceasedName,
        'email'           => $email,
        'customer_email'  => $email,
        'purpose'         => $purpose,
        'payment_plan'    => $payment_plan,
        'payment_term'    => (int)$payment_term,
        'interment_fee'   => (float)$interment_fee,
        'service_price'   => (float)$service_price,
        'total_amount'    => (float)$total_amount,
        'num_lots'        => (int)$actual_num_lots,
        'payment_status'  => 'UNPAID',
        'service_status'  => 'PENDING'
    ];

    $colRes = $conn->query("SHOW COLUMNS FROM `tbl_bookings`");
    if ($colRes) {
        $existingCols = [];
        while ($c = $colRes->fetch_assoc()) {
            $existingCols[strtolower($c['Field'])] = true;
        }

        $insCols = [];
        $insVals = [];
        foreach ($bookingData as $k => $v) {
            if (isset($existingCols[strtolower($k)])) {
                $insCols[] = "`$k`";
                if ($v === null) {
                    $insVals[] = "NULL";
                } elseif (is_numeric($v)) {
                    $insVals[] = $v;
                } else {
                    $insVals[] = "'" . $conn->real_escape_string((string)$v) . "'";
                }
            }
        }

        if (!empty($insCols)) {
            $dynSql = "INSERT INTO `tbl_bookings` (" . implode(', ', $insCols) . ") VALUES (" . implode(', ', $insVals) . ")";
            if ($conn->query($dynSql)) {
                $booking_id = (int)$conn->insert_id;
            } else {
                $prepErr = $conn->error ?: 'Fallback insert query failed';
            }
        }
    }
}

if ($booking_id <= 0) {
    $finalErrMsg = !empty($prepErr) ? $prepErr : ($conn->error ?: 'Database prepare or execute failed');
    $conn->rollback();
    if ($claimedPlot) {
        $release = $conn->prepare("UPDATE tbl_plots SET status = 'available' WHERE section = ? AND plot_x = ? AND plot_y = ? AND LOWER(status) = 'reserved'");
        if ($release) { $release->bind_param('sii', $section, $targetPlotX, $targetPlotY); $release->execute(); $release->close(); }
    }
    json_out(['success' => false, 'message' => 'Database prepare failed: ' . $finalErrMsg]);
}

if ($booking_id > 0) {

    $escFull = $conn->real_escape_string($full_name);
    @$conn->query("UPDATE tbl_bookings SET customer_name = '{$escFull}', customer_email = '{$escEmail}' WHERE id = {$booking_id} AND (customer_name IS NULL OR customer_name = '')");
    @$conn->query("UPDATE tbl_bookings SET remaining_balance = total_amount WHERE id = {$booking_id} AND (remaining_balance IS NULL OR remaining_balance = 0) AND payment_status != 'PAID'");

    // Record in tbl_booking_logs and sales_reports table
    if (function_exists('record_booking_log')) {
        record_booking_log($conn, $booking_id, $section, $contact_number, $booker_location, $total_amount);
    }
    if (function_exists('record_sales_report_entry')) {
        record_sales_report_entry($conn, $booking_id);
    }

    // Record each selected cemetery service in booking_services table
    if (!empty($matchedServices)) {
        $conn->query("CREATE TABLE IF NOT EXISTS `booking_services` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `booking_id` INT NOT NULL,
            `service_id` INT DEFAULT NULL,
            `service_name` VARCHAR(255) NOT NULL,
            `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`booking_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $insBS = $conn->prepare("INSERT INTO booking_services (booking_id, service_id, service_name, price) VALUES (?, ?, ?, ?)");
        if ($insBS) {
            foreach ($matchedServices as $ms) {
                $msId = $ms['id'] ?? null;
                $msName = $ms['name'];
                $msPrice = (float)$ms['price'];
                $insBS->bind_param('iisd', $booking_id, $msId, $msName, $msPrice);
                $insBS->execute();
            }
            $insBS->close();
        }
    }

    if ($payment_plan === 'installment' && $payment_term > 0 && $purpose === 'reserve_grave' && $total_amount > 0) {
        generate_contract_payment_schedule((int)$booking_id, $conn, false);
    }

    // Process optional proof of payment uploaded with booking
    $proof_uploaded = false;
    $proof_filename = '';
    $proof_ref = '';
    $proof_file_key = isset($_FILES['payment_proof']) ? 'payment_proof' : (isset($_FILES['proof_file']) ? 'proof_file' : null);
    if ($proof_file_key && isset($_FILES[$proof_file_key]) && $_FILES[$proof_file_key]['error'] === UPLOAD_ERR_OK) {
        $pfile = $_FILES[$proof_file_key];
        $pext = strtolower(pathinfo($pfile['name'], PATHINFO_EXTENSION));
        $allowed_pext = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        $pmime = mime_content_type($pfile['tmp_name']) ?: '';
        $allowed_pmime = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg', 'application/pdf'];
        $is_valid_img = ($pext === 'pdf') || (!function_exists('exif_imagetype') || @exif_imagetype($pfile['tmp_name']) !== false);

        if (in_array($pext, $allowed_pext) && in_array($pmime, $allowed_pmime) && $is_valid_img && $pfile['size'] <= 10 * 1024 * 1024) {
            $u_dir = __DIR__ . '/uploads/payment-proofs';
            if (!is_dir($u_dir)) {
                @mkdir($u_dir, 0755, true);
            }
            $proof_filename = 'booking_' . $booking_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $pext;
            $dest_path = $u_dir . '/' . $proof_filename;
            if (@move_uploaded_file($pfile['tmp_name'], $dest_path)) {
                $proof_uploaded = true;
                $user_ref = isset($_POST['payment_reference']) ? trim($_POST['payment_reference']) : (isset($_POST['gcash_reference']) ? trim($_POST['gcash_reference']) : '');
                $proof_ref = !empty($user_ref) ? $user_ref : ('PROOF-' . $booking_id . '-' . date('ymdHis') . '-' . strtoupper(substr(uniqid(), -4)));

                // Update tbl_bookings
                if ($payment_plan === 'installment') {
                    $dp_val = ($initial_payment > 0) ? (float)$initial_payment : 0.00;
                    $rec_amount = $dp_val;
                    $rem_balance = max(0.00, $total_amount - $rec_amount);
                } else {
                    $dp_val = 0.00;
                    $rec_amount = $total_amount;
                    $rem_balance = 0.00;
                }

                $upBk = $conn->prepare("UPDATE tbl_bookings SET payment_proof_filename = ?, gcash_reference_number = ?, payment_proof_uploaded_at = NOW(), reference_number = COALESCE(reference_number, ?), payment_status = 'FOR VERIFICATION', downpayment = ?, amount_paid = ?, remaining_balance = ? WHERE id = ?");
                if ($upBk) {
                    $upBk->bind_param('sssdddi', $proof_filename, $proof_ref, $proof_ref, $dp_val, $rec_amount, $rem_balance, $booking_id);
                    $upBk->execute();
                    $upBk->close();
                }

                // Insert into payment_receipts only if an actual payment amount was specified
                if ($rec_amount > 0) {
                    $method_clean = !empty($payment_method) ? $payment_method : 'GCash';
                    $rcpt_tx = 'TXN-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $method_clean), 0, 4)) . '-' . $booking_id . '-' . time();
                    $sched_id = null;
                    $inst_no = ($payment_plan === 'installment') ? 1 : null;
                    if ($payment_plan === 'installment') {
                        $sLookup = $conn->query("SELECT id FROM payment_schedules WHERE contract_id = {$booking_id} AND installment_number = 1 LIMIT 1");
                        if ($sLookup && ($sRow = $sLookup->fetch_assoc())) {
                            $sched_id = (int)$sRow['id'];
                        }
                    }
                    $note_text = 'Uploaded during booking submission';
                    $insRcpt = $conn->prepare("INSERT INTO payment_receipts 
                        (booking_id, user_id, payment_schedule_id, installment_number, amount_paid, payment_method, gcash_reference_number, transaction_reference, payment_date, payment_status, proof_filename, notes, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'PENDING', ?, ?, NOW())");
                    if ($insRcpt) {
                        $insRcpt->bind_param('iiiidsssss', $booking_id, $user_id, $sched_id, $inst_no, $rec_amount, $method_clean, $proof_ref, $rcpt_tx, $proof_filename, $note_text);
                        $insRcpt->execute();
                        $new_rcpt_id = (int)$insRcpt->insert_id;
                        $insRcpt->close();
                        if ($new_rcpt_id > 0) {
                            $rcpt_num = sprintf("RCPT-%s-%04d", date('Ymd'), $new_rcpt_id);
                            $conn->query("UPDATE payment_receipts SET receipt_number = '{$rcpt_num}' WHERE id = {$new_rcpt_id}");
                        }
                    }
                }

                // Log proof upload
                $conn->query("CREATE TABLE IF NOT EXISTS tbl_logs (id INT AUTO_INCREMENT PRIMARY KEY, action VARCHAR(100) NOT NULL, details TEXT, timestamp DATETIME NOT NULL, user_id INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $logDetails = "Payment proof uploaded with initial booking #{$booking_id}. Reference: {$proof_ref}, Method: {$method_clean}";
                $pLog = $conn->prepare("INSERT INTO tbl_logs (action, details, timestamp, user_id) VALUES ('PAYMENT_PROOF_UPLOADED', ?, NOW(), ?)");
                if ($pLog) {
                    $pLog->bind_param('si', $logDetails, $user_id);
                    $pLog->execute();
                    $pLog->close();
                }
            }
        }
    }

    // Clear previous booking_plots records if updating an existing booking
    $conn->query("DELETE FROM booking_plots WHERE booking_id = {$booking_id}");

    // Insert into booking_plots for all selected plots or single plot (status remains 'pending' until approved)
    $bpStmt = $conn->prepare("INSERT INTO booking_plots (booking_id, plot_id, section, plot_x, plot_y, plot_number, lawn_type, price_at_booking, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
    $plotsToRecord = !empty($selected_plots) ? $selected_plots : [[
        'plot_id' => 0,
        'section' => $section,
        'plot_x' => $targetPlotX,
        'plot_y' => $targetPlotY,
        'plot_number' => $targetPlotFormatted,
        'lawn_type' => $plot_type,
        'price' => $grave_price
    ]];

    if ($bpStmt) {
        foreach ($plotsToRecord as $pItem) {
            $pSec = strtoupper(trim((string)($pItem['section'] ?? $section)));
            $pX = (int)($pItem['plot_x'] ?? ($pItem['plotX'] ?? 0));
            $pY = (int)($pItem['plot_y'] ?? ($pItem['plotY'] ?? 0));
            $pId = (int)($pItem['plot_id'] ?? ($pItem['plotId'] ?? 0));
            $pNum = trim((string)($pItem['plot_number'] ?? ($pSec . '- ' . $pX . ',' . $pY)));
            $pLawn = trim((string)($pItem['lawn_type'] ?? 'Regular'));
            $isPlotPurchase = ($purpose === 'reserve_grave' || $booking_type === 'another_plot' || $booking_type === 'plot_purchase');
            $pPrc = $isPlotPurchase ? (float)($pItem['price'] ?? 85000.00) : 0.00;

            if ($pId <= 0 && $pX > 0 && $pY > 0) {
                $fQ = $conn->prepare("SELECT plot_id FROM tbl_plots WHERE UPPER(TRIM(section)) = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
                if ($fQ) {
                    $fQ->bind_param('sii', $pSec, $pX, $pY);
                    $fQ->execute();
                    if ($fRow = $fQ->get_result()->fetch_assoc()) {
                        $pId = (int)$fRow['plot_id'];
                    }
                    $fQ->close();
                }
            }

            $bpStmt->bind_param('iisiissd', $booking_id, $pId, $pSec, $pX, $pY, $pNum, $pLawn, $pPrc);
            $bpStmt->execute();

            // DO NOT update tbl_plots status here.
            // A plot must remain AVAILABLE until the booking reaches the proper confirmed/approved reservation state.
        }
        $bpStmt->close();
    }

    // Plot ownership (tbl_plot_owners and user_plot_ownership) is established only when
    // the reservation is approved/confirmed by an administrator in handle_booking_approval().

        $conn->query("CREATE TABLE IF NOT EXISTS tbl_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            timestamp DATETIME NOT NULL,
            user_id INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $plotLabel = (!empty($section) && $targetPlotX > 0 && $targetPlotY > 0)
            ? "Section {$section}, Plot {$targetPlotFormatted}"
            : "Section {$section} " . (!empty($grave_location) ? $grave_location : $plot);
        $planLabel = ($payment_plan === 'installment' && $payment_term > 0)
            ? "{$payment_term} Months Installment"
            : "Full Payment";

        $logDetails = "{$plotLabel} reserved for {$full_name} | NEW: OWNER={$full_name}, PHONE={$contact_number}, EMAIL={$email}, ADDRESS={$booker_location}, BOOKING=#{$booking_id}";

        $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, timestamp, user_id) VALUES ('Reserved By', ?, NOW(), ?)");
        if ($logStmt) {
            $logStmt->bind_param('si', $logDetails, $user_id);
            $logStmt->execute();
            $logStmt->close();
        }

    if ($purpose === 'cemetery_service' || !empty($service_type)) {
        $srvType = !empty($service_type) ? $service_type : 'Cemetery Service';
        $srvPlot = (!empty($section) && $targetPlotX > 0 && $targetPlotY > 0)
            ? "Section {$section}, Plot {$targetPlotX}-{$targetPlotY}"
            : "Section {$section} " . (!empty($grave_location) ? $grave_location : $plot);
        $srvDetails = "Service booked: {$srvType} for {$srvPlot} | Booking ID: #{$booking_id} | Client: {$full_name} | Price: ₱" . number_format($total_amount, 2) . " | Payment: {$payment_method}";
        
        $srvStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, timestamp, user_id) VALUES ('SERVICE_BOOKING', ?, NOW(), ?)");
        if ($srvStmt) {
            $srvStmt->bind_param('si', $srvDetails, $user_id);
            $srvStmt->execute();
            $srvStmt->close();
        }
    }

    if ($purpose === 'add_deceased' && $targetPlotX > 0 && $targetPlotY > 0) {
        $secUpper = strtoupper(trim($section));
        $diedVal = !empty($dec_date_died) ? $dec_date_died : $dec_interment_date;
        $bdayVal = !empty($dec_bday) ? $dec_bday : null;
        $userBooker = !empty($full_name) ? $full_name : 'Customer Name';
        $decContact = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : (!empty($contact_number) ? $contact_number : '');
        $decFuneral = !empty($_POST['funeral_service']) ? trim($_POST['funeral_service']) : 'ST. PETER';
        $decBornIn  = !empty($_POST['born_in']) ? trim($_POST['born_in']) : (!empty($_POST['booker_location']) ? trim($_POST['booker_location']) : 'ALAMINOS CITY');

        $insDec = $conn->prepare("INSERT INTO tbl_deceased (
            first_name, middle_name, last_name, suffix,
            bday, date_died, interment_date,
            section, plot_x, plot_y, user_id,
            relationship_to_account_holder, added_by, edited_by,
            transfer_status, payment_status, total_amount,
            contact_no, funeral_service, born_in
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PATONG_ADDITIONAL', 'PENDING', ?, ?, ?, ?)");
        if ($insDec) {
            $insDec->bind_param(
                'ssssssssiiisssdsss',
                $dec_first_name,
                $dec_middle_name,
                $dec_last_name,
                $dec_suffix,
                $bdayVal,
                $diedVal,
                $dec_interment_date,
                $secUpper,
                $targetPlotX,
                $targetPlotY,
                $user_id,
                $dec_relationship,
                $userBooker,
                $userBooker,
                $total_amount,
                $decContact,
                $decFuneral,
                $decBornIn
            );
            $insDec->execute();
            $newDecId = $conn->insert_id;
            $insDec->close();

            if ($newDecId > 0) {
                @$conn->query("UPDATE tbl_bookings SET deceased_id = $newDecId WHERE id = $booking_id");
            }
        }

        @$conn->query("UPDATE tbl_plots SET status = 'occupied' WHERE UPPER(TRIM(section)) = '{$secUpper}' AND plot_x = {$targetPlotX} AND plot_y = {$targetPlotY}");
        @$conn->query("UPDATE plots SET status = 'Occupied' WHERE UPPER(TRIM(section)) = '{$secUpper}' AND plot_x = {$targetPlotX} AND plot_y = {$targetPlotY}");

        $prevOccName = $intervalCheck['latest_name'] ?? 'existing occupant';
        $logDetails = "Section {$secUpper}, Plot {$targetPlotX}-{$targetPlotY} additional deceased {$dec_full_name} added (Patong) | Previous occupant: {$prevOccName} | Booking #{$booking_id} | Total: ₱" . number_format($total_amount, 2);
        $logStmt = $conn->prepare("INSERT INTO tbl_logs (action, details, timestamp, user_id) VALUES ('ADD_DECEASED', ?, NOW(), ?)");
        if ($logStmt) {
            $logStmt->bind_param('si', $logDetails, $user_id);
            $logStmt->execute();
            $logStmt->close();
        }
    }

    $conn->commit();

    try {
        if (file_exists(__DIR__ . '/notification_schema.php')) {
            require_once __DIR__ . '/notification_schema.php';
        } elseif (file_exists(__DIR__ . '/includes/notification_schema.php')) {
            require_once __DIR__ . '/includes/notification_schema.php';
        }

        if (file_exists(__DIR__ . '/includes/notification_helper.php')) {
            require_once __DIR__ . '/includes/notification_helper.php';
            if (function_exists('notify_booking_submitted')) {
                notify_booking_submitted($conn, (int)$booking_id);
            }
        } elseif (file_exists(__DIR__ . '/notification_helper.php')) {
            require_once __DIR__ . '/notification_helper.php';
            if (function_exists('notify_booking_submitted')) {
                notify_booking_submitted($conn, (int)$booking_id);
            }
        } elseif (file_exists(__DIR__ . '/mailer.php')) {
            include_once __DIR__ . '/mailer.php';
            if (function_exists('send_booking_confirmation_email')) {
                send_booking_confirmation_email((int)$booking_id, $conn);
            }
        }
    } catch (Throwable $notifEx) {
        error_log("Notification dispatch warning for booking #$booking_id: " . $notifEx->getMessage());
    }

    json_out([
        'success'           => true,
        'message'           => 'Booking submitted successfully!',
        'booking_id'        => $booking_id,
        'price'             => $price,
        'initial_payment'   => $initial_payment,
        'monthly_fee'       => $monthly_fee,
        'total_amount'      => $total_amount,
        'payment_method'    => $payment_method,
        'payment_plan'      => $payment_plan,
        'payment_term'      => $payment_term,
        'has_payment_proof' => !empty($proof_uploaded),
        'proof_reference'   => $proof_ref,
        'proof_filename'    => $proof_filename
    ]);
}

$error = $stmt->error ?: 'Unknown database error';
$stmt->close();
$conn->rollback();
if ($claimedPlot) {
    $release = $conn->prepare("UPDATE tbl_plots SET status = 'available' WHERE section = ? AND plot_x = ? AND plot_y = ? AND LOWER(status) = 'reserved'");
    if ($release) { $release->bind_param('sii', $section, $targetPlotX, $targetPlotY); $release->execute(); $release->close(); }
}
json_out(['success' => false, 'message' => 'Error submitting booking: ' . $error]);

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        try {
            $conn->rollback();
        } catch (Throwable $rbEx) {}
    }
    if (isset($claimedPlot) && $claimedPlot && isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
        try {
            $release = $conn->prepare("UPDATE tbl_plots SET status = 'available' WHERE section = ? AND plot_x = ? AND plot_y = ? AND LOWER(status) = 'reserved'");
            if ($release) { 
                $release->bind_param('sii', $section, $targetPlotX, $targetPlotY); 
                $release->execute(); 
                $release->close(); 
            }
        } catch (Throwable $ignore) {}
    }
    error_log("Booking submission failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    json_out([
        'success' => false,
        'message' => 'Unable to submit reservation: ' . $e->getMessage()
    ]);
}
