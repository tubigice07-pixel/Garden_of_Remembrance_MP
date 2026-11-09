/**
 * ie_polyfills.js
 * Cross-browser polyfills for IE11 and legacy Edge.
 * Included in admin_header.php and user_header.php so every page benefits.
 */
(function (w, d) {
  'use strict';

  /* ── 1. URLSearchParams ──────────────────────────────────────────────── */
  if (typeof w.URLSearchParams === 'undefined') {
    w.URLSearchParams = function URLSearchParams(init) {
      this._params = [];
      var self = this;

      function decode(str) {
        return decodeURIComponent((str || '').replace(/\+/g, ' '));
      }

      if (typeof init === 'string') {
        init = init.replace(/^\?/, '');
        if (init) {
          init.split('&').forEach(function (pair) {
            var idx = pair.indexOf('=');
            if (idx === -1) {
              self._params.push([decode(pair), '']);
            } else {
              self._params.push([decode(pair.slice(0, idx)), decode(pair.slice(idx + 1))]);
            }
          });
        }
      } else if (init && typeof init === 'object') {
        Object.keys(init).forEach(function (k) {
          self._params.push([k, String(init[k])]);
        });
      }
    };

    w.URLSearchParams.prototype = {
      append: function (name, value) { this._params.push([String(name), String(value)]); },
      delete: function (name) { this._params = this._params.filter(function (p) { return p[0] !== name; }); },
      get: function (name) {
        for (var i = 0; i < this._params.length; i++) {
          if (this._params[i][0] === name) return this._params[i][1];
        }
        return null;
      },
      getAll: function (name) {
        return this._params.filter(function (p) { return p[0] === name; }).map(function (p) { return p[1]; });
      },
      has: function (name) { return this._params.some(function (p) { return p[0] === name; }); },
      set: function (name, value) {
        var found = false;
        this._params = this._params.filter(function (p) {
          if (p[0] === name) {
            if (!found) { found = true; p[1] = String(value); return true; }
            return false;
          }
          return true;
        });
        if (!found) this._params.push([String(name), String(value)]);
      },
      toString: function () {
        return this._params.map(function (p) {
          return encodeURIComponent(p[0]) + '=' + encodeURIComponent(p[1]);
        }).join('&');
      },
      forEach: function (cb) { this._params.forEach(function (p) { cb(p[1], p[0]); }); }
    };
  }

  /* ── 2. Object.assign ────────────────────────────────────────────────── */
  if (typeof Object.assign !== 'function') {
    Object.assign = function (target) {
      if (target == null) throw new TypeError('Cannot convert undefined or null to object');
      var to = Object(target);
      for (var i = 1; i < arguments.length; i++) {
        var src = arguments[i];
        if (src != null) {
          for (var key in src) {
            if (Object.prototype.hasOwnProperty.call(src, key)) to[key] = src[key];
          }
        }
      }
      return to;
    };
  }

  /* ── 3. Array.from ───────────────────────────────────────────────────── */
  if (!Array.from) {
    Array.from = function (arrayLike) {
      return Array.prototype.slice.call(arrayLike);
    };
  }

  /* ── 4. String.prototype helpers ─────────────────────────────────────── */
  if (!String.prototype.includes) {
    String.prototype.includes = function (search, start) {
      return this.indexOf(search, start || 0) !== -1;
    };
  }
  if (!String.prototype.startsWith) {
    String.prototype.startsWith = function (searchStr, position) {
      position = position || 0;
      return this.substr(position, searchStr.length) === searchStr;
    };
  }
  if (!String.prototype.endsWith) {
    String.prototype.endsWith = function (searchStr, position) {
      var subjectString = this.toString();
      if (position === undefined || position > subjectString.length) {
        position = subjectString.length;
      }
      position -= searchStr.length;
      var lastIndex = subjectString.indexOf(searchStr, position);
      return lastIndex !== -1 && lastIndex === position;
    };
  }

  /* ── 5. Element.closest ──────────────────────────────────────────────── */
  if (w.Element && !Element.prototype.closest) {
    Element.prototype.closest = function (selector) {
      var el = this;
      while (el && el.nodeType === 1) {
        if (el.matches ? el.matches(selector) : el.msMatchesSelector(selector)) return el;
        el = el.parentElement || el.parentNode;
      }
      return null;
    };
  }

  /* ── 6. NodeList.forEach ─────────────────────────────────────────────── */
  if (w.NodeList && !NodeList.prototype.forEach) {
    NodeList.prototype.forEach = Array.prototype.forEach;
  }

  /* ── 7. Promise (minimal shim — only if completely missing) ──────────── */
  if (typeof w.Promise === 'undefined') {
    w.Promise = function (executor) {
      var self = this;
      self._callbacks = [];
      self._errbacks  = [];
      self._state     = 'pending';
      self._value     = undefined;

      function resolve(value) {
        if (self._state !== 'pending') return;
        self._state = 'fulfilled';
        self._value = value;
        self._callbacks.forEach(function (cb) { setTimeout(function () { cb(value); }, 0); });
      }
      function reject(reason) {
        if (self._state !== 'pending') return;
        self._state = 'rejected';
        self._value = reason;
        self._errbacks.forEach(function (cb) { setTimeout(function () { cb(reason); }, 0); });
      }
      try { executor(resolve, reject); } catch (e) { reject(e); }
    };
    w.Promise.prototype.then = function (onFulfilled, onRejected) {
      var self = this;
      return new w.Promise(function (resolve, reject) {
        function handle(fn, v, fallback) {
          if (typeof fn === 'function') {
            try { resolve(fn(v)); } catch (e) { reject(e); }
          } else { fallback(v); }
        }
        if (self._state === 'fulfilled') { setTimeout(function () { handle(onFulfilled, self._value, resolve); }, 0); }
        else if (self._state === 'rejected') { setTimeout(function () { handle(onRejected, self._value, reject); }, 0); }
        else {
          self._callbacks.push(function (v) { handle(onFulfilled, v, resolve); });
          self._errbacks.push(function (r) { handle(onRejected, r, reject); });
        }
      });
    };
    w.Promise.prototype.catch = function (onRejected) { return this.then(null, onRejected); };
    w.Promise.resolve = function (v) { return new w.Promise(function (res) { res(v); }); };
    w.Promise.reject  = function (r) { return new w.Promise(function (_, rej) { rej(r); }); };
  }

  /* ── 8. window.fetch (basic XHR polyfill) ───────────────────────────── */
  if (!w.fetch) {
    w.fetch = function (url, options) {
      options = options || {};
      return new w.Promise(function (resolve, reject) {
        var xhr = new (w.XMLHttpRequest || w.ActiveXObject)('Microsoft.XMLHTTP');
        xhr.open(options.method || 'GET', url, true);
        var headers = options.headers || {};
        if (typeof headers === 'object') {
          Object.keys(headers).forEach(function (key) {
            xhr.setRequestHeader(key, headers[key]);
          });
        }
        xhr.onload = function () {
          var status = xhr.status;
          var response = {
            ok: status >= 200 && status < 300,
            status: status,
            statusText: xhr.statusText,
            url: url,
            text: function () { return w.Promise.resolve(xhr.responseText); },
            json: function () {
              return w.Promise.resolve(null).then(function () { return JSON.parse(xhr.responseText); });
            }
          };
          resolve(response);
        };
        xhr.onerror = function () { reject(new TypeError('Network request failed')); };
        xhr.ontimeout = function () { reject(new TypeError('Network request timed out')); };
        var body = options.body;
        if (body && typeof body.toString === 'function' && !(body instanceof w.FormData)) {
          body = body.toString();
          if (!headers['Content-Type'] && !headers['content-type']) {
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
          }
        }
        xhr.send(body || null);
      });
    };
  }

  /* ── 9. sessionStorage safe-wrapper (IE private-browsing throws) ─────── */
  try {
    w.sessionStorage.setItem('__ie_compat_test__', '1');
    w.sessionStorage.removeItem('__ie_compat_test__');
  } catch (e) {
    var _store = {};
    Object.defineProperty(w, 'sessionStorage', {
      value: {
        getItem:    function (k) { return _store.hasOwnProperty(k) ? _store[k] : null; },
        setItem:    function (k, v) { _store[k] = String(v); },
        removeItem: function (k) { delete _store[k]; },
        clear:      function () { _store = {}; },
        key:        function (i) { return Object.keys(_store)[i] || null; },
        get length() { return Object.keys(_store).length; }
      }
    });
  }

  /* ── 10. Mark IE/Edge in <html> for CSS fallback targeting ───────────── */
  var isIE = (!!w.MSInputMethodContext && !!d.documentMode);
  var isOldEdge = (w.navigator.userAgent.indexOf('Edge/') !== -1);
  if (isIE)      d.documentElement.className += ' ie11';
  if (isOldEdge) d.documentElement.className += ' edge-legacy';

}(window, document));
