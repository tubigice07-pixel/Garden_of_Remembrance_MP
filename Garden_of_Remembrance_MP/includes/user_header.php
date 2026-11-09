<?php

if (!isset($active_nav)) {
    $active_nav = '';
}
if (file_exists(__DIR__ . '/notification_component.php')) {
    require_once __DIR__ . '/notification_component.php';
} elseif (file_exists(__DIR__ . '/../notification_component.php')) {
    require_once __DIR__ . '/../notification_component.php';
}
$user_profile_pic = 'uploads/profile_pictures/default_avatar.png';
$user_display_name = 'Customer';
$user_email_addr = '';
if (isset($_SESSION['user_id'])) {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        @include_once __DIR__ . '/../connection.php';
    }
    if (isset($conn) && $conn instanceof mysqli) {
        $uid_usr = (int)$_SESSION['user_id'];
        $usrQ = $conn->query("SELECT profile_picture, first_name, last_name, email FROM tbl_user WHERE user_id = $uid_usr LIMIT 1");
        if ($usrQ && $usrRow = $usrQ->fetch_assoc()) {
            if (!empty($usrRow['profile_picture']) && file_exists(__DIR__ . '/../' . $usrRow['profile_picture'])) {
                $user_profile_pic = $usrRow['profile_picture'];
            }
            $fullname_usr = trim(($usrRow['first_name'] ?? '') . ' ' . ($usrRow['last_name'] ?? ''));
            if (!empty($fullname_usr)) {
                $user_display_name = $fullname_usr;
            }
            if (!empty($usrRow['email'])) {
                $user_email_addr = $usrRow['email'];
            }
        }
    }
}
?>

<?php
$hdr_assets_prefix = (file_exists('includes/ie_polyfills.js')) ? '' : ((file_exists('../includes/ie_polyfills.js')) ? '../' : '');
?>
<!-- IE11 / Edge-Legacy polyfills (URLSearchParams, fetch, Promise, etc.) -->
<script src="<?php echo $hdr_assets_prefix; ?>includes/ie_polyfills.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?php echo $hdr_assets_prefix; ?>includes/admin_header.css">
<style id="gorUserHeaderConsistencyStyle">
  /* Exact styling matching User Dashboard for pixel-perfect navigation consistency */
  .admin-topbar {
    position: sticky !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    z-index: 1040 !important;
    background: linear-gradient(135deg, #7b1113 0%, #8e1518 50%, #5d0c0e 100%) !important;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.16), inset 0 -1px 0 rgba(255, 255, 255, 0.1) !important;
    padding: 0 1.25rem !important;
  }

  .admin-topbar-inner {
    max-width: 100% !important;
    width: 100% !important;
    box-sizing: border-box !important;
    margin: 0 auto !important;
    height: 60px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 0.5rem !important;
  }

  .admin-brand {
    display: inline-flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
    text-decoration: none !important;
    padding: 2px 0 !important;
    flex-shrink: 0 !important;
    min-width: 0 !important;
  }

  .admin-brand-logo {
    height: 38px !important;
    width: auto !important;
    max-width: 160px !important;
    object-fit: contain !important;
    display: block !important;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.18)) !important;
  }

  .admin-brand-title {
    color: #ffffff !important;
    font-weight: 700 !important;
    font-size: 0.95rem !important;
    letter-spacing: 0.01em !important;
    white-space: nowrap !important;
    display: inline-block !important;
    line-height: 1.2 !important;
  }

  .admin-nav {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 4px !important;
    flex-wrap: nowrap !important;
    flex-shrink: 1 !important;
    min-width: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
  }

  .admin-nav .topnav-link,
  .topnav-link {
    color: #ffffff !important;
    text-decoration: none !important;
    font-weight: 600 !important;
    font-size: 0.78rem !important;
    letter-spacing: 0.02em !important;
    text-transform: uppercase !important;
    position: relative !important;
    padding: 6px 11px !important;
    border-radius: 18px !important;
    transition: all 0.2s ease !important;
    white-space: nowrap !important;
    display: inline-flex !important;
    align-items: center !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
    flex-shrink: 0 !important;
  }

  .admin-nav .topnav-link i,
  .topnav-link i {
    font-size: 0.85rem !important;
    margin-right: 4px !important;
  }

  .admin-nav .topnav-link:hover,
  .topnav-link:hover {
    color: #ffffff !important;
    background: rgba(255, 255, 255, 0.18) !important;
    transform: translateY(-1px) !important;
  }

  .admin-nav .topnav-link.active,
  .topnav-link.active {
    color: #ffffff !important;
    background: rgba(255, 255, 255, 0.25) !important;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.35), 0 2px 8px rgba(0, 0, 0, 0.12) !important;
    font-weight: 700 !important;
  }

  .admin-nav .topnav-link.text-logout,
  .topnav-link.text-logout {
    color: #ffcccc !important;
    background: rgba(0, 0, 0, 0.22) !important;
    border: 1px solid rgba(255, 255, 255, 0.2) !important;
    padding: 6px 12px !important;
    font-weight: 700 !important;
    font-size: 0.78rem !important;
    flex-shrink: 0 !important;
    margin-left: 5px !important;
    white-space: nowrap !important;
    display: inline-flex !important;
    align-items: center !important;
    box-sizing: border-box !important;
  }

  .admin-nav .topnav-link.text-logout:hover,
  .topnav-link.text-logout:hover {
    color: #ffffff !important;
    background: rgba(220, 53, 69, 0.6) !important;
    border-color: rgba(220, 53, 69, 0.9) !important;
  }

  @media (max-width: 1380px) {
    .admin-topbar { padding: 0 0.75rem !important; }
    .admin-topbar-inner { gap: 0.3rem !important; }
    .admin-brand-logo { height: 32px !important; }
    .admin-brand-title { font-size: 0.86rem !important; }
    .admin-nav { gap: 3px !important; }
    .admin-nav .topnav-link, .topnav-link { padding: 5px 8px !important; font-size: 0.72rem !important; }
    .admin-nav .topnav-link.text-logout, .topnav-link.text-logout { padding: 5px 10px !important; font-size: 0.72rem !important; }
  }

  @media (max-width: 1200px) {
    .admin-topbar { padding: 0 0.5rem !important; }
    .admin-brand-logo { height: 28px !important; }
    .admin-brand-title { font-size: 0.78rem !important; }
    .admin-nav { gap: 2px !important; }
    .admin-nav .topnav-link, .topnav-link { padding: 4px 6px !important; font-size: 0.65rem !important; }
    .admin-nav .topnav-link.text-logout, .topnav-link.text-logout { padding: 4px 8px !important; font-size: 0.65rem !important; }
  }
</style>

<div class="mobile-toolbar">
  <div class="mobile-toolbar-inner">
    <button type="button" class="mobile-menu-btn" id="mobileMenuButton" aria-label="Open navigation menu">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
    </button>
    <a href="user_dashboard.php" class="mobile-brand-link" aria-label="Garden of Remembrance">
      <img src="cmis_logo.png" alt="Memorial Park Logo" class="mobile-logo">
      <span class="mobile-toolbar-title" style="color: #ffffff !important; font-weight: 700 !important; font-size: 0.95rem !important; white-space: nowrap !important; display: inline-block !important;">Garden of Remembrance</span>
    </a>
    <div class="d-flex align-items-center gap-1">
      <?php if (function_exists('render_mobile_notification_bell')) echo render_mobile_notification_bell(); ?>
      <a href="profile.php" class="mobile-profile-btn" aria-label="My Profile" title="My Profile">
        <img src="<?php echo htmlspecialchars($user_profile_pic); ?>" alt="User Avatar" class="mobile-profile-avatar" onerror="this.onerror=null;this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'%23ffffff\'><path d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/></svg>'">
      </a>
    </div>
  </div>
</div>

<div class="mobile-drawer-overlay" id="mobileDrawerOverlay"></div>

<aside class="mobile-drawer" id="mobileDrawer" aria-hidden="true">
  <div class="mobile-drawer-header">
    <div class="d-flex align-items-center gap-2">
      <img src="cmis_logo.png" alt="Logo" style="height: 32px; width: auto; object-fit: contain;">
      <div class="mobile-drawer-title" style="font-weight: 700; color: #7b1113; font-size: 1.05rem;">User Portal</div>
    </div>
    <button type="button" class="mobile-drawer-close" id="mobileDrawerClose" aria-label="Close navigation menu">×</button>
  </div>
  <a href="profile.php" class="mobile-drawer-profile-card text-decoration-none" title="View Profile">
    <img src="<?php echo htmlspecialchars($user_profile_pic); ?>" alt="Avatar" class="drawer-avatar" onerror="this.onerror=null;this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'%237b1113\'><path d=\'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\'/></svg>'">
    <div class="drawer-profile-info">
      <div class="drawer-profile-name"><?php echo htmlspecialchars($user_display_name); ?></div>
      <div class="drawer-profile-role"><?php echo htmlspecialchars(!empty($user_email_addr) ? $user_email_addr : 'Customer'); ?></div>
    </div>
    <span class="drawer-profile-arrow">›</span>
  </a>
  <nav class="mobile-drawer-nav">
    <a href="user_dashboard.php" class="<?php echo ($active_nav === 'dashboard') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-home"></i></span> <span>Dashboard</span>
    </a>
    <?php if ($active_nav === 'dashboard'): ?>
      <a href="#" data-bs-toggle="modal" data-bs-target="#myBookingsModal">
        <span class="nav-icon"><i class="fas fa-calendar-check"></i></span> <span>Reservations</span>
      </a>
    <?php else: ?>
      <a href="user_dashboard.php?open=my_bookings" class="<?php echo ($active_nav === 'my_bookings') ? 'active' : ''; ?>">
        <span class="nav-icon"><i class="fas fa-calendar-check"></i></span> <span>Reservations</span>
      </a>
    <?php endif; ?>
    <a href="map.php" class="<?php echo ($active_nav === 'map') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-map"></i></span> <span>Cemetery Map</span>
    </a>
    <a href="transfer_requests.php" class="<?php echo ($active_nav === 'transfers') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-exchange-alt"></i></span> <span>Plot Transfer</span>
    </a>
    <a href="profile.php" class="<?php echo ($active_nav === 'profile') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-user-circle"></i></span> <span>My Profile</span>
    </a>
    <a href="user_about.php" class="<?php echo ($active_nav === 'about') ? 'active' : ''; ?>">
      <span class="nav-icon"><i class="fas fa-info-circle"></i></span> <span>About</span>
    </a>
    <a href="logout.php" class="drawer-logout-link">
      <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span> <span>Logout</span>
    </a>
  </nav>
  <div class="mobile-drawer-footer">Garden of Remembrance Memorial Park</div>
</aside>

<header class="admin-topbar">
  <div class="admin-topbar-inner">
    <a href="user_dashboard.php" class="admin-brand" aria-label="Garden of Remembrance Memorial Park">
      <img src="cmis_logo.png" alt="Garden of Remembrance Logo" class="admin-brand-logo">
      <span class="admin-brand-title">Garden of Remembrance</span>
    </a>
    <nav class="admin-nav" aria-label="Main Navigation">
      <a href="user_dashboard.php" class="topnav-link <?php echo ($active_nav === 'dashboard') ? 'active' : ''; ?>"><i class="fas fa-home me-1"></i> DASHBOARD</a>
      <?php if ($active_nav === 'dashboard'): ?>
        <a href="#" class="topnav-link" data-bs-toggle="modal" data-bs-target="#myBookingsModal"><i class="fas fa-calendar-check me-1"></i> RESERVATIONS</a>
      <?php else: ?>
        <a href="user_dashboard.php?open=my_bookings" class="topnav-link <?php echo ($active_nav === 'my_bookings') ? 'active' : ''; ?>"><i class="fas fa-calendar-check me-1"></i> RESERVATIONS</a>
      <?php endif; ?>
      <a href="map.php" class="topnav-link <?php echo ($active_nav === 'map') ? 'active' : ''; ?>"><i class="fas fa-map me-1"></i> MAP</a>
      <a href="transfer_requests.php" class="topnav-link <?php echo ($active_nav === 'transfers') ? 'active' : ''; ?>"><i class="fas fa-exchange-alt me-1"></i> PLOT TRANSFER</a>
      <a href="profile.php" class="topnav-link <?php echo ($active_nav === 'profile') ? 'active' : ''; ?>"><i class="fas fa-user-circle me-1"></i> MY PROFILE</a>
      <a href="user_about.php" class="topnav-link <?php echo ($active_nav === 'about') ? 'active' : ''; ?>"><i class="fas fa-info-circle me-1"></i> ABOUT</a>
      <?php if (function_exists('render_desktop_notification_bell')) echo render_desktop_notification_bell(); ?>
      <a href="logout.php" class="topnav-link text-logout"><i class="fas fa-sign-out-alt me-1"></i> LOGOUT</a>
    </nav>
  </div>
</header>

<script>
(function() {
  function initUserDrawer() {
    var drawer = document.getElementById('mobileDrawer');
    var overlay = document.getElementById('mobileDrawerOverlay');
    var btn = document.getElementById('mobileMenuButton');
    var closeBtn = document.getElementById('mobileDrawerClose');
    if (!drawer || !overlay) return;

    function openMobileDrawer() {
      drawer.classList.add('active');
      overlay.classList.add('active');
      document.body.classList.add('drawer-open');
      drawer.setAttribute('aria-hidden', 'false');
    }

    function closeMobileDrawer() {
      drawer.classList.remove('active');
      overlay.classList.remove('active');
      document.body.classList.remove('drawer-open');
      drawer.setAttribute('aria-hidden', 'true');
    }

    window.openMobileDrawer = openMobileDrawer;
    window.closeMobileDrawer = closeMobileDrawer;

    if (btn && !btn.__drawerAttached) {
      btn.__drawerAttached = true;
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        openMobileDrawer();
      });
    }

    if (closeBtn && !closeBtn.__drawerAttached) {
      closeBtn.__drawerAttached = true;
      closeBtn.addEventListener('click', function(e) {
        e.preventDefault();
        closeMobileDrawer();
      });
    }

    if (overlay && !overlay.__drawerAttached) {
      overlay.__drawerAttached = true;
      overlay.addEventListener('click', function() {
        closeMobileDrawer();
      });
    }

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && drawer.classList.contains('active')) {
        closeMobileDrawer();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initUserDrawer);
  } else {
    initUserDrawer();
  }
})();
</script>
<?php if (function_exists('render_notification_containers')) echo render_notification_containers(); ?>
