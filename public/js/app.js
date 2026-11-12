// 月次報告: 画面の補助（JavaScript がなくても主な操作はできる）
(function () {
  'use strict';

  // パスワードの表示・非表示
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.toggle-password');
    if (!btn) { return; }
    var input = document.getElementById(btn.getAttribute('data-target'));
    if (!input) { return; }
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? '隠す' : '表示';
  });

  // 確認の表示（data-confirm）。「いいえ」なら何も送らず、入力も残す
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var msg = form.getAttribute('data-confirm');
    var submitter = e.submitter;
    if (submitter && submitter.getAttribute('data-confirm')) {
      msg = submitter.getAttribute('data-confirm');
    }
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
      return;
    }
    // 二重押しを防ぐ（送信の番号はそのまま。通信が切れても同じ番号で送り直せる）
    if (form.dataset.submitting === '1') {
      e.preventDefault();
      return;
    }
    form.dataset.submitting = '1';
    window.setTimeout(function () { form.dataset.submitting = '0'; }, 30000);
  });
})();
