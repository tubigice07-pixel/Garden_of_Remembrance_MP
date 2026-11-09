<?php

if (!function_exists('render_notification_inline_styles')) {
    function render_notification_inline_styles(): string {
        static $rendered = false;
        if ($rendered) return '';
        $rendered = true;
        return <<<CSS
<style id="gor-notif-critical-styles">
.notif-dropdown-wrapper { position: relative; display: inline-flex; align-items: center; flex-shrink: 0 !important; }
.notif-bell-btn { position: relative; background: rgba(255, 255, 255, 0.14) !important; border: 1px solid rgba(255, 255, 255, 0.22) !important; color: #ffffff !important; font-size: 1.1rem; width: 38px; height: 38px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; margin: 0 4px; transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), background-color 0.2s ease, box-shadow 0.2s ease; outline: none; }
.notif-bell-btn:hover { background: rgba(255, 255, 255, 0.26) !important; transform: translateY(-2px) scale(1.05); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.18); }
.notif-bell-btn:active { transform: scale(0.92); }
@keyframes notifBellSwing {
  0% { transform: rotate(0deg); }
  20% { transform: rotate(18deg) scale(1.15); }
  40% { transform: rotate(-18deg) scale(1.15); }
  60% { transform: rotate(12deg) scale(1.08); }
  80% { transform: rotate(-8deg) scale(1.04); }
  100% { transform: rotate(0deg) scale(1); }
}
.notif-bell-btn.ringing i,
.notif-bell-btn:active i,
.mobile-notif-btn.ringing i,
.mobile-notif-btn:active i {
  display: inline-block;
  animation: notifBellSwing 0.6s cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
  transform-origin: top center;
}
.notif-badge { position: absolute; top: -2px; right: -2px; background: #dc2626; color: #ffffff; font-size: 0.68rem; font-weight: 700; min-width: 18px; height: 18px; line-height: 18px; border-radius: 9px; padding: 0 4px; text-align: center; display: none; box-shadow: 0 2px 5px rgba(0,0,0,0.3); }
@keyframes notifDropdownEntrance {
  0% {
    opacity: 0;
    transform: translateY(-14px) scale(0.94);
    filter: blur(3px);
  }
  65% {
    opacity: 1;
    transform: translateY(2px) scale(1.01);
    filter: blur(0);
  }
  100% {
    opacity: 1;
    transform: translateY(0) scale(1);
    filter: blur(0);
  }
}
@keyframes notifDropdownExit {
  0% {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
  100% {
    opacity: 0;
    transform: translateY(-10px) scale(0.96);
  }
}
.notif-dropdown-menu { position: absolute; top: calc(100% + 8px); right: 0; width: 380px; max-width: 90vw; background: #ffffff !important; border-radius: 14px; box-shadow: 0 14px 40px rgba(0, 0, 0, 0.22), 0 3px 12px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; z-index: 999999; display: none !important; flex-direction: column; overflow: hidden; transform-origin: top right; }
.notif-dropdown-menu.show { display: flex !important; animation: notifDropdownEntrance 0.28s cubic-bezier(0.16, 1, 0.3, 1) forwards !important; }
.notif-dropdown-menu.closing { display: flex !important; animation: notifDropdownExit 0.18s cubic-bezier(0.4, 0, 1, 1) forwards !important; pointer-events: none; }
@keyframes notifItemSlideIn {
  from { opacity: 0; transform: translateY(6px); }
  to { opacity: 1; transform: translateY(0); }
}
.notif-dropdown-menu.show .notif-item { animation: notifItemSlideIn 0.24s cubic-bezier(0.16, 1, 0.3, 1) both; }
.notif-dropdown-menu.show .notif-item:nth-child(1) { animation-delay: 0.03s; }
.notif-dropdown-menu.show .notif-item:nth-child(2) { animation-delay: 0.06s; }
.notif-dropdown-menu.show .notif-item:nth-child(3) { animation-delay: 0.09s; }
.notif-dropdown-menu.show .notif-item:nth-child(4) { animation-delay: 0.12s; }
.notif-dropdown-menu.show .notif-item:nth-child(5) { animation-delay: 0.15s; }
.notif-header { padding: 12px 16px; background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important; color: #ffffff !important; display: flex; align-items: center; justify-content: space-between; border-top-left-radius: 13px; border-top-right-radius: 13px; }
.notif-header-title { font-size: 0.95rem; font-weight: 700; color: #ffffff !important; display: flex; align-items: center; gap: 8px; margin: 0; letter-spacing: 0.3px; }
.notif-mark-all-btn { background: rgba(255, 255, 255, 0.2) !important; border: 1px solid rgba(255, 255, 255, 0.3) !important; color: #ffffff !important; font-size: 0.75rem; font-weight: 600; padding: 4px 10px; border-radius: 6px; cursor: pointer; transition: background 0.15s; }
.notif-mark-all-btn:hover { background: rgba(255, 255, 255, 0.35) !important; }
.notif-body { max-height: 380px; overflow-y: auto; background: #ffffff !important; color: #1e293b; padding: 0; }
.notif-empty { padding: 36px 20px; text-align: center; color: #64748b; font-size: 0.88rem; font-weight: 500; }
.notif-footer { padding: 10px 16px; background: #f8fafc; border-top: 1px solid #e2e8f0; text-align: center; font-size: 0.8rem; }
.notif-footer a { color: #7b1113; text-decoration: none; font-weight: 600; }
.notif-footer a:hover { text-decoration: underline; }
.notif-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 16px; border-bottom: 1px solid #f1f5f9; cursor: pointer; text-decoration: none; color: inherit; transition: background 0.15s; position: relative; }
.notif-item:hover { background: #f8fafc; }
.notif-item.unread { background: #eff6ff; border-left: 3px solid #2563eb; }
.notif-item.unread:hover { background: #e0effe; }
.notif-icon-circle { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: #f1f5f9; color: #7b1113; flex-shrink: 0; font-size: 0.9rem; }
.notif-icon-circle.booking { background: #e0f2fe; color: #0284c7; }
.notif-icon-circle.payment { background: #fef3c7; color: #d97706; }
.notif-icon-circle.reminder { background: #f1f5f9; color: #475569; }
.notif-icon-circle.announcement { background: #fee2e2; color: #dc2626; }
.notif-icon-circle.rejected { background: #fee2e2; color: #dc2626; }
.notif-icon-circle.claim { background: #dcfce7; color: #15803d; }
.notif-icon-circle.transfer { background: #fef3c7; color: #b45309; }
.notif-content { flex: 1; min-width: 0; }
.notif-title { font-size: 0.88rem; font-weight: 600; color: #1e293b; margin-bottom: 2px; }
.notif-msg { font-size: 0.82rem; color: #64748b; line-height: 1.35; margin-bottom: 4px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.notif-time { font-size: 0.72rem; color: #94a3b8; }
.mobile-notif-btn, .mobile-notif-drawer, .mobile-notif-backdrop { display: none !important; }
@media (max-width: 1024px) {
  .mobile-notif-btn { display: inline-flex !important; color: #ffffff !important; font-size: 1.15rem; width: 38px; height: 38px; align-items: center; justify-content: center; border-radius: 8px; background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.2); position: relative; cursor: pointer; margin-right: 4px; touch-action: manipulation; transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1); }
  .mobile-notif-btn:hover { background: rgba(255, 255, 255, 0.24) !important; transform: scale(1.05); }
  .mobile-notif-btn:active { transform: scale(0.92); }
}
#mobileNotificationModal { z-index: 1000045 !important; }
#mobileNotificationModal .modal-dialog { max-width: 500px; margin: 1.25rem auto; }
#mobileNotificationModal .modal-content { border-radius: 16px; border: none; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.25); overflow: hidden; background: #ffffff !important; }
#mobileNotificationModal .modal-header { background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important; color: #ffffff !important; padding: 14px 18px; border-bottom: none; }
#mobileNotificationModal .notif-body { max-height: 65vh; overflow-y: auto; background: #ffffff !important; color: #1e293b; padding: 0; }
</style>
CSS;
    }
}

if (!function_exists('render_desktop_notification_bell')) {
    function render_desktop_notification_bell(): string {
        ob_start();
        echo render_notification_inline_styles();
        $dashLink = (isset($_SESSION['role']) && strtoupper(trim((string)$_SESSION['role'])) === 'ADMIN') ? 'dashboard.php' : 'user_dashboard.php';
        ?>
        <div class="notif-dropdown-wrapper" id="notifDropdownWrapper">
            <button type="button" class="notif-bell-btn" id="notifBellButton" aria-label="Notifications" title="Notifications">
                <i class="fas fa-bell"></i>
                <span class="notif-badge" id="notifBadge">0</span>
            </button>
            <div class="notif-dropdown-menu" id="notifDropdownMenu" role="region" aria-label="Notifications Panel">
                <div class="notif-header">
                    <h4 class="notif-header-title"><i class="fas fa-bell"></i> Notifications</h4>
                    <button type="button" class="notif-mark-all-btn" title="Mark all as read">Mark all read</button>
                </div>
                <div class="notif-body" id="notifDropdownBody">
                    <div class="notif-empty"><i class="fas fa-spinner fa-spin me-1"></i> Loading...</div>
                </div>
                <div class="notif-footer">
                    <a href="<?php echo $dashLink; ?>">Garden of Remembrance CMIS</a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('render_mobile_notification_bell')) {
    function render_mobile_notification_bell(): string {
        ob_start();
        echo render_notification_inline_styles();
        ?>
        <button type="button" class="mobile-notif-btn" id="mobileNotifButton" aria-label="Notifications" title="Notifications">
            <i class="fas fa-bell"></i>
            <span class="notif-badge" id="mobileNotifBadge">0</span>
        </button>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('render_notification_containers')) {
    function render_notification_containers(): string {
        $prefix = (strpos($_SERVER['PHP_SELF'] ?? '', '/sections/') !== false || strpos($_SERVER['PHP_SELF'] ?? '', '/Admin/') !== false || strpos($_SERVER['PHP_SELF'] ?? '', '/User/') !== false) ? '../' : '';
        ob_start();
        echo render_notification_inline_styles();
        ?>

        <div class="modal fade" id="mobileNotificationModal" tabindex="-1" aria-labelledby="mobileNotificationModalLabel" aria-hidden="true" style="z-index: 1000045;">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 500px; margin: 1.25rem auto;">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden; background: #ffffff;">
                    <div class="modal-header py-3 px-4 d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important; color: #ffffff !important; border-bottom: none;">
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm text-white p-0 border-0 me-1" id="mobileNotifBackBtn" style="display: none; font-size: 1.1rem; line-height: 1;" title="Back to notifications">
                                <i class="fas fa-arrow-left"></i>
                            </button>
                            <div style="width: 34px; height: 34px; border-radius: 50%; background: rgba(255,255,255,0.2); display: inline-flex; align-items: center; justify-content: center;">
                                <i class="fas fa-bell text-white" id="mobileNotifHeaderIcon" style="font-size: 1rem;"></i>
                            </div>
                            <h5 class="modal-title fs-6 fw-bold text-white mb-0" id="mobileNotificationModalLabel">Notifications</h5>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="notif-mark-all-btn" id="mobileNotifMarkAllBtn" title="Mark all as read">Mark all read</button>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" id="mobileNotifModalCloseBtn"></button>
                        </div>
                    </div>

                    <div id="mobileNotifListView">
                        <div class="modal-body p-0 notif-body" id="mobileNotifBody" style="max-height: 65vh; overflow-y: auto; background: #ffffff;">
                            <div class="notif-empty"><i class="fas fa-spinner fa-spin me-1"></i> Loading notifications...</div>
                        </div>
                        <div class="modal-footer py-2 px-3 d-flex align-items-center justify-content-between" style="background: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 0.8rem;">
                            <span class="text-muted small"><i class="fas fa-shield-alt me-1"></i> Garden of Remembrance CMIS</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal" id="mobileNotifModalDismissBtn">Close</button>
                        </div>
                    </div>

                    <div id="mobileNotifDetailView" style="display: none;">
                        <div class="modal-body p-4" style="max-height: 65vh; overflow-y: auto; background: #ffffff; color: #1e293b;">
                            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                                <span id="mobileNotifDetailBadge" class="badge" style="background: #e2e8f0; color: #334155; font-weight: 700; font-size: 0.78rem; padding: 5px 10px; border-radius: 20px;">
                                    <i class="fas fa-bell me-1"></i> NOTICE
                                </span>
                                <small class="text-muted" id="mobileNotifDetailTime" style="font-size: 0.78rem;">
                                    <i class="far fa-clock me-1"></i> <span></span>
                                </small>
                            </div>
                            <h6 class="fw-bold mb-3 text-dark" id="mobileNotifDetailTitle" style="font-size: 1.05rem; line-height: 1.4;">Notification Title</h6>
                            <div id="mobileNotifDetailReasonBox" class="p-3 mb-3" style="display:none; background: #fff5f5; border-left: 4px solid #dc2626; border-radius: 10px; box-shadow: 0 1px 3px rgba(220,38,38,0.08);">
                                <div class="d-flex align-items-center gap-2 mb-1" style="color: #991b1b; font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                                    <i class="fas fa-exclamation-triangle"></i> Reason for Rejection
                                </div>
                                <div id="mobileNotifDetailReason" style="color: #7f1d1d; font-size: 0.95rem; font-weight: 600; line-height: 1.5; white-space: pre-wrap;">No reason specified.</div>
                            </div>
                            <div class="mb-3 p-3" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;">
                                <div class="text-muted small fw-semibold mb-1" style="font-size: 0.76rem; text-transform: uppercase;"><i class="fas fa-info-circle me-1"></i> Information &amp; Message</div>
                                <div id="mobileNotifDetailMessage" class="mb-0 text-secondary" style="font-size: 0.9rem; line-height: 1.55; white-space: pre-wrap;"></div>
                            </div>
                            <div id="mobileNotifDetailHelpBox" class="d-flex align-items-start gap-2 p-2 px-3 rounded" style="background: #f1f5f9; font-size: 0.8rem; color: #475569;">
                                <i class="fas fa-shield-alt mt-1 text-secondary"></i>
                                <div id="mobileNotifDetailHelpText">Garden of Remembrance Cemetery Management and Grave Mapping System</div>
                            </div>
                        </div>
                        <div class="modal-footer px-3 py-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background: #f8fafc; border-top: 1px solid #e2e8f0;">
                            <button type="button" class="btn btn-sm btn-outline-secondary px-3" id="mobileNotifDetailBackBtnBottom">
                                <i class="fas fa-arrow-left me-1"></i> Back
                            </button>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <a href="map.php" id="mobileNotifDetailCemeteryBtn" class="btn btn-sm btn-outline-danger px-3 fw-semibold" style="display: none; border-radius: 6px;"><i class="fas fa-map-marked-alt me-1"></i> View Cemetery</a>
                                <a href="user_dashboard.php#recentBookingsSection" id="mobileNotifDetailBookingBtn" class="btn btn-sm text-white px-3 fw-semibold" style="display: none; background: #7b1113; border: none; border-radius: 6px;"><i class="fas fa-calendar-alt me-1"></i> View Book</a>
                                <a href="user_dashboard.php" id="mobileNotifDetailActionBtn" class="btn btn-sm text-white px-3 fw-semibold" style="display: none; background: #7b1113; border: none; border-radius: 6px;"><i class="fas fa-arrow-right me-1"></i> View Details</a>
                                <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mobile-notif-backdrop" id="mobileNotifBackdrop" style="display:none !important;"></div>
        <div class="mobile-notif-drawer" id="mobileNotifDrawer" style="display:none !important;" role="dialog" aria-hidden="true"></div>

        <div class="notif-toast-container" id="notificationToastContainer" aria-live="polite"></div>

        <div class="modal fade" id="gorRejectionModal" tabindex="-1" aria-labelledby="gorRejectionModalLabel" aria-hidden="true" style="z-index: 1000050;">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                    <div class="modal-header" style="background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%); color: #ffffff; padding: 14px 20px; border-bottom: none;">
                        <div class="d-flex align-items-center gap-2">
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.2); display: inline-flex; align-items: center; justify-content: center;">
                                <i class="fas fa-times-circle" style="color: #fecaca; font-size: 1.1rem;"></i>
                            </div>
                            <h5 class="modal-title fs-6 fw-bold text-white mb-0" id="gorRejectionModalLabel">Rejection Details</h5>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" id="gorRejectionModalCloseBtn"></button>
                    </div>

                    <div class="modal-body p-4" style="background: #ffffff; color: #1e293b;">

                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                            <span class="badge" style="background: #fee2e2; color: #991b1b; font-weight: 700; font-size: 0.78rem; padding: 5px 10px; border-radius: 20px; border: 1px solid #fecaca;">
                                <i class="fas fa-ban me-1"></i> STATUS: REJECTED
                            </span>
                            <small class="text-muted" id="gorRejectionModalTime" style="font-size: 0.78rem;">
                                <i class="far fa-clock me-1"></i> <span></span>
                            </small>
                        </div>

                        <h6 class="fw-bold mb-3 text-dark" id="gorRejectionModalTitle" style="font-size: 1rem; line-height: 1.4;">
                            Application / Claim Update
                        </h6>

                        <div class="p-3 mb-3" style="background: #fff5f5; border-left: 4px solid #dc2626; border-radius: 10px; box-shadow: 0 1px 3px rgba(220,38,38,0.08);">
                            <div class="d-flex align-items-center gap-2 mb-1" style="color: #991b1b; font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                                <i class="fas fa-exclamation-triangle"></i> Reason for Rejection
                            </div>
                            <div id="gorRejectionModalReason" style="color: #7f1d1d; font-size: 0.95rem; font-weight: 600; line-height: 1.5; white-space: pre-wrap;">
                                No reason specified.
                            </div>
                        </div>

                        <div class="mb-3 p-3" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;">
                            <div class="text-muted small fw-semibold mb-1" style="font-size: 0.76rem; text-transform: uppercase;">
                                <i class="fas fa-info-circle me-1"></i> Full Remarks &amp; Notice
                            </div>
                            <p id="gorRejectionModalMessage" class="mb-0 text-secondary" style="font-size: 0.86rem; line-height: 1.5;"></p>
                        </div>

                        <div class="d-flex align-items-start gap-2 p-2 px-3 rounded" style="background: #f1f5f9; font-size: 0.8rem; color: #475569;">
                            <i class="fas fa-headset mt-1 text-secondary"></i>
                            <div>
                                If you have official proof of ownership (title deed, purchase receipt, or certification) or questions regarding this rejection, please contact the cemetery office or view your dashboard for further details.
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer px-4 py-3" style="background: #f8fafc; border-top: 1px solid #e2e8f0; gap: 8px;">
                        <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal" id="gorRejectionModalDismissBtn">
                            Close
                        </button>
                        <a href="user_dashboard.php" id="gorRejectionModalActionBtn" class="btn btn-sm text-white px-4 fw-semibold" style="background: #7b1113; border: none; border-radius: 6px;">
                            <i class="fas fa-arrow-right me-1"></i> Go to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <link rel="stylesheet" href="<?php echo $prefix; ?>notification_component.css">
        <script src="<?php echo $prefix; ?>notification_component.js"></script>
        <script>
        (function() {
            function triggerNotifInit() {
                if (window.initNotificationSystem) {
                    window.initNotificationSystem();
                } else if (window.gorNotifications && typeof window.gorNotifications.fetch === 'function') {
                    window.gorNotifications.fetch();
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', triggerNotifInit);
            } else {
                triggerNotifInit();
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
