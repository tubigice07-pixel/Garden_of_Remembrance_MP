<?php
/**
 * Garden of Remembrance Memorial Park CMIS
 * Database Schema & Data Dictionary Downloader (.doc format)
 * Pure Monochrome Black & White - Publication Ready
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isCli = (php_sapi_name() === 'cli');
$docTitle = "Garden_of_Remembrance_CMIS_Database_Schema.doc";

if (!$isCli) {
    header("Content-Type: application/vnd.ms-word; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"" . $docTitle . "\"");
    header("Cache-Control: private, max-age=0, must-revalidate");
    header("Pragma: public");
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
}

ob_start();
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:w="urn:schemas-microsoft-com:office:word"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<!--[if gte mso 9]>
<xml>
  <w:WordDocument>
    <w:View>Print</w:View>
    <w:Zoom>100</w:Zoom>
    <w:DoNotOptimizeForBrowser/>
  </w:WordDocument>
</xml>
<![endif]-->
<style>
  @page {
    size: 8.5in 11.0in; /* Portrait */
    margin: 0.8in 0.8in 0.8in 0.8in;
    mso-header-margin: 0.4in;
    mso-footer-margin: 0.4in;
  }
  body {
    font-family: Arial, sans-serif;
    font-size: 9pt;
    line-height: 1.3;
    color: #000000;
    background-color: #ffffff;
  }
  .title-block {
    text-align: center;
    margin-bottom: 20pt;
    padding-bottom: 10pt;
    border-bottom: 2px solid #000000;
  }
  .title-main {
    font-size: 15pt;
    font-weight: bold;
    text-transform: uppercase;
    color: #000000;
  }
  .title-sub {
    font-size: 11pt;
    font-weight: bold;
    color: #000000;
    margin-top: 3pt;
  }
  
  .table-title {
    font-size: 11pt;
    font-weight: bold;
    color: #000000;
    margin-top: 16pt;
    margin-bottom: 4pt;
    font-family: Arial, sans-serif;
  }
  
  /* Strictly Monochrome Black and White Tables */
  table.schema-table {
    width: 100%;
    border-collapse: collapse;
    border: 1.5px solid #000000;
    margin-bottom: 14pt;
    background-color: #ffffff;
    page-break-inside: avoid;
  }
  table.schema-table th {
    background-color: #ffffff;
    color: #000000;
    font-size: 8.5pt;
    font-weight: bold;
    text-align: left;
    padding: 5px 6px;
    border: 1px solid #000000;
    text-transform: uppercase;
  }
  table.schema-table td {
    font-size: 8pt;
    color: #000000;
    padding: 4px 6px;
    border: 1px solid #000000;
    vertical-align: top;
  }
  table.schema-table td.col-mono {
    font-family: 'Lucida Console', 'Courier New', monospace;
  }
  table.schema-table td.col-center {
    text-align: center;
    font-weight: bold;
  }
</style>
</head>
<body>

<div class="title-block">
  <div class="title-main">Cemetery Management and Grave Mapping System</div>
  <div class="title-sub">Data Dictionary &amp; Relational Database Schema Specifications</div>
</div>

<!-- 1. tbl_user -->
<div class="table-title">1. tbl_user (User Accounts &amp; Authentication)</div>
<table class="schema-table">
  <thead>
    <tr>
      <th style="width: 22%;">Field Name</th>
      <th style="width: 15%;">Data Type</th>
      <th style="width: 8%;">Null</th>
      <th style="width: 8%;">Key</th>
      <th style="width: 15%;">Default</th>
      <th style="width: 32%;">Description</th>
    </tr>
  </thead>
  <tbody>
    <tr><td class="col-mono">user_id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center">PK</td><td>AUTO_INCREMENT</td><td>Unique identifier for user account</td></tr>
    <tr><td class="col-mono">role</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Authorization role (Admin, Staff, Client)</td></tr>
    <tr><td class="col-mono">first_name</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Given first name (Atomic)</td></tr>
    <tr><td class="col-mono">middle_name</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Middle name (Optional)</td></tr>
    <tr><td class="col-mono">last_name</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Surname / family name (Atomic)</td></tr>
    <tr><td class="col-mono">suffix</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">YES</td><td class="col-center"></td><td>''</td><td>Generational suffix (Jr., Sr., III)</td></tr>
    <tr><td class="col-mono">birthday</td><td class="col-mono">DATE</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Date of birth</td></tr>
    <tr><td class="col-mono">gender</td><td class="col-mono">VARCHAR(10)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Gender identification</td></tr>
    <tr><td class="col-mono">email</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">NO</td><td class="col-center">UK</td><td>None</td><td>Unique email address for authentication</td></tr>
    <tr><td class="col-mono">password</td><td class="col-mono">VARCHAR(250)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Hashed password string</td></tr>
    <tr><td class="col-mono">phone_no</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">YES</td><td class="col-center"></td><td>''</td><td>Mobile contact telephone number</td></tr>
    <tr><td class="col-mono">profile_picture</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Server path to uploaded profile image</td></tr>
    <tr><td class="col-mono">account_number</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Customer account code</td></tr>
    <tr><td class="col-mono">email_verified</td><td class="col-mono">TINYINT(1)</td><td class="col-center">YES</td><td class="col-center"></td><td>0</td><td>Verification flag (1=Verified, 0=Pending)</td></tr>
    <tr><td class="col-mono">verification_token</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Email verification security token</td></tr>
    <tr><td class="col-mono">verification_otp</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>One-time pin for authentication</td></tr>
    <tr><td class="col-mono">otp_expiry</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Expiration timestamp for OTP</td></tr>
    <tr><td class="col-mono">reset_otp</td><td class="col-mono">VARCHAR(10)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Password recovery OTP string</td></tr>
    <tr><td class="col-mono">reset_otp_expiry</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Expiration timestamp for reset OTP</td></tr>
    <tr><td class="col-mono">verification_expires</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Verification link expiration timestamp</td></tr>
    <tr><td class="col-mono">reset_token</td><td class="col-mono">VARCHAR(128)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Password reset URL token</td></tr>
    <tr><td class="col-mono">reset_expires</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Password reset token expiration time</td></tr>
    <tr><td class="col-mono">verification_attempts</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-center"></td><td>0</td><td>Counter for invalid auth attempts</td></tr>
  </tbody>
</table>

<!-- 2. tbl_admin -->
<div class="table-title">2. tbl_admin (Administrator Profiles &bull; 1:1 Subtype)</div>
<table class="schema-table">
  <thead>
    <tr>
      <th style="width: 22%;">Field Name</th>
      <th style="width: 15%;">Data Type</th>
      <th style="width: 8%;">Null</th>
      <th style="width: 8%;">Key</th>
      <th style="width: 15%;">Default</th>
      <th style="width: 32%;">Description</th>
    </tr>
  </thead>
  <tbody>
    <tr><td class="col-mono">admin_id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center">PK</td><td>AUTO_INCREMENT</td><td>Unique administrator identifier</td></tr>
    <tr><td class="col-mono">user_id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center">FK, UK</td><td>None</td><td>Foreign key to tbl_user (Strict 1:1 relation)</td></tr>
    <tr><td class="col-mono">full_name</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Full name of administrator</td></tr>
    <tr><td class="col-mono">email</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Administrative contact email</td></tr>
    <tr><td class="col-mono">phone_no</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Administrative mobile telephone number</td></tr>
    <tr><td class="col-mono">role</td><td class="col-mono">VARCHAR(30)</td><td class="col-center">NO</td><td class="col-center"></td><td>'Admin'</td><td>Administrative role privilege tier</td></tr>
    <tr><td class="col-mono">created_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Account creation timestamp</td></tr>
    <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Last profile update timestamp</td></tr>
  </tbody>
</table>

<!-- 3. tbl_guest_reservation_requests -->
<div class="table-title">3. tbl_guest_reservation_requests (Guest Reservations &bull; 1:1 Booking)</div>
<table class="schema-table">
  <thead>
    <tr>
      <th style="width: 22%;">Field Name</th>
      <th style="width: 15%;">Data Type</th>
      <th style="width: 8%;">Null</th>
      <th style="width: 8%;">Key</th>
      <th style="width: 15%;">Default</th>
      <th style="width: 32%;">Description</th>
    </tr>
  </thead>
  <tbody>
    <tr><td class="col-mono">id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center">PK</td><td>AUTO_INCREMENT</td><td>Unique guest request identifier</td></tr>
    <tr><td class="col-mono">user_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-center">FK</td><td>NULL</td><td>Foreign key to tbl_user upon account creation</td></tr>
    <tr><td class="col-mono">plot_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-center">FK</td><td>NULL</td><td>Foreign key to reserved tbl_plots</td></tr>
    <tr><td class="col-mono">booking_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-center">FK, UK</td><td>NULL</td><td>Converted booking ID (Strict 1:1 conversion)</td></tr>
    <tr><td class="col-mono">approved_by</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-center">FK</td><td>NULL</td><td>Staff/admin user who approved request</td></tr>
    <tr><td class="col-mono">rejected_by</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-center">FK</td><td>NULL</td><td>Staff/admin user who rejected request</td></tr>
    <tr><td class="col-mono">full_name</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Full name of requesting guest</td></tr>
    <tr><td class="col-mono">email</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Email address of applicant</td></tr>
    <tr><td class="col-mono">contact_number</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Phone contact number</td></tr>
    <tr><td class="col-mono">address</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Residential address</td></tr>
    <tr><td class="col-mono">section</td><td class="col-mono">VARCHAR(10)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Requested cemetery section (A to F)</td></tr>
    <tr><td class="col-mono">plot_x</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Interactive map grid X coordinate</td></tr>
    <tr><td class="col-mono">plot_y</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Interactive map grid Y coordinate</td></tr>
    <tr><td class="col-mono">plot_code</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Plot display code (e.g. A-005)</td></tr>
    <tr><td class="col-mono">purpose</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">NO</td><td class="col-center"></td><td>'Future Memorial Lot'</td><td>Reservation intent/purpose</td></tr>
    <tr><td class="col-mono">preferred_date</td><td class="col-mono">DATE</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Target appointment / interment date</td></tr>
    <tr><td class="col-mono">notes</td><td class="col-mono">TEXT</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Applicant special notes</td></tr>
    <tr><td class="col-mono">admin_notes</td><td class="col-mono">TEXT</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Administrative review remarks</td></tr>
    <tr><td class="col-mono">account_creation_token_hash</td><td class="col-mono">VARCHAR(64)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Hash of account completion invitation token</td></tr>
    <tr><td class="col-mono">token_expires_at</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Expiration timestamp for invitation token</td></tr>
    <tr><td class="col-mono">token_used_at</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Timestamp when token was consumed</td></tr>
    <tr><td class="col-mono">email_sent</td><td class="col-mono">TINYINT(1)</td><td class="col-center">NO</td><td class="col-center"></td><td>0</td><td>1 if confirmation email was dispatched</td></tr>
    <tr><td class="col-mono">email_error</td><td class="col-mono">TEXT</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Error message if email dispatch failed</td></tr>
    <tr><td class="col-mono">approved_at</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Approval timestamp</td></tr>
    <tr><td class="col-mono">rejected_at</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Rejection timestamp</td></tr>
    <tr><td class="col-mono">cancelled_at</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Cancellation timestamp</td></tr>
    <tr><td class="col-mono">cancelled_by</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>User/staff who initiated cancellation</td></tr>
    <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Last record modification timestamp</td></tr>
  </tbody>
</table>

<!-- 4. tbl_bookings -->
<div class="table-title">4. tbl_bookings (Master Booking Contracts)</div>
<table class="schema-table">
  <thead>
    <tr>
      <th style="width: 22%;">Field Name</th>
      <th style="width: 15%;">Data Type</th>
      <th style="width: 8%;">Null</th>
      <th style="width: 8%;">Key</th>
      <th style="width: 15%;">Default</th>
      <th style="width: 32%;">Description</th>
    </tr>
  </thead>
  <tbody>
    <tr><td class="col-mono">id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center">PK</td><td>AUTO_INCREMENT</td><td>Master contract booking number</td></tr>
    <tr><td class="col-mono">user_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-center">FK</td><td>NULL</td><td>Foreign key to client tbl_user</td></tr>
    <tr><td class="col-mono">plot_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-center">FK</td><td>NULL</td><td>Foreign key to reserved tbl_plots</td></tr>
    <tr><td class="col-mono">deceased_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-center">FK</td><td>NULL</td><td>Foreign key to interred tbl_deceased</td></tr>
    <tr><td class="col-mono">service_type</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Type of service (Grave Reservation, Burial)</td></tr>
    <tr><td class="col-mono">grave_location</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Human readable text description of plot location</td></tr>
    <tr><td class="col-mono">plot_type</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Lawn type / tier specification</td></tr>
    <tr><td class="col-mono">section</td><td class="col-mono">VARCHAR(10)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Section code (A-F)</td></tr>
    <tr><td class="col-mono">plot</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Plot identifier string</td></tr>
    <tr><td class="col-mono">section_plot</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Combined section-plot code</td></tr>
    <tr><td class="col-mono">preferred_date</td><td class="col-mono">DATE</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Target interment or reservation date</td></tr>
    <tr><td class="col-mono">contact_number</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Client contact telephone number</td></tr>
    <tr><td class="col-mono">booker_location</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Address of booking client</td></tr>
    <tr><td class="col-mono">special_requests</td><td class="col-mono">TEXT</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Specific family requests or instructions</td></tr>
    <tr><td class="col-mono">remarks</td><td class="col-mono">TEXT</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Administrative remarks on booking</td></tr>
    <tr><td class="col-mono">payment_type</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Payment method: GCash, Cash, Office</td></tr>
    <tr><td class="col-mono">reference_number</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Transaction tracking reference string</td></tr>
    <tr><td class="col-mono">gcash_reference_number</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>13-digit GCash receipt reference</td></tr>
    <tr><td class="col-mono">payment_receipt_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Reference to payment receipt record</td></tr>
    <tr><td class="col-mono">created_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Booking creation timestamp</td></tr>
    <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Last status update timestamp</td></tr>
  </tbody>
</table>

<!-- 5. tbl_booking_payment_schedule -->
<div class="table-title">5. tbl_booking_payment_schedule (Installment Milestones)</div>
<table class="schema-table">
  <thead>
    <tr>
      <th style="width: 22%;">Field Name</th>
      <th style="width: 15%;">Data Type</th>
      <th style="width: 8%;">Null</th>
      <th style="width: 8%;">Key</th>
      <th style="width: 15%;">Default</th>
      <th style="width: 32%;">Description</th>
    </tr>
  </thead>
  <tbody>
    <tr><td class="col-mono">id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center">PK</td><td>AUTO_INCREMENT</td><td>Unique installment milestone ID</td></tr>
    <tr><td class="col-mono">booking_id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center">FK</td><td>None</td><td>Foreign key to tbl_bookings</td></tr>
    <tr><td class="col-mono">installment_number</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Milestone sequence (1=Downpayment, 2..N=Months)</td></tr>
    <tr><td class="col-mono">due_date</td><td class="col-mono">DATE</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Payment deadline for installment</td></tr>
    <tr><td class="col-mono">amount_due</td><td class="col-mono">DECIMAL(10,2)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Amount due in Philippine Peso (PHP)</td></tr>
    <tr><td class="col-mono">amount_paid</td><td class="col-mono">DECIMAL(10,2)</td><td class="col-center">NO</td><td class="col-center"></td><td>0.00</td><td>Verified amount paid</td></tr>
    <tr><td class="col-mono">status</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">NO</td><td class="col-center"></td><td>'UNPAID'</td><td>Installment status: UNPAID, PAID, OVERDUE</td></tr>
    <tr><td class="col-mono">paid_at</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Timestamp payment was verified</td></tr>
    <tr><td class="col-mono">payment_method</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Payment channel: GCash, Cash</td></tr>
    <tr><td class="col-mono">transaction_reference</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Internal transaction tracking code</td></tr>
    <tr><td class="col-mono">gcash_reference_number</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>13-digit official GCash reference number</td></tr>
    <tr><td class="col-mono">payment_receipt_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-center">FK</td><td>NULL</td><td>Foreign key to generated payment receipt</td></tr>
    <tr><td class="col-mono">created_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Creation timestamp</td></tr>
    <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Last status change timestamp</td></tr>
  </tbody>
</table>

<!-- 6. tbl_deceased -->
<div class="table-title">6. tbl_deceased (Deceased Profiles)</div>
<table class="schema-table">
  <thead>
    <tr>
      <th style="width: 22%;">Field Name</th>
      <th style="width: 15%;">Data Type</th>
      <th style="width: 8%;">Null</th>
      <th style="width: 8%;">Key</th>
      <th style="width: 15%;">Default</th>
      <th style="width: 32%;">Description</th>
    </tr>
  </thead>
  <tbody>
    <tr><td class="col-mono">id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center">PK</td><td>AUTO_INCREMENT</td><td>Unique deceased record identifier</td></tr>
    <tr><td class="col-mono">user_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-center">FK</td><td>NULL</td><td>Foreign key to responsible client tbl_user</td></tr>
    <tr><td class="col-mono">full_name</td><td class="col-mono">VARCHAR(150)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Full name of deceased person</td></tr>
    <tr><td class="col-mono">birth_date</td><td class="col-mono">DATE</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Date of birth</td></tr>
    <tr><td class="col-mono">date_of_death</td><td class="col-mono">DATE</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Date of death</td></tr>
    <tr><td class="col-mono">place_of_birth</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Municipality / City of birth</td></tr>
    <tr><td class="col-mono">family_contact_no</td><td class="col-mono">VARCHAR(25)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Contact mobile number of next-of-kin</td></tr>
    <tr><td class="col-mono">funeral_service</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Mortuary / Funeral service provider</td></tr>
    <tr><td class="col-mono">section</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Cemetery section code (A to F)</td></tr>
    <tr><td class="col-mono">plot_x</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Interactive digital map X coordinate</td></tr>
    <tr><td class="col-mono">plot_y</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Interactive digital map Y coordinate</td></tr>
    <tr><td class="col-mono">created_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Record registration timestamp</td></tr>
    <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Last update timestamp</td></tr>
  </tbody>
</table>

<!-- 7. tbl_plot_pricing -->
<div class="table-title">7. tbl_plot_pricing (Plot Payment Terms)</div>
<table class="schema-table">
  <thead>
    <tr>
      <th style="width: 22%;">Field Name</th>
      <th style="width: 15%;">Data Type</th>
      <th style="width: 8%;">Null</th>
      <th style="width: 8%;">Key</th>
      <th style="width: 15%;">Default</th>
      <th style="width: 32%;">Description</th>
    </tr>
  </thead>
  <tbody>
    <tr><td class="col-mono">id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center">PK</td><td>AUTO_INCREMENT</td><td>Unique pricing term identifier</td></tr>
    <tr><td class="col-mono">plot_id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center">FK</td><td>None</td><td>Foreign key to tbl_plots</td></tr>
    <tr><td class="col-mono">payment_term</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Term duration in months (0=Cash, 12, 24, 36)</td></tr>
    <tr><td class="col-mono">created_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Creation timestamp</td></tr>
    <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Last price update timestamp</td></tr>
  </tbody>
</table>

<!-- 8. tbl_plots -->
<div class="table-title">8. tbl_plots (Cemetery Plots Grid)</div>
<table class="schema-table">
  <thead>
    <tr>
      <th style="width: 22%;">Field Name</th>
      <th style="width: 15%;">Data Type</th>
      <th style="width: 8%;">Null</th>
      <th style="width: 8%;">Key</th>
      <th style="width: 15%;">Default</th>
      <th style="width: 32%;">Description</th>
    </tr>
  </thead>
  <tbody>
    <tr><td class="col-mono">id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center">PK</td><td>AUTO_INCREMENT</td><td>Unique plot identifier</td></tr>
    <tr><td class="col-mono">section</td><td class="col-mono">VARCHAR(10)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Cemetery section code (Sections A to F)</td></tr>
    <tr><td class="col-mono">plot_x</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Interactive digital canvas X coordinate</td></tr>
    <tr><td class="col-mono">plot_y</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center"></td><td>None</td><td>Interactive digital canvas Y coordinate</td></tr>
    <tr><td class="col-mono">plot_no</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">NO</td><td class="col-center">UK</td><td>None</td><td>Human readable plot number (e.g. A-001)</td></tr>
    <tr><td class="col-mono">lot_type</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Lot classification (Standard Lawn, Premium)</td></tr>
    <tr><td class="col-mono">created_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Plot registration timestamp</td></tr>
    <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Last record modification timestamp</td></tr>
  </tbody>
</table>

<!-- 9. tbl_plot_deceased_mapping -->
<div class="table-title">9. tbl_plot_deceased_mapping (Plot &amp; Occupancy Bridge)</div>
<table class="schema-table">
  <thead>
    <tr>
      <th style="width: 22%;">Field Name</th>
      <th style="width: 15%;">Data Type</th>
      <th style="width: 8%;">Null</th>
      <th style="width: 8%;">Key</th>
      <th style="width: 15%;">Default</th>
      <th style="width: 32%;">Description</th>
    </tr>
  </thead>
  <tbody>
    <tr><td class="col-mono">id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center">PK</td><td>AUTO_INCREMENT</td><td>Unique mapping identifier</td></tr>
    <tr><td class="col-mono">plot_id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-center">FK</td><td>None</td><td>Foreign key to tbl_plots</td></tr>
    <tr><td class="col-mono">user_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-center">FK</td><td>NULL</td><td>Foreign key to plot owner / client tbl_user</td></tr>
    <tr><td class="col-mono">reservation_date</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-center"></td><td>current_timestamp()</td><td>Initial reservation timestamp</td></tr>
    <tr><td class="col-mono">expiry_date</td><td class="col-mono">DATE</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Reservation hold expiration deadline</td></tr>
    <tr><td class="col-mono">notes</td><td class="col-mono">TEXT</td><td class="col-center">YES</td><td class="col-center"></td><td>NULL</td><td>Plot occupancy / sexton notes</td></tr>
    <tr><td class="col-mono">created_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Mapping creation timestamp</td></tr>
    <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-center"></td><td>current_timestamp()</td><td>Last status update timestamp</td></tr>
  </tbody>
</table>

</body>
</html>
<?php
$htmlContent = ob_get_clean();

if ($isCli) {
    $outPath = __DIR__ . '/' . $docTitle;
    file_put_contents($outPath, "\xEF\xBB\xBF" . $htmlContent);
    echo "Saved to: " . $outPath . "\n";
} else {
    echo $htmlContent;
    exit;
}
