/**
 * Garden of Remembrance Memorial Park
 * Cemetery Management and Grave Mapping System (CMIS)
 * ------------------------------------------------------------
 * Notification Client Engine (Desktop & Mobile)
 * ------------------------------------------------------------
 */

(function () {
  'use strict';

  if (window.__gorNotificationEngineLoaded) {
    return;
  }
  window.__gorNotificationEngineLoaded = true;

  var seenNotificationIds = new Set();
  var isFirstLoad = true;
  var currentNotifications = [];

  function getBaseUrl() {
    var path = window.location.pathname;
    if (path.indexOf('/sections/') !== -1 || path.indexOf('/Admin/') !== -1 || path.indexOf('/User/') !== -1) {
      return '../';
    }
    return '';
  }

  function isRejectionItem(item) {
    if (!item) return false;
    if (item.is_rejection) return true;
    var type = (item.type || '').toLowerCase();
    var title = (item.title || '').toLowerCase();
    var msg = (item.message || '').toLowerCase();
    return (type.indexOf('reject') !== -1 || title.indexOf('reject') !== -1 || msg.indexOf('rejected') !== -1);
  }

  function extractRejectionReason(item) {
    if (!item) return 'No specific reason provided by administrator.';
    if (item.reason && item.reason.trim()) return item.reason.trim();
    var msg = item.message || '';
    var m = msg.match(/Reason:\s*([^.\n\r]+(?:\.[^.\n\r]+)*?)(?:\.\s*(?:The unverified|Please contact|You may review|Record removed)|$)/i);
    if (m && m[1] && m[1].trim()) return m[1].trim();
    var m2 = msg.match(/Reason:\s*([^\r\n]+)/i);
    if (m2 && m2[1] && m2[1].trim()) return m2[1].trim();
    return msg || 'No specific reason provided by administrator.';
  }

  function getIconClass(type) {
    if (!type) return { icon: 'fa-bell', circleClass: '' };
    var t = type.toLowerCase();
    if (t.indexOf('reject') !== -1) return { icon: 'fa-times-circle', circleClass: 'rejected' };
    if (t.indexOf('booking') !== -1) return { icon: 'fa-calendar-check', circleClass: 'booking' };
    if (t.indexOf('payment') !== -1 || t.indexOf('installment') !== -1) return { icon: 'fa-receipt', circleClass: 'payment' };
    if (t.indexOf('reminder') !== -1) return { icon: 'fa-clock', circleClass: 'reminder' };
    if (t.indexOf('interment') !== -1 || t.indexOf('service') !== -1) return { icon: 'fa-church', circleClass: 'booking' };
    if (t.indexOf('announcement') !== -1 || t.indexOf('promotion') !== -1) return { icon: 'fa-bullhorn', circleClass: 'announcement' };
    if (t.indexOf('claim') !== -1 || t.indexOf('user_action') !== -1) return { icon: 'fa-id-card', circleClass: 'claim' };
    if (t.indexOf('transfer') !== -1) return { icon: 'fa-exchange-alt', circleClass: 'transfer' };
    if (t.indexOf('log') !== -1) return { icon: 'fa-clipboard-list', circleClass: 'reminder' };
    return { icon: 'fa-bell', circleClass: '' };
  }

  // Update UI badge counters
  function updateBadgeCounters(count) {
    var badges = document.querySelectorAll('.notif-badge');
    badges.forEach(function (badge) {
      if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = 'inline-block';
      } else {
        badge.textContent = '0';
        badge.style.display = 'none';
      }
    });
  }

  // Render notification item HTML
  function renderNotifItemHtml(item) {
    var isReject = isRejectionItem(item);
    var iconInfo = getIconClass(isReject ? 'rejected' : item.type);
    var unreadClass = item.is_read ? '' : 'unread';
    var safeTitle = escapeHtml(item.title || 'Notification');
    var safeMsg = escapeHtml(item.message || '');
    var safeTime = escapeHtml(item.time_formatted || '');
    var linkUrl = item.link || 'user_dashboard.php';

    var rejectBadge = isReject ? ' <span style="display:inline-block;font-size:0.68rem;padding:1px 6px;border-radius:4px;background:#fee2e2;color:#b91c1c;font-weight:700;margin-left:4px;">REJECTED</span>' : '';
    var viewReasonHint = isReject ? '<span style="font-size:0.72rem;color:#b91c1c;font-weight:600;"><i class="fas fa-eye me-1"></i>Click to view reason</span>' : '';
    var unreadDot = !item.is_read ? '<span class="notif-unread-dot" title="Unread" style="width: 9px; height: 9px; border-radius: 50%; background: #2563eb; flex-shrink: 0; margin-left: 8px; margin-top: 5px; box-shadow: 0 0 0 2px #ffffff;"></span>' : '';

    return [
      '<div class="notif-item ' + unreadClass + '" data-id="' + item.id + '" data-link="' + escapeHtml(linkUrl) + '" data-is-reject="' + (isReject ? '1' : '0') + '" role="button" tabindex="0">',
      '  <div class="notif-icon-circle ' + iconInfo.circleClass + '">',
      '    <i class="fas ' + iconInfo.icon + '"></i>',
      '  </div>',
      '  <div class="notif-content">',
      '    <div class="notif-title">' + safeTitle + rejectBadge + '</div>',
      '    <div class="notif-msg">' + safeMsg + '</div>',
      '    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:2px;">',
      '      <span class="notif-time">' + safeTime + '</span>',
      (viewReasonHint ? '      ' + viewReasonHint : ''),
      '    </div>',
      '  </div>',
      unreadDot,
      '</div>'
    ].join('');
  }

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function getSeenToastIds() {
    try {
      var raw = sessionStorage.getItem('gor_seen_toasts');
      if (raw) {
        var parsed = JSON.parse(raw);
        if (Array.isArray(parsed)) return new Set(parsed.map(String));
      }
    } catch (e) {}
    return new Set();
  }

  function addSeenToastId(id) {
    if (!id && id !== 0) return;
    try {
      var set = getSeenToastIds();
      set.add(String(id));
      sessionStorage.setItem('gor_seen_toasts', JSON.stringify(Array.from(set)));
    } catch (e) {}
  }

  // Soft audio chime for real-time notifications
  function playNotificationChime() {
    try {
      var AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;
      var ctx = new AudioCtx();
      if (ctx.state === 'suspended') {
        ctx.resume();
      }
      var now = ctx.currentTime;
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();

      osc.type = 'sine';
      osc.frequency.setValueAtTime(587.33, now); // D5
      osc.frequency.setValueAtTime(880, now + 0.12); // A5

      gain.gain.setValueAtTime(0.0001, now);
      gain.gain.exponentialRampToValueAtTime(0.2, now + 0.04);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.45);

      osc.connect(gain);
      gain.connect(ctx.destination);

      osc.start(now);
      osc.stop(now + 0.45);
    } catch (e) {}
  }

  // Show Messenger-style Toast Popup on Bottom-Right
  function showToastPopup(item) {
    var container = document.getElementById('notificationToastContainer');
    if (!container) {
      container = document.createElement('div');
      container.id = 'notificationToastContainer';
      container.className = 'notif-toast-container';
      container.setAttribute('aria-live', 'polite');
      document.body.appendChild(container);
    }

    playNotificationChime();

    var iconInfo = getIconClass(item.type);
    var toast = document.createElement('div');
    toast.className = 'notif-toast';
    toast.setAttribute('data-id', item.id);
    toast.setAttribute('data-link', item.link || 'user_dashboard.php');

    toast.innerHTML = [
      '<div class="notif-icon-circle ' + iconInfo.circleClass + '" style="margin-top:2px;">',
      '  <i class="fas ' + iconInfo.icon + '"></i>',
      '</div>',
      '<div style="flex:1; min-width:0;">',
      '  <div class="notif-toast-header">',
      '    <span class="notif-toast-source"><i class="fas fa-bell"></i> Garden of Remembrance</span>',
      '    <span class="notif-toast-time">' + escapeHtml(item.time_formatted || '') + '</span>',
      '  </div>',
      '  <div class="notif-toast-title">' + escapeHtml(item.title || 'Notification') + '</div>',
      '  <p class="notif-toast-msg">' + escapeHtml(item.message || '') + '</p>',
      '</div>',
      '<button type="button" class="notif-toast-close" title="Dismiss">&times;</button>'
    ].join('');

    // Handle toast click
    toast.addEventListener('click', function (e) {
      if (e.target.classList.contains('notif-toast-close') || e.target.closest('.notif-toast-close')) {
        e.stopPropagation();
        addSeenToastId(item.id);
        dismissToast(toast);
        markAsRead(item.id);
        return;
      }
      e.preventDefault();
      addSeenToastId(item.id);
      dismissToast(toast);
      markAsRead(item.id, function () {
        if (item.link && item.link !== '#' && item.link !== 'user_dashboard.php' && item.link !== 'dashboard.php') {
          window.location.href = item.link;
        } else {
          showNotificationModal(item);
        }
      });
    });

    container.appendChild(toast);

    // Auto-dismiss after 6 seconds
    var timer = setTimeout(function () {
      addSeenToastId(item.id);
      dismissToast(toast);
    }, 6000);

    // Pause dismissal on hover
    toast.addEventListener('mouseenter', function () {
      clearTimeout(timer);
    });
    toast.addEventListener('mouseleave', function () {
      timer = setTimeout(function () {
        addSeenToastId(item.id);
        dismissToast(toast);
      }, 3000);
    });
  }

  function dismissToast(toast) {
    if (!toast) return;
    toast.classList.add('dismissing');
    setTimeout(function () {
      if (toast.parentNode) {
        toast.parentNode.removeChild(toast);
      }
    }, 300);
  }

  // Resilient API Fetch with fallback support
  function apiNotifFetch(queryString, options) {
    var opt = options || {};
    opt.credentials = opt.credentials || 'same-origin';
    var isPost = (opt.method && opt.method.toUpperCase() === 'POST');
    var q = queryString ? ('?' + queryString.replace(/^\?/, '')) : '';

    var base = getBaseUrl();
    var endpoints = [
      base + 'notifications_api.php' + (isPost ? '' : q),
      'notifications_api.php' + (isPost ? '' : q),
      '../notifications_api.php' + (isPost ? '' : q),
      './notifications_api.php' + (isPost ? '' : q),
      base + 'api/notifications.php' + (isPost ? '' : q),
      'api/notifications.php' + (isPost ? '' : q)
    ];

    var seen = new Set();
    endpoints = endpoints.filter(function (ep) {
      if (seen.has(ep)) return false;
      seen.add(ep);
      return true;
    });

    function tryNext(index) {
      if (index >= endpoints.length) return Promise.reject(new Error('Notification endpoints unavailable'));
      return fetch(endpoints[index], opt).then(function (res) {
        if (!res.ok && (res.status === 404 || res.status === 500) && index < endpoints.length - 1) {
          return tryNext(index + 1);
        }
        return res.text().then(function (text) {
          var clean = (text || '').trim();
          if (clean.indexOf('{') === 0 || clean.indexOf('[') === 0) {
            try {
              return JSON.parse(clean);
            } catch (jsonErr) {
              if (index < endpoints.length - 1) return tryNext(index + 1);
              throw jsonErr;
            }
          }
          if (index < endpoints.length - 1) return tryNext(index + 1);
          throw new Error('Non-JSON response from server');
        });
      }).catch(function (err) {
        if (index < endpoints.length - 1) return tryNext(index + 1);
        throw err;
      });
    }
    return tryNext(0);
  }

  // Fetch notifications from API
  function fetchNotifications() {
    apiNotifFetch('action=get_notifications&limit=15&_t=' + Date.now())
      .then(function (data) {
        if (!data || !data.success) {
          renderNotificationContainers([]);
          updateBadgeCounters(0);
          return;
        }

        updateBadgeCounters(data.unread_count || 0);
        currentNotifications = data.notifications || [];

        // Render into desktop body and mobile body
        renderNotificationContainers(currentNotifications);

        var seenToasts = getSeenToastIds();

        // On first load, never flood screen with historical unread cards.
        // Show at most 1 most recent notification if unseen in this session.
        if (isFirstLoad) {
          var unreadList = currentNotifications.filter(function (n) {
            return !n.is_read && !seenToasts.has(String(n.id)) && !seenNotificationIds.has(String(n.id));
          });
          if (unreadList.length > 0) {
            var newest = unreadList[0];
            seenNotificationIds.add(String(newest.id));
            addSeenToastId(newest.id);
            showToastPopup(newest);
          }
          // Mark all loaded unread items as seen in this session so page reloads don't spam popups
          currentNotifications.forEach(function (n) {
            if (!n.is_read) {
              seenNotificationIds.add(String(n.id));
              addSeenToastId(n.id);
            }
          });
        } else {
          // On polling, show only brand-new notifications
          currentNotifications.forEach(function (n) {
            var sId = String(n.id);
            if (!n.is_read && !seenNotificationIds.has(sId) && !seenToasts.has(sId)) {
              seenNotificationIds.add(sId);
              addSeenToastId(n.id);
              showToastPopup(n);
            } else {
              seenNotificationIds.add(sId);
            }
          });
        }

        isFirstLoad = false;
      })
      .catch(function (err) {
        renderNotificationContainers([]);
        updateBadgeCounters(0);
      });
  }

  function renderNotificationContainers(items) {
    var desktopList = document.getElementById('notifDropdownBody');
    var mobileList = document.getElementById('mobileNotifBody');

    var html = '';
    if (!items || items.length === 0) {
      html = '<div class="notif-empty"><i class="fas fa-bell-slash fa-2x mb-2" style="opacity:0.4;display:block;"></i>No notifications right now</div>';
    } else {
      items.forEach(function (item) {
        html += renderNotifItemHtml(item);
      });
    }

    if (desktopList) desktopList.innerHTML = html;
    if (mobileList) mobileList.innerHTML = html;

    // Attach click handlers to items
    attachItemClickHandlers(desktopList);
    attachItemClickHandlers(mobileList);
  }

  function ensureMobileNotificationModalDom() {
    var modal = document.getElementById('mobileNotificationModal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.id = 'mobileNotificationModal';
    modal.className = 'modal fade';
    modal.setAttribute('tabindex', '-1');
    modal.setAttribute('aria-labelledby', 'mobileNotificationModalLabel');
    modal.setAttribute('aria-hidden', 'true');
    modal.style.zIndex = '1000045';

    modal.innerHTML = [
      '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 500px; margin: 1.25rem auto;">',
      '  <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden; background: #ffffff;">',
      '    <div class="modal-header py-3 px-4 d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important; color: #ffffff !important; border-bottom: none;">',
      '      <div class="d-flex align-items-center gap-2">',
      '        <button type="button" class="btn btn-sm text-white p-0 border-0 me-1" id="mobileNotifBackBtn" style="display: none; font-size: 1.1rem; line-height: 1;" title="Back to notifications">',
      '          <i class="fas fa-arrow-left"></i>',
      '        </button>',
      '        <div style="width: 34px; height: 34px; border-radius: 50%; background: rgba(255,255,255,0.2); display: inline-flex; align-items: center; justify-content: center;">',
      '          <i class="fas fa-bell text-white" id="mobileNotifHeaderIcon" style="font-size: 1rem;"></i>',
      '        </div>',
      '        <h5 class="modal-title fs-6 fw-bold text-white mb-0" id="mobileNotificationModalLabel">Notifications</h5>',
      '      </div>',
      '      <div class="d-flex align-items-center gap-2">',
      '        <button type="button" class="notif-mark-all-btn" id="mobileNotifMarkAllBtn" title="Mark all as read">Mark all read</button>',
      '        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" id="mobileNotifModalCloseBtn"></button>',
      '      </div>',
      '    </div>',
      '    <div id="mobileNotifListView">',
      '      <div class="modal-body p-0 notif-body" id="mobileNotifBody" style="max-height: 65vh; overflow-y: auto; background: #ffffff;">',
      '        <div class="notif-empty"><i class="fas fa-spinner fa-spin me-1"></i> Loading notifications...</div>',
      '      </div>',
      '      <div class="modal-footer py-2 px-3 d-flex align-items-center justify-content-between" style="background: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 0.8rem;">',
      '        <span class="text-muted small"><i class="fas fa-shield-alt me-1"></i> Garden of Remembrance CMIS</span>',
      '        <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal" id="mobileNotifModalDismissBtn">Close</button>',
      '      </div>',
      '    </div>',
      '    <div id="mobileNotifDetailView" style="display: none;">',
      '      <div class="modal-body p-4" style="max-height: 65vh; overflow-y: auto; background: #ffffff; color: #1e293b;">',
      '        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">',
      '          <span id="mobileNotifDetailBadge" class="badge" style="background: #e2e8f0; color: #334155; font-weight: 700; font-size: 0.78rem; padding: 5px 10px; border-radius: 20px;"><i class="fas fa-bell me-1"></i> NOTICE</span>',
      '          <small class="text-muted" id="mobileNotifDetailTime" style="font-size: 0.78rem;"><i class="far fa-clock me-1"></i> <span></span></small>',
      '        </div>',
      '        <h6 class="fw-bold mb-3 text-dark" id="mobileNotifDetailTitle" style="font-size: 1.05rem; line-height: 1.4;">Notification Title</h6>',
      '        <div id="mobileNotifDetailReasonBox" class="p-3 mb-3" style="display:none; background: #fff5f5; border-left: 4px solid #dc2626; border-radius: 10px; box-shadow: 0 1px 3px rgba(220,38,38,0.08);">',
      '          <div class="d-flex align-items-center gap-2 mb-1" style="color: #991b1b; font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;"><i class="fas fa-exclamation-triangle"></i> Reason for Rejection</div>',
      '          <div id="mobileNotifDetailReason" style="color: #7f1d1d; font-size: 0.95rem; font-weight: 600; line-height: 1.5; white-space: pre-wrap;">No reason specified.</div>',
      '        </div>',
      '        <div class="mb-3 p-3" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;">',
      '          <div class="text-muted small fw-semibold mb-1" style="font-size: 0.76rem; text-transform: uppercase;"><i class="fas fa-info-circle me-1"></i> Information &amp; Message</div>',
      '          <div id="mobileNotifDetailMessage" class="mb-0 text-secondary" style="font-size: 0.9rem; line-height: 1.55; white-space: pre-wrap;"></div>',
      '        </div>',
      '        <div id="mobileNotifDetailHelpBox" class="d-flex align-items-start gap-2 p-2 px-3 rounded" style="background: #f1f5f9; font-size: 0.8rem; color: #475569;">',
      '          <i class="fas fa-shield-alt mt-1 text-secondary"></i>',
      '          <div id="mobileNotifDetailHelpText">Garden of Remembrance Cemetery Management and Grave Mapping System</div>',
      '        </div>',
      '      </div>',
      '      <div class="modal-footer px-3 py-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background: #f8fafc; border-top: 1px solid #e2e8f0;">',
      '        <button type="button" class="btn btn-sm btn-outline-secondary px-3" id="mobileNotifDetailBackBtnBottom"><i class="fas fa-arrow-left me-1"></i> Back</button>',
      '        <div class="d-flex align-items-center gap-2 flex-wrap">',
      '          <a href="map.php" id="mobileNotifDetailCemeteryBtn" class="btn btn-sm btn-outline-danger px-3 fw-semibold" style="display: none; border-radius: 6px;"><i class="fas fa-map-marked-alt me-1"></i> View Cemetery</a>',
      '          <a href="user_dashboard.php#recentBookingsSection" id="mobileNotifDetailBookingBtn" class="btn btn-sm text-white px-3 fw-semibold" style="display: none; background: #7b1113; border: none; border-radius: 6px;"><i class="fas fa-calendar-alt me-1"></i> View Booking</a>',
      '          <a href="user_dashboard.php" id="mobileNotifDetailActionBtn" class="btn btn-sm text-white px-3 fw-semibold" style="display: none; background: #7b1113; border: none; border-radius: 6px;"><i class="fas fa-arrow-right me-1"></i> View Details</a>',
      '          <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Close</button>',
      '        </div>',
      '      </div>',
      '    </div>',
      '  </div>',
      '</div>'
    ].join('');

    document.body.appendChild(modal);

    var markBtn = modal.querySelector('.notif-mark-all-btn');
    if (markBtn) {
      markBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        markAllAsRead();
      });
    }

    var backBtn = modal.querySelector('#mobileNotifBackBtn');
    if (backBtn) backBtn.addEventListener('click', resetMobileNotifView);
    var bottomBackBtn = modal.querySelector('#mobileNotifDetailBackBtnBottom');
    if (bottomBackBtn) bottomBackBtn.addEventListener('click', resetMobileNotifView);

    return modal;
  }

  function resetMobileNotifView() {
    var listView = document.getElementById('mobileNotifListView');
    var detailView = document.getElementById('mobileNotifDetailView');
    var backBtn = document.getElementById('mobileNotifBackBtn');
    var markAllBtn = document.getElementById('mobileNotifMarkAllBtn');
    var headerTitleEl = document.getElementById('mobileNotificationModalLabel');

    if (listView) listView.style.display = 'block';
    if (detailView) detailView.style.display = 'none';
    if (backBtn) backBtn.style.display = 'none';
    if (markAllBtn) markAllBtn.style.display = 'inline-block';
    if (headerTitleEl) headerTitleEl.textContent = 'Notifications';
  }

  function showMobileDetailView(item) {
    if (!item) return;
    var modalEl = ensureMobileNotificationModalDom();
    var listView = document.getElementById('mobileNotifListView');
    var detailView = document.getElementById('mobileNotifDetailView');
    var backBtn = document.getElementById('mobileNotifBackBtn');
    var markAllBtn = document.getElementById('mobileNotifMarkAllBtn');
    var headerTitleEl = document.getElementById('mobileNotificationModalLabel');

    if (listView) listView.style.display = 'none';
    if (detailView) detailView.style.display = 'block';
    if (backBtn) backBtn.style.display = 'inline-block';
    if (markAllBtn) markAllBtn.style.display = 'none';

    var isReject = isRejectionItem(item);
    var type = (item.type || '').toLowerCase();
    var title = item.title || 'Notification';
    var message = item.message || '';
    var timeFormatted = item.time_formatted || item.created_at || '';
    var targetLink = item.link || '';

    var badgeEl = document.getElementById('mobileNotifDetailBadge');
    var timeEl = document.getElementById('mobileNotifDetailTime');
    var titleEl = document.getElementById('mobileNotifDetailTitle');
    var reasonBox = document.getElementById('mobileNotifDetailReasonBox');
    var reasonEl = document.getElementById('mobileNotifDetailReason');
    var msgEl = document.getElementById('mobileNotifDetailMessage');
    var helpText = document.getElementById('mobileNotifDetailHelpText');
    var cemeteryBtn = document.getElementById('mobileNotifDetailCemeteryBtn');
    var bookingBtn = document.getElementById('mobileNotifDetailBookingBtn');
    var actionBtn = document.getElementById('mobileNotifDetailActionBtn');

    if (cemeteryBtn) cemeteryBtn.style.display = 'none';
    if (bookingBtn) bookingBtn.style.display = 'none';
    if (actionBtn) actionBtn.style.display = 'none';

    if (titleEl) titleEl.textContent = title;
    if (msgEl) msgEl.textContent = message;
    if (timeEl) {
      var span = timeEl.querySelector('span') || timeEl;
      span.textContent = timeFormatted;
    }

    if (isReject) {
      if (headerTitleEl) headerTitleEl.textContent = (type.indexOf('claim') !== -1) ? 'Plot Claim Rejection' : 'Rejection Details';
      if (badgeEl) {
        badgeEl.style.background = '#fee2e2';
        badgeEl.style.color = '#991b1b';
        badgeEl.innerHTML = '<i class="fas fa-ban me-1"></i> STATUS: REJECTED';
      }
      if (reasonBox) reasonBox.style.display = 'block';
      if (reasonEl) reasonEl.textContent = extractRejectionReason(item);
      if (helpText) helpText.textContent = 'If you have official proof of ownership (title deed, purchase receipt, or certification) or questions regarding this rejection, please contact the cemetery office or view your dashboard.';
    } else {
      if (reasonBox) reasonBox.style.display = 'none';
      if (type.indexOf('booking') !== -1) {
        if (headerTitleEl) headerTitleEl.textContent = 'Booking Information';
        if (badgeEl) {
          badgeEl.style.background = '#e0f2fe';
          badgeEl.style.color = '#0284c7';
          badgeEl.innerHTML = '<i class="fas fa-calendar-check me-1"></i> BOOKING UPDATE';
        }
        if (helpText) helpText.textContent = 'You can manage this booking and schedule requirements on your dashboard.';
      } else if (type.indexOf('payment') !== -1 || type.indexOf('installment') !== -1) {
        if (headerTitleEl) headerTitleEl.textContent = 'Payment & Installment Notice';
        if (badgeEl) {
          badgeEl.style.background = '#fef3c7';
          badgeEl.style.color = '#92400e';
          badgeEl.innerHTML = '<i class="fas fa-receipt me-1"></i> PAYMENT NOTICE';
        }
        if (helpText) helpText.textContent = 'Payment records and installment schedules can be viewed under plot finances.';
      } else if (type.indexOf('claim') !== -1 || title.toLowerCase().indexOf('claim') !== -1 || title.toLowerCase().indexOf('plot') !== -1) {
        if (headerTitleEl) headerTitleEl.textContent = 'Plot Claim Information';
        if (badgeEl) {
          badgeEl.style.background = '#dcfce7';
          badgeEl.style.color = '#15803d';
          badgeEl.innerHTML = '<i class="fas fa-check-circle me-1"></i> PLOT CLAIM / OWNERSHIP';
        }
        if (helpText) helpText.textContent = 'Verified plots can be managed and assigned deceased records on your dashboard.';
      } else if (type.indexOf('announcement') !== -1 || type.indexOf('promotion') !== -1) {
        if (headerTitleEl) headerTitleEl.textContent = 'Official Cemetery Announcement';
        if (badgeEl) {
          badgeEl.style.background = '#fef2f2';
          badgeEl.style.color = '#b91c1c';
          badgeEl.innerHTML = '<i class="fas fa-bullhorn me-1"></i> ANNOUNCEMENT';
        }
        if (helpText) helpText.textContent = 'Published by the Garden of Remembrance administration team.';
      } else {
        if (headerTitleEl) headerTitleEl.textContent = 'Notification Details';
        if (badgeEl) {
          badgeEl.style.background = '#e2e8f0';
          badgeEl.style.color = '#334155';
          badgeEl.innerHTML = '<i class="fas fa-bell me-1"></i> UPDATE';
        }
        if (helpText) helpText.textContent = 'Garden of Remembrance Cemetery Management and Grave Mapping System';
      }
    }

    var isAnnouncement = (
      type === 'announcement' ||
      type === 'promotion' ||
      type.indexOf('announcement') !== -1 ||
      title.toLowerCase().indexOf('announcement') !== -1
    );

    if (isAnnouncement) {
      if (cemeteryBtn) cemeteryBtn.style.display = 'none';
      if (bookingBtn) bookingBtn.style.display = 'none';
      if (actionBtn) actionBtn.style.display = 'none';
    } else {
      var bId = item.booking_id;
      if (!bId) {
        var bMatch = (title + ' ' + message).match(/(?:Ref\s*#?|booking\s*#?|Booking\s*#?)(\d+)/i);
        if (bMatch) bId = bMatch[1];
      }

      var secMatch = (message + ' ' + title).match(/Section\s+([A-Ja-j])/i);
      var plotMatch = (message + ' ' + title).match(/plot\s*#?\s*\(?(\d+)[-_,\s]+(\d+)\)?/i) || (message + ' ' + title).match(/\((\d+)[-_,](\d+)\)/);
      var targetSec = secMatch ? secMatch[1].toUpperCase() : '';
      var plotX = plotMatch ? plotMatch[1] : '';
      var plotY = plotMatch ? plotMatch[2] : '';

      var isTransfer = Boolean(
        type.indexOf('transfer') !== -1 ||
        title.toLowerCase().indexOf('transfer') !== -1 ||
        message.toLowerCase().indexOf('transfer') !== -1 ||
        (targetLink && targetLink.indexOf('transfer') !== -1)
      );

      var isGuestReservation = Boolean(
        type.indexOf('guest') !== -1 ||
        title.toLowerCase().indexOf('guest') !== -1 ||
        message.toLowerCase().indexOf('guest') !== -1 ||
        title.toLowerCase().indexOf('reservation request') !== -1 ||
        message.toLowerCase().indexOf('reservation request') !== -1 ||
        (targetLink && targetLink.indexOf('guest_reservations') !== -1)
      );

      var isBooking = Boolean(
        bId ||
        type.indexOf('booking') !== -1 ||
        type.indexOf('payment') !== -1 ||
        type.indexOf('installment') !== -1 ||
        type.indexOf('interment') !== -1 ||
        title.toLowerCase().indexOf('booking') !== -1 ||
        (targetLink && targetLink.indexOf('booking') !== -1)
      );

      var isCemetery = Boolean(
        targetSec ||
        type.indexOf('claim') !== -1 ||
        type.indexOf('plot') !== -1 ||
        title.toLowerCase().indexOf('claim') !== -1 ||
        title.toLowerCase().indexOf('plot') !== -1 ||
        (targetLink && (targetLink.indexOf('popup.php') !== -1 || targetLink.indexOf('myPlotsSection') !== -1))
      );

      if (isTransfer && actionBtn) {
        actionBtn.style.display = 'inline-flex';
        actionBtn.setAttribute('href', 'transfer_requests.php');
        actionBtn.innerHTML = '<i class="fas fa-exchange-alt me-1"></i> View Transfers';
      }

      if (isCemetery && cemeteryBtn) {
        cemeteryBtn.style.display = 'inline-flex';
        var cemUrl = 'map.php';
        if (targetSec && plotX && plotY) {
          cemUrl = 'sections/popup.php?section=' + encodeURIComponent(targetSec) + '&plot_x=' + plotX + '&plot_y=' + plotY + '&search_highlight=1&auto_open=1&from=user_dashboard';
        } else if (targetSec) {
          cemUrl = 'sections/popup.php?section=' + encodeURIComponent(targetSec) + '&from=user_dashboard';
        } else if (type.indexOf('claim') !== -1 || (targetLink && targetLink.indexOf('myPlotsSection') !== -1)) {
          cemUrl = 'user_dashboard.php#myPlotsSection';
        }
        cemeteryBtn.setAttribute('href', cemUrl);
      }

      if (isGuestReservation && bookingBtn) {
        bookingBtn.style.display = 'inline-flex';
        bookingBtn.innerHTML = '<i class="fas fa-clipboard-check me-1"></i> Check';
        var refMatch = (title + ' ' + message).match(/(?:Ref|Request)\s*#?(\d+)/i);
        var refId = refMatch ? refMatch[1] : (item.request_id || bId || '');
        var guestUrl = refId ? ('guest_reservations.php?request_id=' + encodeURIComponent(refId)) : 'guest_reservations.php';
        bookingBtn.setAttribute('href', guestUrl);
        bookingBtn.onclick = function (e) {
          e.preventDefault();
          closeModal();
          window.location.href = guestUrl;
        };
      } else if (isBooking && bookingBtn) {
        bookingBtn.style.display = 'inline-flex';
        bookingBtn.innerHTML = '<i class="fas fa-calendar-alt me-1"></i> View Booking';
        var bookUrl = bId ? ('user_dashboard.php?view_booking=' + encodeURIComponent(bId) + '#recentBookingsSection') : 'user_dashboard.php#recentBookingsSection';
        bookingBtn.setAttribute('href', bookUrl);
      }

      if (!isBooking && !isCemetery && !isTransfer && actionBtn && targetLink && targetLink !== '#' && targetLink !== 'user_dashboard.php') {
        actionBtn.style.display = 'inline-flex';
        actionBtn.setAttribute('href', targetLink);
        actionBtn.innerHTML = '<i class="fas fa-arrow-right me-1"></i> View Details';
      }
    }
  }

  function openMobileNotifs() {
    var modalEl = ensureMobileNotificationModalDom();
    resetMobileNotifView();

    // Close desktop dropdown if open
    var dropdown = document.getElementById('notifDropdownMenu');
    if (dropdown) dropdown.classList.remove('show');

    // Populate current notifications immediately if cached
    if (currentNotifications && currentNotifications.length > 0) {
      renderNotificationContainers(currentNotifications);
    }

    // Always fetch fresh notifications on open
    fetchNotifications();

    // Bind close handlers explicitly to prevent trap
    var closeBtn = modalEl.querySelector('#mobileNotifModalCloseBtn');
    if (closeBtn) closeBtn.onclick = closeMobileNotifs;
    var dismissBtn = modalEl.querySelector('#mobileNotifModalDismissBtn');
    if (dismissBtn) dismissBtn.onclick = closeMobileNotifs;
    modalEl.onclick = function (e) {
      if (e.target === modalEl) closeMobileNotifs();
    };

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
      var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
      bsModal.show();
    } else {
      modalEl.classList.add('show');
      modalEl.style.display = 'block';
      modalEl.setAttribute('aria-modal', 'true');
      modalEl.removeAttribute('aria-hidden');
      if (!document.getElementById('mobileModalBackdropFallback')) {
        var fb = document.createElement('div');
        fb.id = 'mobileModalBackdropFallback';
        fb.className = 'modal-backdrop fade show';
        fb.style.zIndex = '1000040';
        fb.onclick = closeMobileNotifs;
        document.body.appendChild(fb);
      }
    }
  }

  function closeMobileNotifs() {
    var modalEl = document.getElementById('mobileNotificationModal');
    if (modalEl) {
      resetMobileNotifView();
      if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        var bs = bootstrap.Modal.getInstance(modalEl);
        if (bs) bs.hide();
      }
      modalEl.classList.remove('show');
      modalEl.style.display = 'none';
      modalEl.setAttribute('aria-hidden', 'true');
      var fb = document.getElementById('mobileModalBackdropFallback');
      if (fb && fb.parentNode) fb.parentNode.removeChild(fb);
    }
    var mobileDrawer = document.getElementById('mobileNotifDrawer');
    var mobileBackdrop = document.getElementById('mobileNotifBackdrop');
    if (mobileDrawer) mobileDrawer.classList.remove('show');
    if (mobileBackdrop) mobileBackdrop.classList.remove('show');
  }

  function ensureNotificationModalDom() {
    var modal = document.getElementById('gorNotificationInfoModal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.id = 'gorNotificationInfoModal';
    modal.className = 'modal fade';
    modal.setAttribute('tabindex', '-1');
    modal.setAttribute('aria-labelledby', 'gorNotificationModalLabel');
    modal.setAttribute('aria-hidden', 'true');
    modal.style.zIndex = '1000050';

    modal.innerHTML = [
      '<div class="modal-dialog modal-dialog-centered">',
      '  <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">',
      '    <div class="modal-header" id="gorNotifModalHeader" style="background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%); color: #ffffff; padding: 14px 20px; border-bottom: none;">',
      '      <div class="d-flex align-items-center gap-2">',
      '        <div id="gorNotifModalIconBox" style="width: 34px; height: 34px; border-radius: 50%; background: rgba(255,255,255,0.2); display: inline-flex; align-items: center; justify-content: center;">',
      '          <i id="gorNotifModalIcon" class="fas fa-bell text-white" style="font-size: 1.05rem;"></i>',
      '        </div>',
      '        <h5 class="modal-title fs-6 fw-bold text-white mb-0" id="gorNotificationModalLabel">Notification Details</h5>',
      '      </div>',
      '      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" id="gorNotificationModalCloseBtn"></button>',
      '    </div>',
      '    <div class="modal-body p-4" style="background: #ffffff; color: #1e293b;">',
      '      <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">',
      '        <span id="gorNotifModalBadge" class="badge" style="background: #e2e8f0; color: #334155; font-weight: 700; font-size: 0.78rem; padding: 5px 10px; border-radius: 20px;">',
      '          <i class="fas fa-bell me-1"></i> NOTICE',
      '        </span>',
      '        <small class="text-muted" id="gorNotifModalTime" style="font-size: 0.78rem;"><i class="far fa-clock me-1"></i><span></span></small>',
      '      </div>',
      '      <h6 class="fw-bold mb-3 text-dark" id="gorNotifModalTitle" style="font-size: 1.05rem; line-height: 1.4;">Notification Title</h6>',
      '      <div id="gorNotifModalReasonBox" class="p-3 mb-3" style="display:none; background: #fff5f5; border-left: 4px solid #dc2626; border-radius: 10px; box-shadow: 0 1px 3px rgba(220,38,38,0.08);">',
      '        <div class="d-flex align-items-center gap-2 mb-1" style="color: #991b1b; font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">',
      '          <i class="fas fa-exclamation-triangle"></i> Reason for Rejection',
      '        </div>',
      '        <div id="gorNotifModalReason" style="color: #7f1d1d; font-size: 0.95rem; font-weight: 600; line-height: 1.5; white-space: pre-wrap;">No reason specified.</div>',
      '      </div>',
      '      <div class="mb-3 p-3" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;">',
      '        <div class="text-muted small fw-semibold mb-1" style="font-size: 0.76rem; text-transform: uppercase;"><i class="fas fa-info-circle me-1"></i> Information &amp; Message</div>',
      '        <div id="gorNotifModalMessage" class="mb-0 text-secondary" style="font-size: 0.9rem; line-height: 1.55; white-space: pre-wrap;"></div>',
      '      </div>',
      '      <div id="gorNotifModalHelpBox" class="d-flex align-items-start gap-2 p-2 px-3 rounded" style="background: #f1f5f9; font-size: 0.8rem; color: #475569;">',
      '        <i class="fas fa-shield-alt mt-1 text-secondary"></i>',
      '        <div id="gorNotifModalHelpText">Garden of Remembrance Cemetery Management and Grave Mapping System</div>',
      '      </div>',
      '    </div>',
      '    <div class="modal-footer px-4 py-3" style="background: #f8fafc; border-top: 1px solid #e2e8f0; gap: 8px;">',
      '      <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal" id="gorNotificationModalDismissBtn">Close</button>',
      '      <a href="map.php" id="gorNotifModalCemeteryBtn" class="btn btn-sm btn-outline-danger px-3 fw-semibold" style="display: none; border-radius: 6px;"><i class="fas fa-map-marked-alt me-1"></i> View Cemetery</a>',
      '      <a href="user_dashboard.php#recentBookingsSection" id="gorNotifModalBookingBtn" class="btn btn-sm text-white px-3 fw-semibold" style="display: none; background: #7b1113; border: none; border-radius: 6px;"><i class="fas fa-calendar-alt me-1"></i> View Booking</a>',
      '      <a href="user_dashboard.php" id="gorNotifModalActionBtn" class="btn btn-sm text-white px-4 fw-semibold" style="display: none; background: #7b1113; border: none; border-radius: 6px;"><i class="fas fa-arrow-right me-1"></i> View Details</a>',
      '    </div>',
      '  </div>',
      '</div>'
    ].join('');

    document.body.appendChild(modal);
    return modal;
  }

  function showNotificationModal(item) {
    if (!item) return;
    var modal = ensureNotificationModalDom();

    // Close notification menus & mobile notification modal
    closeMobileNotifs();
    var dropdown = document.getElementById('notifDropdownMenu');
    if (dropdown) dropdown.classList.remove('show');

    // Populate data
    var isReject = isRejectionItem(item);
    var type = (item.type || '').toLowerCase();
    var title = item.title || 'Notification';
    var message = item.message || '';
    var timeFormatted = item.time_formatted || item.created_at || '';
    var targetLink = item.link || '';

    var headerTitleEl = document.getElementById('gorNotificationModalLabel');
    var iconEl = document.getElementById('gorNotifModalIcon');
    var badgeEl = document.getElementById('gorNotifModalBadge');
    var timeEl = document.getElementById('gorNotifModalTime');
    var titleEl = document.getElementById('gorNotifModalTitle');
    var reasonBox = document.getElementById('gorNotifModalReasonBox');
    var reasonEl = document.getElementById('gorNotifModalReason');
    var msgEl = document.getElementById('gorNotifModalMessage');
    var helpText = document.getElementById('gorNotifModalHelpText');
    var cemeteryBtn = document.getElementById('gorNotifModalCemeteryBtn');
    var bookingBtn = document.getElementById('gorNotifModalBookingBtn');
    var actionBtn = document.getElementById('gorNotifModalActionBtn');

    if (cemeteryBtn) cemeteryBtn.style.display = 'none';
    if (bookingBtn) bookingBtn.style.display = 'none';
    if (actionBtn) actionBtn.style.display = 'none';

    if (titleEl) titleEl.textContent = title;
    if (msgEl) msgEl.textContent = message;
    if (timeEl) {
      var span = timeEl.querySelector('span') || timeEl;
      span.textContent = timeFormatted;
    }

    if (isReject) {
      if (headerTitleEl) headerTitleEl.textContent = (type.indexOf('claim') !== -1) ? 'Plot Claim Rejection Details' : 'Rejection Details';
      if (iconEl) iconEl.className = 'fas fa-times-circle text-danger';
      if (badgeEl) {
        badgeEl.style.background = '#fee2e2';
        badgeEl.style.color = '#991b1b';
        badgeEl.innerHTML = '<i class="fas fa-ban me-1"></i> STATUS: REJECTED';
      }
      if (reasonBox) reasonBox.style.display = 'block';
      if (reasonEl) reasonEl.textContent = extractRejectionReason(item);
      if (helpText) helpText.textContent = 'If you have official proof of ownership (title deed, purchase receipt, or certification) or questions regarding this rejection, please contact the cemetery office or view your dashboard.';
    } else {
      if (reasonBox) reasonBox.style.display = 'none';
      if (type.indexOf('booking') !== -1) {
        if (headerTitleEl) headerTitleEl.textContent = 'Booking Information';
        if (iconEl) iconEl.className = 'fas fa-calendar-check text-white';
        if (badgeEl) {
          badgeEl.style.background = '#e0f2fe';
          badgeEl.style.color = '#0284c7';
          badgeEl.innerHTML = '<i class="fas fa-calendar-check me-1"></i> BOOKING UPDATE';
        }
        if (helpText) helpText.textContent = 'You can manage this booking and schedule requirements on your dashboard.';
      } else if (type.indexOf('payment') !== -1 || type.indexOf('installment') !== -1) {
        if (headerTitleEl) headerTitleEl.textContent = 'Payment & Installment Notice';
        if (iconEl) iconEl.className = 'fas fa-receipt text-white';
        if (badgeEl) {
          badgeEl.style.background = '#fef3c7';
          badgeEl.style.color = '#92400e';
          badgeEl.innerHTML = '<i class="fas fa-receipt me-1"></i> PAYMENT NOTICE';
        }
        if (helpText) helpText.textContent = 'Payment records and installment schedules can be viewed under plot finances.';
      } else if (type.indexOf('claim') !== -1 || title.toLowerCase().indexOf('claim') !== -1 || title.toLowerCase().indexOf('plot') !== -1) {
        if (headerTitleEl) headerTitleEl.textContent = 'Plot Claim Information';
        if (iconEl) iconEl.className = 'fas fa-map-marked-alt text-white';
        if (badgeEl) {
          badgeEl.style.background = '#dcfce7';
          badgeEl.style.color = '#15803d';
          badgeEl.innerHTML = '<i class="fas fa-check-circle me-1"></i> PLOT CLAIM / OWNERSHIP';
        }
        if (helpText) helpText.textContent = 'Verified plots can be managed and assigned deceased records on your dashboard.';
      } else if (type.indexOf('announcement') !== -1 || type.indexOf('promotion') !== -1) {
        if (headerTitleEl) headerTitleEl.textContent = 'Official Cemetery Announcement';
        if (iconEl) iconEl.className = 'fas fa-bullhorn text-white';
        if (badgeEl) {
          badgeEl.style.background = '#fef2f2';
          badgeEl.style.color = '#b91c1c';
          badgeEl.innerHTML = '<i class="fas fa-bullhorn me-1"></i> ANNOUNCEMENT';
        }
        if (helpText) helpText.textContent = 'Published by the Garden of Remembrance administration team.';
      } else {
        if (headerTitleEl) headerTitleEl.textContent = 'Notification Details';
        if (iconEl) iconEl.className = 'fas fa-bell text-white';
        if (badgeEl) {
          badgeEl.style.background = '#e2e8f0';
          badgeEl.style.color = '#334155';
          badgeEl.innerHTML = '<i class="fas fa-bell me-1"></i> UPDATE';
        }
        if (helpText) helpText.textContent = 'Garden of Remembrance Cemetery Management and Grave Mapping System';
      }
    }

    // Configure action buttons according to user specifications
    var isAnnouncement = (
      type === 'announcement' ||
      type === 'promotion' ||
      type.indexOf('announcement') !== -1 ||
      title.toLowerCase().indexOf('announcement') !== -1
    );

    if (isAnnouncement) {
      // User request: remove the view details on notifications if its only a text that admin's announced
      if (cemeteryBtn) cemeteryBtn.style.display = 'none';
      if (bookingBtn) bookingBtn.style.display = 'none';
      if (actionBtn) actionBtn.style.display = 'none';
    } else {
      // Extract Booking ID if present
      var bId = item.booking_id;
      if (!bId) {
        var bMatch = (title + ' ' + message).match(/(?:Ref\s*#?|booking\s*#?|Booking\s*#?)(\d+)/i);
        if (bMatch) bId = bMatch[1];
      }

      // Extract Section and Plot if present
      var secMatch = (message + ' ' + title).match(/Section\s+([A-Ja-j])/i);
      var plotMatch = (message + ' ' + title).match(/plot\s*#?\s*\(?(\d+)[-_,\s]+(\d+)\)?/i) || (message + ' ' + title).match(/\((\d+)[-_,](\d+)\)/);
      var targetSec = secMatch ? secMatch[1].toUpperCase() : '';
      var plotX = plotMatch ? plotMatch[1] : '';
      var plotY = plotMatch ? plotMatch[2] : '';

      var isGuestReservation = Boolean(
        type.indexOf('guest') !== -1 ||
        title.toLowerCase().indexOf('guest') !== -1 ||
        message.toLowerCase().indexOf('guest') !== -1 ||
        title.toLowerCase().indexOf('reservation request') !== -1 ||
        message.toLowerCase().indexOf('reservation request') !== -1 ||
        (targetLink && targetLink.indexOf('guest_reservations') !== -1)
      );

      var isBooking = Boolean(
        bId ||
        type.indexOf('booking') !== -1 ||
        type.indexOf('payment') !== -1 ||
        type.indexOf('installment') !== -1 ||
        type.indexOf('interment') !== -1 ||
        title.toLowerCase().indexOf('booking') !== -1 ||
        (targetLink && targetLink.indexOf('booking') !== -1)
      );

      var isCemetery = Boolean(
        targetSec ||
        type.indexOf('claim') !== -1 ||
        type.indexOf('plot') !== -1 ||
        title.toLowerCase().indexOf('claim') !== -1 ||
        title.toLowerCase().indexOf('plot') !== -1 ||
        (targetLink && (targetLink.indexOf('popup.php') !== -1 || targetLink.indexOf('myPlotsSection') !== -1))
      );

      // Handle "View Cemetery" button
      if (isCemetery && cemeteryBtn) {
        cemeteryBtn.style.display = 'inline-flex';
        var cemUrl = 'map.php';
        if (targetSec && plotX && plotY) {
          cemUrl = 'sections/popup.php?section=' + encodeURIComponent(targetSec) + '&plot_x=' + plotX + '&plot_y=' + plotY + '&search_highlight=1&auto_open=1&from=user_dashboard';
        } else if (targetSec) {
          cemUrl = 'sections/popup.php?section=' + encodeURIComponent(targetSec) + '&from=user_dashboard';
        } else if (type.indexOf('claim') !== -1 || (targetLink && targetLink.indexOf('myPlotsSection') !== -1)) {
          cemUrl = 'user_dashboard.php#myPlotsSection';
        }
        cemeteryBtn.setAttribute('href', cemUrl);
        cemeteryBtn.onclick = function (e) {
          closeModal();
          if (cemUrl.indexOf('myPlotsSection') !== -1 && window.location.pathname.indexOf('user_dashboard.php') !== -1) {
            e.preventDefault();
            var el = document.getElementById('myPlotsSection');
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        };
      }

      // Handle "Check" (Guest Request) vs "View Booking" (Booking) button
      if (isGuestReservation && bookingBtn) {
        bookingBtn.style.display = 'inline-flex';
        bookingBtn.innerHTML = '<i class="fas fa-clipboard-check me-1"></i> Check';
        var refMatch = (title + ' ' + message).match(/(?:Ref|Request)\s*#?(\d+)/i);
        var refId = refMatch ? refMatch[1] : (item.request_id || bId || '');
        var guestUrl = refId ? ('guest_reservations.php?request_id=' + encodeURIComponent(refId)) : 'guest_reservations.php';
        bookingBtn.setAttribute('href', guestUrl);
        bookingBtn.onclick = function (e) {
          e.preventDefault();
          closeModal();
          window.location.href = guestUrl;
        };
      } else if (isBooking && bookingBtn) {
        bookingBtn.style.display = 'inline-flex';
        bookingBtn.innerHTML = '<i class="fas fa-calendar-alt me-1"></i> View Booking';
        var bookUrl = 'user_dashboard.php';
        if (bId) {
          bookUrl = 'user_dashboard.php?view_booking=' + encodeURIComponent(bId) + '#recentBookingsSection';
        } else {
          bookUrl = 'user_dashboard.php#recentBookingsSection';
        }
        bookingBtn.setAttribute('href', bookUrl);
        bookingBtn.onclick = function (e) {
          closeModal();
          if (window.location.pathname.indexOf('user_dashboard.php') !== -1) {
            e.preventDefault();
            var bCard = bId ? (document.getElementById('bookingCard_' + bId) || document.getElementById('bookingDetails_' + bId)) : null;
            var targetElem = bCard || document.getElementById('recentBookingsSection');
            if (targetElem) {
              targetElem.scrollIntoView({ behavior: 'smooth', block: 'center' });
              if (bId) {
                var collapseEl = document.getElementById('bookingDetails_' + bId);
                if (collapseEl && typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
                  bootstrap.Collapse.getOrCreateInstance(collapseEl).show();
                }
              }
            }
          }
        };
      }

      var isTransfer = Boolean(
        type.indexOf('transfer') !== -1 ||
        title.toLowerCase().indexOf('transfer') !== -1 ||
        message.toLowerCase().indexOf('transfer') !== -1 ||
        (targetLink && targetLink.indexOf('transfer') !== -1)
      );

      if (isTransfer && actionBtn) {
        actionBtn.style.display = 'inline-flex';
        actionBtn.setAttribute('href', 'transfer_requests.php');
        actionBtn.innerHTML = '<i class="fas fa-exchange-alt me-1"></i> View Transfers';
      }

      // Fallback: If neither booking nor cemetery nor transfer, but has custom link
      if (!isBooking && !isCemetery && !isTransfer && actionBtn && targetLink && targetLink !== '#' && targetLink !== 'user_dashboard.php') {
        actionBtn.style.display = 'inline-flex';
        actionBtn.setAttribute('href', targetLink);
        actionBtn.innerHTML = '<i class="fas fa-arrow-right me-1"></i> View Details';
      }
    }

    function closeModal() {
      if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        var bs = bootstrap.Modal.getInstance(modal);
        if (bs) bs.hide();
      }
      modal.classList.remove('show');
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
      var fb = document.getElementById('gorModalBackdropFallback');
      if (fb && fb.parentNode) fb.parentNode.removeChild(fb);
    }

    var closeBtn = document.getElementById('gorNotificationModalCloseBtn');
    if (closeBtn) closeBtn.onclick = closeModal;
    var dismissBtn = document.getElementById('gorNotificationModalDismissBtn');
    if (dismissBtn) dismissBtn.onclick = closeModal;
    modal.onclick = function (e) {
      if (e.target === modal) closeModal();
    };

    // Show modal via Bootstrap or Fallback
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
      var bsModal = bootstrap.Modal.getOrCreateInstance(modal);
      bsModal.show();
    } else {
      modal.classList.add('show');
      modal.style.display = 'block';
      modal.setAttribute('aria-modal', 'true');
      modal.removeAttribute('aria-hidden');
      if (!document.getElementById('gorModalBackdropFallback')) {
        var backdrop = document.createElement('div');
        backdrop.id = 'gorModalBackdropFallback';
        backdrop.className = 'modal-backdrop fade show';
        backdrop.style.zIndex = '1000040';
        backdrop.onclick = closeModal;
        document.body.appendChild(backdrop);
      }
    }
  }

  function showRejectionModal(item) {
    showNotificationModal(item);
  }

  function attachItemClickHandlers(container) {
    if (!container) return;
    var items = container.querySelectorAll('.notif-item');
    items.forEach(function (el) {
      el.addEventListener('click', function (e) {
        e.preventDefault();
        var notifId = this.getAttribute('data-id');
        var link = this.getAttribute('data-link');
        var isReject = this.getAttribute('data-is-reject') === '1';

        var itemObj = currentNotifications.find(function (n) {
          return String(n.id) === String(notifId);
        });

        if (!itemObj) {
          var titleText = this.querySelector('.notif-title') ? this.querySelector('.notif-title').textContent : '';
          var msgText = this.querySelector('.notif-msg') ? this.querySelector('.notif-msg').textContent : '';
          var timeText = this.querySelector('.notif-time') ? this.querySelector('.notif-time').textContent : '';
          itemObj = {
            id: notifId,
            title: titleText,
            message: msgText,
            time_formatted: timeText,
            link: link,
            is_rejection: isReject
          };
        }

        markAsRead(notifId);

        // Actionable direct link (Just like Facebook notification click-through)
        var hasDirectLink = Boolean(
          link &&
          link !== '#' &&
          link !== 'user_dashboard.php' &&
          link !== 'dashboard.php' &&
          !isReject
        );

        if (hasDirectLink) {
          closeMobileNotifs();
          var dropdown = document.getElementById('notifDropdownMenu');
          if (dropdown) dropdown.classList.remove('show');
          window.location.href = link;
          return;
        }

        var isInsideMobileModal = Boolean(el.closest('#mobileNotificationModal') || (container && container.id === 'mobileNotifBody'));
        if (isInsideMobileModal) {
          showMobileDetailView(itemObj);
        } else {
          closeMobileNotifs();
          showNotificationModal(itemObj);
        }
      });
    });
  }

  // Mark single as read
  function markAsRead(id, callback) {
    if (!id && id !== 0) {
      if (typeof callback === 'function') callback();
      return;
    }
    addSeenToastId(id);

    // Dismiss active toast for this item if visible
    var activeToast = document.querySelector('.notif-toast[data-id="' + id + '"]');
    if (activeToast) dismissToast(activeToast);

    // Mark as read in local model
    currentNotifications.forEach(function (n) {
      if (String(n.id) === String(id)) {
        n.is_read = true;
      }
    });

    // Mark element visually read immediately
    document.querySelectorAll('.notif-item[data-id="' + id + '"]').forEach(function (el) {
      el.classList.remove('unread');
    });

    var fd = new FormData();
    fd.append('action', 'mark_as_read');
    fd.append('id', id);

    apiNotifFetch('', {
      method: 'POST',
      body: fd
    })
      .then(function (data) {
        if (data && data.success) {
          updateBadgeCounters(data.unread_count !== undefined ? data.unread_count : 0);
        }
        if (typeof callback === 'function') callback();
      })
      .catch(function () {
        if (typeof callback === 'function') callback();
      });
  }

  // Mark all as read
  function markAllAsRead() {
    // Dismiss all active toasts immediately
    document.querySelectorAll('.notif-toast').forEach(function (toast) {
      dismissToast(toast);
    });

    // Mark all as read in memory and session
    currentNotifications.forEach(function (n) {
      n.is_read = true;
      addSeenToastId(n.id);
    });

    updateBadgeCounters(0);
    document.querySelectorAll('.notif-item').forEach(function (el) {
      el.classList.remove('unread');
    });

    var fd = new FormData();
    fd.append('action', 'mark_all_read');

    apiNotifFetch('', {
      method: 'POST',
      body: fd
    })
      .then(function (data) {
        if (data && data.success) {
          updateBadgeCounters(0);
        }
      })
      .catch(function () {});
  }

  // Initialization
  var isNotificationSystemInitialized = false;
  var closeDropdownTimer = null;

  function initNotificationSystem() {
    if (isNotificationSystemInitialized) return;
    isNotificationSystemInitialized = true;

    // 1. Fetch initial notifications
    fetchNotifications();

    // 2. Setup real-time live polling every 5 seconds for instant admin updates without page refresh
    if (!window.__gorNotifPollInterval) {
      window.__gorNotifPollInterval = setInterval(fetchNotifications, 5000);
    }

    // 3. Desktop Bell Button Toggle
    var bellBtn = document.getElementById('notifBellButton');
    var dropdown = document.getElementById('notifDropdownMenu');

    if (bellBtn && dropdown) {
      function closeDropdownSmoothly() {
        if (closeDropdownTimer) {
          clearTimeout(closeDropdownTimer);
          closeDropdownTimer = null;
        }
        if (!dropdown.classList.contains('show')) return;
        dropdown.classList.add('closing');
        closeDropdownTimer = setTimeout(function () {
          dropdown.classList.remove('show', 'closing');
          closeDropdownTimer = null;
        }, 180);
      }

      function openDropdownSmoothly() {
        if (closeDropdownTimer) {
          clearTimeout(closeDropdownTimer);
          closeDropdownTimer = null;
        }
        dropdown.classList.remove('closing');
        dropdown.classList.add('show');
        bellBtn.classList.remove('ringing');
        void bellBtn.offsetWidth;
        bellBtn.classList.add('ringing');
        setTimeout(function () {
          bellBtn.classList.remove('ringing');
        }, 650);
      }

      function toggleDropdown(e) {
        if (e) {
          e.preventDefault();
          e.stopPropagation();
        }
        var isOpen = dropdown.classList.contains('show') && !dropdown.classList.contains('closing');
        if (isOpen) {
          closeDropdownSmoothly();
        } else {
          openDropdownSmoothly();
        }
      }

      bellBtn.onclick = toggleDropdown;

      // Close on outside click
      document.addEventListener('click', function (e) {
        if (dropdown.classList.contains('show')) {
          if (!dropdown.contains(e.target) && !bellBtn.contains(e.target) && e.target !== bellBtn) {
            closeDropdownSmoothly();
          }
        }
      });

      // Prevent clicks inside the dropdown from closing it
      dropdown.addEventListener('click', function (e) {
        e.stopPropagation();
      });
    }

    // 4. Mobile Bell Button & Notification Modal
    var mobileBellBtn = document.getElementById('mobileNotifButton');
    if (mobileBellBtn) {
      mobileBellBtn.onclick = function (e) {
        if (e) {
          e.preventDefault();
          e.stopPropagation();
        }
        mobileBellBtn.classList.remove('ringing');
        void mobileBellBtn.offsetWidth;
        mobileBellBtn.classList.add('ringing');
        setTimeout(function () {
          mobileBellBtn.classList.remove('ringing');
        }, 650);
        openMobileNotifs();
      };
    }

    // Support any dynamically rendered mobile notification triggers via event delegation
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('#mobileNotifButton, .mobile-notif-btn, [data-notif-trigger="mobile"]');
      if (btn && btn !== mobileBellBtn) {
        e.preventDefault();
        e.stopPropagation();
        openMobileNotifs();
      }
    });

    var mobileCloseBtn = document.getElementById('mobileNotifClose');
    var mobileBackdrop = document.getElementById('mobileNotifBackdrop');
    if (mobileCloseBtn) mobileCloseBtn.onclick = closeMobileNotifs;
    if (mobileBackdrop) mobileBackdrop.onclick = closeMobileNotifs;

    // Explicit dismiss binding for mobile modal close buttons
    document.addEventListener('click', function (e) {
      if (e.target && (e.target.id === 'mobileNotifModalCloseBtn' || e.target.id === 'mobileNotifModalDismissBtn' || e.target.closest('#mobileNotifModalCloseBtn, #mobileNotifModalDismissBtn'))) {
        closeMobileNotifs();
      }
      if (e.target && (e.target.id === 'mobileNotifBackBtn' || e.target.closest('#mobileNotifBackBtn') || e.target.id === 'mobileNotifDetailBackBtnBottom' || e.target.closest('#mobileNotifDetailBackBtnBottom'))) {
        e.preventDefault();
        resetMobileNotifView();
      }
    });

    var mobileModalEl = document.getElementById('mobileNotificationModal');
    if (mobileModalEl) {
      mobileModalEl.addEventListener('show.bs.modal', resetMobileNotifView);
      mobileModalEl.addEventListener('hidden.bs.modal', resetMobileNotifView);
    }

    // 5. Mark All Read buttons
    document.querySelectorAll('.notif-mark-all-btn').forEach(function (btn) {
      btn.onclick = function (e) {
        if (e) e.stopPropagation();
        markAllAsRead();
      };
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNotificationSystem);
  } else {
    initNotificationSystem();
  }

  window.initNotificationSystem = initNotificationSystem;
  window.gorNotifications = {
    init: initNotificationSystem,
    fetch: fetchNotifications,
    markAsRead: markAsRead,
    markAllAsRead: markAllAsRead,
    showToast: showToastPopup,
    openMobile: openMobileNotifs,
    closeMobile: closeMobileNotifs
  };
})();
