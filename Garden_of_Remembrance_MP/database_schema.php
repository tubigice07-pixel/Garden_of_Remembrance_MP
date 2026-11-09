<?php
/**
 * Garden of Remembrance Memorial Park CMIS
 * Database Schema & Data Dictionary Viewer (Monochrome Black & White)
 * Accessible at: http://localhost/Garden_of_Remembrance_MP/database_schema.php
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cemetery Management and Grave Mapping System - Database Schema</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
  * {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
  }
  body {
    font-family: 'Inter', Arial, sans-serif;
    background-color: #ffffff;
    color: #000000;
    line-height: 1.4;
    padding-top: 60px;
    padding-bottom: 60px;
  }
  
  /* Top Fixed Toolbar */
  header.toolbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 52px;
    background: #ffffff;
    border-bottom: 2px solid #000000;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 32px;
    z-index: 1000;
  }
  .toolbar-title h1 {
    font-size: 14.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: -0.2px;
  }
  .toolbar-actions {
    display: flex;
    gap: 10px;
    align-items: center;
  }
  .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    font-size: 11.5px;
    font-weight: 700;
    border-radius: 4px;
    border: 1.5px solid #000000;
    background: #ffffff;
    color: #000000;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s ease;
  }
  .btn:hover {
    background: #000000;
    color: #ffffff;
  }
  .btn-black {
    background: #000000;
    color: #ffffff;
  }
  .btn-black:hover {
    background: #ffffff;
    color: #000000;
  }

  .container {
    max-width: 1100px;
    margin: 24px auto;
    padding: 0 24px;
  }

  .doc-title-block {
    text-align: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 2px solid #000000;
  }
  .doc-title-block h1 {
    font-size: 18px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .doc-title-block h2 {
    font-size: 13px;
    font-weight: 600;
    color: #333333;
    margin-top: 2px;
  }

  /* Quick Navigation Bar */
  .quick-nav {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 24px;
    padding: 10px;
    border: 1.5px solid #000000;
    background: #fafafa;
  }
  .nav-chip {
    padding: 4px 10px;
    font-size: 11px;
    font-family: 'JetBrains Mono', monospace;
    font-weight: 600;
    border: 1px solid #000000;
    color: #000000;
    text-decoration: none;
    background: #ffffff;
  }
  .nav-chip:hover {
    background: #000000;
    color: #ffffff;
  }

  /* Table Specification Box */
  .table-section {
    margin-bottom: 32px;
    page-break-inside: avoid;
  }
  .table-header-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border: 2px solid #000000;
    border-bottom: none;
    padding: 8px 12px;
    background: #ffffff;
  }
  .table-header-bar h3 {
    font-size: 13.5px;
    font-weight: 700;
    font-family: 'JetBrains Mono', monospace;
  }
  .table-badge {
    font-size: 10px;
    font-weight: 700;
    border: 1px solid #000000;
    padding: 2px 8px;
    text-transform: uppercase;
  }

  /* Data Table Grid */
  table.schema-grid {
    width: 100%;
    border-collapse: collapse;
    border: 2px solid #000000;
    font-size: 11px;
  }
  table.schema-grid th {
    background-color: #ffffff;
    color: #000000;
    font-weight: 700;
    text-align: left;
    padding: 6px 10px;
    border: 1px solid #000000;
    text-transform: uppercase;
    font-size: 10px;
    letter-spacing: 0.3px;
  }
  table.schema-grid td {
    padding: 5px 10px;
    border: 1px solid #000000;
    vertical-align: top;
  }
  table.schema-grid td.col-mono {
    font-family: 'JetBrains Mono', monospace;
    font-size: 10.5px;
  }
  table.schema-grid td.col-center {
    text-align: center;
    font-weight: 600;
  }
  table.schema-grid td.col-key {
    text-align: center;
    font-weight: 700;
    font-size: 10px;
  }

  @media print {
    header.toolbar, .quick-nav {
      display: none;
    }
    body {
      padding-top: 0;
      padding-bottom: 0;
    }
  }
</style>
</head>
<body>

<header class="toolbar">
  <div class="toolbar-title">
    <h1>Cemetery Management and Grave Mapping System &bull; Database Schema</h1>
  </div>
  <div class="toolbar-actions">
    <a href="erd.php" class="btn" title="View Interactive ERD">View ERD</a>
    <button class="btn" onclick="window.print()" title="Print Database Schema">Print Schema</button>
    <a href="download_database_schema.php" class="btn btn-black" title="Download Word .doc file">Download .DOC File</a>
  </div>
</header>

<div class="container">
  
  <div class="doc-title-block">
    <h1>Cemetery Management and Grave Mapping System</h1>
    <h2>Data Dictionary &amp; Relational Database Schema Specifications</h2>
  </div>

  <div class="quick-nav">
    <a href="#tbl_user" class="nav-chip">tbl_user</a>
    <a href="#tbl_admin" class="nav-chip">tbl_admin</a>
    <a href="#tbl_guest_reservation_requests" class="nav-chip">tbl_guest_reservation_requests</a>
    <a href="#tbl_bookings" class="nav-chip">tbl_bookings</a>
    <a href="#tbl_booking_payment_schedule" class="nav-chip">tbl_booking_payment_schedule</a>
    <a href="#tbl_deceased" class="nav-chip">tbl_deceased</a>
    <a href="#tbl_plot_pricing" class="nav-chip">tbl_plot_pricing</a>
    <a href="#tbl_plots" class="nav-chip">tbl_plots</a>
    <a href="#tbl_plot_deceased_mapping" class="nav-chip">tbl_plot_deceased_mapping</a>
  </div>

  <!-- 1. tbl_user -->
  <div class="table-section" id="tbl_user">
    <div class="table-header-bar">
      <h3>1. tbl_user</h3>
      <span class="table-badge">Primary Key: user_id</span>
    </div>
    <table class="schema-grid">
      <thead>
        <tr>
          <th style="width: 20%;">Column Name</th>
          <th style="width: 15%;">Data Type</th>
          <th style="width: 8%;">Null</th>
          <th style="width: 10%;">Key</th>
          <th style="width: 15%;">Default</th>
          <th style="width: 32%;">Description</th>
        </tr>
      </thead>
      <tbody>
        <tr><td class="col-mono">user_id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key">PK</td><td>AUTO_INCREMENT</td><td>Unique identifier for user account</td></tr>
        <tr><td class="col-mono">role</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Account authorization role (Admin, Staff, Client)</td></tr>
        <tr><td class="col-mono">first_name</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>User first name (Atomic)</td></tr>
        <tr><td class="col-mono">middle_name</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>User middle name (Optional)</td></tr>
        <tr><td class="col-mono">last_name</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>User surname / family name</td></tr>
        <tr><td class="col-mono">suffix</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">YES</td><td class="col-key"></td><td>''</td><td>Generational suffix (Jr., Sr., III)</td></tr>
        <tr><td class="col-mono">birthday</td><td class="col-mono">DATE</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Birth date of user</td></tr>
        <tr><td class="col-mono">gender</td><td class="col-mono">VARCHAR(10)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Gender identification</td></tr>
        <tr><td class="col-mono">email</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">NO</td><td class="col-key">UK</td><td>None</td><td>Unique email address for authentication</td></tr>
        <tr><td class="col-mono">password</td><td class="col-mono">VARCHAR(250)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Hashed password security string</td></tr>
        <tr><td class="col-mono">phone_no</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">YES</td><td class="col-key"></td><td>''</td><td>Contact mobile telephone number</td></tr>
        <tr><td class="col-mono">profile_picture</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Server path to uploaded avatar</td></tr>
        <tr><td class="col-mono">account_number</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Cemetery customer account code</td></tr>
        <tr><td class="col-mono">email_verified</td><td class="col-mono">TINYINT(1)</td><td class="col-center">YES</td><td class="col-key"></td><td>0</td><td>Verification status flag (1=Verified, 0=Pending)</td></tr>
        <tr><td class="col-mono">verification_token</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Email verification SHA hash token</td></tr>
        <tr><td class="col-mono">verification_otp</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>One-time verification numeric code</td></tr>
        <tr><td class="col-mono">otp_expiry</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Expiration timestamp for OTP</td></tr>
        <tr><td class="col-mono">reset_otp</td><td class="col-mono">VARCHAR(10)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Password recovery OTP string</td></tr>
        <tr><td class="col-mono">reset_otp_expiry</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Expiration timestamp for reset OTP</td></tr>
        <tr><td class="col-mono">verification_expires</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Email verification link expiration time</td></tr>
        <tr><td class="col-mono">reset_token</td><td class="col-mono">VARCHAR(128)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Password reset URL token</td></tr>
        <tr><td class="col-mono">reset_expires</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Password reset token expiration time</td></tr>
        <tr><td class="col-mono">verification_attempts</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-key"></td><td>0</td><td>Counter for invalid auth attempts</td></tr>
      </tbody>
    </table>
  </div>

  <!-- 2. tbl_admin -->
  <div class="table-section" id="tbl_admin">
    <div class="table-header-bar">
      <h3>2. tbl_admin</h3>
      <span class="table-badge">Primary Key: admin_id &bull; 1:1 Subtype</span>
    </div>
    <table class="schema-grid">
      <thead>
        <tr>
          <th style="width: 20%;">Column Name</th>
          <th style="width: 15%;">Data Type</th>
          <th style="width: 8%;">Null</th>
          <th style="width: 10%;">Key</th>
          <th style="width: 15%;">Default</th>
          <th style="width: 32%;">Description</th>
        </tr>
      </thead>
      <tbody>
        <tr><td class="col-mono">admin_id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key">PK</td><td>AUTO_INCREMENT</td><td>Unique administrator identifier</td></tr>
        <tr><td class="col-mono">user_id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key">FK, UK</td><td>None</td><td>Foreign key to tbl_user (Strict 1:1 relation)</td></tr>
        <tr><td class="col-mono">full_name</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Full name of administrator</td></tr>
        <tr><td class="col-mono">email</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Administrative contact email</td></tr>
        <tr><td class="col-mono">phone_no</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Administrative mobile telephone number</td></tr>
        <tr><td class="col-mono">role</td><td class="col-mono">VARCHAR(30)</td><td class="col-center">NO</td><td class="col-key"></td><td>'Admin'</td><td>Administrative role privilege tier</td></tr>
        <tr><td class="col-mono">created_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Account creation timestamp</td></tr>
        <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Last profile update timestamp</td></tr>
      </tbody>
    </table>
  </div>

  <!-- 3. tbl_guest_reservation_requests -->
  <div class="table-section" id="tbl_guest_reservation_requests">
    <div class="table-header-bar">
      <h3>3. tbl_guest_reservation_requests</h3>
      <span class="table-badge">Primary Key: id &bull; 1:1 Booking</span>
    </div>
    <table class="schema-grid">
      <thead>
        <tr>
          <th style="width: 20%;">Column Name</th>
          <th style="width: 15%;">Data Type</th>
          <th style="width: 8%;">Null</th>
          <th style="width: 10%;">Key</th>
          <th style="width: 15%;">Default</th>
          <th style="width: 32%;">Description</th>
        </tr>
      </thead>
      <tbody>
        <tr><td class="col-mono">id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key">PK</td><td>AUTO_INCREMENT</td><td>Unique guest request identifier</td></tr>
        <tr><td class="col-mono">user_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-key">FK</td><td>NULL</td><td>Foreign key to tbl_user upon account creation</td></tr>
        <tr><td class="col-mono">plot_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-key">FK</td><td>NULL</td><td>Foreign key to reserved tbl_plots</td></tr>
        <tr><td class="col-mono">booking_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-key">FK, UK</td><td>NULL</td><td>Converted booking ID (Strict 1:1 conversion)</td></tr>
        <tr><td class="col-mono">approved_by</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-key">FK</td><td>NULL</td><td>Foreign key to staff/admin user who approved</td></tr>
        <tr><td class="col-mono">rejected_by</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-key">FK</td><td>NULL</td><td>Foreign key to staff/admin user who rejected</td></tr>
        <tr><td class="col-mono">full_name</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Full name of requesting guest</td></tr>
        <tr><td class="col-mono">email</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Email address of applicant</td></tr>
        <tr><td class="col-mono">contact_number</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Phone contact number</td></tr>
        <tr><td class="col-mono">address</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Residential address</td></tr>
        <tr><td class="col-mono">section</td><td class="col-mono">VARCHAR(10)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Requested cemetery section (A to F)</td></tr>
        <tr><td class="col-mono">plot_x</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Interactive map grid X coordinate</td></tr>
        <tr><td class="col-mono">plot_y</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Interactive map grid Y coordinate</td></tr>
        <tr><td class="col-mono">plot_code</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Plot display code (e.g. A-005)</td></tr>
        <tr><td class="col-mono">purpose</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">NO</td><td class="col-key"></td><td>'Future Memorial Lot'</td><td>Reservation intent/purpose</td></tr>
        <tr><td class="col-mono">preferred_date</td><td class="col-mono">DATE</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Preferred date for appointment or interment</td></tr>
        <tr><td class="col-mono">notes</td><td class="col-mono">TEXT</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Applicant special notes</td></tr>
        <tr><td class="col-mono">admin_notes</td><td class="col-mono">TEXT</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Administrative review remarks</td></tr>
        <tr><td class="col-mono">account_creation_token_hash</td><td class="col-mono">VARCHAR(64)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Hash of account completion invitation token</td></tr>
        <tr><td class="col-mono">token_expires_at</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Expiration timestamp for invitation token</td></tr>
        <tr><td class="col-mono">token_used_at</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Timestamp when token was claimed</td></tr>
        <tr><td class="col-mono">email_sent</td><td class="col-mono">TINYINT(1)</td><td class="col-center">NO</td><td class="col-key"></td><td>0</td><td>1 if confirmation email was dispatched</td></tr>
        <tr><td class="col-mono">email_error</td><td class="col-mono">TEXT</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Error message if email dispatch failed</td></tr>
        <tr><td class="col-mono">approved_at</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Approval timestamp</td></tr>
        <tr><td class="col-mono">rejected_at</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Rejection timestamp</td></tr>
        <tr><td class="col-mono">cancelled_at</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Cancellation timestamp</td></tr>
        <tr><td class="col-mono">cancelled_by</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>User/staff who initiated cancellation</td></tr>
        <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Last record modification timestamp</td></tr>
      </tbody>
    </table>
  </div>

  <!-- 4. tbl_bookings -->
  <div class="table-section" id="tbl_bookings">
    <div class="table-header-bar">
      <h3>4. tbl_bookings</h3>
      <span class="table-badge">Primary Key: id</span>
    </div>
    <table class="schema-grid">
      <thead>
        <tr>
          <th style="width: 20%;">Column Name</th>
          <th style="width: 15%;">Data Type</th>
          <th style="width: 8%;">Null</th>
          <th style="width: 10%;">Key</th>
          <th style="width: 15%;">Default</th>
          <th style="width: 32%;">Description</th>
        </tr>
      </thead>
      <tbody>
        <tr><td class="col-mono">id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key">PK</td><td>AUTO_INCREMENT</td><td>Master contract booking number</td></tr>
        <tr><td class="col-mono">user_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-key">FK</td><td>NULL</td><td>Foreign key to client tbl_user</td></tr>
        <tr><td class="col-mono">plot_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-key">FK</td><td>NULL</td><td>Foreign key to reserved tbl_plots</td></tr>
        <tr><td class="col-mono">deceased_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-key">FK</td><td>NULL</td><td>Foreign key to interred tbl_deceased</td></tr>
        <tr><td class="col-mono">service_type</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Type of service (Grave Reservation, Burial)</td></tr>
        <tr><td class="col-mono">grave_location</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Human readable text description of plot location</td></tr>
        <tr><td class="col-mono">plot_type</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Lawn type / tier specification</td></tr>
        <tr><td class="col-mono">section</td><td class="col-mono">VARCHAR(10)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Section code (A-F)</td></tr>
        <tr><td class="col-mono">plot</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Plot identifier string</td></tr>
        <tr><td class="col-mono">section_plot</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Combined section-plot code</td></tr>
        <tr><td class="col-mono">preferred_date</td><td class="col-mono">DATE</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Target interment or reservation date</td></tr>
        <tr><td class="col-mono">contact_number</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Client contact number</td></tr>
        <tr><td class="col-mono">booker_location</td><td class="col-mono">VARCHAR(255)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Address of booking client</td></tr>
        <tr><td class="col-mono">special_requests</td><td class="col-mono">TEXT</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Specific family requests or instructions</td></tr>
        <tr><td class="col-mono">remarks</td><td class="col-mono">TEXT</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Administrative remarks on booking</td></tr>
        <tr><td class="col-mono">payment_type</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Payment method: GCash, Cash, Office</td></tr>
        <tr><td class="col-mono">reference_number</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Transaction tracking reference string</td></tr>
        <tr><td class="col-mono">gcash_reference_number</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>13-digit GCash receipt reference</td></tr>
        <tr><td class="col-mono">payment_receipt_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Reference to payment receipt record</td></tr>
        <tr><td class="col-mono">created_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Booking creation timestamp</td></tr>
        <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Last status update timestamp</td></tr>
      </tbody>
    </table>
  </div>

  <!-- 5. tbl_booking_payment_schedule -->
  <div class="table-section" id="tbl_booking_payment_schedule">
    <div class="table-header-bar">
      <h3>5. tbl_booking_payment_schedule</h3>
      <span class="table-badge">Primary Key: id</span>
    </div>
    <table class="schema-grid">
      <thead>
        <tr>
          <th style="width: 20%;">Column Name</th>
          <th style="width: 15%;">Data Type</th>
          <th style="width: 8%;">Null</th>
          <th style="width: 10%;">Key</th>
          <th style="width: 15%;">Default</th>
          <th style="width: 32%;">Description</th>
        </tr>
      </thead>
      <tbody>
        <tr><td class="col-mono">id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key">PK</td><td>AUTO_INCREMENT</td><td>Unique installment milestone ID</td></tr>
        <tr><td class="col-mono">booking_id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key">FK</td><td>None</td><td>Foreign key to tbl_bookings</td></tr>
        <tr><td class="col-mono">installment_number</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Milestone sequence (1=Downpayment, 2..N=Months)</td></tr>
        <tr><td class="col-mono">due_date</td><td class="col-mono">DATE</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Payment deadline for installment</td></tr>
        <tr><td class="col-mono">amount_due</td><td class="col-mono">DECIMAL(10,2)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Amount due in Philippine Peso (PHP)</td></tr>
        <tr><td class="col-mono">amount_paid</td><td class="col-mono">DECIMAL(10,2)</td><td class="col-center">NO</td><td class="col-key"></td><td>0.00</td><td>Verified amount paid</td></tr>
        <tr><td class="col-mono">status</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">NO</td><td class="col-key"></td><td>'UNPAID'</td><td>Installment status: UNPAID, PAID, OVERDUE</td></tr>
        <tr><td class="col-mono">paid_at</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Timestamp payment was verified</td></tr>
        <tr><td class="col-mono">payment_method</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Payment channel: GCash, Cash</td></tr>
        <tr><td class="col-mono">transaction_reference</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Internal transaction tracking code</td></tr>
        <tr><td class="col-mono">gcash_reference_number</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>13-digit official GCash reference number</td></tr>
        <tr><td class="col-mono">payment_receipt_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-key">FK</td><td>NULL</td><td>Foreign key to generated payment receipt</td></tr>
        <tr><td class="col-mono">created_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Creation timestamp</td></tr>
        <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Last status change timestamp</td></tr>
      </tbody>
    </table>
  </div>

  <!-- 6. tbl_deceased -->
  <div class="table-section" id="tbl_deceased">
    <div class="table-header-bar">
      <h3>6. tbl_deceased</h3>
      <span class="table-badge">Primary Key: id</span>
    </div>
    <table class="schema-grid">
      <thead>
        <tr>
          <th style="width: 20%;">Column Name</th>
          <th style="width: 15%;">Data Type</th>
          <th style="width: 8%;">Null</th>
          <th style="width: 10%;">Key</th>
          <th style="width: 15%;">Default</th>
          <th style="width: 32%;">Description</th>
        </tr>
      </thead>
      <tbody>
        <tr><td class="col-mono">id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key">PK</td><td>AUTO_INCREMENT</td><td>Unique deceased record identifier</td></tr>
        <tr><td class="col-mono">user_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-key">FK</td><td>NULL</td><td>Foreign key to responsible client tbl_user</td></tr>
        <tr><td class="col-mono">full_name</td><td class="col-mono">VARCHAR(150)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Full name of deceased person</td></tr>
        <tr><td class="col-mono">birth_date</td><td class="col-mono">DATE</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Date of birth</td></tr>
        <tr><td class="col-mono">date_of_death</td><td class="col-mono">DATE</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Date of death</td></tr>
        <tr><td class="col-mono">place_of_birth</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Municipality / City of birth</td></tr>
        <tr><td class="col-mono">family_contact_no</td><td class="col-mono">VARCHAR(25)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Contact mobile number of next-of-kin</td></tr>
        <tr><td class="col-mono">funeral_service</td><td class="col-mono">VARCHAR(100)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Mortuary / Funeral service provider</td></tr>
        <tr><td class="col-mono">section</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Cemetery section code (A to F)</td></tr>
        <tr><td class="col-mono">plot_x</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Interactive digital map X coordinate</td></tr>
        <tr><td class="col-mono">plot_y</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Interactive digital map Y coordinate</td></tr>
        <tr><td class="col-mono">created_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Record registration timestamp</td></tr>
        <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Last update timestamp</td></tr>
      </tbody>
    </table>
  </div>

  <!-- 7. tbl_plot_pricing -->
  <div class="table-section" id="tbl_plot_pricing">
    <div class="table-header-bar">
      <h3>7. tbl_plot_pricing</h3>
      <span class="table-badge">Primary Key: id</span>
    </div>
    <table class="schema-grid">
      <thead>
        <tr>
          <th style="width: 20%;">Column Name</th>
          <th style="width: 15%;">Data Type</th>
          <th style="width: 8%;">Null</th>
          <th style="width: 10%;">Key</th>
          <th style="width: 15%;">Default</th>
          <th style="width: 32%;">Description</th>
        </tr>
      </thead>
      <tbody>
        <tr><td class="col-mono">id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key">PK</td><td>AUTO_INCREMENT</td><td>Unique pricing term identifier</td></tr>
        <tr><td class="col-mono">plot_id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key">FK</td><td>None</td><td>Foreign key to tbl_plots</td></tr>
        <tr><td class="col-mono">payment_term</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Term duration in months (0=Cash, 12, 24, 36)</td></tr>
        <tr><td class="col-mono">created_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Creation timestamp</td></tr>
        <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Last price update timestamp</td></tr>
      </tbody>
    </table>
  </div>

  <!-- 8. tbl_plots -->
  <div class="table-section" id="tbl_plots">
    <div class="table-header-bar">
      <h3>8. tbl_plots</h3>
      <span class="table-badge">Primary Key: id</span>
    </div>
    <table class="schema-grid">
      <thead>
        <tr>
          <th style="width: 20%;">Column Name</th>
          <th style="width: 15%;">Data Type</th>
          <th style="width: 8%;">Null</th>
          <th style="width: 10%;">Key</th>
          <th style="width: 15%;">Default</th>
          <th style="width: 32%;">Description</th>
        </tr>
      </thead>
      <tbody>
        <tr><td class="col-mono">id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key">PK</td><td>AUTO_INCREMENT</td><td>Unique plot identifier</td></tr>
        <tr><td class="col-mono">section</td><td class="col-mono">VARCHAR(10)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Cemetery section code (Sections A to F)</td></tr>
        <tr><td class="col-mono">plot_x</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Interactive digital canvas X coordinate</td></tr>
        <tr><td class="col-mono">plot_y</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key"></td><td>None</td><td>Interactive digital canvas Y coordinate</td></tr>
        <tr><td class="col-mono">plot_no</td><td class="col-mono">VARCHAR(20)</td><td class="col-center">NO</td><td class="col-key">UK</td><td>None</td><td>Human readable plot number (e.g. A-001)</td></tr>
        <tr><td class="col-mono">lot_type</td><td class="col-mono">VARCHAR(50)</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Lot classification (Standard Lawn, Premium)</td></tr>
        <tr><td class="col-mono">created_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Plot registration timestamp</td></tr>
        <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Last record modification timestamp</td></tr>
      </tbody>
    </table>
  </div>

  <!-- 9. tbl_plot_deceased_mapping -->
  <div class="table-section" id="tbl_plot_deceased_mapping">
    <div class="table-header-bar">
      <h3>9. tbl_plot_deceased_mapping</h3>
      <span class="table-badge">Primary Key: id &bull; Bridge Entity</span>
    </div>
    <table class="schema-grid">
      <thead>
        <tr>
          <th style="width: 20%;">Column Name</th>
          <th style="width: 15%;">Data Type</th>
          <th style="width: 8%;">Null</th>
          <th style="width: 10%;">Key</th>
          <th style="width: 15%;">Default</th>
          <th style="width: 32%;">Description</th>
        </tr>
      </thead>
      <tbody>
        <tr><td class="col-mono">id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key">PK</td><td>AUTO_INCREMENT</td><td>Unique mapping identifier</td></tr>
        <tr><td class="col-mono">plot_id</td><td class="col-mono">INT(11)</td><td class="col-center">NO</td><td class="col-key">FK</td><td>None</td><td>Foreign key to tbl_plots</td></tr>
        <tr><td class="col-mono">user_id</td><td class="col-mono">INT(11)</td><td class="col-center">YES</td><td class="col-key">FK</td><td>NULL</td><td>Foreign key to plot owner / client tbl_user</td></tr>
        <tr><td class="col-mono">reservation_date</td><td class="col-mono">DATETIME</td><td class="col-center">YES</td><td class="col-key"></td><td>current_timestamp()</td><td>Initial reservation timestamp</td></tr>
        <tr><td class="col-mono">expiry_date</td><td class="col-mono">DATE</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Reservation hold expiration deadline</td></tr>
        <tr><td class="col-mono">notes</td><td class="col-mono">TEXT</td><td class="col-center">YES</td><td class="col-key"></td><td>NULL</td><td>Plot occupancy / sexton notes</td></tr>
        <tr><td class="col-mono">created_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Mapping creation timestamp</td></tr>
        <tr><td class="col-mono">updated_at</td><td class="col-mono">TIMESTAMP</td><td class="col-center">NO</td><td class="col-key"></td><td>current_timestamp()</td><td>Last status update timestamp</td></tr>
      </tbody>
    </table>
  </div>

</div>

</body>
</html>
