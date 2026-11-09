<?php
/**
 * Cemetery Management and Grave Mapping System
 * Entity Relationship Diagram (ERD) Interactive Viewer
 * Perfectly matching the official schema and diagram layout with connecting lines & cardinality
 * URL: http://localhost/Garden_of_Remembrance_MP/erd.php
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cemetery Management and Grave Mapping System &bull; Entity Relationship Diagram (ERD)</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  * {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
  }
  body {
    font-family: 'Inter', Arial, sans-serif;
    background-color: #f8fafc;
    color: #000000;
    overflow-x: auto;
    overflow-y: auto;
    min-width: 1400px;
  }
  
  /* Top Navigation Toolbar */
  header.toolbar {
    position: sticky;
    top: 0;
    left: 0;
    right: 0;
    height: 58px;
    background: #ffffff;
    border-bottom: 2px solid #000000;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 28px;
    z-index: 1000;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
  }
  .toolbar-title {
    display: flex;
    flex-direction: column;
  }
  .toolbar-title h1 {
    font-size: 15px;
    font-weight: 800;
    color: #000000;
    text-transform: uppercase;
    letter-spacing: -0.2px;
  }
  .toolbar-title span {
    font-size: 11.5px;
    color: #475569;
    font-weight: 600;
  }
  .toolbar-actions {
    display: flex;
    gap: 10px;
    align-items: center;
  }
  .btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 15px;
    font-size: 12px;
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
  .btn-primary {
    background: #2563eb;
    border-color: #1d4ed8;
    color: #ffffff;
  }
  .btn-primary:hover {
    background: #1e40af;
    color: #ffffff;
  }

  /* View Containers */
  .view-container {
    padding: 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
  }
  
  .diagram-header-banner {
    text-align: center;
    margin-bottom: 24px;
  }
  .diagram-header-banner h2 {
    font-size: 26px;
    font-weight: 800;
    color: #000000;
    letter-spacing: -0.5px;
  }
  .diagram-header-banner h3 {
    font-size: 20px;
    font-weight: 700;
    color: #1e293b;
    margin-top: 4px;
  }

  /* Image View Mode */
  #view-image {
    width: 100%;
    max-width: 1320px;
    margin: 0 auto;
    background: #ffffff;
    border: 2px solid #000000;
    padding: 16px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.08);
    display: block;
    text-align: center;
  }
  #view-image img {
    width: 100%;
    max-width: 1260px;
    height: auto;
    display: inline-block;
  }

  /* Interactive Canvas Mode */
  #view-interactive {
    display: none;
    width: 100%;
    min-height: 1100px;
  }
  #workspace {
    position: relative;
    width: 1540px;
    height: 1150px;
    margin: 0 auto;
    background: #ffffff;
    border: 2px solid #000000;
    box-shadow: 0 6px 20px rgba(0,0,0,0.08);
  }

  /* SVG Layer for Connecting Lines */
  #connections-layer {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 10;
  }
  .line-connector {
    stroke: #000000;
    stroke-width: 1.8;
    fill: none;
  }
  .cardinality-badge {
    font-family: Arial, sans-serif;
    font-size: 12px;
    font-weight: 800;
    fill: #000000;
    paint-order: stroke;
    stroke: #ffffff;
    stroke-width: 3px;
    stroke-linecap: butt;
    stroke-linejoin: miter;
  }

  /* Entity Boxes - Exact Match to Screenshot */
  .entity-card {
    position: absolute;
    background: #ffffff;
    border: 1.8px solid #000000;
    box-shadow: 2px 2px 0px #000000;
    z-index: 20;
    cursor: move;
  }
  .entity-card:hover {
    box-shadow: 3px 3px 0px #000000;
  }
  .entity-card.dragging {
    box-shadow: 5px 5px 0px #000000;
    z-index: 100;
  }
  
  .entity-header {
    background: #ffffff;
    border-bottom: 1.8px solid #000000;
    padding: 5px 8px;
    font-size: 13px;
    font-weight: 800;
    text-align: center;
    color: #000000;
    font-family: Arial, sans-serif;
  }

  .entity-body {
    background: #ffffff;
  }

  .attr-row {
    display: flex;
    border-bottom: 1px solid #d4d4d4;
    font-size: 11px;
    line-height: 19px;
  }
  .attr-row:last-child {
    border-bottom: none;
  }
  .attr-key {
    width: 32px;
    padding: 0 4px;
    text-align: center;
    font-weight: 800;
    font-size: 10.5px;
    color: #000000;
    border-right: 1.4px solid #000000;
    background: #ffffff;
    flex-shrink: 0;
  }
  .attr-name {
    padding: 0 6px;
    color: #000000;
    font-family: Arial, sans-serif;
    font-size: 11px;
    white-space: nowrap;
  }

  /* Specific Card Widths Matching Screenshot Proportions */
  #tbl_user { width: 230px; }
  #tbl_admin { width: 195px; }
  #tbl_guest_reservation_requests { width: 255px; }
  #tbl_bookings { width: 210px; }
  #tbl_booking_payment_schedule { width: 250px; }
  #tbl_deceased { width: 230px; }
  #tbl_plot_pricing { width: 215px; }
  #tbl_plots { width: 200px; }
  #tbl_plot_deceased_mapping { width: 275px; }

  /* Bottom Help Badge */
  .info-bar {
    margin-top: 18px;
    background: #ffffff;
    border: 1.5px solid #000000;
    padding: 8px 18px;
    font-size: 12px;
    font-weight: 700;
    box-shadow: 2px 2px 0px #000000;
  }
</style>
</head>
<body>

<header class="toolbar">
  <div class="toolbar-title">
    <h1>Cemetery Management and Grave Mapping System</h1>
    <span>Official Entity Relationship Diagram (ERD) &bull; Connecting Lines & Cardinality</span>
  </div>
  <div class="toolbar-actions">
    <button class="btn btn-black" id="btn-toggle-view"><i class="fa-solid fa-code-merge"></i> Switch to Interactive Canvas</button>
    <button class="btn" id="btn-reset" title="Reset cards to default positions" style="display:none;"><i class="fa-solid fa-rotate-left"></i> Reset Layout</button>
    <a href="erd_docs.php" class="btn" title="View 26-Table Database Documentation & Normalization"><i class="fa-solid fa-book"></i> Docs & Normalization</a>
    <a href="download_erd_normalization.php" class="btn btn-primary" title="Download Word .doc File with Embedded Diagram"><i class="fa-solid fa-file-word"></i> Download .DOC File</a>
  </div>
</header>

<div class="view-container">

  <div class="diagram-header-banner">
    <h2>Cemetery Management and Grave Mapping System</h2>
    <h3>Entity Relationship Diagram (ERD)</h3>
  </div>

  <!-- VIEW 1: OFFICIAL IMAGE VIEW (100% FAITHFUL TO SCREENSHOT) -->
  <div id="view-image">
    <img src="assets/images/cemetery_erd_diagram.jpg" alt="Official Cemetery Management and Grave Mapping System Entity Relationship Diagram" />
  </div>

  <!-- VIEW 2: INTERACTIVE SVG CONNECTED CANVAS -->
  <div id="view-interactive">
    <div id="workspace">
      <svg id="connections-layer"></svg>

      <!-- ================= TOP ROW: 5 TABLES ================= -->

      <!-- 1. tbl_user -->
      <div class="entity-card" id="tbl_user" data-x="25" data-y="50">
        <div class="entity-header">tbl_user</div>
        <div class="entity-body">
          <div class="attr-row"><div class="attr-key">PK</div><div class="attr-name">user_id</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">role</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">first_name</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">middle_name</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">last_name</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">suffix</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">birthday</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">email</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">password</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">phone_no</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">profile_picture</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">account_number</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">email_verified</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">verification_token</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">verification_otp</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">otp_expiry</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">reset_otp</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">email_otp_expiry</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">verification_expires</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">reset_token</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">reset_expires</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">verification_attempts</div></div>
        </div>
      </div>

      <!-- 2. tbl_admin -->
      <div class="entity-card" id="tbl_admin" data-x="345" data-y="50">
        <div class="entity-header">tbl_admin</div>
        <div class="entity-body">
          <div class="attr-row"><div class="attr-key">PK</div><div class="attr-name">admin_id</div></div>
          <div class="attr-row"><div class="attr-key">FK</div><div class="attr-name">user_id</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">full_name</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">email</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">phone_no</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">role</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">created_at</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">updated_at</div></div>
        </div>
      </div>

      <!-- 3. tbl_guest_reservation_requests -->
      <div class="entity-card" id="tbl_guest_reservation_requests" data-x="620" data-y="50">
        <div class="entity-header">tbl_guest_reservation_requests</div>
        <div class="entity-body">
          <div class="attr-row"><div class="attr-key">PK</div><div class="attr-name">id</div></div>
          <div class="attr-row"><div class="attr-key">FK</div><div class="attr-name">user_id</div></div>
          <div class="attr-row"><div class="attr-key">FK</div><div class="attr-name">plot_id</div></div>
          <div class="attr-row"><div class="attr-key">FK</div><div class="attr-name">booking_id</div></div>
          <div class="attr-row"><div class="attr-key">FK</div><div class="attr-name">approved_by</div></div>
          <div class="attr-row"><div class="attr-key">FK</div><div class="attr-name">rejected_by</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">full_name</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">email</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">contact_number</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">address</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">section</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">plot_x</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">plot_y</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">plot_code</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">purpose</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">preferred_date</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">notes</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">admin_notes</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">account_creation_token_hash</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">token_expires_at</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">token_used_at</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">email_sent</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">email_error</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">approved_at</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">approved_by</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">rejected_at</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">rejected_by</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">cancelled_at</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">cancelled_by</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">updated_at</div></div>
        </div>
      </div>

      <!-- 4. tbl_bookings -->
      <div class="entity-card" id="tbl_bookings" data-x="960" data-y="50">
        <div class="entity-header">tbl_bookings</div>
        <div class="entity-body">
          <div class="attr-row"><div class="attr-key">PK</div><div class="attr-name">id</div></div>
          <div class="attr-row"><div class="attr-key">FK</div><div class="attr-name">user_id</div></div>
          <div class="attr-row"><div class="attr-key">FK</div><div class="attr-name">plot_id</div></div>
          <div class="attr-row"><div class="attr-key">FK</div><div class="attr-name">deceased_id</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">service_type</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">grave_location</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">plot_type</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">section</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">plot</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">section_plot</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">preferred_date</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">contact_number</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">booker_location</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">special_requests</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">remarks</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">payment_type</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">reference_number</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">gcash_reference_number</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">payment_receipt_id</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">created_at</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">updated_at</div></div>
        </div>
      </div>

      <!-- 5. tbl_booking_payment_schedule -->
      <div class="entity-card" id="tbl_booking_payment_schedule" data-x="1245" data-y="50">
        <div class="entity-header">tbl_booking_payment_schedule</div>
        <div class="entity-body">
          <div class="attr-row"><div class="attr-key">PK</div><div class="attr-name">id</div></div>
          <div class="attr-row"><div class="attr-key">FK</div><div class="attr-name">booking_id</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">installment_number</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">due_date</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">amount_due</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">amount_paid</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">status</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">paid_at</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">payment_method</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">transaction_reference</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">gcash_reference_number</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">payment_receipt_id</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">created_at</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">updated_at</div></div>
        </div>
      </div>

      <!-- ================= BOTTOM ROW: 4 TABLES ================= -->

      <!-- 6. tbl_deceased -->
      <div class="entity-card" id="tbl_deceased" data-x="30" data-y="690">
        <div class="entity-header">tbl_deceased</div>
        <div class="entity-body">
          <div class="attr-row"><div class="attr-key">PK</div><div class="attr-name">id</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">full_name</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">birth_date</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">date_of_death</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">place_of_birth</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">family_contact_no</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">funeral_service</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">section</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">plot_x</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">plot_y</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">created_at</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">updated_at</div></div>
        </div>
      </div>

      <!-- 7. tbl_plot_pricing -->
      <div class="entity-card" id="tbl_plot_pricing" data-x="330" data-y="710">
        <div class="entity-header">tbl_plot_pricing</div>
        <div class="entity-body">
          <div class="attr-row"><div class="attr-key">PK</div><div class="attr-name">id</div></div>
          <div class="attr-row"><div class="attr-key">FK</div><div class="attr-name">plot_id</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">payment_term</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">created_at</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">updated_at</div></div>
        </div>
      </div>

      <!-- 8. tbl_plots -->
      <div class="entity-card" id="tbl_plots" data-x="660" data-y="770">
        <div class="entity-header">tbl_plots</div>
        <div class="entity-body">
          <div class="attr-row"><div class="attr-key">PK</div><div class="attr-name">id</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">section</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">plot_x</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">plot_y</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">plot_no</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">lot_type</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">created_at</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">updated_at</div></div>
        </div>
      </div>

      <!-- 9. tbl_plot_deceased_mapping -->
      <div class="entity-card" id="tbl_plot_deceased_mapping" data-x="980" data-y="720">
        <div class="entity-header">tbl_plot_deceased_mapping</div>
        <div class="entity-body">
          <div class="attr-row"><div class="attr-key">PK</div><div class="attr-name">id</div></div>
          <div class="attr-row"><div class="attr-key">FK</div><div class="attr-name">plot_id</div></div>
          <div class="attr-row"><div class="attr-key">FK</div><div class="attr-name">user_id</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">reservation_date</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">expiry_date</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">notes</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">created_at</div></div>
          <div class="attr-row"><div class="attr-key"></div><div class="attr-name">updated_at</div></div>
        </div>
      </div>

    </div>
  </div>

  <div class="info-bar">
    <i class="fa-solid fa-circle-check text-success"></i> All 9 core entities are properly connected with verified cardinality lines (<code>1</code> and <code>0..*</code>).
  </div>

</div>

<script>
// Toggle between Image View and Interactive View
const btnToggle = document.getElementById('btn-toggle-view');
const btnReset = document.getElementById('btn-reset');
const viewImage = document.getElementById('view-image');
const viewInteractive = document.getElementById('view-interactive');

let isInteractive = false;

btnToggle.addEventListener('click', () => {
  isInteractive = !isInteractive;
  if (isInteractive) {
    viewImage.style.display = 'none';
    viewInteractive.style.display = 'block';
    btnReset.style.display = 'inline-flex';
    btnToggle.innerHTML = '<i class="fa-solid fa-image"></i> Switch to Official Image View';
    applyPositions(false);
  } else {
    viewImage.style.display = 'block';
    viewInteractive.style.display = 'none';
    btnReset.style.display = 'none';
    btnToggle.innerHTML = '<i class="fa-solid fa-code-merge"></i> Switch to Interactive Canvas';
  }
});

const cards = document.querySelectorAll('.entity-card');
const svg = document.getElementById('connections-layer');

function getBox(id) {
  const el = document.getElementById(id);
  if (!el) return null;
  return {
    x: parseInt(el.style.left) || 0,
    y: parseInt(el.style.top) || 0,
    w: el.offsetWidth,
    h: el.offsetHeight
  };
}

function drawLine(dStr) {
  const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
  path.setAttribute('d', dStr);
  path.setAttribute('class', 'line-connector');
  svg.appendChild(path);
}

function drawBadge(x, y, text) {
  const badge = document.createElementNS('http://www.w3.org/2000/svg', 'text');
  badge.setAttribute('class', 'cardinality-badge');
  badge.setAttribute('x', x);
  badge.setAttribute('y', y);
  badge.textContent = text;
  svg.appendChild(badge);
}

// Render the 8 exact connecting lines matching the screenshot
function renderLines() {
  svg.innerHTML = '';

  const user = getBox('tbl_user');
  const admin = getBox('tbl_admin');
  const guest = getBox('tbl_guest_reservation_requests');
  const bookings = getBox('tbl_bookings');
  const payment = getBox('tbl_booking_payment_schedule');
  const deceased = getBox('tbl_deceased');
  const pricing = getBox('tbl_plot_pricing');
  const plots = getBox('tbl_plots');
  const mapping = getBox('tbl_plot_deceased_mapping');

  if (!user || !admin || !guest || !bookings || !payment || !deceased || !pricing || !plots || !mapping) return;

  // 1. tbl_user (1) -> tbl_admin (0..*)
  const yUserAdmin = user.y + 45;
  drawLine(`M ${user.x + user.w} ${yUserAdmin} L ${admin.x} ${yUserAdmin}`);
  drawBadge(user.x + user.w + 6, yUserAdmin - 5, '1');
  drawBadge(admin.x - 28, yUserAdmin - 5, '0..*');

  // 2. tbl_user (1) branching down to tbl_guest_reservation_requests (0..*)
  // Branch drops down in the gap between tbl_user and tbl_admin, runs under tbl_admin into tbl_guest
  const xDrop = user.x + user.w + 30;
  const yDropUnder = admin.y + admin.h + 50;
  drawLine(`M ${xDrop} ${yUserAdmin} L ${xDrop} ${yDropUnder} L ${guest.x} ${yDropUnder}`);
  drawBadge(xDrop + 6, user.y + 160, '1');
  drawBadge(guest.x - 28, yDropUnder - 5, '0..*');

  // 3. tbl_admin (1) -> tbl_guest_reservation_requests (0..*)
  const yAdminGuest = admin.y + 45;
  drawLine(`M ${admin.x + admin.w} ${yAdminGuest} L ${guest.x} ${yAdminGuest}`);
  drawBadge(admin.x + admin.w + 6, yAdminGuest - 5, '1');
  drawBadge(guest.x - 28, yAdminGuest - 5, '0..*');

  // 4. tbl_guest_reservation_requests (1) -> tbl_bookings (0..*)
  const yGuestBookings = guest.y + 45;
  drawLine(`M ${guest.x + guest.w} ${yGuestBookings} L ${bookings.x} ${yGuestBookings}`);
  drawBadge(guest.x + guest.w + 6, yGuestBookings - 5, '1');
  drawBadge(bookings.x - 28, yGuestBookings - 5, '0..*');

  // 5. tbl_bookings (1) -> tbl_booking_payment_schedule (0..*)
  const yBookingsPayment = bookings.y + 45;
  drawLine(`M ${bookings.x + bookings.w} ${yBookingsPayment} L ${payment.x} ${yBookingsPayment}`);
  drawBadge(bookings.x + bookings.w + 6, yBookingsPayment - 5, '1');
  drawBadge(payment.x - 28, yBookingsPayment - 5, '0..*');

  // 6. tbl_plot_pricing (0..*) -> tbl_plots (1)
  const yPricingPlots = pricing.y + 80;
  drawLine(`M ${pricing.x + pricing.w} ${yPricingPlots} L ${plots.x} ${yPricingPlots}`);
  drawBadge(pricing.x + pricing.w + 6, yPricingPlots - 5, '0..*');
  drawBadge(plots.x - 16, yPricingPlots - 5, '1');

  // 7. tbl_plots (1) -> tbl_plot_deceased_mapping (0..*)
  const yPlotsMapping = plots.y + 35;
  drawLine(`M ${plots.x + plots.w} ${yPlotsMapping} L ${mapping.x} ${yPlotsMapping}`);
  drawBadge(plots.x + plots.w + 6, yPlotsMapping - 5, '1');
  drawBadge(mapping.x - 28, yPlotsMapping - 5, '0..*');

  // 8. tbl_deceased (1) -> bottom of tbl_plot_deceased_mapping (0..*)
  // Starts at bottom-right corner of tbl_deceased, runs along bottom under pricing & plots, turns up into mapping
  const xDeceasedCorner = deceased.x + deceased.w;
  const yDeceasedCorner = deceased.y + deceased.h;
  const yBottomRail = Math.max(deceased.y + deceased.h + 30, plots.y + plots.h + 30);
  const xMappingBottom = mapping.x + 120;
  drawLine(`M ${xDeceasedCorner} ${yDeceasedCorner} L ${xDeceasedCorner} ${yBottomRail} L ${xMappingBottom} ${yBottomRail} L ${xMappingBottom} ${mapping.y + mapping.h}`);
  drawBadge(xDeceasedCorner + 8, yDeceasedCorner - 4, '1');
  drawBadge(xMappingBottom - 26, mapping.y + mapping.h + 18, '0..*');
}

function applyPositions(useStored = false) {
  cards.forEach(card => {
    card.style.left = card.getAttribute('data-x') + 'px';
    card.style.top = card.getAttribute('data-y') + 'px';
  });
  renderLines();
}

// Drag & Drop Functionality
let activeCard = null;
let startX = 0, startY = 0;
let initialLeft = 0, initialTop = 0;

cards.forEach(card => {
  card.addEventListener('mousedown', e => {
    if (e.button !== 0) return;
    activeCard = card;
    activeCard.classList.add('dragging');
    startX = e.clientX;
    startY = e.clientY;
    initialLeft = parseInt(card.style.left) || 0;
    initialTop = parseInt(card.style.top) || 0;
    e.preventDefault();
  });
});

window.addEventListener('mousemove', e => {
  if (!activeCard) return;
  const dx = e.clientX - startX;
  const dy = e.clientY - startY;
  activeCard.style.left = Math.max(0, initialLeft + dx) + 'px';
  activeCard.style.top = Math.max(0, initialTop + dy) + 'px';
  renderLines();
});

window.addEventListener('mouseup', () => {
  if (activeCard) {
    activeCard.classList.remove('dragging');
    activeCard = null;
  }
});

btnReset.addEventListener('click', () => {
  applyPositions(false);
});

window.addEventListener('resize', renderLines);
</script>
</body>
</html>
