<?php
include 'connection.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$isAdmin = strtoupper(trim((string)($_SESSION['role'] ?? ''))) === 'ADMIN';
if ($isAdmin) {
    header("Location: dashboard.php?open=booking");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: my_bookings.php");
    exit;
}

$success_message = '';
$error_message = '';
$sections = ['A', 'B', 'C', 'D', 'E', 'F'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_booking'])) {
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $section = strtoupper(trim($_POST['section'] ?? ''));
    $plot = trim($_POST['plot'] ?? '');
    $contact_number = preg_replace('/\D+/', '', trim($_POST['contact_number'] ?? ''));
    $preferred_date = trim($_POST['preferred_date'] ?? '');
    $booker_location = trim($_POST['booker_location'] ?? '');
    $special_requests = trim($_POST['special_requests'] ?? '');
    $payment_method = trim($_POST['payment_method'] ?? 'cash');
    $pType = (strtolower($payment_method) === 'gcash') ? 'GCash' : 'Cash on Site';

    if (empty($section) || empty($plot) || empty($preferred_date) || empty($contact_number)) {
        $error_message = '<div class="alert alert-danger">Please fill in all required fields.</div>';
    } elseif (empty($_POST['terms_agreed'])) {
        $error_message = '<div class="alert alert-danger">You must read and agree to the Cemetery Terms and Agreements before submitting your reservation.</div>';
    } else {
        
        $uName = 'Valued Customer';
        $uEmail = '';
        $uStmt = $conn->query("SELECT first_name, last_name, email FROM tbl_user WHERE user_id = $user_id LIMIT 1");
        if ($uStmt && ($uRow = $uStmt->fetch_assoc())) {
            $uName = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
            $uEmail = trim($uRow['email'] ?? '');
        }

        
        $chkCols = [
            'service_type'   => "VARCHAR(50) NOT NULL DEFAULT 'Cemetery Service'",
            'grave_location' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'section'        => "VARCHAR(10) DEFAULT NULL",
            'plot'           => "VARCHAR(50) DEFAULT NULL",
            'preferred_date' => "DATE NOT NULL",
            'contact_number' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'booker_location'=> "VARCHAR(255) DEFAULT NULL",
            'special_requests'=> "TEXT DEFAULT NULL",
            'payment_type'   => "VARCHAR(50) DEFAULT 'Cash on Site'",
            'status'         => "VARCHAR(20) DEFAULT 'pending'",
            'user_id'        => "INT NOT NULL",
            'customer_name'  => "VARCHAR(255) DEFAULT NULL",
            'customer_email' => "VARCHAR(255) DEFAULT NULL",
            'full_name'      => "VARCHAR(255) DEFAULT NULL",
            'email'          => "VARCHAR(255) DEFAULT NULL",
            'purpose'        => "VARCHAR(30) DEFAULT 'cemetery_service'",
            'booking_type'   => "VARCHAR(40) DEFAULT 'general'",
            'total_amount'   => "DECIMAL(10,2) DEFAULT 0.00"
        ];
        foreach ($chkCols as $col => $def) {
            $chk = @$conn->query("SHOW COLUMNS FROM `tbl_bookings` LIKE '$col'");
            if ($chk && $chk->num_rows === 0) {
                @$conn->query("ALTER TABLE `tbl_bookings` ADD COLUMN `$col` $def");
            }
        }

        $grave_location = $section . ' ' . $plot;
        $svcType = 'Cemetery Service';
        $purposeVal = 'cemetery_service';
        $bType = 'general';
        $totAmt = 200.00;

        $ins = $conn->prepare("INSERT INTO tbl_bookings (service_type, grave_location, section, plot, preferred_date, contact_number, booker_location, special_requests, payment_type, status, user_id, customer_name, customer_email, full_name, email, purpose, booking_type, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($ins) {
            $ins->bind_param('sssssssssissssssd', $svcType, $grave_location, $section, $plot, $preferred_date, $contact_number, $booker_location, $special_requests, $pType, $user_id, $uName, $uEmail, $uName, $uEmail, $purposeVal, $bType, $totAmt);
            if ($ins->execute()) {
                $newBkId = (int)$ins->insert_id;
                $ins->close();
                if ($newBkId > 0 && function_exists('record_booking_log')) {
                    record_booking_log($conn, $newBkId, $section, $contact_number, $booker_location, $totAmt);
                }
                header("Location: my_bookings.php?success=1");
                exit;
            } else {
                $error_message = '<div class="alert alert-danger">Failed to submit reservation: ' . htmlspecialchars($ins->error) . '</div>';
                $ins->close();
            }
        } else {
            $error_message = '<div class="alert alert-danger">Database error: ' . htmlspecialchars($conn->error) . '</div>';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reserve a Service - Garden of Remembrance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    (function() {
      var _nativeAlert = window.alert;
      window.alert = function(msg) {
        if (typeof Swal !== 'undefined') {
          var text = String(msg == null ? '' : msg);
          var title = 'Notice';
          var icon = 'info';
          if (/error|failed|cannot|denied|restricted|invalid|not allowed|please enter|must be|required/i.test(text)) {
            title = /error|failed/i.test(text) ? 'Notice' : 'Attention';
            icon = 'warning';
          } else if (/success|completed|updated|booked|added|settled/i.test(text)) {
            title = 'Success';
            icon = 'success';
          }
          Swal.fire({
            icon: icon,
            title: title,
            text: text,
            confirmButtonColor: '#7b1113'
          });
        } else {
          _nativeAlert(msg);
        }
      };
    })();
    </script>
    <style>
        :root {
            --primary-color: #7b1113;
            --secondary-color: #a03a3c;
            --text-dark: #2c3e50;
            --text-light: #6c757d;
            --bg-white: #ffffff;
            --bg-light: #f8f9fa;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: 100vh;
            color: var(--text-dark);
        }

        /* Header */
        .booking-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 2rem 0;
            box-shadow: 0 4px 20px rgba(123, 17, 19, 0.3);
        }

        .header-content {
            text-align: center;
        }

        .header-content h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .header-content p {
            font-size: 1.1rem;
            opacity: 0.9;
            margin: 0;
        }

        /* Navigation */
        .booking-nav {
            background: var(--bg-white);
            padding: 1rem 0;
            border-bottom: 1px solid #dee2e6;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        .nav-links {
            display: flex;
            justify-content: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .nav-link {
            color: var(--text-dark);
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-link:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        /* Main Container */
        .booking-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        /* Booking Form */
        .booking-form {
            background: var(--bg-white);
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .form-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .form-section {
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-label {
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 0.75rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(123, 17, 19, 0.25);
        }

        .form-text {
            font-size: 0.9rem;
            color: var(--text-light);
            margin-top: 0.25rem;
        }

        /* Submit Button */
        .submit-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            border-radius: 12px;
            padding: 1rem 2rem;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(123, 17, 19, 0.3);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        /* Service Types */
        .service-types {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .service-card {
            background: var(--bg-light);
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .service-card:hover {
            border-color: var(--primary-color);
            background: rgba(123, 17, 19, 0.05);
        }

        .service-card.selected {
            border-color: var(--primary-color);
            background: rgba(123, 17, 19, 0.1);
        }

        .service-icon {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .service-name {
            font-weight: 600;
            color: var(--text-dark);
        }

        .service-price {
            font-size: 0.9rem;
            color: var(--text-light);
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .header-content h1 {
                font-size: 2rem;
            }

            .booking-container {
                margin: 1rem auto;
            }

            .booking-form {
                padding: 1.5rem;
            }

            .service-types {
                grid-template-columns: 1fr;
            }
        }

        /* Back Button */
        .back-btn {
            position: fixed;
            bottom: 2rem;
            left: 2rem;
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 1rem 1.5rem;
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
            color: white;
        }

        @media (max-width: 768px) {
            .back-btn {
                bottom: 1rem;
                left: 1rem;
                padding: 0.8rem 1.2rem;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    
    <header class="booking-header">
        <div class="container">
            <div class="header-content">
                <h1>Reserve a Service</h1>
                <p>Request burial, memorial, or maintenance services</p>
            </div>
        </div>
    </header>

    
    <nav class="booking-nav">
        <div class="nav-container">
            <div class="nav-links">
                <a href="user_dashboard.php" class="nav-link">
                    <i class="fas fa-home"></i>
                    Dashboard
                </a>
                <a href="user_dashboard.php" class="nav-link">
                    <i class="fas fa-calendar-plus"></i>
                    Reserve Service
                </a>
                <a href="map.php" class="nav-link">
                    <i class="fas fa-map"></i>
                    Cemetery Map
                </a>
                <a href="logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </div>
        </div>
    </nav>

    
    <main class="booking-container">
        <?php echo $success_message; ?>
        <?php echo $error_message; ?>

        <div class="booking-form">
            <h2 class="form-title">Service Reservation Form</h2>
            
            <form method="POST" action="">
                
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-map-marker-alt"></i>
                        Location Details
                    </h3>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="section">Section</label>
                            <select class="form-select" name="section" id="section" required>
                                <option value="">Select Section</option>
                                <?php foreach ($sections as $section): ?>
                                    <option value="<?php echo htmlspecialchars($section); ?>"><?php echo htmlspecialchars($section); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="plot">Plot Number</label>
                            <input type="text" class="form-control" name="plot" id="plot" placeholder="e.g., A-1-1" autocomplete="off" required>
                            <div class="form-text">Format: Section-Row-Number (e.g., A-1-1)</div>
                        </div>
                    </div>
                </div>

                
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-phone"></i>
                        Contact Information
                    </h3>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="contact_number">Contact Number</label>
                            <input type="tel" class="form-control" name="contact_number" id="contact_number" placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" maxlength="11" minlength="11" autocomplete="tel" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="preferred_date">Preferred Date</label>
                            <input type="date" class="form-control" name="preferred_date" id="preferred_date" autocomplete="bday" required>
                            <div class="form-text">Select your preferred service date</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="booker_location">Your Location / Address <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="booker_location" id="booker_location" placeholder="Your address/location" autocomplete="street-address" data-ph-address-selector="true" required>
                    </div>
                </div>

                
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-comment"></i>
                        Special Requests
                    </h3>
                    <div class="mb-3">
                        <label class="form-label" for="special_requests">Additional Requirements</label>
                        <textarea class="form-control" name="special_requests" id="special_requests" rows="4" placeholder="Any specific requirements or special arrangements needed..."></textarea>
                        <div class="form-text">Optional: Let us know if you have any special requests</div>
                    </div>
                </div>

                
                
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-credit-card"></i>
                        Payment Method
                    </h3>
                    <div class="mb-3">
                        <label class="form-label" for="payment_method">Choose Payment Method</label>
                        <select class="form-select" name="payment_method" id="payment_method" autocomplete="off">
                            <option value="cash">Cash on Site</option>
                            <option value="gcash">GCash</option>
                        </select>
                        <div class="form-text">Pay securely via GCash.</div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="p-3 rounded mb-3" style="background: #fdfbf7; border: 1px solid #f1e4d3;">
                        <div class="form-check d-flex align-items-start gap-2 mb-0">
                            <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" id="terms_agreed" name="terms_agreed" value="1" required style="cursor: pointer; width: 1.15em; height: 1.15em; border-color: #7b1113;">
                            <label class="form-check-label small text-dark" for="terms_agreed" style="cursor: pointer; line-height: 1.45;">
                                I have read, understood, and agree to the 
                                <a href="javascript:void(0)" class="fw-bold text-decoration-underline" style="color: #7b1113;" data-bs-toggle="modal" data-bs-target="#cemeteryTermsModal">Terms and Agreements</a> 
                                and Cemetery Policies of <strong>Garden of Remembrance Memorial Park</strong>. <span class="text-danger">*</span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" name="submit_booking" class="submit-btn">
                        <i class="fas fa-calendar-check"></i>
                        Submit Reservation Request
                    </button>
                </div>
            </form>
        </div>
    </main>

    <!-- Cemetery Terms and Agreements Modal -->
    <div class="modal fade" id="cemeteryTermsModal" tabindex="-1" aria-labelledby="cemeteryTermsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: min(650px, 94vw);">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); color: #fff;">
                    <h5 class="modal-title fw-bold m-0" id="cemeteryTermsModalLabel">
                        <i class="fas fa-file-contract me-2 text-warning"></i> Terms & Agreements
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 bg-light small" style="line-height: 1.6; color: #334155;">
                    <div class="p-3 bg-white rounded border mb-3 shadow-sm">
                        <h6 class="fw-bold text-dark mb-2"><i class="fas fa-landmark text-primary me-2"></i>1. Plot Rights & Cemetery Authority</h6>
                        <p class="mb-0">A burial plot purchase or reservation conveys exclusive perpetual interment rights under the rules and regulations of Garden of Remembrance Memorial Park. Fee simple title to the cemetery grounds and facilities remains perpetually with the memorial park administration.</p>
                    </div>

                    <div class="p-3 bg-white rounded border mb-3 shadow-sm">
                        <h6 class="fw-bold text-dark mb-2"><i class="fas fa-money-check-alt text-success me-2"></i>2. Payment, Installments & Verification</h6>
                        <p class="mb-0">All reservations are subject to administrator verification. Cash and online payments must provide legitimate transaction references. For installment contracts, the mandatory downpayment and agreed monthly amortizations must be satisfied on schedule to keep the reservation active.</p>
                    </div>

                    <div class="p-3 bg-white rounded border mb-3 shadow-sm" style="border-left: 4px solid #dc3545 !important;">
                        <h6 class="fw-bold text-danger mb-2"><i class="fas fa-calendar-times text-danger me-2"></i>3. Three (3) Months Non-Payment Cancellation Policy</h6>
                        <p class="mb-0"><strong>Automatic Cancellation:</strong> If a client fails to pay or settles no payment for three (3) consecutive months (90 days of non-payment or overdue installment amortizations), the reservation will be automatically cancelled. The reserved plot will be immediately forfeited, released, and made available for other buyers. In accordance with memorial park policy, all previous payments and reservation fees are strictly non-refundable.</p>
                    </div>

                    <div class="p-3 bg-white rounded border mb-3 shadow-sm">
                        <h6 class="fw-bold text-dark mb-2"><i class="fas fa-cross text-danger me-2"></i>4. Interment & Layering (Patong) Regulations</h6>
                        <p class="mb-0">Secondary interment (layering / patong) requires registered owner or legal family consent and compliance with sanitary interval standards (8-10 years interval or approved vault-sealed tiering). Capacity cannot exceed 4 occupants per standard lawn lot.</p>
                    </div>

                    <div class="p-3 bg-white rounded border mb-3 shadow-sm">
                        <h6 class="fw-bold text-dark mb-2"><i class="fas fa-seedling text-success me-2"></i>5. Park Maintenance, Markers & Decorum</h6>
                        <p class="mb-0">General landscaping, mowing, and structural maintenance are conducted exclusively by authorized cemetery personnel. Gravestones, markers, and decorations must conform to park design guidelines. Visitors must observe park visiting hours, cleanliness, and peace at all times.</p>
                    </div>

                    <div class="p-3 bg-white rounded border shadow-sm">
                        <h6 class="fw-bold text-dark mb-2"><i class="fas fa-user-shield text-info me-2"></i>6. Data Privacy Act Compliance</h6>
                        <p class="mb-0">All personal information collected is handled in strict compliance with the Data Privacy Act of 2012 (R.A. 10173) and will be utilized strictly for cemetery booking, reservation records, and official communications.</p>
                    </div>
                </div>
                <div class="modal-footer bg-white py-2 px-4 d-flex justify-content-between">
                    <span class="text-muted small"><i class="fas fa-shield-alt text-success me-1"></i> Official Park Policy</span>
                    <button type="button" class="btn btn-secondary px-4 fw-semibold" data-bs-dismiss="modal">I Understand</button>
                </div>
            </div>
        </div>
    </div>

    
    <a href="user_dashboard.php" class="back-btn">
        <i class="fas fa-arrow-left"></i>
        Back to Dashboard
    </a>

    <script>
        // Set minimum date to today
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('preferred_date');
            const today = new Date().toISOString().split('T')[0];
            dateInput.min = today;
            
            // Contact number validation (always starts with 09, exactly 11 digits)
            const contactInput = document.getElementById('contact_number');
            if (contactInput) {
                function sanitizeContact(val) {
                    let digits = String(val || '').replace(/\D/g, '');
                    if (!digits) return '';
                    if (digits.startsWith('639')) digits = '09' + digits.substring(3);
                    else if (digits.startsWith('9')) digits = '09' + digits.substring(1);
                    else if (digits.startsWith('0') && digits.length >= 2 && digits.charAt(1) !== '9') digits = '09' + digits.substring(2);
                    else if (!digits.startsWith('09')) digits = '09' + digits;
                    return digits.slice(0, 11);
                }

                contactInput.addEventListener('focus', function() {
                    if (!this.value || this.value.trim() === '') this.value = '09';
                    else this.value = sanitizeContact(this.value);
                });

                contactInput.addEventListener('input', function() {
                    let digits = this.value.replace(/\D/g, '');
                    if (!digits) { this.value = '09'; return; }
                    this.value = sanitizeContact(this.value);
                });

                contactInput.addEventListener('keydown', function(e) {
                    if ((e.key === 'Backspace' || e.key === 'Delete') && (this.selectionStart <= 2 && this.selectionEnd <= 2)) {
                        e.preventDefault();
                        this.setSelectionRange(2, 2);
                    }
                });

                contactInput.addEventListener('blur', function() {
                    if (this.value === '09' && !this.hasAttribute('required')) this.value = '';
                    else if (this.value) this.value = sanitizeContact(this.value);
                });

                if (contactInput.value) {
                    contactInput.value = sanitizeContact(contactInput.value);
                }
            }
        });

        // Form submission validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const contactInput = document.getElementById('contact_number');
            const contactValue = contactInput.value.replace(/\s/g, '');
            
            if (!/^09\d{9}$/.test(contactValue)) {
                e.preventDefault();
                alert('Contact number must be an 11-digit mobile number starting with 09 (e.g., 09XXXXXXXXX)');
                contactInput.focus();
                return false;
            }
        });
    </script>

    <script src="assets/js/ph_address_selector.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
