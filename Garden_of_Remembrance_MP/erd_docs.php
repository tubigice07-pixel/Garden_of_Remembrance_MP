<?php
/**
 * Cemetery Management and Grave Mapping System
 * Entity Relationship Diagram (ERD) & Database Normalization Viewer
 * Complete 26-Table Production Schema with 1:N Cardinality Structure
 */

$dataFile = __DIR__ . '/config/erd_schema_data.json';
$erdDocFile = __DIR__ . '/Garden_of_Remembrance_CMIS_ERD_and_Normalization.doc';
$schemaDocFile = __DIR__ . '/Garden_of_Remembrance_CMIS_Database_Schema.doc';

if (!file_exists($dataFile)) {
    http_response_code(500);
    die("Error: Schema data file not found at " . htmlspecialchars($dataFile));
}

$schemaJson = file_get_contents($dataFile);
$schema = json_decode($schemaJson, true);

// -------------------------------------------------------------
// Helper: Re-generate .doc Files from current JSON data
// -------------------------------------------------------------
function regenerate_word_documents(array $data, string $erdPath, string $schemaPath): void
{
    // Simply invoke the builder if available or write current doc
    if (file_exists(__DIR__ . '/build_26_erd_doc.php')) {
        include_once __DIR__ . '/build_26_erd_doc.php';
    }
}

// -------------------------------------------------------------
// Handle File Downloads
// -------------------------------------------------------------
if (isset($_GET['download'])) {
    $type = $_GET['download'];
    if ($type === 'erd_doc' && file_exists($erdDocFile)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/msword');
        header('Content-Disposition: attachment; filename="Garden_of_Remembrance_CMIS_ERD_and_Normalization.doc"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($erdDocFile));
        readfile($erdDocFile);
        exit;
    }
    if ($type === 'schema_doc' && file_exists($schemaDocFile)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/msword');
        header('Content-Disposition: attachment; filename="Garden_of_Remembrance_CMIS_Database_Schema.doc"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($schemaDocFile));
        readfile($schemaDocFile);
        exit;
    }
    if ($type === 'json' && file_exists($dataFile)) {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="cemetery_erd_schema.json"');
        readfile($dataFile);
        exit;
    }
}

// Functional Grouping of the 26 tables
$groups = [
    'User Management & System Auditing' => [
        'icon' => 'fa-users',
        'tables' => ['tbl_user', 'tbl_logs', 'notifications', 'email_notifications']
    ],
    'Plot Layout & Ownership' => [
        'icon' => 'fa-map-location-dot',
        'tables' => ['tbl_plots', 'sections', 'lot_pricing', 'user_plot_ownership', 'tbl_plot_owners']
    ],
    'Deceased & Interment Records' => [
        'icon' => 'fa-monument',
        'tables' => ['tbl_deceased', 'tbl_deceased_transfers', 'plot_transfer_requests', 'plot_deceased_mapping']
    ],
    'Contract Bookings & Cemetery Services' => [
        'icon' => 'fa-file-signature',
        'tables' => ['tbl_bookings', 'booking_plots', 'booking_services', 'services', 'tbl_booking_logs']
    ],
    'Financial Amortization & Billing' => [
        'icon' => 'fa-receipt',
        'tables' => ['booking_payment_schedule', 'payment_schedules', 'payment_receipts', 'payment_reminder_history', 'sales_reports']
    ],
    'Reservations & Interactive Access' => [
        'icon' => 'fa-calendar-check',
        'tables' => ['guest_reservation_requests', 'reservations', 'interment_packages', 'tbl_recent_clicks']
    ]
];

$entitiesByName = [];
foreach ($schema['entities'] as $ent) {
    $entitiesByName[$ent['name']] = $ent;
}

$relationships = $schema['relationships'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cemetery Management and Grave Mapping System - ERD (26 Tables)</title>
  
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  
  <style>
    body {
      background-color: #f8fafc;
      color: #0f172a;
      font-family: 'Segoe UI', Arial, sans-serif;
      margin: 0;
      padding-bottom: 80px;
    }

    .top-toolbar {
      background: #0f172a;
      color: #ffffff;
      padding: 12px 28px;
      position: sticky;
      top: 0;
      z-index: 1000;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      border-bottom: 2px solid #e2e8f0;
    }

    .canvas-wrapper {
      background: #ffffff;
      width: 100%;
      max-width: 1480px;
      margin: 24px auto;
      padding: 36px 32px;
      border: 1px solid #cbd5e1;
      border-radius: 12px;
      box-shadow: 0 10px 35px rgba(0,0,0,0.06);
    }

    .title-banner {
      text-align: center;
      margin-bottom: 28px;
      padding-bottom: 16px;
      border-bottom: 2px solid #000000;
    }

    .title-banner h1 {
      font-size: 24px;
      font-weight: 800;
      margin: 0;
      color: #000000;
      text-transform: uppercase;
      letter-spacing: -0.3px;
    }

    .title-banner h2 {
      font-size: 18px;
      font-weight: 700;
      margin: 6px 0 0;
      color: #334155;
    }

    .title-banner .meta-badge {
      font-size: 13px;
      color: #64748b;
      margin-top: 6px;
    }

    /* Monochrome Box Styling */
    .erd-table-box {
      border: 1.8px solid #000000;
      background: #ffffff;
      margin-bottom: 16px;
      border-collapse: collapse;
      width: 100%;
      box-shadow: 0 2px 6px rgba(0,0,0,0.04);
    }

    .erd-table-box th.box-header {
      background: #ffffff;
      color: #000000;
      font-size: 12px;
      font-weight: 800;
      text-align: center;
      padding: 6px 8px;
      border-bottom: 1.8px solid #000000;
      font-family: Arial, sans-serif;
    }

    .erd-table-box td.key-cell {
      width: 32px;
      font-weight: 800;
      font-size: 10.5px;
      text-align: center;
      padding: 3px 4px;
      border-right: 1.2px solid #000000;
      border-bottom: 1px solid #e2e8f0;
      vertical-align: middle;
    }

    .erd-table-box td.attr-cell {
      font-size: 10.5px;
      padding: 3px 8px;
      border-bottom: 1px solid #e2e8f0;
      color: #000000;
      font-family: Arial, sans-serif;
    }

    .erd-table-box td.type-cell {
      font-size: 9.5px;
      padding: 3px 6px;
      border-bottom: 1px solid #e2e8f0;
      color: #64748b;
      text-align: right;
      font-family: 'Lucida Console', 'Courier New', monospace;
    }

    .domain-section-title {
      font-size: 14px;
      font-weight: 800;
      color: #000000;
      text-transform: uppercase;
      background-color: #f1f5f9;
      border-left: 5px solid #000000;
      padding: 6px 12px;
      margin-top: 24px;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .cardinality-badge {
      font-size: 11px;
      font-weight: 700;
      color: #000000;
      text-align: center;
      padding: 2px 0 4px;
    }

    .table-directory-wrapper {
      max-height: 380px;
      overflow-y: auto;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      margin-bottom: 24px;
    }

    .table-directory {
      width: 100%;
      border-collapse: collapse;
      font-size: 12px;
    }

    .table-directory th {
      background-color: #0f172a;
      color: #ffffff;
      padding: 6px 10px;
      position: sticky;
      top: 0;
      z-index: 10;
    }

    .table-directory td {
      padding: 5px 10px;
      border-bottom: 1px solid #e2e8f0;
    }

    .table-directory tr:hover {
      background-color: #f8fafc;
    }

    .norm-card {
      background: #ffffff;
      border: 1.5px solid #000000;
      border-radius: 6px;
      padding: 14px 16px;
      margin-bottom: 12px;
      font-size: 12.5px;
      line-height: 1.4;
    }

    @media print {
      .top-toolbar, .no-print {
        display: none !important;
      }
      body {
        background: #ffffff !important;
        padding: 0 !important;
      }
      .canvas-wrapper {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        max-width: 100% !important;
      }
      .erd-table-box {
        break-inside: avoid;
        page-break-inside: avoid;
      }
    }
  </style>
</head>
<body>

  <!-- Top Action Toolbar -->
  <div class="top-toolbar no-print">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
      <div class="d-flex align-items-center gap-2">
        <span class="badge bg-success px-2.5 py-1.5"><i class="fa-solid fa-database me-1"></i> 26 Tables Active</span>
        <span class="fw-bold fs-6">Garden of Remembrance CMIS &bull; Master ERD & Normalization</span>
      </div>

      <div class="d-flex flex-wrap align-items-center gap-2">
        <!-- Download Word Document -->
        <a href="Garden_of_Remembrance_CMIS_ERD_and_Normalization.doc" download="Garden_of_Remembrance_CMIS_ERD_and_Normalization.doc" class="btn btn-sm btn-primary fw-bold px-3">
          <i class="fa-solid fa-file-word me-1"></i> Download Word Document (.doc)
        </a>

        <!-- Download Schema Data Dictionary -->
        <a href="erd_docs.php?download=schema_doc" class="btn btn-sm btn-outline-light fw-bold">
          <i class="fa-solid fa-table-list me-1"></i> Data Dictionary (.doc)
        </a>

        <!-- Print / PDF -->
        <button onclick="window.print()" class="btn btn-sm btn-outline-light fw-bold">
          <i class="fa-solid fa-print me-1"></i> Print / PDF
        </button>
      </div>
    </div>
  </div>

  <!-- Main Canvas Container -->
  <div class="canvas-wrapper">
    
    <div class="title-banner">
      <h1><?= htmlspecialchars($schema['system_title'] ?? 'Cemetery Management and Grave Mapping System') ?></h1>
      <h2><?= htmlspecialchars($schema['document_subtitle'] ?? 'Entity Relationship Diagram (ERD)') ?></h2>
      <div class="meta-badge">
        <strong>Visual Entity Relationship Diagram with Connected Lines & Cardinality</strong>
      </div>
    </div>

    <!-- Visual ERD Diagram with Connecting Lines -->
    <div class="text-center mb-5">
      <img src="assets/images/cemetery_erd_diagram.jpg" alt="Entity Relationship Diagram (ERD) with Connected Lines" class="img-fluid border shadow-sm" style="max-width: 100%; border: 2px solid #000000 !important; background: #ffffff;" />
    </div>

    <!-- Master Cardinality Directory -->
    <div class="mb-4">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="fw-bold m-0"><i class="fa-solid fa-diagram-project me-2 text-primary"></i>Master One-to-Many (1 : 0..*) Cardinality Directory</h5>
        <span class="badge bg-dark"><?= count($relationships) ?> Explicit Relationships</span>
      </div>
      <div class="table-directory-wrapper">
        <table class="table-directory">
          <thead>
            <tr>
              <th style="width:5%;">#</th>
              <th style="width:20%;">Parent Master (1)</th>
              <th style="width:12%; text-align:center;">Cardinality</th>
              <th style="width:22%;">Child Entity (0..*)</th>
              <th style="width:16%;">Foreign Key</th>
              <th style="width:25%;">Relational Logic</th>
            </tr>
          </thead>
          <tbody>
            <?php $rIdx = 1; foreach ($relationships as $rel): ?>
            <tr>
              <td class="fw-bold text-center"><?= $rIdx++ ?></td>
              <td class="fw-bold text-primary"><?= htmlspecialchars($rel['from']) ?></td>
              <td class="fw-bold text-center text-success"><?= htmlspecialchars($rel['type']) ?></td>
              <td class="fw-bold"><?= htmlspecialchars($rel['to']) ?></td>
              <td><code><?= htmlspecialchars($rel['fk'] ?? '') ?></code></td>
              <td class="text-secondary"><?= htmlspecialchars($rel['desc'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 26 Tables Grouped by Domain -->
    <h5 class="fw-bold mb-3"><i class="fa-solid fa-layer-group me-2 text-primary"></i>26 Database Tables (Entities, Attributes & Keys)</h5>

    <?php foreach ($groups as $groupTitle => $grp): ?>
      <div class="domain-section-title">
        <span><i class="fa-solid <?= $grp['icon'] ?> me-2"></i><?= htmlspecialchars($groupTitle) ?> (<?= count($grp['tables']) ?> Tables)</span>
        <span style="font-size:11px; font-weight:normal; text-transform:none; color:#475569;">One-to-Many Relational Domain</span>
      </div>

      <div class="row g-3 align-items-start mb-3">
        <?php 
        $colWidthClass = count($grp['tables']) === 5 ? 'col-12 col-md-4 col-lg-2' : (count($grp['tables']) === 4 ? 'col-12 col-md-6 col-lg-3' : 'col-12 col-md-4');
        foreach ($grp['tables'] as $tName):
          $tData = $entitiesByName[$tName] ?? null;
          if (!$tData) continue;
          $isParent = in_array($tName, ['tbl_user', 'tbl_plots', 'tbl_bookings', 'tbl_deceased', 'services']);
        ?>
        <div class="<?= $colWidthClass ?>" style="min-width: 220px; flex: 1;">
          <div class="cardinality-badge">
            <span class="badge <?= $isParent ? 'bg-primary' : 'bg-secondary' ?>"><?= $isParent ? 'PARENT MASTER (1)' : 'CHILD DETAIL (0..*)' ?></span>
          </div>
          <table class="erd-table-box">
            <thead>
              <tr>
                <th colspan="3" class="box-header"><?= htmlspecialchars($tName) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tData['columns'] as $c): 
                $badge = $c['key'] ?? '';
              ?>
              <tr>
                <td class="key-cell"><?= htmlspecialchars($badge) ?></td>
                <td class="attr-cell"><?= htmlspecialchars($c['name']) ?></td>
                <td class="type-cell"><?= htmlspecialchars($c['type'] ?? '') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <!-- Normalization Section -->
    <div class="mt-5">
      <h5 class="fw-bold mb-3"><i class="fa-solid fa-check-double me-2 text-success"></i>Database Normalization Analysis (1NF, 2NF, 3NF Across All 26 Tables)</h5>

      <div class="norm-card">
        <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-atom me-2 text-primary"></i>First Normal Form (1NF) &mdash; Attribute Atomicity & Elimination of Repeating Groups</h6>
        <p class="mb-0 text-secondary">
          Every attribute across the 26 tables contains atomic, indivisible values. No multi-valued attributes or repeating columns exist. Plot coordinates are separated into distinct numerical columns (<code>plot_x</code> and <code>plot_y</code>), names are partitioned into atomic name fields, and multiple plots or services per contract are managed through dedicated child associative tables (<code>booking_plots</code>, <code>booking_services</code>). Every table possesses a defined Primary Key ensuring unique record identification.
        </p>
      </div>

      <div class="norm-card">
        <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-key me-2 text-primary"></i>Second Normal Form (2NF) &mdash; Elimination of Partial Functional Dependencies</h6>
        <p class="mb-0 text-secondary">
          All 26 tables satisfy 1NF and ensure that every non-key column is fully functionally dependent on the entire Primary Key. In all tables utilizing surrogate Primary Keys (such as <code>user_id</code>, <code>plot_id</code>, <code>id</code>, <code>deceased_id</code>), partial dependencies on composite keys are mathematically eliminated. In associative relationship tables (<code>plot_deceased_mapping</code>, <code>booking_plots</code>), surrogate identifiers paired with explicit Foreign Keys guarantee full dependency on the entity identifier.
        </p>
      </div>

      <div class="norm-card">
        <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-network-wired me-2 text-primary"></i>Third Normal Form (3NF) &mdash; Elimination of Transitive Functional Dependencies</h6>
        <p class="mb-0 text-secondary">
          All tables satisfy 2NF and contain no transitive functional dependencies (where a non-key attribute depends on another non-key attribute). Service pricing catalog, plot pricing matrix, section boundaries, and installment schedules are isolated into dedicated master and child tables (<code>services</code>, <code>lot_pricing</code>, <code>sections</code>, <code>booking_payment_schedule</code>) referenced strictly via Foreign Keys, rather than recalculating or storing redundant attributes in parent booking or plot records.
        </p>
      </div>
    </div>

  </div>

</body>
</html>
