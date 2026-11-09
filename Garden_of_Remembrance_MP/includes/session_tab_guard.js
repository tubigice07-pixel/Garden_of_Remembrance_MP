/**
 * Garden of Remembrance — Session Tab Guard
 * Tracks per-tab "home page" using sessionStorage so that when a session
 * expires or a redirect happens, each tab returns to the correct page.
 *
 * Usage: Include this script early in dashboard.php, user_dashboard.php, index.php
 * Initialise by calling: GorSessionGuard.init('dashboard'); // or 'user_dashboard' or 'index'
 */
(function (window) {
    'use strict';

    var GorSessionGuard = {

        /**
         * Known home page configs keyed by page name
         */
        pages: {
            'dashboard':      { home: 'dashboard.php',      loginUrl: 'admin_login.php',  role: 'ADMIN' },
            'user_dashboard': { home: 'user_dashboard.php', loginUrl: 'login.php',        role: 'USER'  },
            'index':          { home: 'index.php',          loginUrl: 'login.php',        role: 'GUEST' }
        },

        /**
         * Initialise the guard for the current page.
         * @param {string} pageName  - 'dashboard' | 'user_dashboard' | 'index'
         */
        init: function (pageName) {
            if (!pageName || !this.pages[pageName]) return;

            // Store per-tab home in sessionStorage (does NOT bleed to other tabs)
            sessionStorage.setItem('gor_tab_home', pageName);
            sessionStorage.setItem('gor_tab_login', this.pages[pageName].loginUrl);

            // Patch any existing global fetch / XMLHttpRequest error handlers
            this._patchFetch();
        },

        /**
         * Get the home page URL for the current tab.
         */
        getHome: function () {
            var name = sessionStorage.getItem('gor_tab_home') || 'index';
            var cfg  = this.pages[name] || this.pages['index'];
            return cfg.home;
        },

        /**
         * Get the login URL for the current tab.
         */
        getLoginUrl: function () {
            var name = sessionStorage.getItem('gor_tab_home') || 'index';
            var cfg  = this.pages[name] || this.pages['index'];
            return cfg.loginUrl;
        },

        /**
         * Handle a session_expired flag from a JSON response.
         * Shows a brief toast then redirects to the correct login page.
         * @param {string|null} message - Optional message to show
         */
        handleSessionExpired: function (message) {
            var loginUrl = this.getLoginUrl();
            // Append ?session_expired=1 for the login page to show the banner
            var dest = loginUrl + (loginUrl.indexOf('?') === -1 ? '?' : '&') + 'session_expired=1';

            if (message) {
                // Try to show a non-blocking notification before redirect
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Session Expired',
                        text: message,
                        timer: 2500,
                        showConfirmButton: false,
                        timerProgressBar: true
                    }).then(function () {
                        window.location.href = dest;
                    });
                } else {
                    alert(message);
                    window.location.href = dest;
                }
            } else {
                window.location.href = dest;
            }
        },

        /**
         * Handle a response object that may contain session_expired.
         * Returns true if the session was expired and handled, false otherwise.
         * @param {Object} res - Parsed JSON response
         * @returns {boolean}
         */
        checkResponse: function (res) {
            if (res && res.session_expired) {
                this.handleSessionExpired(res.message || null);
                return true;
            }
            return false;
        },

        /**
         * Patch the global fetch API to intercept session_expired on all
         * JSON responses automatically.
         * Individual callers can still handle the result themselves; this
         * is just a safety net.
         */
        _patchFetch: function () {
            var self = this;
            if (!window.fetch || window._gorFetchPatched) return;
            window._gorFetchPatched = true;

            var origFetch = window.fetch;
            window.fetch = function () {
                return origFetch.apply(this, arguments).then(function (response) {
                    // Clone to allow body to be read twice
                    var cloned = response.clone();
                    var ct = response.headers.get('Content-Type') || '';
                    if (ct.indexOf('application/json') !== -1) {
                        cloned.json().then(function (data) {
                            if (data && data.session_expired && !window._gorHandlingExpiry) {
                                window._gorHandlingExpiry = true;
                                self.handleSessionExpired(data.message || null);
                            }
                        }).catch(function () {});
                    }
                    return response; // Return original (unconsumed) response to caller
                });
            };
        }
    };

    // Expose globally
    window.GorSessionGuard = GorSessionGuard;

}(window));
