<?php

if (!isset($active_nav)) {
    $active_nav = '';
}
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}
if (file_exists(__DIR__ . '/notification_component.php')) {
    require_once __DIR__ . '/notification_component.php';
} elseif (file_exists(__DIR__ . '/../notification_component.php')) {
    require_once __DIR__ . '/../notification_component.php';
}
$admin_profile_pic = 'uploads/profile_pictures/default_avatar.png';
$admin_display_name = 'Administrator';

if (!empty($profile_image) && (file_exists(__DIR__ . '/../' . ltrim($profile_image, '/')) || file_exists($profile_image))) {
    $admin_profile_pic = ltrim($profile_image, '/');
} elseif (!empty($_SESSION['profile_picture']) && (file_exists(__DIR__ . '/../' . ltrim($_SESSION['profile_picture'], '/')) || file_exists($_SESSION['profile_picture']))) {
    $admin_profile_pic = ltrim($_SESSION['profile_picture'], '/');
}

$uid_hdr = (int)($_SESSION['user_id'] ?? ($_SESSION['admin_id'] ?? 0));
if ($uid_hdr > 0) {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        @include_once __DIR__ . '/../connection.php';
    }
    if (isset($conn) && $conn instanceof mysqli) {
        $uQ = $conn->query("SELECT profile_picture, first_name, last_name FROM tbl_user WHERE user_id = $uid_hdr LIMIT 1");
        if ($uQ && $uRow = $uQ->fetch_assoc()) {
            if (!empty($uRow['profile_picture'])) {
                $clean_pic = ltrim($uRow['profile_picture'], '/');
                if (file_exists(__DIR__ . '/../' . $clean_pic) || file_exists($clean_pic)) {
                    $admin_profile_pic = $clean_pic;
                    $_SESSION['profile_picture'] = $clean_pic;
                }
            }
            $fullname_hdr = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
            if (!empty($fullname_hdr)) {
                $admin_display_name = $fullname_hdr;
                $_SESSION['first_name'] = $uRow['first_name'] ?? '';
                $_SESSION['last_name'] = $uRow['last_name'] ?? '';
            }
        }
    }
}

$pending_claims_count = 0;
$pending_transfers_count = 0;
$recent_logs_count = 0;

if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
    try {
        $qClaims = $conn->query("SELECT COUNT(*) AS c FROM user_plot_ownership WHERE verification_status = 'PENDING'");
        if ($qClaims && $rClaims = $qClaims->fetch_assoc()) {
            $pending_claims_count = (int)$rClaims['c'];
        }
    } catch (Throwable $e) {}

    try {
        $qTrans = $conn->query("SELECT COUNT(*) AS c FROM transfer_requests WHERE status = 'pending'");
        if ($qTrans && $rTrans = $qTrans->fetch_assoc()) {
            $pending_transfers_count = (int)$rTrans['c'];
        }
    } catch (Throwable $e) {}

    try {
        $qLogs = $conn->query("SELECT COUNT(*) AS c FROM tbl_logs WHERE timestamp >= (NOW() - INTERVAL 24 HOUR)");
        if ($qLogs && $rLogs = $qLogs->fetch_assoc()) {
            $recent_logs_count = (int)$rLogs['c'];
        }
    } catch (Throwable $e) {}

    $pending_guest_requests_count = 0;
    try {
        $qGrr = $conn->query("SELECT COUNT(*) AS c FROM guest_reservation_requests WHERE status = 'Pending'");
        if ($qGrr && $rGrr = $qGrr->fetch_assoc()) {
            $pending_guest_requests_count = (int)$rGrr['c'];
        }
    } catch (Throwable $e) {}
}
?>

<?php
$hdr_assets_prefix = (file_exists('includes/ie_polyfills.js')) ? '' : ((file_exists('../includes/ie_polyfills.js')) ? '../' : '');
?>
<!-- IE11 / Edge-Legacy polyfills (URLSearchParams, fetch, Promise, etc.) -->
<script src="<?php echo $hdr_assets_prefix; ?>includes/ie_polyfills.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo $hdr_assets_prefix; ?>includes/admin_header.css">

<div class="mobile-toolbar">
  <div class="mobile-toolbar-inner">
    <button type="button" class="mobile-menu-btn" id="mobileMenuButton" aria-label="Open navigation menu">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
    </button>
    <a href="dashboard.php" class="mobile-brand-link" aria-label="Garden of Remembrance">
      <img src="cmis_logo.png" alt="Memorial Park Logo" class="mobile-logo">
      <span class="mobile-toolbar-title" style="color: #ffffff !important; font-weight: 700 !important; font-size: 0.95rem !important; white-space: nowrap !important; display: inline-block !important;">Garden of Remembrance</span>
    </a>
    <div class="d-flex align-items-center gap-1">
      <?php if (function_exists('render_mobile_notification_bell')) echo render_mobile_notification_bell(); ?>
      <a href="admin_profile.php" class="mobile-profile-btn" aria-label="Admin Profile" title="Admin Profile">
        <img src="<?php echo htmlspecialchars($admin_profile_pic); ?>" alt="Admin Avatar" class="mobile-profile-avatar" onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'%23ffffff\'><path d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/></svg>'">
      </a>
    </div>
  </div>
</div>
<div class="mobile-drawer-overlay" id="mobileDrawerOverlay"></div>
<aside class="mobile-drawer" id="mobileDrawer" aria-hidden="true">
  <div class="mobile-drawer-header">
    <div class="d-flex align-items-center gap-2">
      <img src="cmis_logo.png" alt="Logo" style="height: 32px; width: auto; object-fit: contain;">
      <div class="mobile-drawer-title">Admin Portal</div>
    </div>
    <button type="button" class="mobile-drawer-close" id="mobileDrawerClose" aria-label="Close navigation menu">×</button>
  </div>
  <a href="admin_profile.php" class="mobile-drawer-profile-card text-decoration-none">
    <img src="<?php echo htmlspecialchars($admin_profile_pic); ?>" alt="Avatar" class="drawer-avatar" onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'%237b1113\'><path d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/></svg>'">
    <div class="drawer-profile-info">
      <div class="drawer-profile-name"><?php echo htmlspecialchars($admin_display_name); ?></div>
      <div class="drawer-profile-role"><i class="fas fa-shield-alt text-warning me-1"></i> Administrator</div>
    </div>
    <span class="drawer-profile-arrow">›</span>
  </a>
  <nav class="mobile-drawer-nav">
    <a href="dashboard.php" class="<?php echo ($active_nav === 'dashboard') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-home"></i></span> <span>Dashboard</span>
    </a>
    <a href="deceased_records.php" class="<?php echo ($active_nav === 'deceased') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-cross"></i></span> <span>Deceased Records</span>
    </a>
    <a href="dashboard.php?open=booking" onclick="if(window.closeMobileDrawer)closeMobileDrawer();" class="<?php echo ($active_nav === 'booking') ? 'active' : ''; ?>" <?php if ($active_nav === 'dashboard') echo 'data-bs-toggle="modal" data-bs-target="#bookingModal"'; ?>>
      <span class="nav-icon"><i class="fas fa-calendar-alt"></i></span> <span>Reservations</span>
    </a>
    <a href="map.php" class="<?php echo ($active_nav === 'map') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-map"></i></span> <span>Maps</span>
    </a>
    <a href="logs.php" class="<?php echo ($active_nav === 'logs') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span> <span>Logs</span>
      <?php if ($recent_logs_count > 0): ?>
        <span class="nav-notif-dot ms-auto"></span>
      <?php endif; ?>
    </a>
    <a href="sales_reports.php" class="<?php echo ($active_nav === 'sales_reports') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-chart-line"></i></span> <span>Sales / Reports</span>
    </a>
    <a href="monthly_payments.php" class="<?php echo ($active_nav === 'monthly_payments') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span> <span>Monthly Payments</span>
    </a>
    <a href="transfer_requests.php" class="<?php echo ($active_nav === 'transfers') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-exchange-alt"></i></span> <span>Transfers</span>
      <?php if ($pending_transfers_count > 0): ?>
        <span class="nav-notif-badge ms-auto"><?php echo $pending_transfers_count; ?></span>
      <?php endif; ?>
    </a>
    <a href="claims_review.php" class="<?php echo ($active_nav === 'claims') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-id-card"></i></span> <span>Claims Review</span>
      <?php if ($pending_claims_count > 0): ?>
        <span class="nav-notif-badge ms-auto"><?php echo $pending_claims_count; ?></span>
      <?php endif; ?>
    </a>
    <a href="guest_reservations.php" class="<?php echo ($active_nav === 'guest_reservations') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-user-clock"></i></span> <span>Guest Requests</span>
      <?php if ($pending_guest_requests_count > 0): ?>
        <span class="nav-notif-badge ms-auto"><?php echo $pending_guest_requests_count; ?></span>
      <?php endif; ?>
    </a>
    <a href="admin_profile.php" class="<?php echo ($active_nav === 'profile') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-user-circle"></i></span> <span>Admin Profile</span>
    </a>
    <a href="admin_about.php" class="<?php echo ($active_nav === 'about') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-info-circle"></i></span> <span>About</span>
    </a>
    <a href="logout.php" class="drawer-logout-link">
      <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span> <span>Logout</span>
    </a>
  </nav>
  <div class="mobile-drawer-footer">Garden of Remembrance Memorial Park CMIS</div>
</aside>

<header class="admin-topbar">
  <div class="admin-topbar-inner">
    <a href="dashboard.php" class="admin-brand" aria-label="Garden of Remembrance Memorial Park">
      <img src="cmis_logo.png" alt="Garden of Remembrance Logo" class="admin-brand-logo">
      <span class="admin-brand-title">Garden of Remembrance</span>
    </a>
    <nav class="admin-nav" aria-label="Main Navigation">
      <a href="dashboard.php" class="topnav-link <?php echo ($active_nav === 'dashboard') ? 'active' : ''; ?>"><i class="fas fa-home me-1"></i> DASHBOARD</a>
      <a href="deceased_records.php" class="topnav-link <?php echo ($active_nav === 'deceased') ? 'active' : ''; ?>" title="Deceased Persons Registry"><i class="fas fa-cross me-1"></i> DECEASED</a>
      <a href="<?php echo ($active_nav === 'dashboard' ? '#' : 'dashboard.php?open=booking'); ?>" class="topnav-link <?php echo ($active_nav === 'booking') ? 'active' : ''; ?>" <?php if ($active_nav === 'dashboard') echo 'data-bs-toggle="modal" data-bs-target="#bookingModal"'; ?>><i class="fas fa-calendar-plus me-1"></i> RESERVATIONS</a>
      <a href="map.php" class="topnav-link <?php echo ($active_nav === 'map') ? 'active' : ''; ?>"><i class="fas fa-map me-1"></i> MAP</a>
      <a href="logs.php" class="topnav-link <?php echo ($active_nav === 'logs') ? 'active' : ''; ?>" title="Activity Logs">
        <i class="fas fa-clipboard-list me-1"></i> LOGS
        <?php if ($recent_logs_count > 0): ?>
          <span class="nav-notif-dot" title="<?php echo $recent_logs_count; ?> new activity log(s)"></span>
        <?php endif; ?>
      </a>
      <a href="sales_reports.php" class="topnav-link <?php echo ($active_nav === 'sales_reports') ? 'active' : ''; ?>" title="Sales &amp; Analytics Reports"><i class="fas fa-chart-line me-1"></i> REPORTS</a>
      <a href="monthly_payments.php" class="topnav-link <?php echo ($active_nav === 'monthly_payments') ? 'active' : ''; ?>" title="Monthly Payment Records per Account"><i class="fas fa-file-invoice-dollar me-1"></i> PAYMENTS</a>
      <a href="transfer_requests.php" class="topnav-link <?php echo ($active_nav === 'transfers') ? 'active' : ''; ?>" title="Plot Transfer Requests">
        <i class="fas fa-exchange-alt me-1"></i> TRANSFERS
        <?php if ($pending_transfers_count > 0): ?>
          <span class="nav-notif-dot" title="<?php echo $pending_transfers_count; ?> pending transfer(s)"></span>
          <span class="nav-notif-badge"><?php echo $pending_transfers_count; ?></span>
        <?php endif; ?>
      </a>
      <a href="claims_review.php" class="topnav-link <?php echo ($active_nav === 'claims') ? 'active' : ''; ?>" title="Plot Ownership Claims Review">
        <i class="fas fa-id-card me-1"></i> CLAIMS
        <?php if ($pending_claims_count > 0): ?>
          <span class="nav-notif-dot" title="<?php echo $pending_claims_count; ?> pending claim(s)"></span>
          <span class="nav-notif-badge"><?php echo $pending_claims_count; ?></span>
        <?php endif; ?>
      </a>
      <a href="guest_reservations.php" class="topnav-link <?php echo ($active_nav === 'guest_reservations') ? 'active' : ''; ?>" title="Guest Reserve Requests">
        <i class="fas fa-user-clock me-1"></i> GUESTS
        <?php if ($pending_guest_requests_count > 0): ?>
          <span class="nav-notif-dot" title="<?php echo $pending_guest_requests_count; ?> pending guest request(s)"></span>
          <span class="nav-notif-badge"><?php echo $pending_guest_requests_count; ?></span>
        <?php endif; ?>
      </a>
      <a href="admin_profile.php" class="topnav-link <?php echo ($active_nav === 'profile') ? 'active' : ''; ?>"><i class="fas fa-user-circle me-1"></i> PROFILE</a>
      <a href="admin_about.php" class="topnav-link <?php echo ($active_nav === 'about') ? 'active' : ''; ?>"><i class="fas fa-info-circle me-1"></i> ABOUT</a>
      <?php if (function_exists('render_desktop_notification_bell')) echo render_desktop_notification_bell(); ?>
      <a href="logout.php" class="topnav-link text-logout"><i class="fas fa-sign-out-alt me-1"></i> LOGOUT</a>
    </nav>
  </div>
</header>

<script>
(function() {
  function openMobileDrawer() {
    var drawer = document.getElementById('mobileDrawer');
    var overlay = document.getElementById('mobileDrawerOverlay');
    if (!drawer || !overlay) return;
    drawer.classList.add('active');
    overlay.classList.add('active');
    document.body.classList.add('drawer-open');
    drawer.setAttribute('aria-hidden', 'false');
  }

  function closeMobileDrawer() {
    var drawer = document.getElementById('mobileDrawer');
    var overlay = document.getElementById('mobileDrawerOverlay');
    if (!drawer || !overlay) return;
    drawer.classList.remove('active');
    overlay.classList.remove('active');
    document.body.classList.remove('drawer-open');
    drawer.setAttribute('aria-hidden', 'true');
  }

  window.openMobileDrawer = openMobileDrawer;
  window.closeMobileDrawer = closeMobileDrawer;
  try {
    if (window.sessionStorage) {
      sessionStorage.setItem('gor_tab_home', 'dashboard');
    }
  } catch (e) {}

  document.addEventListener('DOMContentLoaded', function() {
    var mobileMenuButton = document.getElementById('mobileMenuButton');
    var mobileDrawerClose = document.getElementById('mobileDrawerClose');
    var mobileDrawerOverlay = document.getElementById('mobileDrawerOverlay');

    if (mobileMenuButton) {
      mobileMenuButton.addEventListener('click', function(e) {
        e.preventDefault();
        var drawer = document.getElementById('mobileDrawer');
        if (drawer && drawer.classList.contains('active')) {
          closeMobileDrawer();
        } else {
          openMobileDrawer();
        }
      });
    }

    if (mobileDrawerClose) {
      mobileDrawerClose.addEventListener('click', function(e) {
        e.preventDefault();
        closeMobileDrawer();
      });
    }

    if (mobileDrawerOverlay) {
      mobileDrawerOverlay.addEventListener('click', function(e) {
        e.preventDefault();
        closeMobileDrawer();
      });
    }

    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') {
        closeMobileDrawer();
      }
    });

    // Automatically close drawer when any drawer link is clicked
    var drawerLinks = document.querySelectorAll('#mobileDrawer a');
    drawerLinks.forEach(function(link) {
      link.addEventListener('click', function() {
        closeMobileDrawer();
      });
    });

    // When any modal is triggered, immediately close the drawer so it never blocks the modal
    document.addEventListener('show.bs.modal', function() {
      closeMobileDrawer();
    });
  });
})();
</script>
<?php if (function_exists('render_notification_containers')) echo render_notification_containers(); ?>
