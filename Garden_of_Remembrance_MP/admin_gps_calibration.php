<?php

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/includes/gps_calculator.php';

$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$userRole = strtoupper(trim((string)($_SESSION['role'] ?? 'GUEST')));
$isAdmin = ($isLoggedIn && ($userRole === 'ADMIN' || !empty($_SESSION['admin_id'])));

if (!$isAdmin) {
    $_SESSION['flash_error'] = 'Access denied. Administrative privileges required to manage GPS calibration.';
    header('Location: login.php');
    exit;
}

if (isset($_REQUEST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = trim((string)$_REQUEST['ajax_action']);

    if ($action === 'save_settings') {
        $settings = [
            'cemetery_ref_lat' => floatval($_POST['cemetery_ref_lat'] ?? 16.15024000),
            'cemetery_ref_lng' => floatval($_POST['cemetery_ref_lng'] ?? 119.98232000),
            'orientation_deg' => floatval($_POST['orientation_deg'] ?? -15.5),
            'meters_per_pixel_x' => floatval($_POST['meters_per_pixel_x'] ?? 0.31),
            'meters_per_pixel_y' => floatval($_POST['meters_per_pixel_y'] ?? 0.31),
            'enable_auto_calculation' => !empty($_POST['enable_auto_calculation']) ? 1 : 0
        ];
        $ok = CemeteryGPSCalculator::saveSettings($conn, $settings);
        echo json_encode(['success' => $ok, 'message' => $ok ? 'Calibration settings saved successfully.' : 'Failed to save settings.']);
        exit;
    }

    if ($action === 'save_point') {
        $res = CemeteryGPSCalculator::saveCalibrationPoint($conn, $_POST);
        echo json_encode($res);
        exit;
    }

    if ($action === 'delete_point') {
        $id = intval($_POST['id'] ?? 0);
        $ok = CemeteryGPSCalculator::deleteCalibrationPoint($conn, $id);
        echo json_encode(['success' => $ok, 'message' => $ok ? 'Calibration reference point deleted.' : 'Failed to delete point.']);
        exit;
    }

    if ($action === 'test_plot') {
        $section = strtoupper(trim((string)($_POST['section'] ?? 'A')));
        $plotX = intval($_POST['plot_x'] ?? 1);
        $plotY = intval($_POST['plot_y'] ?? 1);
        $result = CemeteryGPSCalculator::calculatePlotGPS($conn, 0, $section, $plotX, $plotY, false);
        echo json_encode($result);
        exit;
    }

    if ($action === 'bulk_recalculate') {
        $section = !empty($_POST['section']) ? strtoupper(trim((string)$_POST['section'])) : null;
        $overwriteManual = !empty($_POST['overwrite_manual']) ? true : false;
        $stats = CemeteryGPSCalculator::bulkRecalculate($conn, $section, $overwriteManual);
        echo json_encode($stats);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

$calibPoints = CemeteryGPSCalculator::getCalibrationPoints($conn, false);
$settings = CemeteryGPSCalculator::getSettings($conn);
$model = CemeteryGPSCalculator::solveCalibrationTransform($conn, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS Calibration & Coordinate Management - Garden of Remembrance</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-burgundy: #7b1113;
            --primary-hover: #9c1518;
            --bg-light: #f8fafc;
            --card-border: #e2e8f0;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-light);
            color: #1e293b;
            min-height: 100vh;
        }
        .admin-navbar {
            background: #ffffff;
            border-bottom: 1px solid var(--card-border);
            padding: 0.75rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 1030;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .navbar-brand {
            font-weight: 700;
            color: var(--primary-burgundy);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .navbar-brand img {
            height: 36px;
        }
        .card-custom {
            background: #ffffff;
            border: 1px solid var(--card-border);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            margin-bottom: 1.5rem;
        }
        .card-header-custom {
            background: transparent;
            border-bottom: 1px solid var(--card-border);
            padding: 1rem 1.25rem;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .badge-calculated {
            background-color: #e0f2fe;
            color: #0369a1;
            font-weight: 600;
        }
        .badge-manual {
            background-color: #fef3c7;
            color: #b45309;
            font-weight: 600;
        }
        .btn-burgundy {
            background-color: var(--primary-burgundy);
            color: #ffffff;
            font-weight: 600;
            border: none;
        }
        .btn-burgundy:hover {
            background-color: var(--primary-hover);
            color: #ffffff;
        }
        .table thead th {
            background-color: #f1f5f9;
            color: #475569;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            border-bottom: 1px solid var(--card-border);
        }
        .form-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: #475569;
        }
        .map-interactive-container {
            position: relative;
            display: inline-block;
            max-width: 100%;
            overflow: auto;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            background: #f1f5f9;
        }
        .map-interactive-container img {
            display: block;
            width: 600px;
            height: 750px;
            cursor: crosshair;
        }
        .gcp-marker {
            position: absolute;
            width: 22px;
            height: 22px;
            transform: translate(-50%, -50%);
            background: #ef4444;
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: bold;
            border: 2px solid #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
            pointer-events: none;
        }
    </style>
</head>
<body>

<nav class="admin-navbar d-flex justify-content-between align-items-center">
    <a href="dashboard.php" class="navbar-brand text-decoration-none">
        <img src="cmis_logo.png" alt="CMIS Logo" onerror="this.style.display='none'">
        <span>Garden of Remembrance · GPS Calibration</span>
    </a>
    <div class="d-flex align-items-center gap-2">
        <a href="map.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-map me-1"></i>View Cemetery Map</a>
        <a href="dashboard.php" class="btn btn-outline-dark btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
    </div>
</nav>

<div class="container-fluid py-4 px-lg-4">

    <?php if (!empty($model['calibrated'])): ?>
    <div class="alert alert-success d-flex align-items-center mb-4 border-0 shadow-sm" style="border-radius: 10px; background-color: #f0fdf4; color: #166534; border-left: 4px solid #22c55e !important;">
        <i class="fas fa-check-circle fs-3 me-3 text-success"></i>
        <div class="flex-grow-1">
            <strong>Cemetery Map Calibrated (Conformal Least-Squares Model):</strong>
            Active Reference Points: <strong><?= (int)$model['point_count'] ?></strong> | 
            Calculated Scale: <strong><?= number_format($model['scale_m_per_px'], 4) ?> m/px</strong> | 
            Orientation Rotation: <strong><?= number_format($model['rotation_deg'], 2) ?>°</strong> | 
            Model Quality (RMSE): <strong><?= number_format($model['rmse_meters'], 2) ?> meters</strong>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-warning d-flex align-items-center mb-4 border-0 shadow-sm" style="border-radius: 10px; background-color: #fffbeb; color: #92400e; border-left: 4px solid #f59e0b !important;">
        <i class="fas fa-exclamation-triangle fs-3 me-3 text-warning"></i>
        <div>
            <strong>GPS Calibration Incomplete:</strong>
            GPS calibration is required before plot-level navigation can be considered accurate. Please configure at least 2 active Ground Control Points with known real-world coordinates.
        </div>
    </div>
    <?php endif; ?>

    <div class="row">

        <div class="col-lg-4 col-12">

            <div class="card-custom">
                <div class="card-header-custom">
                    <span><i class="fas fa-crosshairs me-2 text-danger"></i>Interactive Plot GPS Tester</span>
                </div>
                <div class="card-body p-3">
                    <form id="plotTestForm">
                        <div class="row g-2 mb-3">
                            <div class="col-4">
                                <label class="form-label">Section</label>
                                <select class="form-select" name="section" id="testSection">
                                    <option value="A">Section A</option>
                                    <option value="B">Section B</option>
                                    <option value="C">Section C</option>
                                    <option value="D">Section D</option>
                                    <option value="E">Section E</option>
                                    <option value="F">Section F</option>
                                    <option value="G">Section G</option>
                                    <option value="H">Section H</option>
                                    <option value="I">Section I</option>
                                    <option value="J">Section J</option>
                                </select>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Row (Plot X)</label>
                                <input type="number" class="form-control" name="plot_x" id="testPlotX" value="1" min="1" max="100" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label">Plot Y</label>
                                <input type="number" class="form-control" name="plot_y" id="testPlotY" value="2" min="1" max="10" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-outline-primary w-100 mb-3"><i class="fas fa-calculator me-1"></i>Compute Coordinates</button>
                    </form>

                    <div id="testOutputBox" style="display:none;" class="p-3 bg-light rounded border">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong id="outPlotName">Section A · Plot 1-2</strong>
                            <span id="outBadge" class="badge badge-calculated">CALCULATED</span>
                        </div>
                        <div class="small mb-1"><strong>Latitude:</strong> <span id="outLat" class="font-monospace text-primary"></span></div>
                        <div class="small mb-1"><strong>Longitude:</strong> <span id="outLng" class="font-monospace text-primary"></span></div>
                        <div class="small mb-1"><strong>Section Layout:</strong> <span id="outLayout" class="text-muted"></span></div>
                        <div class="small mb-1"><strong>Overview Coords:</strong> <span id="outOverview" class="text-muted"></span></div>
                        <div class="small mb-3"><strong>Offset from Origin:</strong> <span id="outOffset" class="text-muted"></span></div>
                        <a id="outNavBtn" href="#" target="_blank" class="btn btn-success btn-sm w-100">
                            <i class="fas fa-directions me-1"></i>Open Google Maps Navigation
                        </a>
                    </div>
                </div>
            </div>

            <div class="card-custom">
                <div class="card-header-custom">
                    <span><i class="fas fa-sync-alt me-2 text-warning"></i>Batch GPS Recalculation</span>
                </div>
                <div class="card-body p-3">
                    <p class="small text-muted mb-3">Recalculate coordinates for all plots stored in the database based on the active calibration model.</p>
                    <form id="bulkForm">
                        <div class="mb-3">
                            <label class="form-label">Section Scope</label>
                            <select class="form-select" name="section">
                                <option value="">Entire Cemetery (All Sections)</option>
                                <option value="A">Section A Only</option>
                                <option value="B">Section B Only</option>
                                <option value="C">Section C Only</option>
                                <option value="D">Section D Only</option>
                                <option value="E">Section E Only</option>
                                <option value="F">Section F Only</option>
                            </select>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="overwriteManual" name="overwrite_manual" value="1">
                            <label class="form-check-label small text-danger fw-bold" for="overwriteManual">
                                Overwrite manual coordinates if present
                            </label>
                        </div>
                        <button type="submit" class="btn btn-warning w-100 text-dark fw-bold" id="bulkBtn">
                            <i class="fas fa-cogs me-1"></i>Run Batch Recalculation
                        </button>
                    </form>
                    <div id="bulkResult" class="mt-3 small" style="display:none;"></div>
                </div>
            </div>

            <div class="card-custom">
                <div class="card-header-custom">
                    <span><i class="fas fa-sliders-h me-2 text-primary"></i>Fallback Calibration Settings</span>
                </div>
                <div class="card-body p-3">
                    <form id="settingsForm">
                        <div class="mb-3">
                            <label class="form-label">Reference Latitude</label>
                            <input type="number" step="0.00000001" class="form-control" name="cemetery_ref_lat" value="<?= htmlspecialchars($settings['cemetery_ref_lat']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Reference Longitude</label>
                            <input type="number" step="0.00000001" class="form-control" name="cemetery_ref_lng" value="<?= htmlspecialchars($settings['cemetery_ref_lng']) ?>" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label">Scale (m/px)</label>
                                <input type="number" step="0.001" class="form-control" name="meters_per_pixel_x" value="<?= htmlspecialchars($settings['meters_per_pixel_x']) ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Rotation (°)</label>
                                <input type="number" step="0.1" class="form-control" name="orientation_deg" value="<?= htmlspecialchars($settings['orientation_deg']) ?>" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-burgundy btn-sm w-100"><i class="fas fa-save me-1"></i>Save Fallback Settings</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8 col-12">

            <div class="card-custom">
                <div class="card-header-custom">
                    <span><i class="fas fa-map-marker-alt me-2 text-danger"></i>Ground Control Reference Points (GCPs)</span>
                    <button type="button" class="btn btn-burgundy btn-sm" onclick="showAddPointModal()">
                        <i class="fas fa-plus me-1"></i>Add Reference Point
                    </button>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Point Name</th>
                                <th>Map X / Y</th>
                                <th>Latitude</th>
                                <th>Longitude</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($calibPoints)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">No reference points defined. Please click on the cemetery map or click "Add Reference Point".</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($calibPoints as $idx => $pt): ?>
                            <tr>
                                <td><span class="badge bg-secondary"><?= $idx + 1 ?></span></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($pt['point_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($pt['notes'] ?: 'No notes') ?></small>
                                </td>
                                <td><?= (float)$pt['map_x'] ?>, <?= (float)$pt['map_y'] ?></td>
                                <td class="font-monospace small"><?= number_format($pt['latitude'], 8) ?></td>
                                <td class="font-monospace small"><?= number_format($pt['longitude'], 8) ?></td>
                                <td>
                                    <?= $pt['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Disabled</span>' ?>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-outline-dark btn-sm me-1" onclick='editPoint(<?= json_encode($pt) ?>)' title="Edit Anchor">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm" onclick="deletePoint(<?= $pt['id'] ?>, '<?= htmlspecialchars($pt['point_name']) ?>')" title="Delete Anchor">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-custom">
                <div class="card-header-custom">
                    <span><i class="fas fa-map me-2 text-primary"></i>Visual Map Reference Point Picker</span>
                    <small class="text-muted">Click anywhere on the map to set reference point coordinates (Map X, Map Y)</small>
                </div>
                <div class="card-body p-3 text-center">
                    <div class="map-interactive-container" id="mapClickContainer">
                        <img src="cmi_map.png" alt="Cemetery Overview Map" id="overviewMapImg">
                        <?php foreach ($calibPoints as $idx => $pt): if ($pt['is_active']): ?>
                        <div class="gcp-marker" style="left: <?= (float)$pt['map_x'] ?>px; top: <?= (float)$pt['map_y'] ?>px;" title="<?= htmlspecialchars($pt['point_name']) ?>">
                            <?= $idx + 1 ?>
                        </div>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="pointModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-burgundy text-white" style="background: var(--primary-burgundy);">
                <h5 class="modal-title" id="pointModalTitle"><i class="fas fa-map-marker-alt me-2"></i>Reference Point</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="pointForm">
                <div class="modal-body p-4">
                    <input type="hidden" name="id" id="pointId" value="0">
                    <div class="mb-3">
                        <label class="form-label">Point Name</label>
                        <input type="text" class="form-control" name="point_name" id="pointName" placeholder="e.g. Section A Shed Anchor" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Associated Section (Optional)</label>
                        <select class="form-select" name="section" id="pointSection">
                            <option value="">None (General Cemetery Landmark)</option>
                            <option value="A">Section A</option>
                            <option value="B">Section B</option>
                            <option value="C">Section C</option>
                            <option value="D">Section D</option>
                            <option value="E">Section E</option>
                            <option value="F">Section F</option>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Map Pixel X</label>
                            <input type="number" step="0.1" class="form-control" name="map_x" id="pointMapX" value="0" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Map Pixel Y</label>
                            <input type="number" step="0.1" class="form-control" name="map_y" id="pointMapY" value="0" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Real Latitude (WGS84)</label>
                            <input type="number" step="0.00000001" class="form-control font-monospace" name="latitude" id="pointLat" placeholder="16.1502..." required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Real Longitude (WGS84)</label>
                            <input type="number" step="0.00000001" class="form-control font-monospace" name="longitude" id="pointLng" placeholder="119.9819..." required>
                        </div>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="pointActive" name="is_active" value="1" checked>
                        <label class="form-check-label" for="pointActive">Active in Calibration Model</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes / Real-World Description</label>
                        <textarea class="form-control" name="notes" id="pointNotes" rows="2" placeholder="e.g. Measured at northwest corner of shed roof"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-burgundy"><i class="fas fa-check me-1"></i>Save Reference Point</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const pointModal = new bootstrap.Modal(document.getElementById('pointModal'));

function showAddPointModal(mapX = 0, mapY = 0) {
    document.getElementById('pointId').value = '0';
    document.getElementById('pointModalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>Add Reference Point';
    document.getElementById('pointForm').reset();
    document.getElementById('pointActive').checked = true;
    if (mapX > 0 && mapY > 0) {
        document.getElementById('pointMapX').value = mapX.toFixed(1);
        document.getElementById('pointMapY').value = mapY.toFixed(1);
    }
    pointModal.show();
}

function editPoint(pt) {
    document.getElementById('pointId').value = pt.id;
    document.getElementById('pointModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Reference Point';
    document.getElementById('pointName').value = pt.point_name;
    document.getElementById('pointSection').value = pt.section || '';
    document.getElementById('pointMapX').value = pt.map_x;
    document.getElementById('pointMapY').value = pt.map_y;
    document.getElementById('pointLat').value = pt.latitude;
    document.getElementById('pointLng').value = pt.longitude;
    document.getElementById('pointActive').checked = (pt.is_active == 1);
    document.getElementById('pointNotes').value = pt.notes || '';
    pointModal.show();
}

function deletePoint(id, name) {
    if (!confirm(`Are you sure you want to delete reference point "${name}"?`)) return;
    const formData = new FormData();
    formData.append('ajax_action', 'delete_point');
    formData.append('id', id);

    fetch('admin_gps_calibration.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert(data.message || 'Delete failed.');
        }
    });
}

// Click on visual map to add point
document.getElementById('overviewMapImg').addEventListener('click', function(e) {
    const rect = this.getBoundingClientRect();
    const scaleX = this.naturalWidth / rect.width;
    const scaleY = this.naturalHeight / rect.height;
    const clickX = (e.clientX - rect.left) * scaleX;
    const clickY = (e.clientY - rect.top) * scaleY;
    showAddPointModal(clickX, clickY);
});

document.getElementById('pointForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('ajax_action', 'save_point');

    fetch('admin_gps_calibration.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            pointModal.hide();
            location.reload();
        } else {
            alert(data.message || 'Error saving point.');
        }
    });
});

document.getElementById('settingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('ajax_action', 'save_settings');

    fetch('admin_gps_calibration.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        alert(data.message || 'Settings updated.');
    });
});

document.getElementById('plotTestForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('ajax_action', 'test_plot');

    fetch('admin_gps_calibration.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.has_coordinates) {
            document.getElementById('outPlotName').innerText = data.plot_name || 'Plot';
            document.getElementById('outBadge').innerText = (data.coordinate_source || 'CALCULATED').toUpperCase();
            document.getElementById('outBadge').className = data.coordinate_source === 'manual' ? 'badge badge-manual' : 'badge badge-calculated';
            document.getElementById('outLat').innerText = Number(data.latitude).toFixed(8);
            document.getElementById('outLng').innerText = Number(data.longitude).toFixed(8);
            document.getElementById('outLayout').innerText = data.layout_pixels ? `cx: ${data.layout_pixels.cx}, cy: ${data.layout_pixels.cy}` : 'N/A';
            document.getElementById('outOverview').innerText = data.overview_coords ? `ox: ${data.overview_coords.ox}, oy: ${data.overview_coords.oy}` : 'N/A';
            document.getElementById('outOffset').innerText = data.offset_meters ? `${data.offset_meters.east}m East, ${data.offset_meters.north}m North` : '0m';
            document.getElementById('outNavBtn').href = data.navigation_url || '#';
            document.getElementById('testOutputBox').style.display = 'block';
        } else {
            alert(data.message || 'Coordinates could not be calculated for this plot.');
        }
    });
});

document.getElementById('bulkForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if (!confirm('Run batch GPS recalculation for plots?')) return;

    const btn = document.getElementById('bulkBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Calculating...';

    const formData = new FormData(this);
    formData.append('ajax_action', 'bulk_recalculate');

    fetch('admin_gps_calibration.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        const resBox = document.getElementById('bulkResult');
        resBox.style.display = 'block';
        if (data.success) {
            resBox.className = 'mt-3 small alert alert-success';
            resBox.innerHTML = `<strong>Batch Completed:</strong> ${data.updated} updated, ${data.skipped_manual} skipped (manual override), ${data.errors} errors out of ${data.total_plots} total plots.`;
        } else {
            resBox.className = 'mt-3 small alert alert-danger';
            resBox.innerText = data.message || 'Batch failed: ' + (data.message || 'Calibration required.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        alert('Batch recalculation failed.');
    });
});
</script>
</body>
</html>
