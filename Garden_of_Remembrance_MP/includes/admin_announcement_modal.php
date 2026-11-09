<?php

if (session_status() === PHP_SESSION_NONE) {
  @session_start();
}
if (empty($_SESSION['announcement_csrf'])) {
  $_SESSION['announcement_csrf'] = bin2hex(random_bytes(32));
}
$announcementCsrf = $_SESSION['announcement_csrf'];
?>

<div class="modal fade" id="announcementModal" data-csrf="<?php echo htmlspecialchars($announcementCsrf, ENT_QUOTES, 'UTF-8'); ?>" tabindex="-1" aria-labelledby="announcementModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem; overflow: hidden;">

      <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important; color: #ffffff !important; border-bottom: 1px solid rgba(255,255,255,0.15) !important;">
        <div class="d-flex align-items-center gap-2">
          <div style="background: rgba(255,255,255,0.18); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0;">
            <i class="fas fa-bullhorn text-white"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold m-0" id="announcementModalLabel" style="font-size: 1.15rem; color: #ffffff !important;">
              Broadcast Announcement or Promotion
            </h5>
            <div style="font-size: 0.8rem; color: rgba(255,255,255,0.92) !important;">
              Dispatches In-System Notifications and PHPMailer Emails to Registered Users
            </div>
          </div>
        </div>
        <button type="button" class="btn-close custom-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-4" style="background: #f8fafc;">
        <form id="adminAnnouncementForm" onsubmit="return false;" novalidate>

          <div class="row g-3 mb-3">
            <div class="col-md-7">
              <label for="announcementTitle" class="form-label small fw-bold text-dark text-uppercase">
                Title / Subject <span class="text-danger">*</span>
              </label>
              <input type="text" class="form-control" id="announcementTitle" placeholder="e.g. All Saints' Day Schedule Notice" required>
            </div>

            <div class="col-md-5">
              <label for="announcementType" class="form-label small fw-bold text-dark text-uppercase">
                Notification Type <span class="text-danger">*</span>
              </label>
              <select class="form-select" id="announcementType" required>
                <option value="announcement" selected>📢 Official Announcement</option>
                <option value="promotion">🎁 Promotional Offer</option>
              </select>
            </div>
          </div>

          <div class="mb-3">
            <label for="announcementGroup" class="form-label small fw-bold text-dark text-uppercase">
              Target Audience / Recipient Group <span class="text-danger">*</span>
            </label>
            <select class="form-select" id="announcementGroup" required>
              <option value="all" selected>All Registered Users (Cemetery Community)</option>
              <option value="active_bookings">Users with Active Bookings (Pending & Approved)</option>
              <option value="active_installments">Users with Active Installment Plans (Pending & Overdue)</option>
              <option value="specific">Specific User Only</option>
            </select>
          </div>

          <div class="mb-3" id="specificUserRow" style="display: none;">
            <label for="specificUserSelect" class="form-label small fw-bold text-dark text-uppercase">
              Select Specific User <span class="text-danger">*</span>
            </label>
            <select class="form-select" id="specificUserSelect">
              <option value="">-- Choose User --</option>
            </select>
          </div>

          <div class="mb-3">
            <label for="announcementMessage" class="form-label small fw-bold text-dark text-uppercase">
              Message Content <span class="text-danger">*</span>
            </label>
            <textarea class="form-control" id="announcementMessage" rows="5" placeholder="Enter your detailed announcement or promotion details here..." required></textarea>
            <div class="form-text text-muted small">
              This message will appear in the user's notification bell panel and be sent to their verified email address.
            </div>
          </div>

          <div id="announcementAlert" class="alert alert-danger py-2 px-3 small" style="display: none;"></div>

        </form>
      </div>

      <div class="modal-footer bg-white border-top py-3 px-4 d-flex justify-content-between">
        <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4 fw-bold shadow-sm" id="sendAnnouncementBtn" style="background: #7b1113; border-color: #7b1113;">
          <i class="fas fa-paper-plane me-2"></i> Send Broadcast
        </button>
      </div>

    </div>
  </div>
</div>

<div class="modal fade" id="announcementConfirmModal" tabindex="-1" aria-labelledby="announcementConfirmLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #7b1113 0%, #580c0d 100%) !important; color: #ffffff !important; border-bottom: 1px solid rgba(255,255,255,0.15) !important;">
        <h5 class="modal-title text-white fw-bold m-0" id="announcementConfirmLabel" style="color: #ffffff !important;">Confirm Notification</h5>
        <button type="button" class="btn-close custom-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <dl class="row mb-0 small">
          <dt class="col-4">Title</dt><dd class="col-8" id="confirmAnnouncementTitle"></dd>
          <dt class="col-4">Recipients</dt><dd class="col-8" id="confirmAnnouncementRecipients"></dd>
          <dt class="col-4">Message</dt><dd class="col-8 text-break" id="confirmAnnouncementMessage"></dd>
        </dl>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmAnnouncementSendBtn">Send Notification</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="announcementResultModal" tabindex="-1" aria-labelledby="announcementResultLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow">
      <div class="modal-body text-center p-4">
        <div id="announcementResultIcon" class="fs-1 mb-2"></div>
        <h5 id="announcementResultLabel" class="mb-2"></h5>
        <p id="announcementResultMessage" class="small text-muted mb-3"></p>
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
// Global compatibility entry point for older live button markup.
window.openBroadcastModal = function() {
  var modalEl = document.getElementById('announcementModal');
  if (!modalEl) return false;
  if (window.bootstrap && bootstrap.Modal) {
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  } else {
    modalEl.classList.add('show');
    modalEl.style.display = 'block';
    modalEl.setAttribute('aria-hidden', 'false');
  }
  return false;
};

document.addEventListener('DOMContentLoaded', function() {
  var groupSelect = document.getElementById('announcementGroup');
  var specificRow = document.getElementById('specificUserRow');
  var specificUserSelect = document.getElementById('specificUserSelect');
  var badgeCount = document.getElementById('recipientBadgeCount');
  var previewText = document.getElementById('recipientPreviewText');
  var refreshBtn = document.getElementById('refreshRecipientCountBtn');
  var sendBtn = document.getElementById('sendAnnouncementBtn');
  var alertBox = document.getElementById('announcementAlert');
  var modalEl = document.getElementById('announcementModal');
  var confirmModalEl = document.getElementById('announcementConfirmModal');
  var resultModalEl = document.getElementById('announcementResultModal');
  var confirmSendBtn = document.getElementById('confirmAnnouncementSendBtn');

  document.querySelectorAll('[data-bs-target="#announcementModal"], #broadcastAnnouncementBtn').forEach(function(button) {
    button.addEventListener('click', function(event) {
      if (!window.bootstrap || !bootstrap.Modal) {
        event.preventDefault();
        window.openBroadcastModal();
      }
    });
  });

  function apiUrl(path) {
    var pathname = window.location.pathname;
    var prefix = pathname.indexOf('/Admin/') !== -1 || pathname.indexOf('/User/') !== -1 || pathname.indexOf('/sections/') !== -1 ? '../' : '';
    return prefix + path;
  }

  function apiAnnouncementFetch(queryOrAction, options) {
    var opt = options || {};
    opt.credentials = opt.credentials || 'same-origin';
    var isPost = (opt.method && opt.method.toUpperCase() === 'POST');
    var qStr = queryOrAction ? ('?' + queryOrAction.replace(/^\?/, '')) : '';

    var endpoints = [
      apiUrl('api/admin_announcement.php' + (isPost ? '' : qStr)),
      apiUrl('admin_announcement_api.php' + (isPost ? '' : qStr))
    ];

    function tryUrl(index) {
      if (index >= endpoints.length) return Promise.reject(new Error('Announcement endpoint unavailable'));
      return fetch(endpoints[index], opt).then(function(r) {
        if (!r.ok && (r.status === 404 || r.status === 500) && index < endpoints.length - 1) {
          return tryUrl(index + 1);
        }
        return r;
      }).catch(function(err) {
        if (index < endpoints.length - 1) return tryUrl(index + 1);
        throw err;
      });
    }
    return tryUrl(0);
  }

  function showResult(success, message) {
    var icon = document.getElementById('announcementResultIcon');
    document.getElementById('announcementResultLabel').textContent = success ? 'Notification sent successfully.' : 'Notification failed';
    document.getElementById('announcementResultMessage').textContent = message || '';
    icon.innerHTML = success ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-circle-exclamation text-danger"></i>';
    if (resultModalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(resultModalEl).show();
  }

  function updateRecipientPreview() {
    if (!groupSelect || !badgeCount) return;
    var group = groupSelect.value;
    var specificId = (group === 'specific' && specificUserSelect) ? specificUserSelect.value : '';

    badgeCount.textContent = '...';
    previewText.textContent = 'Updating recipient count...';

    apiAnnouncementFetch('action=preview_recipients&recipient_group=' + encodeURIComponent(group) + '&specific_user_id=' + encodeURIComponent(specificId))
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (d && d.success) {
          badgeCount.textContent = d.count + ' Users';
          var samples = (d.sample_names && d.sample_names.length) ? ' (e.g. ' + d.sample_names.slice(0, 3).join(', ') + ')' : '';
          previewText.textContent = d.count + ' recipient(s) will receive in-system notification & email' + samples;
        } else {
          badgeCount.textContent = '0';
          previewText.textContent = d.message || 'No recipients found';
        }
      })
      .catch(function() {
        badgeCount.textContent = '?';
        previewText.textContent = 'Could not preview recipients';
      });
  }

  function loadUsersList() {
    if (!specificUserSelect) return;
    apiAnnouncementFetch('action=get_users_list')
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (d && d.success && d.users) {
          specificUserSelect.innerHTML = '<option value="">-- Choose User --</option>';
          d.users.forEach(function(u) {
            var opt = document.createElement('option');
            opt.value = u.user_id;
            opt.textContent = (u.name || 'User #' + u.user_id) + ' (' + u.email + ')';
            specificUserSelect.appendChild(opt);
          });
        }
      });
  }

  if (groupSelect) {
    groupSelect.addEventListener('change', function() {
      if (this.value === 'specific') {
        if (specificRow) specificRow.style.display = 'block';
        loadUsersList();
      } else {
        if (specificRow) specificRow.style.display = 'none';
      }
      updateRecipientPreview();
    });
  }

  if (specificUserSelect) {
    specificUserSelect.addEventListener('change', updateRecipientPreview);
  }

  if (refreshBtn) {
    refreshBtn.addEventListener('click', updateRecipientPreview);
  }

  // Update on modal open
  if (modalEl) {
    modalEl.addEventListener('shown.bs.modal', updateRecipientPreview);
  }

  // Handle Send Broadcast
  if (sendBtn) {
    sendBtn.addEventListener('click', function() {
      var title = (document.getElementById('announcementTitle') ? document.getElementById('announcementTitle').value : '').trim();
      var message = (document.getElementById('announcementMessage') ? document.getElementById('announcementMessage').value : '').trim();
      var type = document.getElementById('announcementType') ? document.getElementById('announcementType').value : 'announcement';
      var group = groupSelect ? groupSelect.value : 'all';
      var specificId = (group === 'specific' && specificUserSelect) ? specificUserSelect.value : '';

      if (alertBox) alertBox.style.display = 'none';

      if (!title || !message) {
        if (alertBox) {
          alertBox.textContent = 'Please enter both title and message content.';
          alertBox.style.display = 'block';
        }
        return;
      }

      if (group === 'specific' && !specificId) {
        if (alertBox) {
          alertBox.textContent = 'Please select a specific user.';
          alertBox.style.display = 'block';
        }
        return;
      }

      document.getElementById('confirmAnnouncementTitle').textContent = title;
      document.getElementById('confirmAnnouncementRecipients').textContent = badgeCount ? badgeCount.textContent : 'Selected recipients';
      document.getElementById('confirmAnnouncementMessage').textContent = message;
      if (confirmModalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(confirmModalEl).show();
    });
  }

  if (confirmSendBtn) {
    confirmSendBtn.addEventListener('click', function() {
      var title = (document.getElementById('announcementTitle') || {}).value || '';
      var message = (document.getElementById('announcementMessage') || {}).value || '';
      var type = document.getElementById('announcementType') ? document.getElementById('announcementType').value : 'announcement';
      var group = groupSelect ? groupSelect.value : 'all';
      var specificId = (group === 'specific' && specificUserSelect) ? specificUserSelect.value : '';
      confirmSendBtn.disabled = true;
      confirmSendBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Sending...';
      var fd = new FormData();
      fd.append('action', 'send'); fd.append('title', title); fd.append('message', message);
      fd.append('type', type); fd.append('recipient_group', group);
      fd.append('csrf_token', modalEl ? (modalEl.getAttribute('data-csrf') || '') : '');
      if (specificId) fd.append('specific_user_id', specificId);
      function sendRequest(retry) {
        apiAnnouncementFetch('', { method: 'POST', body: fd })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, status: r.status, data: d }; }); })
        .then(function(result) {
          var d = result.data || {};
          if (result.status === 419 && d.csrf_token && !retry) {
            if (modalEl) modalEl.setAttribute('data-csrf', d.csrf_token);
            fd.set('csrf_token', d.csrf_token);
            return sendRequest(true);
          }
          if (!result.ok || !d.success) throw new Error(d.message || 'Unable to send notification.');
          var stats = d.stats || {};
          if (document.getElementById('announcementTitle')) document.getElementById('announcementTitle').value = '';
          if (document.getElementById('announcementMessage')) document.getElementById('announcementMessage').value = '';
          if (confirmModalEl) bootstrap.Modal.getOrCreateInstance(confirmModalEl).hide();
          if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
          showResult(true, (stats.in_system_sent || 0) + ' in-system notification(s) saved.');
          if (window.gorNotifications && typeof window.gorNotifications.fetch === 'function') window.gorNotifications.fetch();
        })
        .catch(function(error) { showResult(false, error.message); })
        .finally(function() {
          confirmSendBtn.disabled = false;
          confirmSendBtn.innerHTML = 'Send Notification';
          sendBtn.disabled = false;
          sendBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i> Send Broadcast';
        });
      }
      apiAnnouncementFetch('action=csrf_token')
        .then(function(r) { return r.json(); })
        .then(function(tokenData) {
          if (tokenData && tokenData.success && tokenData.csrf_token) {
            if (modalEl) modalEl.setAttribute('data-csrf', tokenData.csrf_token);
            fd.set('csrf_token', tokenData.csrf_token);
          }
          return sendRequest(false);
        })
        .catch(function() { return sendRequest(false); });
    });
  }
});
</script>
