// 月次報告: 画面の補助（JavaScript がなくても主な操作はできる）
(function () {
  'use strict';

  function randomHex(bytes) {
    var a = new Uint8Array(bytes);
    window.crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  // ---- パスワードの表示・非表示 ----
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.toggle-password');
    if (!btn) { return; }
    var input = document.getElementById(btn.getAttribute('data-target'));
    if (!input) { return; }
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? '隠す' : '表示';
  });

  // ---- 客先名らしい言葉の注意（共通編 §9.3。保存は止めない） ----
  var form = document.getElementById('report-form');
  var words = [];
  try {
    var src = (form && form.getAttribute('data-words')) || document.body.getAttribute('data-words');
    words = src ? JSON.parse(src) : [];
  } catch (err) { words = []; }
  if (!words.length) {
    words = ['株式会社', '(株)', '㈱', '有限会社', '合同会社', '様', '御中', 'inc.', 'co., ltd.', 'corporation', 'ltd.'];
  }
  function normalize(s) {
    s = s.replace(/[！-～]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0xFEE0); });
    return s.replace(/　/g, ' ').toLowerCase();
  }
  function hasWarning(text) {
    var n = normalize(text || '');
    for (var i = 0; i < words.length; i++) {
      if (words[i] && n.indexOf(normalize(words[i])) !== -1) { return true; }
    }
    return false;
  }
  function updateWarning(el) {
    var box = el.closest('.field') || el.closest('form');
    if (!box) { return; }
    var warn = box.querySelector('.field-warning');
    if (!warn) { return; }
    var any = false;
    box.querySelectorAll('[data-free-text]').forEach(function (x) { if (hasWarning(x.value)) { any = true; } });
    warn.hidden = !any;
  }
  document.addEventListener('input', function (e) {
    if (e.target.matches && e.target.matches('[data-free-text]')) { updateWarning(e.target); }
  });

  // ---- 表示の条件（show_when）: 同じ案件ブロック・同じ欄の中で切り替える ----
  function applyShowWhen(scope) {
    scope.querySelectorAll('.field[data-show-field]').forEach(function (field) {
      var code = field.getAttribute('data-show-field');
      var values = (field.getAttribute('data-show-values') || '').split(',');
      var src = scope.querySelector('.field[data-code="' + code + '"]');
      var current = '';
      if (src) {
        var checked = src.querySelector('input[type=radio]:checked');
        var sel = src.querySelector('select');
        var cb = src.querySelector('input[type=checkbox]');
        current = checked ? checked.value : (sel ? sel.value : (cb && cb.checked ? '1' : ''));
      }
      var show = values.indexOf(current) !== -1;
      field.hidden = !show;
      field.querySelectorAll('input, select, textarea').forEach(function (x) { x.disabled = !show; });
    });
  }
  document.querySelectorAll('fieldset.block').forEach(applyShowWhen);
  document.addEventListener('change', function (e) {
    var block = e.target.closest && e.target.closest('fieldset.block');
    if (block) { applyShowWhen(block); }
  });

  // ---- 確認の表示（data-confirm）と二重押しの防止 ----
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var submitter = e.submitter;
    var msg = (submitter && submitter.getAttribute('data-confirm')) || f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    if (submitter && submitter.getAttribute('data-warn-check')) {
      var warned = f.querySelectorAll('.field-warning:not([hidden])').length;
      if (warned && !window.confirm('客先名や個人名の注意が出ている欄が ' + warned + ' か所あります。このまま送りますか？')) {
        e.preventDefault(); return;
      }
    }
    f.querySelectorAll('input[type=checkbox][data-confirm-on]').forEach(function (cb) {
      if (cb.checked && !cb.defaultChecked && !window.confirm(cb.getAttribute('data-confirm-on') + '。よろしいですか？')) {
        e.preventDefault();
      }
    });
    if (e.defaultPrevented) { return; }
    if (f.dataset.submitting === '1') { e.preventDefault(); return; }
    f.dataset.submitting = '1';
    window.setTimeout(function () { f.dataset.submitting = '0'; }, 30000);
  });

  // ---- 印刷用画面（G-10） ----
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('.print-button')) { window.print(); }
  });

  // ---- 横並び表示: 自由記述のセルを開く（G-09） ----
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('.open-cell');
    if (!btn || !window.fetch) { return; }
    var u = new URL(window.location.href);
    u.search = '?cell=' + encodeURIComponent(btn.getAttribute('data-cell'));
    fetch(u.toString(), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var span = btn.nextElementSibling;
        span.textContent = j.ok ? j.text : '表示できません';
        btn.hidden = true;
      })
      .catch(function () { btn.textContent = '通信エラー'; });
  });

  // ---- 下書きの自動保存（入力が変わったときだけ、60 秒ごと） ----
  if (form && form.getAttribute('data-autosave') === '1' && window.fetch && window.FormData) {
    var dirty = false;
    var status = form.querySelector('.autosave-status');
    form.addEventListener('input', function () { dirty = true; });
    form.addEventListener('change', function () { dirty = true; });
    form.addEventListener('submit', function () { dirty = false; });
    window.setInterval(function () {
      if (!dirty) { return; }
      dirty = false;
      var data = new FormData(form);
      data.set('action', 'autosave');
      data.set('request_key', randomHex(16));
      var ctrl = window.AbortController ? new AbortController() : null;
      var timer = window.setTimeout(function () { if (ctrl) { ctrl.abort(); } }, 30000);
      fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (status) { status.textContent = j.ok ? ('自動保存しました ' + j.saved_at) : '自動保存できませんでした（入力を確かめてください）'; }
          if (!j.ok) { dirty = true; }
        })
        .catch(function () {
          dirty = true;
          if (status) { status.textContent = '通信が切れたため自動保存できませんでした。入力は残っています'; }
        })
        .then(function () { window.clearTimeout(timer); });
    }, 60000);
  }
})();
