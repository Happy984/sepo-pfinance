/*
 * Sepo Finance: the dashboard screens' behaviour.
 *
 * Forms are built from the record specification (window.SepoFinanceSpec), so
 * the drawer, the quick-add row and the server agree on every field. Requests
 * go to the REST API one at a time. Confirmations are page dialogs, and every
 * delete can be undone from the toast that follows it.
 */
(function () {
  'use strict';

  var cfg = window.SepoFinance || {};
  var SPEC = window.SepoFinanceSpec || {};
  var T = cfg.i18n || {};
  var refs = cfg.refs || { accounts: [], categories: [], counterparties: [] };
  var dark = false;

  // ------------------------------------------------------------ helpers

  function q(root, sel) { return (root || document).querySelector(sel); }
  function qa(root, sel) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function fmt(s) {
    var args = Array.prototype.slice.call(arguments, 1), i = 0;
    return String(s || '').replace(/%(\d+)\$[sd]|%[sd]/g, function (m, n) { return String(n ? args[n - 1] : args[i++]); });
  }
  /** el('div', {class: 'x', text: 'y', onclick: fn}, [children]) */
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) { return; }
      if (k === 'text') { n.textContent = v; } else if (k === 'html') { n.innerHTML = v; } else if (k.slice(0, 2) === 'on') { n.addEventListener(k.slice(2), v); } else if (v === true) { n.setAttribute(k, ''); } else { n.setAttribute(k, String(v)); }
    });
    (kids || []).forEach(function (c) { if (c !== null && c !== undefined) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
    return n;
  }
  function typeName(type) { return (T.typeNames && T.typeNames[type]) || type; }
  function decimals() { var d = parseInt(cfg.decimals, 10); return isNaN(d) ? 2 : d; }
  function number(n) {
    var d = decimals();
    try { return new Intl.NumberFormat(cfg.locale || undefined, { minimumFractionDigits: d, maximumFractionDigits: d }).format(Math.abs(n)); } catch (e) { return Math.abs(n).toFixed(d); }
  }
  function money(n, cur) { return (cur || cfg.currency || '') + ' ' + number(+n || 0); }
  function signed(n, cur) { return ((+n || 0) < -0.004 ? '-' : '') + money(n, cur); }
  /** Amounts as people type them: 1,200 · K1,200.50 · (450) · -450 · 1.200,50. Null when nothing numeric is there. */
  function parseAmount(v) {
    var s = String(v === undefined || v === null ? '' : v).trim();
    if (!s) { return null; }
    var neg = /^\(.*\)$/.test(s) || /^-/.test(s) || /-$/.test(s);
    s = s.replace(/[^\d.,]/g, '');
    if (!/\d/.test(s)) { return null; }
    var lc = s.lastIndexOf(','), ld = s.lastIndexOf('.');
    if (lc > -1 && ld > -1) { s = lc > ld ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, ''); } else { s = s.replace(/,/g, ''); }
    var n = parseFloat(s);
    if (isNaN(n)) { return null; }
    return neg ? -n : n;
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function todayDate() { var p = String(cfg.today || ymd(new Date())).split('-'); return new Date(+p[0], +p[1] - 1, +p[2], 12); }
  /** A default from the spec: "today", "+30d", "-3d", or a date. */
  function relDate(v) {
    v = String(v || '').trim();
    if (v === 'today') { return ymd(todayDate()); }
    var m = v.match(/^([+-]\d+)d$/);
    if (m) { var d = todayDate(); d.setDate(d.getDate() + parseInt(m[1], 10)); return ymd(d); }
    return /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : '';
  }
  /** A date in the site's own format (the PHP date_format setting). */
  function showDate(s) {
    if (!s || /^1970/.test(s)) { return ''; }
    var p = String(s).split('-');
    var d = new Date(+p[0], +p[1] - 1, +p[2], 12);
    if (isNaN(d.getTime())) { return s; }
    var loc = cfg.locale || undefined;
    var name = function (o) { try { return new Intl.DateTimeFormat(loc, o).format(d); } catch (e) { return ''; } };
    var f = String(cfg.dateFormat || 'Y-m-d'), out = '';
    for (var i = 0; i < f.length; i++) {
      var c = f.charAt(i);
      if (c === '\\') { out += f.charAt(++i); continue; }
      switch (c) {
        case 'd': out += pad(d.getDate()); break;
        case 'j': out += d.getDate(); break;
        case 'S': var j = d.getDate(); out += (j % 10 === 1 && j !== 11) ? 'st' : (j % 10 === 2 && j !== 12) ? 'nd' : (j % 10 === 3 && j !== 13) ? 'rd' : 'th'; break;
        case 'm': out += pad(d.getMonth() + 1); break;
        case 'n': out += d.getMonth() + 1; break;
        case 'M': out += name({ month: 'short' }); break;
        case 'F': out += name({ month: 'long' }); break;
        case 'Y': out += d.getFullYear(); break;
        case 'y': out += String(d.getFullYear()).slice(2); break;
        case 'D': out += name({ weekday: 'short' }); break;
        case 'l': out += name({ weekday: 'long' }); break;
        default: out += c;
      }
    }
    return out;
  }
  var PREFIX = { transactions: 'txn', accounts: 'acct', categories: 'cat', counterparties: 'cp', invoices: 'inv', bills: 'bill', recurring: 'rule' };
  /** A fresh id in the server's shape, so a double submit overwrites instead of duplicating. */
  function newId(type) {
    var hex = '';
    var bytes = new Uint8Array(4);
    if (window.crypto && window.crypto.getRandomValues) { window.crypto.getRandomValues(bytes); } else { for (var i = 0; i < 4; i++) { bytes[i] = Math.floor(Math.random() * 256); } }
    for (var k = 0; k < 4; k++) { hex += (bytes[k] < 16 ? '0' : '') + bytes[k].toString(16); }
    return (PREFIX[type] || 'rec') + '_' + Math.floor(Date.now() / 1000) + '_' + hex;
  }
  function specFor(type) { return SPEC[type] || []; }
  function fieldOf(type, key) { var f = specFor(type).filter(function (x) { return x.key === key; }); return f[0] || null; }
  function met(cond, values) {
    if (cond === true) { return true; }
    if (!cond) { return false; }
    var p = String(cond).split('=');
    if (p.length < 2) { return !!values[p[0]]; }
    return String(values[p[0].trim()] === undefined ? '' : values[p[0].trim()]) === p[1].trim();
  }
  function recordOf(tr) { try { return JSON.parse(tr.getAttribute('data-record') || '{}'); } catch (e) { return {}; } }

  // ------------------------------------------------------------ dark mode

  /** A declared theme marker wins; otherwise the page background decides. */
  function isDark() {
    var nodes = [document.documentElement, document.body];
    var attrs = ['data-theme', 'data-bs-theme', 'data-color-scheme', 'data-mode', 'data-color-mode'];
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (!n) { continue; }
      for (var j = 0; j < attrs.length; j++) {
        var v = (n.getAttribute(attrs[j]) || '').toLowerCase();
        if (v === 'dark') { return true; }
        if (v === 'light') { return false; }
      }
      if (/(^|\s)(dark|dark-mode|theme-dark|is-dark-theme|wp-dark-mode-active|admin-color-dark)(\s|$)/.test(n.className || '')) { return true; }
    }
    var probes = [document.documentElement, document.body, document.getElementById('wpwrap'), document.getElementById('wpcontent'), document.getElementById('wpbody-content')];
    var bg = '';
    probes.forEach(function (n) {
      if (!n) { return; }
      var c = getComputedStyle(n).backgroundColor;
      if (c && !/rgba\(\s*0,\s*0,\s*0,\s*0\s*\)|transparent/.test(c)) { bg = c; }
    });
    var m = bg.match(/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/);
    return !!m && (0.2126 * m[1] + 0.7152 * m[2] + 0.0722 * m[3]) / 255 < 0.4;
  }
  function paintMode() {
    dark = isDark();
    qa(document, '.sfin-scope').forEach(function (s) { s.classList.toggle('sfin-dark', dark); });
  }
  /** A container for overlays, outside the admin's own stacking. */
  function layer(cls) {
    var n = el('div', { 'class': 'sfin-scope ' + (cls || '') + (dark ? ' sfin-dark' : '') });
    document.body.appendChild(n);
    return n;
  }

  // ------------------------------------------------------------ requests

  var queue = Promise.resolve();
  /** One request at a time. body: a plain object (JSON) or FormData (files). */
  function request(method, path, body) {
    var run = function () {
      var headers = { 'Accept': 'application/json' };
      var payload;
      if (body instanceof FormData) { payload = body; } else if (body) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
      if (cfg.nonce) { headers['X-WP-Nonce'] = cfg.nonce; }
      return fetch(String(cfg.api || '').replace(/\/$/, '') + path, { method: method, credentials: 'same-origin', headers: headers, body: payload })
        .then(function (res) {
          return res.text().then(function (text) {
            var data = null;
            try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
            if (!data) { throw new Error(T.network); }
            if (!res.ok || data.code) {
              var err = new Error(data.message || T.failed);
              err.status = res.status;
              err.errors = (data.data && data.data.errors) || null;
              throw err;
            }
            return data;
          });
        }, function () { throw new Error(T.network); });
    };
    var p = queue.then(run, run);
    queue = p.catch(function () {});
    return p;
  }
  function busy(btn, on) {
    if (!btn) { return; }
    btn.disabled = !!on;
    if (on) { btn.setAttribute('aria-busy', 'true'); } else { btn.removeAttribute('aria-busy'); }
  }
  /** Reload, carrying a message to show once the page is back. */
  function reloadWith(msg) {
    try { if (msg) { sessionStorage.setItem('sfin-flash', msg); } } catch (e) { /* storage unavailable: the reload still happens */ }
    window.location.reload();
  }

  // ------------------------------------------------------------ toasts and dialogs

  var toastBox = null;
  function toast(msg, opts) {
    opts = opts || {};
    if (!toastBox || !document.body.contains(toastBox)) {
      toastBox = layer('sfin-toasts');
      toastBox.setAttribute('role', 'status');
      toastBox.setAttribute('aria-live', 'polite');
    }
    toastBox.classList.toggle('sfin-dark', dark);
    var t = el('div', { 'class': 'sfin-toast' + (opts.bad ? ' sfin-toast--bad' : '') }, [el('span', { text: msg })]);
    if (opts.action) {
      t.appendChild(el('button', { type: 'button', 'class': 'sfin-link', text: opts.action.label, onclick: function () { t.remove(); opts.action.run(); } }));
    }
    toastBox.appendChild(t);
    while (toastBox.children.length > 3) { toastBox.removeChild(toastBox.firstChild); }
    setTimeout(function () { t.remove(); }, opts.action ? 9000 : (opts.bad ? 7000 : 4000));
  }

  var openLayers = [];
  function trapFocus(box, e) {
    var f = qa(box, 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])').filter(function (n) { return n.offsetParent !== null; });
    if (!f.length) { return; }
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); } else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }
  /**
   * A modal: overlay plus a box. Returns {box, body, actions, close, error}.
   * opts: title, wide, drawer, onClose.
   */
  function modal(opts) {
    var wrap = layer();
    var back = document.activeElement;
    var overlay = el('div', { 'class': 'sfin-overlay' });
    var titleId = 'sfin-m-' + Math.random().toString(36).slice(2);
    var box, body, actions;
    var close = function () {
      wrap.remove();
      openLayers = openLayers.filter(function (l) { return l !== api; });
      if (back && back.focus && document.body.contains(back)) { back.focus(); }
      if (opts.onClose) { opts.onClose(); }
    };
    var x = el('button', { type: 'button', 'class': 'sfin-x', 'aria-label': T.close, html: '&times;', onclick: close });
    body = el('div', { 'class': opts.drawer ? 'sfin-drawer__body' : 'sfin-dialog__body' });
    actions = el('div', { 'class': opts.drawer ? 'sfin-drawer__foot' : 'sfin-dialog__actions' });
    if (opts.drawer) {
      box = el('div', { 'class': 'sfin-drawer', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId }, [
        el('div', { 'class': 'sfin-drawer__head' }, [el('h2', { id: titleId, text: opts.title }), x]), body, actions]);
    } else {
      box = el('div', { 'class': 'sfin-dialog' + (opts.wide ? ' sfin-dialog--wide' : ''), role: opts.alert ? 'alertdialog' : 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId }, [
        el('h2', { id: titleId, text: opts.title }), x, body, actions]);
    }
    overlay.addEventListener('click', close);
    box.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.stopPropagation(); close(); }
      if (e.key === 'Tab') { trapFocus(box, e); }
    });
    wrap.appendChild(overlay);
    wrap.appendChild(box);
    var errBox = null;
    var api = {
      box: box, body: body, actions: actions, close: close,
      error: function (msg) {
        if (errBox) { errBox.remove(); errBox = null; }
        if (msg) { errBox = el('p', { 'class': 'sfin-error', role: 'alert', text: msg }); body.insertBefore(errBox, body.firstChild); }
      }
    };
    openLayers.push(api);
    setTimeout(function () {
      var f = q(body, 'input:not([type="hidden"]):not([disabled]),select,textarea') || q(actions, '.sfin-btn--primary') || x;
      f.focus();
    }, 20);
    return api;
  }
  /** A yes/no page dialog. run() returns a promise; the dialog stays open on failure with the error shown. */
  function confirmBox(title, text, label, run, danger) {
    var m = modal({ title: title, alert: true });
    if (text) { m.body.appendChild(el('p', { text: text })); }
    var go = el('button', { type: 'button', 'class': 'sfin-btn ' + (danger ? 'sfin-btn--danger' : 'sfin-btn--primary'), text: label });
    m.actions.appendChild(el('button', { type: 'button', 'class': 'sfin-btn', text: T.cancel, onclick: m.close }));
    m.actions.appendChild(go);
    go.addEventListener('click', function () {
      busy(go, true);
      m.error('');
      Promise.resolve(run()).then(function () { m.close(); }, function (e) { busy(go, false); m.error(e.message); });
    });
    setTimeout(function () { go.focus(); }, 30);
    return m;
  }

  // ------------------------------------------------------------ reference selects

  /** The value a ref select narrows by: a literal type, or another field's value in the same form. */
  function filterValue(sel) {
    var f = sel.getAttribute('data-filter');
    if (!f) { return ''; }
    if (f === 'income' || f === 'expense') { return f; }
    var scope = sel.closest('form,[data-sfin-quick],.sfin-drawer,.sfin-dialog,tr') || document;
    var src = q(scope, '[data-field="' + f + '"]') || q(scope, '[name="' + f + '"]');
    var v = src ? src.value : '';
    return v === 'transfer' ? '' : v;
  }
  function fillRef(sel) {
    var type = sel.getAttribute('data-ref');
    var list = refs[type] || [];
    var cur = sel.getAttribute('data-value') !== null ? sel.getAttribute('data-value') : sel.value;
    sel.removeAttribute('data-value');
    var narrow = filterValue(sel);
    var refType = sel.getAttribute('data-reftype') || '';
    if (sel.getAttribute('data-placeholder') === null && sel.options.length && sel.options[0].value === '' && sel.options[0].textContent) {
      sel.setAttribute('data-placeholder', sel.options[0].textContent);
    }
    var placeholder = sel.getAttribute('data-placeholder') || T.none;
    sel.innerHTML = '';
    sel.appendChild(el('option', { value: '', text: placeholder }));
    var shown = 0;
    list.forEach(function (r) {
      if (r.id === sel.getAttribute('data-exclude')) { return; }
      // A category of the other kind is dropped even when selected: switching an entry to income clears an expense category.
      var fits = !narrow || type !== 'categories' || r.type === narrow;
      var keep = fits && (r.id === cur || !r.archived);
      if (!keep) { return; }
      var label = r.name + (r.archived ? ' (' + (T.archived || 'archived') + ')' : '');
      var o = el('option', { value: r.id, text: label });
      if (refType && r.type && r.type !== refType && type === 'counterparties') { o.textContent = label + ' · ' + r.type; }
      sel.appendChild(o);
      shown++;
    });
    if (cur && !list.some(function (r) { return r.id === cur; }) && cur !== '__new') { sel.appendChild(el('option', { value: cur, text: T.unknown })); }
    if (sel.getAttribute('data-noadd') === null) { sel.appendChild(el('option', { value: '__new', text: shown ? T.addNew : T.addFirst })); }
    sel.value = cur || '';
    if (sel.value !== (cur || '')) { sel.value = ''; }
    if (refType && type === 'counterparties' && !cur) {
      // Customers first for invoices, suppliers first for bills.
      var opts = qa(sel, 'option').filter(function (o) { var r = list.filter(function (x) { return x.id === o.value; })[0]; return r && r.type === refType; });
      opts.reverse().forEach(function (o) { sel.insertBefore(o, sel.options[1]); });
    }
  }
  function refillAll(type) { qa(document, 'select[data-ref="' + type + '"]').forEach(fillRef); }
  /** The inline panel for adding an account, category or customer without leaving the form. */
  function inlineAdd(sel) {
    var type = sel.getAttribute('data-ref');
    var prevPanel = sel.parentNode.querySelector('.sfin-inline-add');
    if (prevPanel) { prevPanel.remove(); }
    var prev = sel.getAttribute('data-prev') || '';
    sel.value = prev;
    var name = el('input', { type: 'text', 'aria-label': T.name, placeholder: T.name, maxlength: '191' });
    var kind = null;
    var spec = fieldOf(type, 'type');
    if (spec && spec.options) {
      kind = el('select', { 'aria-label': T.type });
      Object.keys(spec.options).forEach(function (k) { kind.appendChild(el('option', { value: k, text: spec.options[k] })); });
      var want = type === 'categories' ? filterValue(sel) : (sel.getAttribute('data-reftype') || '');
      if (want && spec.options[want]) { kind.value = want; }
    }
    var save = el('button', { type: 'button', 'class': 'sfin-btn sfin-btn--primary sfin-btn--sm', text: T.add });
    var cancel = el('button', { type: 'button', 'class': 'sfin-btn sfin-btn--quiet sfin-btn--sm', text: T.cancel });
    var msg = el('p', { 'class': 'sfin-field__help', text: T.refHelp });
    var panel = el('div', { 'class': 'sfin-inline-add', role: 'group', 'aria-label': fmt(T['new'], typeName(type)) }, [
      el('div', { 'class': 'sfin-inline-add__row' }, [name, kind, save, cancel]), msg]);
    sel.parentNode.appendChild(panel);
    name.focus();
    var done = function () { panel.remove(); sel.focus(); };
    cancel.addEventListener('click', done);
    var submit = function () {
      var n = name.value.trim();
      if (!n) { name.setAttribute('aria-invalid', 'true'); name.focus(); return; }
      var body = { id: newId(type), name: n };
      if (kind) { body.type = kind.value; }
      busy(save, true);
      request('POST', '/' + type, body).then(function (res) {
        if (res.refs) { refs[type] = res.refs; }
        refillAll(type);
        sel.value = res.record.id;
        sel.setAttribute('data-prev', res.record.id);
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        toast(fmt(T.added, n));
        done();
      }, function (e) { busy(save, false); msg.textContent = e.message; msg.className = 'sfin-field__error'; });
    };
    save.addEventListener('click', submit);
    panel.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); submit(); }
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done(); }
    });
  }
  document.addEventListener('focusin', function (e) {
    var s = e.target;
    if (s.matches && s.matches('select[data-ref]')) { s.setAttribute('data-prev', s.value === '__new' ? (s.getAttribute('data-prev') || '') : s.value); }
  });
  document.addEventListener('change', function (e) {
    var s = e.target;
    if (s.matches && s.matches('select[data-ref]')) {
      if (s.value === '__new') { inlineAdd(s); return; }
      s.setAttribute('data-prev', s.value);
    }
    // A change to a field another select filters by refreshes that select.
    if (s.name || s.getAttribute('data-field')) {
      var key = s.getAttribute('data-field') || s.name;
      var scope = s.closest('form,[data-sfin-quick],.sfin-drawer,.sfin-dialog') || document;
      qa(scope, 'select[data-ref][data-filter="' + key + '"]').forEach(fillRef);
    }
  });

  // ------------------------------------------------------------ the form drawer

  function input(field, value, type) {
    var id = 'sfin-f-' + field.key + '-' + Math.random().toString(36).slice(2, 7);
    var attrs = { id: id, name: field.key, 'data-field': field.key };
    var n;
    switch (field.type) {
      case 'textarea':
        n = el('textarea', Object.assign(attrs, { rows: '3' }));
        n.value = value || '';
        break;
      case 'select':
        n = el('select', attrs);
        Object.keys(field.options || {}).forEach(function (k) { n.appendChild(el('option', { value: k, text: field.options[k] })); });
        n.value = value === undefined || value === null ? '' : String(value);
        if (n.value !== String(value)) { n.value = String(field['default'] === undefined ? Object.keys(field.options || {})[0] : field['default']); }
        break;
      case 'ref':
        n = el('select', Object.assign(attrs, { 'data-ref': field.ref, 'data-filter': field.filter || null, 'data-reftype': field.refType || null, 'data-value': value || '' }));
        if (type === 'categories' && field.key === 'parent_id') { n.setAttribute('data-noadd', ''); }
        break;
      case 'bool':
        n = el('input', Object.assign(attrs, { type: 'checkbox', value: '1' }));
        n.checked = !!(+value);
        return el('label', { 'class': 'sfin-check' }, [n, ' ' + field.label]);
      case 'date':
        n = el('input', Object.assign(attrs, { type: 'date' }));
        n.value = value && !/^1970/.test(value) ? value : '';
        break;
      case 'money':
      case 'percent':
        n = el('input', Object.assign(attrs, { type: 'text', inputmode: 'decimal', 'class': 'sfin-num', autocomplete: 'off', placeholder: field.type === 'money' ? '0.00' : '0' }));
        n.value = value === undefined || value === null || value === '' ? '' : (field.type === 'money' && +value === 0 ? '' : String(value));
        break;
      case 'color':
        n = el('input', Object.assign(attrs, { type: 'text', maxlength: '7', placeholder: '#2e8540', pattern: '#[0-9A-Fa-f]{6}', style: 'max-width:9em' }));
        n.value = value || '';
        var pick = el('input', { type: 'color', 'aria-label': field.label, value: /^#[0-9a-f]{6}$/i.test(value || '') ? value : '#2e8540' });
        pick.addEventListener('input', function () { n.value = pick.value; });
        n.addEventListener('input', function () { if (/^#[0-9a-f]{6}$/i.test(n.value)) { pick.value = n.value; } });
        return el('span', { 'class': 'sfin-inline-add__row', 'data-wrap': id }, [n, pick]);
      case 'email':
        n = el('input', Object.assign(attrs, { type: 'email', autocomplete: 'off' }));
        n.value = value || '';
        break;
      default:
        n = el('input', Object.assign(attrs, { type: 'text', autocomplete: 'off', maxlength: field.max ? String(field.max) : null, placeholder: field.key === 'currency' ? cfg.currency : null }));
        n.value = value === undefined || value === null ? '' : String(value);
    }
    return n;
  }

  /** The items editor for invoices and bills. Returns {node, get(), total()} */
  function linesEditor(lines, onChange) {
    var tbody = el('tbody');
    var table = el('table', { 'class': 'sfin-lines' }, [
      el('thead', {}, [el('tr', {}, [el('th', { text: T.item }), el('th', { 'class': 'sfin-lines__qty', text: T.qty }), el('th', { 'class': 'sfin-lines__price', text: T.price }), el('th', { 'class': 'sfin-lines__total', text: T.total }), el('th', { 'class': 'sfin-lines__x' })])]),
      tbody]);
    var addBtn = el('button', { type: 'button', 'class': 'sfin-btn sfin-btn--sm', text: '+ ' + T.addItem });
    var row = function (l) {
      var d = el('input', { type: 'text', 'aria-label': T.item, value: l.description || '' });
      var qn = el('input', { type: 'text', inputmode: 'decimal', 'class': 'sfin-num', 'aria-label': T.qty, value: l.qty === undefined ? '1' : String(l.qty) });
      var p = el('input', { type: 'text', inputmode: 'decimal', 'class': 'sfin-num', 'aria-label': T.price, value: l.price === undefined || l.price === '' ? '' : String(l.price) });
      var tot = el('td', { 'class': 'sfin-lines__total' });
      var x = el('button', { type: 'button', 'class': 'sfin-x', 'aria-label': T.removeItem, html: '&times;' });
      var tr = el('tr', {}, [el('td', {}, [d]), el('td', { 'class': 'sfin-lines__qty' }, [qn]), el('td', { 'class': 'sfin-lines__price' }, [p]), tot, el('td', { 'class': 'sfin-lines__x' }, [x])]);
      tr._get = function () { return { description: d.value.trim(), qty: parseAmount(qn.value) === null ? 1 : parseAmount(qn.value), price: parseAmount(p.value) || 0 }; };
      tr._paint = function () { var g = tr._get(); tot.textContent = number(g.qty * g.price); };
      [d, qn, p].forEach(function (i) { i.addEventListener('input', function () { tr._paint(); onChange(); }); });
      x.addEventListener('click', function () { var nx = tr.nextElementSibling || tr.previousElementSibling; tr.remove(); onChange(); if (nx) { q(nx, 'input').focus(); } else { addBtn.focus(); } });
      tr._paint();
      tbody.appendChild(tr);
      return d;
    };
    (lines || []).forEach(row);
    addBtn.addEventListener('click', function () { row({}).focus(); onChange(); });
    var get = function () { return qa(tbody, 'tr').map(function (tr) { return tr._get(); }).filter(function (l) { return l.description || Math.abs(l.price) >= 0.005; }); };
    return { node: el('div', {}, [table, addBtn]), get: get };
  }

  /**
   * The full form for any record type, in a drawer.
   * opts: copy (bool), defaults (object), onSaved(record)
   */
  function openForm(type, record, opts) {
    opts = opts || {};
    var editing = !!(record && record.id) && !opts.copy;
    var values = {};
    specFor(type).forEach(function (f) {
      var v = record ? record[f.key] : undefined;
      if (v === undefined && opts.defaults && opts.defaults[f.key] !== undefined) { v = opts.defaults[f.key]; }
      if (v === undefined) { v = f['default']; }
      if (f.type === 'date' && v !== undefined) { v = relDate(v) || v; }
      values[f.key] = v;
    });
    if (opts.copy && type === 'transactions') { values.date = ymd(todayDate()); values.status = 'cleared'; }
    var m = modal({ title: editing ? fmt(T.editing, typeName(type)) : fmt(T['new'], typeName(type)), drawer: true });
    var form = el('form', { 'class': 'sfin-form-grid', novalidate: true });
    m.body.appendChild(form);
    var wraps = {};
    var lines = null;
    var sumBox = null;
    var isDoc = type === 'invoices' || type === 'bills';

    var refreshDoc = function () {
      if (!isDoc || !lines) { return; }
      var items = lines.get();
      var amt = q(form, '[data-field="amount"]');
      var rate = parseAmount((q(form, '[data-field="tax_rate"]') || {}).value) || 0;
      if (items.length) {
        var sub = items.reduce(function (s, l) { return s + l.qty * l.price; }, 0);
        sub = Math.round(sub * 100) / 100;
        var tax = Math.round(sub * rate) / 100;
        amt.value = (sub + tax).toFixed(2);
        amt.readOnly = true;
        sumBox.hidden = false;
        sumBox.innerHTML = '';
        [[T.subtotal, sub], [T.tax + ' ' + rate + '%', tax], [T.total, sub + tax]].forEach(function (r) { sumBox.appendChild(el('span', { text: r[0] })); sumBox.appendChild(el('b', { text: money(r[1], (q(form, '[data-field="currency"]') || {}).value || cfg.currency) })); });
      } else {
        amt.readOnly = false;
        sumBox.hidden = true;
      }
    };

    specFor(type).forEach(function (f) {
      var wrap = el('div', { 'class': 'sfin-field' + (['textarea', 'lines'].indexOf(f.type) > -1 || f.key === 'description' || f.key === 'name' ? ' sfin-span' : '') });
      var req = f.required === true;
      if (f.type === 'bool') {
        wrap.appendChild(input(f, values[f.key], type));
      } else if (f.type === 'lines') {
        wrap.appendChild(el('span', { 'class': 'sfin-field__label', text: f.label }));
        lines = linesEditor(values.lines || [], refreshDoc);
        wrap.appendChild(lines.node);
        sumBox = el('div', { 'class': 'sfin-lines__sum', hidden: true });
        wrap.appendChild(sumBox);
      } else {
        var n = input(f, values[f.key], type);
        var inputEl = n.matches('input,select,textarea') ? n : q(n, 'input');
        var lab = el('label', { 'class': 'sfin-field__label', 'for': inputEl.id }, [f.label]);
        if (req) { lab.appendChild(el('span', { 'class': 'sfin-req', 'aria-hidden': 'true', text: ' *' })); inputEl.setAttribute('aria-required', 'true'); }
        wrap.appendChild(lab);
        wrap.appendChild(n);
      }
      if (f.help) {
        var hid = 'sfin-h-' + f.key + '-' + Math.random().toString(36).slice(2, 6);
        wrap.appendChild(el('p', { 'class': 'sfin-field__help', id: hid, text: f.help }));
        var i2 = q(wrap, 'input,select,textarea');
        if (i2) { i2.setAttribute('aria-describedby', hid); }
      }
      wraps[f.key] = wrap;
      form.appendChild(wrap);
    });
    qa(form, 'select[data-ref]').forEach(fillRef);
    if (type === 'categories' && editing) { var par = q(form, '[data-field="parent_id"]'); if (par) { par.setAttribute('data-exclude', record.id); fillRef(par); } }

    var current = function () {
      var v = {};
      specFor(type).forEach(function (f) {
        if (f.type === 'lines') { v[f.key] = lines ? lines.get() : []; return; }
        var n = q(form, '[data-field="' + f.key + '"]');
        if (!n) { return; }
        v[f.key] = f.type === 'bool' ? (n.checked ? 1 : 0) : n.value;
      });
      return v;
    };
    var showHide = function () {
      var v = current();
      specFor(type).forEach(function (f) {
        var hide = (f.showWhen && !met(f.showWhen, v)) || (f.hideWhen && met(f.hideWhen, v));
        wraps[f.key].hidden = !!hide;
      });
    };
    form.addEventListener('change', showHide);
    form.addEventListener('input', function (e) { if (isDoc && e.target.getAttribute('data-field') === 'tax_rate') { refreshDoc(); } });
    showHide();
    refreshDoc();

    var id = editing ? record.id : newId(type);
    var clearErrors = function () {
      qa(form, '.sfin-field__error').forEach(function (n) { n.remove(); });
      qa(form, '[aria-invalid]').forEach(function (n) { n.removeAttribute('aria-invalid'); });
      m.error('');
    };
    var save = function (again, btn) {
      clearErrors();
      var body = current();
      body.id = id;
      if (type === 'transactions') {
        var a = parseAmount(body.amount);
        if (a !== null && a < 0 && body.type !== 'transfer') { body.type = 'expense'; body.amount = String(Math.abs(a)); }
      }
      if (body.amount === '' && isDoc && lines && lines.get().length) { body.amount = '0'; }
      busy(btn, true);
      request('POST', '/' + type, body).then(function (res) {
        if (res.refs) { refs[type] = res.refs; refillAll(type); }
        busy(btn, false);
        if (opts.onSaved) { opts.onSaved(res.record, again); }
        if (again) {
          id = newId(type);
          ['amount', 'description', 'reference', 'notes'].forEach(function (k) { var n = q(form, '[data-field="' + k + '"]'); if (n) { n.value = ''; } });
          toast(T.saved);
          var a2 = q(form, '[data-field="amount"]');
          if (a2) { a2.focus(); }
        } else {
          m.close();
          if (!opts.onSaved) { reloadWith(T.saved); }
        }
      }, function (e) {
        busy(btn, false);
        if (e.errors) {
          var first = null;
          Object.keys(e.errors).forEach(function (k) {
            var w = wraps[k];
            if (!w) { return; }
            w.hidden = false;
            var n = q(w, 'input,select,textarea');
            var eid = 'sfin-e-' + k + '-' + Math.random().toString(36).slice(2, 6);
            w.appendChild(el('p', { 'class': 'sfin-field__error', id: eid, text: e.errors[k] }));
            if (n) {
              n.setAttribute('aria-invalid', 'true');
              n.setAttribute('aria-describedby', ((n.getAttribute('aria-describedby') || '') + ' ' + eid).trim());
              if (!first) { first = n; }
            }
          });
          m.error(Object.keys(e.errors).length > 1 ? T.checkFields : '');
          if (first) { first.focus(); }
        } else {
          m.error(e.message);
        }
      });
    };
    m.actions.appendChild(el('button', { type: 'button', 'class': 'sfin-btn sfin-btn--quiet', text: T.cancel, onclick: m.close }));
    if (!editing && type === 'transactions') {
      var again = el('button', { type: 'button', 'class': 'sfin-btn', text: T.saveNew });
      again.addEventListener('click', function () { save(true, again); });
      m.actions.appendChild(again);
    }
    var ok = el('button', { type: 'button', 'class': 'sfin-btn sfin-btn--primary', text: T.save });
    ok.addEventListener('click', function () { save(false, ok); });
    m.actions.appendChild(ok);
    form.addEventListener('submit', function (e) { e.preventDefault(); save(false, ok); });
    form.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(false, ok); }
      if (e.key === 'Enter' && e.target.tagName === 'INPUT' && !e.target.closest('.sfin-lines,.sfin-inline-add')) { e.preventDefault(); save(false, ok); }
    });
    return m;
  }

  // ------------------------------------------------------------ ledger rows

  function nameOf(type, id) { var r = (refs[type] || []).filter(function (x) { return x.id === id; })[0]; return r ? r.name : (id ? T.unknown : ''); }
  var PILL = { reconciled: 'good', cleared: '', pending: 'warn' };
  var DOCPILL = { paid: 'good', sent: 'info', draft: '', cancelled: 'muted' };
  function ledgerRow(t) {
    var type = t.type;
    var sign = type === 'income' ? '+' : (type === 'expense' ? '−' : '');
    var account = nameOf('accounts', t.account_id) + (type === 'transfer' ? ' → ' + nameOf('accounts', t.to_account_id) : '');
    var files = (t.attachments || []).length;
    var desc = el('td', { 'data-cell': 'description' }, [t.description || '']);
    if (t.reference) { desc.appendChild(document.createTextNode(' ')); desc.appendChild(el('span', { 'class': 'sfin-sub', text: t.reference })); }
    if (files) { desc.appendChild(document.createTextNode(' ')); desc.appendChild(el('span', { 'class': 'sfin-clip', html: clipSvg() + files })); }
    var act = function (a, label, cls) { return el('button', { type: 'button', 'class': 'sfin-link' + (cls ? ' ' + cls : ''), 'data-act': a, text: label }); };
    var tr = el('tr', { 'data-type': 'transactions', 'data-id': t.id, 'data-record': JSON.stringify(t), 'class': t.status === 'pending' ? 'sfin-row--pending' : '' }, [
      el('td', { 'data-cell': 'date', 'class': 'sfin-nowrap', text: showDate(t.date) }),
      desc,
      el('td', { 'data-cell': 'counterparty_id', text: t.counterparty_id ? nameOf('counterparties', t.counterparty_id) : '' }),
      el('td', { 'data-cell': 'category_id', text: type === 'transfer' ? '—' : (t.category_id ? nameOf('categories', t.category_id) : '') }),
      el('td', { 'data-cell': 'account_id', text: account }),
      el('td', { 'data-cell': 'status' }, [el('span', { 'class': 'sfin-pill sfin-pill--' + (PILL[t.status] || ''), text: T[t.status] || t.status })]),
      el('td', { 'data-cell': 'amount', 'class': 'sfin-num ' + (type === 'income' ? 'sfin-in' : (type === 'expense' ? 'sfin-out' : '')), text: sign + money(t.amount, t.currency) }),
      el('td', { 'class': 'sfin-actions' }, [act('edit', T.edit), act('files', T.files), act('copy', T.copy), act('delete', T['delete'], 'sfin-link--danger')])
    ]);
    return tr;
  }
  function clipSvg() { return '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21.4 11.1l-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/></svg>'; }
  function flash(tr) { tr.classList.add('sfin-row--flash'); setTimeout(function () { tr.classList.remove('sfin-row--flash'); }, 1500); }
  /** Put a saved entry into the ledger table: replace its row, or add it at the top. */
  function placeRow(t) {
    var tbody = q(document, '.sfin-ledger tbody');
    if (!tbody) { return false; }
    var row = ledgerRow(t);
    var old = q(tbody, 'tr[data-id="' + t.id + '"]');
    if (old) { tbody.replaceChild(row, old); } else { tbody.insertBefore(row, tbody.firstChild); }
    var empty = q(document, '[data-sfin-empty]');
    if (empty) { empty.hidden = true; }
    flash(row);
    return true;
  }
  function onLedger() { return !!q(document, '.sfin-ledger'); }
  /** Bring the ledger's totals line up to date after a change. */
  function refreshSummary() {
    var n = q(document, '[data-sfin-summary]');
    if (!n) { return; }
    var f = {};
    try { f = JSON.parse(n.getAttribute('data-sfin-summary') || '{}'); } catch (e) { f = {}; }
    var qs = Object.keys(f).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(f[k]); }).join('&');
    request('GET', '/transactions?limit=2000' + (qs ? '&' + qs : '')).then(function (res) {
      var s = res.sums || {};
      n.textContent = fmt(s.count === 1 ? T.ledgerSummary1 : T.ledgerSummary, money(s.income), money(s.expenses), signed(s.net), s.count || 0, showDate(f.from), showDate(f.to));
    }, function () { /* the line stays as it was */ });
  }
  function savedHandler(type) {
    if (type === 'transactions' && onLedger()) {
      return function (rec) { placeRow(rec); refreshSummary(); toast(T.saved); };
    }
    return null;
  }

  // ------------------------------------------------------------ the quick-add row

  function quick(box) {
    var field = function (k) { return q(box, '[data-field="' + k + '"]'); };
    var typeSel = field('type');
    var cat = field('category_id');
    var to = field('to_account_id');
    var addBtn = q(box, '[data-sfin-add]');
    var syncType = function () {
      var tr = typeSel.value === 'transfer';
      if (cat) { cat.hidden = tr; cat.disabled = tr; }
      if (to) { to.hidden = !tr; to.disabled = !tr; }
    };
    typeSel.addEventListener('change', syncType);
    syncType();
    var amount = field('amount');
    amount.addEventListener('input', function () {
      var s = amount.value.trim();
      if (/^[-(]/.test(s) && typeSel.value !== 'expense' && typeSel.value !== 'transfer') { typeSel.value = 'expense'; typeSel.dispatchEvent(new Event('change', { bubbles: true })); }
    });
    var submit = function () {
      qa(box, '[aria-invalid]').forEach(function (n) { n.removeAttribute('aria-invalid'); });
      var body = { id: newId('transactions') };
      qa(box, '[data-field]').forEach(function (n) { if (!n.disabled) { body[n.getAttribute('data-field')] = n.value; } });
      if (body.category_id === '__new') { body.category_id = ''; }
      var a = parseAmount(body.amount);
      if (a !== null && a < 0) { body.amount = String(Math.abs(a)); if (body.type !== 'transfer') { body.type = 'expense'; } }
      busy(addBtn, true);
      request('POST', '/transactions', body).then(function (res) {
        busy(addBtn, false);
        placeRow(res.record);
        refreshSummary();
        // Keep the context; clear what changes line to line.
        ['amount', 'description'].forEach(function (k) { var n = field(k); if (n) { n.value = ''; } });
        var who = field('counterparty_id');
        if (who) { who.value = ''; }
        field('date').value = res.record.date;
        toast(T.saved);
        amount.focus();
      }, function (e) {
        busy(addBtn, false);
        var first = null;
        Object.keys(e.errors || {}).forEach(function (k) { var n = field(k); if (n) { n.setAttribute('aria-invalid', 'true'); first = first || n; } });
        toast(e.errors ? Object.keys(e.errors).map(function (k) { return e.errors[k]; }).join(' ') : e.message, { bad: true });
        if (first) { first.focus(); }
      });
    };
    addBtn.addEventListener('click', submit);
    box.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.target.closest('.sfin-inline-add') && e.target.tagName !== 'BUTTON') { e.preventDefault(); submit(); }
    });
  }

  // ------------------------------------------------------------ row actions

  function removeRow(tr, type, id) {
    var restore = function () {
      request('POST', '/' + type + '/' + encodeURIComponent(id) + '/restore').then(function (res) {
        if (type === 'transactions' && onLedger()) { placeRow(res.record); refreshSummary(); toast(T.restored); } else { reloadWith(T.restored); }
      }, function (e) { toast(e.message, { bad: true }); });
    };
    return request('DELETE', '/' + type + '/' + encodeURIComponent(id)).then(function () {
      if (tr) { tr.remove(); }
      if (type === 'transactions') { refreshSummary(); }
      toast(fmt(T.deleted, typeName(type)), { action: { label: T.undo, run: restore } });
    });
  }

  function settleDialog(tr, type) {
    var doc = recordOf(tr);
    var owed = parseFloat(tr.getAttribute('data-owed')) || 0;
    var isInv = type === 'invoices';
    var m = modal({ title: fmt(isInv ? T.receivePayment : T.makePayment, doc.number) });
    m.body.appendChild(el('p', { 'class': 'sfin-muted', text: fmt(T.owed, money(owed, doc.currency)) }));
    var f = function (label, node, help) {
      var id = 'sfin-s-' + Math.random().toString(36).slice(2, 7);
      node.id = id;
      return el('div', { 'class': 'sfin-field' }, [el('label', { 'class': 'sfin-field__label', 'for': id, text: label }), node, help ? el('p', { 'class': 'sfin-field__help', text: help }) : null]);
    };
    var amount = el('input', { type: 'text', inputmode: 'decimal', 'class': 'sfin-num', value: owed.toFixed(2) });
    var date = el('input', { type: 'date', value: ymd(todayDate()) });
    var acct = el('select', { 'data-ref': 'accounts', 'data-placeholder': '—' });
    var method = el('select', {});
    var mf = fieldOf('transactions', 'method');
    Object.keys((mf && mf.options) || {}).forEach(function (k) { method.appendChild(el('option', { value: k, text: mf.options[k] })); });
    var cat = el('select', { 'data-ref': 'categories', 'data-filter': isInv ? 'income' : 'expense' });
    var grid = el('div', { 'class': 'sfin-form-grid' }, [f(T.amount, amount, T.amountHelp), f(T.date, date), f(T.account, acct, T.accountHelp), f(T.method, method), f(T.category, cat)]);
    m.body.appendChild(grid);
    fillRef(acct);
    fillRef(cat);
    var live = (refs.accounts || []).filter(function (a) { return !a.archived; });
    if (live.length === 1) { acct.value = live[0].id; }
    var go = el('button', { type: 'button', 'class': 'sfin-btn sfin-btn--primary', text: T.recordPayment });
    m.actions.appendChild(el('button', { type: 'button', 'class': 'sfin-btn', text: T.cancel, onclick: m.close }));
    m.actions.appendChild(go);
    var submit = function () {
      m.error('');
      busy(go, true);
      request('POST', '/' + type + '/' + encodeURIComponent(doc.id) + '/settle', { amount: amount.value, date: date.value, account_id: acct.value === '__new' ? '' : acct.value, method: method.value, category_id: cat.value === '__new' ? '' : cat.value })
        .then(function (res) { m.close(); reloadWith(res.outstanding > 0.004 ? fmt(T.leftOwed, money(res.outstanding, doc.currency)) : T.paidFull); }, function (e) { busy(go, false); m.error(e.message); });
    };
    go.addEventListener('click', submit);
    m.body.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT' && !e.target.closest('.sfin-inline-add')) { e.preventDefault(); submit(); } });
    setTimeout(function () { amount.focus(); amount.select(); }, 30);
  }

  function filesDialog(tr) {
    var t = recordOf(tr);
    var m = modal({ title: T.filesTitle });
    var list = el('ul', { 'class': 'sfin-files' });
    var empty = el('p', { 'class': 'sfin-muted', text: T.noFiles });
    var status = el('p', { 'class': 'sfin-sub', role: 'status', 'aria-live': 'polite' });
    var fileIn = el('input', { type: 'file', multiple: true, accept: 'image/*,.pdf,.doc,.docx,.odt,.xls,.xlsx,.csv', 'class': 'screen-reader-text', id: 'sfin-up-' + t.id });
    var upBtn = el('label', { 'class': 'sfin-btn', 'for': fileIn.id, text: T.upload, tabindex: '0', role: 'button' });
    var mediaBtn = (window.wp && window.wp.media) ? el('button', { type: 'button', 'class': 'sfin-btn', text: T.fromMedia }) : null;
    var zone = el('div', { 'class': 'sfin-dropzone' }, [el('div', { 'class': 'sfin-inline-add__row', style: 'justify-content:center' }, [upBtn, mediaBtn]), fileIn, status]);
    m.body.appendChild(list);
    m.body.appendChild(empty);
    m.body.appendChild(zone);
    m.actions.appendChild(el('button', { type: 'button', 'class': 'sfin-btn sfin-btn--primary', text: T.close, onclick: m.close }));
    var path = '/transactions/' + encodeURIComponent(t.id) + '/attachments';
    var paint = function (files) {
      list.innerHTML = '';
      empty.hidden = files.length > 0;
      files.forEach(function (f, i) {
        var rm = el('button', { type: 'button', 'class': 'sfin-link sfin-link--danger', text: T.removeFile });
        var li = el('li', {}, [
          f.image ? el('img', { src: f.url, alt: '' }) : null,
          el('a', { href: f.url, target: '_blank', rel: 'noopener', text: f.name }),
          el('span', { 'class': 'sfin-tag', text: f.kind === 'media' ? T.media : T['private'] }),
          el('span', { 'class': 'sfin-sub', text: f.size }), rm]);
        rm.addEventListener('click', function () {
          // Asked in place: a second click on the same button confirms.
          if (!rm.hasAttribute('data-armed')) { rm.setAttribute('data-armed', ''); rm.textContent = T.removeFile + '?'; status.textContent = T.removeFileQ; return; }
          busy(rm, true);
          request('DELETE', path + '/' + i).then(function (res) { status.textContent = ''; done(res.attachments); }, function (e) { busy(rm, false); m.error(e.message); });
        });
        list.appendChild(li);
      });
    };
    var done = function (files) {
      paint(files);
      t.attachments = files.map(function () { return {}; });
      tr.setAttribute('data-record', JSON.stringify(Object.assign(recordOf(tr), { attachments: t.attachments })));
      var d = q(tr, '[data-cell="description"]');
      var clip = d && q(d, '.sfin-clip');
      if (clip) { clip.remove(); }
      if (d && files.length) { d.appendChild(el('span', { 'class': 'sfin-clip', html: clipSvg() + files.length })); }
    };
    status.textContent = T.loading;
    request('GET', path).then(function (res) { status.textContent = ''; paint(res.attachments); }, function (e) { status.textContent = ''; m.error(e.message); });
    var upload = function (fl) {
      if (!fl || !fl.length) { return; }
      var fd = new FormData();
      Array.prototype.forEach.call(fl, function (f) { fd.append('files[]', f, f.name); });
      status.textContent = T.loading;
      m.error('');
      request('POST', path, fd).then(function (res) { status.textContent = ''; done(res.attachments); }, function (e) { status.textContent = ''; m.error(e.message); });
    };
    fileIn.addEventListener('change', function () { upload(fileIn.files); fileIn.value = ''; });
    upBtn.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileIn.click(); } });
    ['dragover', 'dragenter'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function () { zone.classList.remove('is-over'); }); });
    zone.addEventListener('drop', function (e) { e.preventDefault(); upload(e.dataTransfer.files); });
    if (mediaBtn) {
      mediaBtn.addEventListener('click', function () {
        var frame = window.wp.media({ title: T.mediaTitle, button: { text: T.mediaButton }, multiple: false });
        frame.on('select', function () {
          var a = frame.state().get('selection').first();
          if (!a) { return; }
          status.textContent = T.loading;
          request('POST', path, { media_id: a.get('id') }).then(function (res) { status.textContent = ''; done(res.attachments); }, function (e) { status.textContent = ''; m.error(e.message); });
        });
        frame.open();
      });
    }
  }

  function statementDialog(tr) {
    var cp = recordOf(tr);
    var m = modal({ title: T.statement + ': ' + cp.name, wide: true });
    m.actions.appendChild(el('button', { type: 'button', 'class': 'sfin-btn sfin-btn--primary', text: T.close, onclick: m.close }));
    var status = el('p', { 'class': 'sfin-muted', text: T.loading });
    m.body.appendChild(status);
    request('GET', '/counterparties/' + encodeURIComponent(cp.id) + '/statement').then(function (s) {
      status.remove();
      var tile = function (label, v, mod) { return el('div', { 'class': 'sfin-tile' + (mod ? ' sfin-tile--' + mod : '') }, [el('b', { text: money(v) }), el('span', { text: label })]); };
      m.body.appendChild(el('div', { 'class': 'sfin-tiles' }, [tile(T.owedToUs, s.owed_to_us, s.owed_to_us > 0 ? 'live' : ''), tile(T.owedByUs, s.owed_by_us, s.owed_by_us > 0 ? 'warn' : ''), tile(T.received, s.income), tile(T.paidOut, s.expenses)]));
      if (s.documents.length) {
        var dt = el('tbody');
        s.documents.forEach(function (d) {
          var owed = Math.max(0, (+d.amount || 0) - (+d.paid_amount || 0));
          dt.appendChild(el('tr', {}, [el('td', { text: d.number }), el('td', { text: (T.docTypes && T.docTypes[d._type]) || d._type }), el('td', { 'class': 'sfin-nowrap', text: showDate(d.date_issued) }), el('td', {}, [el('span', { 'class': 'sfin-pill sfin-pill--' + (DOCPILL[d.status] || ''), text: (d._type === 'bills' && d.status === 'sent' ? T.billReceived : T[d.status]) || d.status })]), el('td', { 'class': 'sfin-num', text: money(d.amount, d.currency) }), el('td', { 'class': 'sfin-num', text: d.status === 'cancelled' ? '—' : money(owed, d.currency) })]));
        });
        m.body.appendChild(el('h3', { text: T.documents }));
        m.body.appendChild(el('div', { 'class': 'sfin-tablewrap' }, [el('table', { 'class': 'sfin-table' }, [el('thead', {}, [el('tr', {}, [el('th', { text: '#' }), el('th', { text: T.type }), el('th', { text: T.date }), el('th', { text: '' }), el('th', { 'class': 'sfin-num', text: T.total }), el('th', { 'class': 'sfin-num', text: T.stillOwed })])]), dt])]));
      }
      m.body.appendChild(el('h3', { text: T.entries }));
      if (!s.transactions.length) { m.body.appendChild(el('p', { 'class': 'sfin-muted', text: T.noEntries })); return; }
      var tb = el('tbody');
      s.transactions.slice(0, 200).forEach(function (t) {
        var sign = t.type === 'income' ? '+' : (t.type === 'expense' ? '−' : '');
        tb.appendChild(el('tr', { 'class': t.status === 'pending' ? 'sfin-row--pending' : '' }, [el('td', { 'class': 'sfin-nowrap', text: showDate(t.date) }), el('td', { text: t.description || t.reference || '' }), el('td', { text: nameOf('categories', t.category_id) }), el('td', { 'class': 'sfin-num ' + (t.type === 'income' ? 'sfin-in' : 'sfin-out'), text: sign + money(t.amount, t.currency) })]));
      });
      m.body.appendChild(el('div', { 'class': 'sfin-tablewrap' }, [el('table', { 'class': 'sfin-table' }, [el('thead', {}, [el('tr', {}, [el('th', { text: T.date }), el('th', { text: T.description }), el('th', { text: T.category }), el('th', { 'class': 'sfin-num', text: T.amount })])]), tb])]));
    }, function (e) { status.remove(); m.error(e.message); });
  }

  document.addEventListener('click', function (e) {
    var nb = e.target.closest('[data-sfin-new]');
    if (nb) {
      e.preventDefault();
      var defaults = {};
      try { defaults = JSON.parse(nb.getAttribute('data-defaults') || '{}'); } catch (x) { defaults = {}; }
      var type = nb.getAttribute('data-sfin-new');
      openForm(type, null, { defaults: defaults, onSaved: savedHandler(type) });
      return;
    }
    if (e.target.closest('[data-sfin-print]')) { window.print(); return; }
    var b = e.target.closest('[data-act]');
    if (!b) { return; }
    var tr = b.closest('tr[data-type]');
    if (!tr) { return; }
    var type = tr.getAttribute('data-type');
    var id = tr.getAttribute('data-id');
    var rec = recordOf(tr);
    switch (b.getAttribute('data-act')) {
      case 'edit': openForm(type, rec, { onSaved: savedHandler(type) }); break;
      case 'copy': openForm(type, rec, { copy: true, onSaved: savedHandler(type) }); break;
      case 'files': filesDialog(tr); break;
      case 'statement': statementDialog(tr); break;
      case 'settle': settleDialog(tr, type); break;
      case 'delete':
        confirmBox(fmt(T.deleteTitle, typeName(type)), type === 'transactions' ? T.deleteEntry : T.deleteRecord, T['delete'], function () { return removeRow(tr, type, id); }, true);
        break;
      case 'email':
        var to = tr.getAttribute('data-email');
        confirmBox(T.emailTitle, fmt(T.emailText, to || '—'), T.send, function () {
          return request('POST', '/invoices/' + encodeURIComponent(id) + '/email').then(function (res) { reloadWith(res.message); });
        });
        break;
      case 'post':
        confirmBox(T.postTitle, fmt(T.postText, money(rec.t_amount), showDate(rec.next_due)), T.post, function () {
          return request('POST', '/recurring/' + encodeURIComponent(id) + '/post').then(function (res) { reloadWith(fmt(T.posted, showDate(res.rule && res.rule.next_due))); });
        });
        break;
      case 'skip':
        confirmBox(T.skipTitle, fmt(T.skipText, showDate(rec.next_due)), T.skip, function () {
          return request('POST', '/recurring/' + encodeURIComponent(id) + '/skip').then(function () { reloadWith(T.skipped); });
        });
        break;
    }
  });

  // ------------------------------------------------------------ reconcile

  function reconcile(box) {
    var cur = box.getAttribute('data-currency');
    var stmt = parseFloat(box.getAttribute('data-statement')) || 0;
    var tickedEl = q(box, '[data-recon-ticked]');
    var diffEl = q(box, '[data-recon-diff]');
    var tile = q(box, '[data-recon-tile]');
    var label = q(box, '[data-recon-label]');
    var total = parseAmount(tickedEl.textContent) || 0;
    if (/^\s*-/.test(tickedEl.textContent)) { total = -Math.abs(total); }
    var paint = function () {
      var diff = Math.round((total - stmt) * 100) / 100;
      tickedEl.innerHTML = '';
      tickedEl.appendChild(el('span', { 'class': total < -0.004 ? 'sfin-neg' : '', text: signed(total, cur) }));
      diffEl.innerHTML = '';
      diffEl.appendChild(el('span', { text: signed(diff, cur) }));
      var ok = Math.abs(diff) < 0.01;
      tile.classList.toggle('sfin-tile--good', ok);
      tile.classList.toggle('sfin-tile--alert', !ok);
      label.textContent = ok ? T.balanced : (diff > 0 ? T.booksHigher : T.stmtHigher);
    };
    box.addEventListener('change', function (e) {
      var cb = e.target;
      if (!cb.matches('[data-tick]')) { return; }
      var tr = cb.closest('tr');
      var eff = parseFloat(tr.getAttribute('data-effect')) || 0;
      var on = cb.checked;
      total = Math.round((total + (on ? eff : -eff)) * 100) / 100;
      paint();
      cb.disabled = true;
      request('POST', '/reconcile/tick', { id: tr.getAttribute('data-id'), on: on }).then(function () { cb.disabled = false; }, function (err) {
        cb.disabled = false;
        cb.checked = !on;
        total = Math.round((total - (on ? eff : -eff)) * 100) / 100;
        paint();
        toast(err.message, { bad: true });
      });
    });
    var fin = q(box, '[data-recon-finish]');
    if (fin) {
      fin.addEventListener('click', function () {
        busy(fin, true);
        request('POST', '/reconcile/finish', { account_id: box.getAttribute('data-account'), date: box.getAttribute('data-date'), balance: stmt }).then(function (res) {
          var r = res.reconciliation || {};
          reloadWith(r.status === 'balanced' ? T.finishedOk : fmt(T.finishedDiff, signed(r.difference, cur)));
        }, function (err) { busy(fin, false); toast(err.message, { bad: true }); });
      });
    }
  }

  // ------------------------------------------------------------ import

  function importer(box) {
    var state = { rows: [], header: false, account: '', items: [] };
    var step = function (n) {
      qa(box, '[data-step]').forEach(function (s) { s.hidden = +s.getAttribute('data-step') !== n; });
      qa(box, '[data-step-marker]').forEach(function (li) {
        var k = +li.getAttribute('data-step-marker');
        li.classList.toggle('is-on', k === n);
        li.classList.toggle('is-done', k < n);
        if (k === n) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); }
      });
      var s = q(box, '[data-step="' + n + '"]');
      var h = s && q(s, 'h3,select,input,button');
      if (h) { if (h.tagName === 'H3') { h.setAttribute('tabindex', '-1'); } h.focus(); }
    };
    var form = q(box, '[data-import-read]');
    var mapBox = q(box, '[data-import-map]');
    var sample = q(box, '[data-import-sample]');
    var MAP = [['date', T.date], ['description', T.description], ['reference', T.reference], ['amount', T.amountBoth], ['credit', T.moneyIn], ['debit', T.moneyOut]];
    var GUESS = {
      date: /date|posted|value d|time/i,
      description: /desc|narr|detail|particular|memo|payee|remark|name/i,
      reference: /ref|cheque|check|receipt|transaction id|txn id|trans id|receipt no/i,
      amount: /^amount$|^amt$|amount \(|^value$/i,
      credit: /credit|deposit|paid in|money in|^in$|^cr$/i,
      debit: /debit|withdraw|paid out|money out|^out$|^dr$/i
    };
    var colName = function (i) {
      var h = state.header && state.rows[0] ? state.rows[0][i] : '';
      return h ? h : fmt(T.column, i + 1);
    };
    var buildMap = function () {
      mapBox.innerHTML = '';
      var width = state.rows.reduce(function (w, r) { return Math.max(w, r.length); }, 0);
      var used = {};
      MAP.forEach(function (m) {
        var sel = el('select', { 'data-map': m[0], id: 'sfin-map-' + m[0] });
        sel.appendChild(el('option', { value: '-1', text: T.notUsed }));
        for (var i = 0; i < width; i++) { sel.appendChild(el('option', { value: String(i), text: colName(i) })); }
        var guess = -1;
        if (state.header && state.rows[0]) {
          state.rows[0].forEach(function (h, i) { if (guess < 0 && !used[i] && GUESS[m[0]].test(String(h).trim())) { guess = i; } });
        } else if (state.rows[0]) {
          if (m[0] === 'date') { state.rows[0].forEach(function (c, i) { if (guess < 0 && /\d{1,4}[\/.\-]\d{1,2}[\/.\-]\d{1,4}/.test(c)) { guess = i; } }); }
          if (m[0] === 'amount') { for (var j = state.rows[0].length - 1; j >= 0; j--) { if (!used[j] && /^\(?-?[\d.,\s]+\)?$/.test(state.rows[0][j]) && /\d/.test(state.rows[0][j])) { guess = j; break; } } }
          if (m[0] === 'description') { state.rows[0].forEach(function (c, i) { if (guess < 0 && !used[i] && /[a-z]{3}/i.test(c)) { guess = i; } }); }
        }
        if (guess >= 0) { used[guess] = true; }
        sel.value = String(guess);
        mapBox.appendChild(el('div', { 'class': 'sfin-field' }, [el('label', { 'class': 'sfin-field__label', 'for': sel.id, text: m[1] }), sel]));
      });
      // With separate in and out columns, the single amount column is not needed.
      var am = q(mapBox, '[data-map="amount"]'), cr = q(mapBox, '[data-map="credit"]'), db = q(mapBox, '[data-map="debit"]');
      if (am.value !== '-1' && (cr.value !== '-1' || db.value !== '-1')) { cr.value = '-1'; db.value = '-1'; }
      q(box, '[data-map-opt="header"]').checked = state.header;
      paintSample();
    };
    var paintSample = function () {
      sample.innerHTML = '';
      var width = state.rows.reduce(function (w, r) { return Math.max(w, r.length); }, 0);
      var head = el('tr', {});
      for (var i = 0; i < width; i++) { head.appendChild(el('th', { scope: 'col', text: colName(i) })); }
      var tb = el('tbody');
      state.rows.slice(state.header ? 1 : 0, (state.header ? 1 : 0) + 5).forEach(function (r) {
        var tr = el('tr', {});
        for (var i = 0; i < width; i++) { tr.appendChild(el('td', { text: r[i] || '' })); }
        tb.appendChild(tr);
      });
      sample.appendChild(el('table', { 'class': 'sfin-table sfin-import-sample' }, [el('thead', {}, [head]), tb]));
    };
    q(box, '[data-map-opt="header"]').addEventListener('change', function (e) { state.header = e.target.checked; buildMap(); });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var file = q(form, 'input[type="file"]');
      if (!file.files.length) { file.setAttribute('aria-invalid', 'true'); file.focus(); return; }
      state.account = q(form, '[name="account_id"]').value;
      var fd = new FormData();
      fd.append('file', file.files[0], file.files[0].name);
      var btn = q(form, 'button[type="submit"]');
      busy(btn, true);
      toast(T.readFile);
      request('POST', '/import/read', fd).then(function (res) {
        busy(btn, false);
        state.rows = res.rows;
        state.header = !!res.header;
        buildMap();
        toast(fmt(T.rowsFound, state.rows.length - (state.header ? 1 : 0)));
        step(2);
      }, function (err) { busy(btn, false); toast(err.message, { bad: true }); });
    });
    qa(box, '[data-import-back]').forEach(function (b) { b.addEventListener('click', function () { step(+b.getAttribute('data-import-back')); }); });

    var rowsBox = q(box, '[data-import-rows]');
    var saveBtn = q(box, '[data-import-save]');
    var countTicked = function () {
      var n = qa(rowsBox, 'input[data-keep]:checked').length;
      saveBtn.textContent = fmt(T.importN, n);
      saveBtn.disabled = n === 0;
    };
    q(box, '[data-import-preview]').addEventListener('click', function () {
      var map = {};
      qa(mapBox, '[data-map]').forEach(function (s) { map[s.getAttribute('data-map')] = parseInt(s.value, 10); });
      qa(box, '[data-map-opt]').forEach(function (c) { map[c.getAttribute('data-map-opt')] = c.checked; });
      var btn = this;
      busy(btn, true);
      request('POST', '/import/preview', { account_id: state.account, rows: state.rows, map: map }).then(function (res) {
        busy(btn, false);
        state.items = res.items;
        var counts = q(box, '[data-import-counts]');
        counts.innerHTML = '';
        [['ok', 'good'], ['duplicate', 'warn'], ['error', 'alert']].forEach(function (c) {
          counts.appendChild(el('div', { 'class': 'sfin-tile' + (res.counts[c[0]] ? ' sfin-tile--' + c[1] : '') }, [el('b', { text: String(res.counts[c[0]] || 0) }), el('span', { text: T[c[0]] })]));
        });
        rowsBox.innerHTML = '';
        var tb = el('tbody');
        res.items.forEach(function (it, i) {
          var cbId = 'sfin-imp-' + i;
          var cb = el('input', { type: 'checkbox', id: cbId, 'data-keep': String(i) });
          cb.checked = it.status === 'ok';
          cb.disabled = it.status === 'error';
          var cat = el('select', { 'data-ref': 'categories', 'data-filter': it.type, 'data-row-cat': String(i), 'aria-label': T.category });
          var pillCls = it.status === 'ok' ? 'good' : (it.status === 'duplicate' ? 'warn' : 'bad');
          tb.appendChild(el('tr', { 'class': 'sfin-import-row sfin-import-row--' + it.status }, [
            el('td', {}, [cb]),
            el('td', { 'class': 'sfin-nowrap' }, [el('label', { 'for': cbId, text: it.date ? showDate(it.date) : '—' })]),
            el('td', { text: it.description }),
            el('td', { 'class': 'sfin-sub', text: it.reference }),
            el('td', { 'class': 'sfin-num ' + (it.type === 'income' ? 'sfin-in' : 'sfin-out'), text: it.status === 'error' && !it.amount ? '—' : (it.type === 'income' ? '+' : '−') + money(it.amount) }),
            el('td', {}, [el('span', { 'class': 'sfin-pill sfin-pill--' + pillCls, text: T[it.status] }), it.note ? el('div', { 'class': 'sfin-sub', text: it.note }) : null]),
            el('td', {}, [it.status === 'error' ? null : cat])
          ]));
        });
        rowsBox.appendChild(el('table', { 'class': 'sfin-table' }, [el('thead', {}, [el('tr', {}, [el('th', {}, [el('span', { 'class': 'screen-reader-text', text: T.add })]), el('th', { text: T.date }), el('th', { text: T.description }), el('th', { text: T.reference }), el('th', { 'class': 'sfin-num', text: T.amount }), el('th', { text: T.check }), el('th', { text: T.category })])]), tb]));
        qa(box, 'select[data-ref]').forEach(fillRef);
        countTicked();
        step(3);
      }, function (err) { busy(btn, false); toast(err.message, { bad: true }); });
    });
    rowsBox.addEventListener('change', function (e) {
      if (e.target.matches('[data-keep]')) { countTicked(); }
      if (e.target.matches('[data-row-cat]')) { e.target.setAttribute('data-touched', ''); }
    });
    qa(box, '[data-import-cat]').forEach(function (s) {
      s.addEventListener('change', function () {
        if (s.value === '__new') { return; }
        var kind = s.getAttribute('data-import-cat');
        state.items.forEach(function (it, i) {
          var rs = q(rowsBox, '[data-row-cat="' + i + '"]');
          if (rs && it.type === kind && !rs.hasAttribute('data-touched')) { rs.value = s.value; }
        });
      });
    });
    saveBtn.addEventListener('click', function () {
      var items = [];
      var first = '';
      qa(rowsBox, 'input[data-keep]:checked').forEach(function (cb) {
        var i = +cb.getAttribute('data-keep');
        var it = state.items[i];
        var cs = q(rowsBox, '[data-row-cat="' + i + '"]');
        items.push({ date: it.date, type: it.type, amount: it.amount, description: it.description, reference: it.reference, category_id: cs && cs.value !== '__new' ? cs.value : '' });
        if (!first || it.date < first) { first = it.date; }
      });
      if (!items.length) { return; }
      busy(saveBtn, true);
      request('POST', '/import/save', { account_id: state.account, items: items }).then(function (res) {
        busy(saveBtn, false);
        var out = q(box, '[data-import-done]');
        out.innerHTML = '';
        out.appendChild(el('p', { 'class': 'sfin-note sfin-note--good', role: 'status', text: fmt(T.imported, res.added) }));
        if (res.failed && res.failed.length) {
          out.appendChild(el('p', { text: fmt(T.importFailed, res.failed.length) }));
          out.appendChild(el('ul', {}, res.failed.map(function (f) { return el('li', { text: '#' + f.row + ': ' + f.message }); })));
        }
        var link = q(box, '[data-import-ledger]');
        if (link && cfg.pages && cfg.pages.ledger) { link.href = cfg.pages.ledger + '&account=' + encodeURIComponent(state.account) + (first ? '&from=' + first : ''); }
        step(4);
      }, function (err) { busy(saveBtn, false); toast(err.message, { bad: true }); });
    });
  }

  // ------------------------------------------------------------ settings: the logo

  function logoPicker(box) {
    var idIn = q(box, '[data-logo-id]'), img = q(box, '[data-logo-img]'), pick = q(box, '[data-logo-pick]'), clear = q(box, '[data-logo-clear]');
    if (!window.wp || !window.wp.media) { pick.hidden = true; return; }
    pick.addEventListener('click', function () {
      var frame = window.wp.media({ title: pick.textContent, library: { type: 'image' }, multiple: false });
      frame.on('select', function () {
        var a = frame.state().get('selection').first().toJSON();
        idIn.value = a.id;
        img.src = (a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url);
        img.hidden = false;
        clear.hidden = false;
      });
      frame.open();
    });
    clear.addEventListener('click', function () { idIn.value = '0'; img.hidden = true; img.removeAttribute('src'); clear.hidden = true; pick.focus(); });
  }

  // ------------------------------------------------------------ start

  function typing(t) { return t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)); }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && openLayers.length) { openLayers[openLayers.length - 1].close(); return; }
    if (openLayers.length || typing(e.target) || e.ctrlKey || e.metaKey || e.altKey) { return; }
    if (!q(document, '[data-sfin-root]')) { return; }
    if (e.key === 'n') {
      var nb = q(document, '[data-sfin-root] [data-sfin-new]');
      if (onLedger()) { e.preventDefault(); openForm('transactions', null, { onSaved: savedHandler('transactions') }); } else if (nb) { e.preventDefault(); nb.click(); }
    } else if (e.key === '/') {
      var s = q(document, '[data-sfin-search]');
      if (s) { e.preventDefault(); s.focus(); s.select(); }
    }
  });

  /** Give each cell its column name, for the one-card-per-row layout on phones. */
  function labelCells(root) {
    qa(root, 'table[data-sfin-table]').forEach(function (t) {
      var heads = qa(t, 'thead th').map(function (th) { return th.textContent.trim(); });
      if (!heads.length) { return; }
      qa(t, 'tbody tr').forEach(function (tr) {
        Array.prototype.forEach.call(tr.children, function (td, i) { if (heads[i] && !td.hasAttribute('data-label')) { td.setAttribute('data-label', heads[i]); } });
      });
    });
  }

  function start() {
    paintMode();
    labelCells(document);
    qa(document, '[data-sfin-root] select[data-ref]').forEach(fillRef);
    qa(document, '[data-sfin-quick]').forEach(quick);
    qa(document, '[data-recon]').forEach(reconcile);
    qa(document, '[data-sfin-import]').forEach(importer);
    qa(document, '[data-sfin-logo]').forEach(logoPicker);
    try {
      var msg = sessionStorage.getItem('sfin-flash');
      if (msg) { sessionStorage.removeItem('sfin-flash'); toast(msg); }
    } catch (e) { /* storage unavailable */ }
    var mo = new MutationObserver(paintMode);
    var opts = { attributes: true, attributeFilter: ['class', 'data-theme', 'data-bs-theme', 'data-color-scheme', 'data-mode', 'data-color-mode', 'style'] };
    mo.observe(document.documentElement, opts);
    if (document.body) { mo.observe(document.body, opts); }
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      if (mq.addEventListener) { mq.addEventListener('change', function () { setTimeout(paintMode, 50); }); }
    }
  }

  window.SepoFinanceUI = { paintMode: paintMode, openForm: openForm };

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
