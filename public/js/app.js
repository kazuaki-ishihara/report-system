// 月次報告: 画面の補助（JavaScript がなくても主な操作はできる）
(function () {
  'use strict';

  // ---- 文言（課長が画面で変えられる。layout.php が body の data-msgs で渡す。G17_messages.md） ----
  var displayTextByDefaultText = {};
  try {
    displayTextByDefaultText = JSON.parse(document.body.getAttribute('data-msgs') || '{}');
  } catch (parseError) { displayTextByDefaultText = {}; }
  /** 既定の文言から画面に出す文言を返す。placeholderValues の {name} に値を入れる */
  /**
   * 文（メッセージ）を要素に入れる。改行を含むときは改行して表示する（課長がメッセージの管理で文に改行を入れたとき。
   * 改行を含まないときは、その要素の今までの表示のまま）
   */
  function setMessageText(element, text) {
    element.textContent = text;
    element.style.whiteSpace = String(text).indexOf('\n') >= 0 ? 'pre-line' : '';
  }

  function M(defaultText, placeholderValues) {
    var displayText = displayTextByDefaultText[defaultText] || defaultText;
    Object.keys(placeholderValues || {}).forEach(function (placeholderName) {
      displayText = displayText.split('{' + placeholderName + '}').join(String(placeholderValues[placeholderName]));
    });
    return displayText;
  }

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

  // ---- 自分の色（G-16 §4.6）: 選んでいる色を見本と色見本にすぐ出し、読みにくい組み合わせを知らせる ----
  var customColorFieldset = document.getElementById('custom-colors');
  if (customColorFieldset) {
    var baseColorsByName = {light: {card: '#ffffff', text: '#1f2328'}, dark: {card: '#161b22', text: '#e6edf3'}};
    var relativeLuminance = function (hexColor) {
      return [1, 3, 5].map(function (offset) {
        var channelRatio = parseInt(hexColor.substr(offset, 2), 16) / 255;
        return channelRatio <= 0.03928 ? channelRatio / 12.92 : Math.pow((channelRatio + 0.055) / 1.055, 2.4);
      }).reduce(function (sum, channelValue, channelIndex) { return sum + channelValue * [0.2126, 0.7152, 0.0722][channelIndex]; }, 0);
    };
    var contrastRatio = function (firstHex, secondHex) {
      var firstLuminance = relativeLuminance(firstHex), secondLuminance = relativeLuminance(secondHex);
      return (Math.max(firstLuminance, secondLuminance) + 0.05) / (Math.min(firstLuminance, secondLuminance) + 0.05);
    };
    var textOn = function (hexColor) { return contrastRatio(hexColor, '#ffffff') >= contrastRatio(hexColor, '#000000') ? '#ffffff' : '#000000'; };
    var applyCustomColorPreview = function () {
      var checkedBase = customColorFieldset.querySelector('input[name="custom_base"]:checked');
      var baseName = checkedBase ? checkedBase.value : 'light';
      var baseColors = baseColorsByName[baseName];
      var accentColor = document.getElementById('custom_accent').value;
      var backgroundColor = document.getElementById('custom_background').value;
      var previewBox = customColorFieldset.querySelector('.custom-color-preview');
      previewBox.style.background = backgroundColor;
      var previewCard = previewBox.querySelector('.custom-color-preview-card');
      previewCard.style.background = baseColors.card;
      previewCard.style.color = baseColors.text;
      previewBox.querySelector('.custom-color-preview-heading').style.color = accentColor;
      previewBox.querySelector('a').style.color = accentColor;
      var previewButton = previewBox.querySelector('.custom-color-preview-button');
      previewButton.style.background = accentColor;
      previewButton.style.color = textOn(accentColor);
      var customSwatchSpans = document.querySelectorAll('.theme-swatch-custom span');
      if (customSwatchSpans.length === 3) {
        customSwatchSpans[0].style.background = backgroundColor;
        customSwatchSpans[1].style.background = baseColors.card;
        customSwatchSpans[2].style.background = accentColor;
      }
      var problemTextList = [];
      if (contrastRatio(backgroundColor, baseColors.text) < 4.5) { problemTextList.push(M('背景の色と文字の色が近く、読みにくくなります')); }
      if (contrastRatio(accentColor, baseColors.card) < 3) { problemTextList.push(M('強調の色と面の色が近く、見出しやリンクが見えにくくなります')); }
      if (contrastRatio(accentColor, textOn(accentColor)) < 4.5) { problemTextList.push(M('ボタンの文字が読みにくくなります')); }
      var checkLabel = customColorFieldset.querySelector('.custom-color-check');
      setMessageText(checkLabel, problemTextList.length ? problemTextList.join('。') + M('（保存はできますが、読みにくくなります）') : M('読みやすい組み合わせです'));
      checkLabel.classList.toggle('warn', problemTextList.length > 0);
    };
    customColorFieldset.addEventListener('input', applyCustomColorPreview);
    customColorFieldset.addEventListener('change', applyCustomColorPreview);
    customColorFieldset.addEventListener('click', function (clickEvent) {
      // 自分の色を触ったら、テーマの「自分の色」を選んだことにする
      var customThemeRadio = document.querySelector('input[name="ui_theme"][value="custom"]');
      if (customThemeRadio && clickEvent.target.closest('input')) { customThemeRadio.checked = true; }
    });
    applyCustomColorPreview();
  }

  // ---- メンテナンス中（便利機能 F）: 保存などのボタンを止める（入力は残る。終わってから保存できる） ----
  if (document.body.getAttribute('data-maintenance') === '1') {
    Array.prototype.forEach.call(document.querySelectorAll('form[method="post"], form[method="POST"]'), function (postForm) {
      if (postForm.action && /logout\.php/.test(postForm.action)) { return; }
      Array.prototype.forEach.call(postForm.querySelectorAll('button[type="submit"], button:not([type])'), function (submitButton) {
        submitButton.disabled = true;
        submitButton.title = M('メンテナンス中のため、保存などの操作はできません');
      });
    });
  }

  // ---- 要望・依頼の返信の定型文（便利機能 B）: 選ぶと返信の欄に足す ----
  Array.prototype.forEach.call(document.querySelectorAll('.support-reply-template-select'), function (templateSelect) {
    templateSelect.addEventListener('change', function () {
      var replyArea = document.getElementById(templateSelect.getAttribute('data-target'));
      if (!replyArea || templateSelect.value === '') { return; }
      replyArea.value = replyArea.value.trim() === '' ? templateSelect.value : replyArea.value.replace(/\s+$/, '') + '\n' + templateSelect.value;
      replyArea.dispatchEvent(new Event('input', {bubbles: true}));
      templateSelect.value = '';
      replyArea.focus();
    });
  });

  // ---- パスワードを自動で作る・コピーする（APP-022。乱数はブラウザーの暗号用の乱数） ----
  var passwordAlphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789-_!#%';
  function generatePassword(passwordLength) {
    var randomValues = new Uint32Array(passwordLength);
    window.crypto.getRandomValues(randomValues);
    return Array.prototype.map.call(randomValues, function (randomValue) { return passwordAlphabet.charAt(randomValue % passwordAlphabet.length); }).join('');
  }
  function showCopyResult(button, resultText) {
    var resultLabel = button.parentNode.querySelector('.copy-result');
    if (resultLabel) { setMessageText(resultLabel, resultText); }
  }
  document.addEventListener('click', function (e) {
    var generateButton = e.target.closest('.generate-password');
    if (generateButton) {
      var generatedPassword = generatePassword(16);
      // 新しいパスワードの欄は、控えられるよう表示する。確認の欄は値を入れるだけで、隠したままにする（画面をのぞかれても 2 つ並んで見えないように）
      var newPasswordTargetId = generateButton.getAttribute('data-target');
      [newPasswordTargetId, generateButton.getAttribute('data-confirm-target')].forEach(function (targetId) {
        var targetInput = targetId ? document.getElementById(targetId) : null;
        if (targetInput) {
          targetInput.value = generatedPassword;
          targetInput.type = targetId === newPasswordTargetId ? 'text' : 'password';
          targetInput.dispatchEvent(new Event('input', {bubbles: true}));
        }
      });
      var toggleButton = document.querySelector('.toggle-password[data-target="' + generateButton.getAttribute('data-target') + '"]');
      if (toggleButton) { toggleButton.textContent = '隠す'; }
      showCopyResult(generateButton, M('16 文字のパスワードを作りました。控えるときは「コピー」を押してください。'));
      return;
    }
    var copyButton = e.target.closest('.copy-text');
    if (!copyButton) { return; }
    var copySource = document.getElementById(copyButton.getAttribute('data-copy-from'));
    if (!copySource) { return; }
    var copyValue;
    if (copySource.value !== undefined) {
      copyValue = copySource.value;
    } else if (copySource.querySelector('li')) {
      // 一覧（予備のコードなど）は 1 つ 1 行にしてコピーする
      copyValue = Array.prototype.map.call(copySource.querySelectorAll('li'), function (listItem) { return listItem.textContent.trim(); }).join('\n');
    } else {
      copyValue = copySource.textContent.trim();
    }
    if (!copyValue) {
      showCopyResult(copyButton, M('コピーする文字がありません'));
      return;
    }
    var onCopied = function () { showCopyResult(copyButton, M('コピーしました')); };
    // クリップボードの API が使えないとき（http の画面・古いブラウザー）は、選んでコピーの命令を使う。
    // iPhone の Safari は textarea の select() では選べないので、範囲（Range）で選ぶ
    var copyByCommand = function () {
      var copyHolder = document.createElement('span');
      copyHolder.textContent = copyValue;
      copyHolder.style.whiteSpace = 'pre';
      copyHolder.style.position = 'fixed';
      copyHolder.style.top = '0';
      copyHolder.style.left = '0';
      copyHolder.style.opacity = '0';
      copyHolder.style.userSelect = 'text';
      copyHolder.style.webkitUserSelect = 'text';
      document.body.appendChild(copyHolder);
      var pageSelection = window.getSelection();
      var copyRange = document.createRange();
      copyRange.selectNodeContents(copyHolder);
      pageSelection.removeAllRanges();
      pageSelection.addRange(copyRange);
      var isCopied = false;
      try { isCopied = document.execCommand('copy'); } catch (copyError) { isCopied = false; }
      pageSelection.removeAllRanges();
      document.body.removeChild(copyHolder);
      showCopyResult(copyButton, isCopied ? M('コピーしました') : M('コピーできませんでした。選んでコピーしてください'));
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(copyValue).then(onCopied, copyByCommand);
    } else {
      copyByCommand();
    }
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

  // ---- 保存していない入力があるまま画面を離れるときの確認（data-leave-warn="1" のフォーム） ----
  // data-unsaved="1" は、開いた時点で保存していない内容がある（写した直後・入力エラーで戻ったとき）
  var unsaved = false;
  var leaving = false;
  var editSeq = 0;
  function markEdited() { unsaved = true; editSeq++; }
  document.querySelectorAll('form[data-leave-warn="1"]').forEach(function (f) {
    if (f.getAttribute('data-unsaved') === '1') { unsaved = true; }
    f.addEventListener('input', markEdited);
    f.addEventListener('change', markEdited);
  });
  window.addEventListener('beforeunload', function (e) {
    if (unsaved && !leaving) {
      e.preventDefault();
      e.returnValue = '';
    }
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

  // ---- 案件ブロックの内容を「案件を追加するとき」の欄に写す（G-04 §4.5。案件は選び直す） ----
  // 写す先の欄の名前は b[<元の番号>]… を b[<新しい欄の番号>]… に読み替える。表（繰り返し行）は、写す先にある行の分だけ写す
  var newBlockFieldset = document.querySelector('fieldset.block-new');
  document.querySelectorAll('button.duplicate-block').forEach(function (duplicateButton) {
    if (!newBlockFieldset) { return; }
    duplicateButton.hidden = false;
    duplicateButton.addEventListener('click', function () {
      var sourceBlockIndex = duplicateButton.getAttribute('data-source-block-index');
      var sourceFieldset = duplicateButton.closest('fieldset.block');
      var newProjectSelect = newBlockFieldset.querySelector('select[name$="[project]"]');
      var newBlockIndex = newProjectSelect.name.replace(/^b\[(\d+)\].*$/, '$1');
      var sourceNamePrefix = 'b[' + sourceBlockIndex + '][f]';
      sourceFieldset.querySelectorAll('input, select, textarea').forEach(function (sourceInput) {
        if (!sourceInput.name || sourceInput.name.indexOf(sourceNamePrefix) !== 0) { return; }
        var targetName = 'b[' + newBlockIndex + '][f]' + sourceInput.name.slice(sourceNamePrefix.length);
        newBlockFieldset.querySelectorAll('[name="' + targetName.replace(/"/g, '\\"') + '"]').forEach(function (targetInput) {
          if (sourceInput.type === 'radio' || sourceInput.type === 'checkbox') {
            if (targetInput.value === sourceInput.value) { targetInput.checked = sourceInput.checked; }
          } else if (targetInput.type !== 'hidden' || sourceInput.type === 'hidden') {
            targetInput.value = sourceInput.value;
          }
        });
      });
      newProjectSelect.value = '';
      newBlockFieldset.querySelectorAll('input, select, textarea').forEach(function (targetInput) {
        targetInput.dispatchEvent(new Event('input', { bubbles: true }));
      });
      newBlockFieldset.dispatchEvent(new Event('change', { bubbles: true }));
      applyShowWhen(newBlockFieldset);
      markEdited();
      var sourceProjectSelect = sourceFieldset.querySelector('select[name$="[project]"]');
      var sourceProjectCode = sourceProjectSelect && sourceProjectSelect.selectedIndex > 0
        ? sourceProjectSelect.options[sourceProjectSelect.selectedIndex].text : M('（案件なし）');
      var duplicateNote = newBlockFieldset.querySelector('.duplicate-note');
      if (!duplicateNote) {
        duplicateNote = document.createElement('p');
        duplicateNote.className = 'note duplicate-note';
        duplicateNote.setAttribute('role', 'status');
        newProjectSelect.insertAdjacentElement('afterend', duplicateNote);
      }
      setMessageText(duplicateNote, M('「{code}」の内容を写しました。追加する案件を選んでください（保存するまで反映されません）', { code: sourceProjectCode }));
      newBlockFieldset.scrollIntoView({ block: 'start' });
      newProjectSelect.focus();
    });
  });

  // ---- 項目の編集（G-12）: 入力部品に合う設定だけを出す・選択肢の行を足す・表示の条件 ----
  var fieldTypeSelect = document.getElementById('field_type');
  if (fieldTypeSelect) {
    var fieldEditForm = fieldTypeSelect.closest('form');
    var applyFieldTypeSections = function () {
      var selectedFieldType = fieldTypeSelect.value;
      fieldEditForm.querySelectorAll('[data-field-types]').forEach(function (typeSection) {
        typeSection.hidden = typeSection.getAttribute('data-field-types').split(' ').indexOf(selectedFieldType) === -1;
      });
      var noSettingNote = fieldEditForm.querySelector('.no-setting-note');
      if (noSettingNote) {
        var limitSectionList = noSettingNote.parentNode.querySelectorAll('[data-field-types]');
        noSettingNote.hidden = Array.prototype.some.call(limitSectionList, function (limitSection) { return !limitSection.hidden; });
      }
    };
    fieldTypeSelect.addEventListener('change', applyFieldTypeSections);
    applyFieldTypeSections();

    var addOptionRowButton = fieldEditForm.querySelector('.add-option-row');
    if (addOptionRowButton) {
      addOptionRowButton.hidden = false;
      addOptionRowButton.addEventListener('click', function () {
        var optionTableBody = addOptionRowButton.parentNode.querySelector('tbody');
        var lastOptionRow = optionTableBody.querySelector('tr:last-child');
        var newOptionIndex = optionTableBody.querySelectorAll('tr').length;
        while (fieldEditForm.querySelector('[name="opt_label[' + newOptionIndex + ']"]')) { newOptionIndex++; }
        var newOptionRow = document.createElement('tr');
        newOptionRow.innerHTML = '<td><input type="text" maxlength="60"></td>'
          + '<td><input type="text" maxlength="40" pattern="[a-z0-9_]{1,40}" placeholder="自動"></td><td><input type="checkbox" value="1" checked></td><td></td>';
        var newOptionInputs = newOptionRow.querySelectorAll('input');
        var newOptionLabelInput = newOptionInputs[0];
        newOptionLabelInput.name = 'opt_label[' + newOptionIndex + ']';
        newOptionInputs[1].name = 'opt_code[' + newOptionIndex + ']';
        newOptionInputs[2].name = 'opt_active[' + newOptionIndex + ']';
        lastOptionRow.insertAdjacentElement('afterend', newOptionRow);
        newOptionLabelInput.focus();
        markEdited();
      });
    }

    var showWhenFieldSelect = document.getElementById('show_when_field');
    if (showWhenFieldSelect) {
      var applyShowWhenEditor = function () {
        var checkedScopeRadio = fieldEditForm.querySelector('input[name="field_scope"]:checked');
        var currentScope = fieldTypeSelect.value === 'project' ? 'project' : (checkedScopeRadio ? checkedScopeRadio.value : '');
        Array.prototype.forEach.call(showWhenFieldSelect.options, function (candidateOption) {
          if (candidateOption.value === '') { return; }
          candidateOption.hidden = candidateOption.getAttribute('data-scope') !== currentScope;
          candidateOption.disabled = candidateOption.hidden;
        });
        if (showWhenFieldSelect.selectedOptions.length && showWhenFieldSelect.selectedOptions[0].disabled) {
          showWhenFieldSelect.value = '';
        }
        fieldEditForm.querySelectorAll('.show-when-values').forEach(function (valueGroup) {
          var isSelectedGroup = valueGroup.getAttribute('data-field-code') === showWhenFieldSelect.value;
          valueGroup.hidden = !isSelectedGroup;
          valueGroup.querySelectorAll('input').forEach(function (valueCheckbox) { valueCheckbox.disabled = !isSelectedGroup; });
        });
      };
      showWhenFieldSelect.addEventListener('change', applyShowWhenEditor);
      fieldEditForm.querySelectorAll('input[name="field_scope"]').forEach(function (scopeRadio) {
        scopeRadio.addEventListener('change', applyShowWhenEditor);
      });
      fieldTypeSelect.addEventListener('change', applyShowWhenEditor);
      applyShowWhenEditor();
    }
  }

  // ---- ページの中のタブ（.tabbed-sections の中の section[data-tab-title]。G-21） ----
  // JavaScript が動くときだけ、節ごとのタブにして 1 つずつ出す。URL の #節の id か、誤りのある節を最初に出す
  document.querySelectorAll('.tabbed-sections').forEach(function (tabbedContainer) {
    var tabSectionList = Array.prototype.filter.call(tabbedContainer.children, function (childElement) {
      return childElement.matches && childElement.matches('section[data-tab-title]');
    });
    if (tabSectionList.length < 2) { return; }
    var tabBar = document.createElement('div');
    tabBar.className = 'section-tabs';
    tabBar.setAttribute('role', 'tablist');
    var showTabSection = function (shownSection) {
      tabSectionList.forEach(function (tabSection, tabIndex) {
        var isShown = tabSection === shownSection;
        tabSection.hidden = !isShown;
        tabBar.children[tabIndex].classList.toggle('active', isShown);
        tabBar.children[tabIndex].setAttribute('aria-selected', isShown ? 'true' : 'false');
      });
    };
    tabSectionList.forEach(function (tabSection) {
      var tabButton = document.createElement('button');
      tabButton.type = 'button';
      tabButton.setAttribute('role', 'tab');
      tabButton.textContent = tabSection.getAttribute('data-tab-title');
      tabButton.addEventListener('click', function () {
        showTabSection(tabSection);
        if (window.history && window.history.replaceState) { window.history.replaceState(null, '', '#' + tabSection.id); }
      });
      tabBar.appendChild(tabButton);
    });
    tabbedContainer.insertBefore(tabBar, tabSectionList[0]);
    var hashSection = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
    var errorSection = tabSectionList.filter(function (tabSection) { return tabSection.querySelector('.field-error, .flash-error'); })[0];
    showTabSection(tabSectionList.indexOf(hashSection) !== -1 ? hashSection : (errorSection || tabSectionList[0]));
  });

  // ---- 表の絞り込み（input.table-filter の data-filter-table で表を指定。行の文字に含むものだけ出す） ----
  document.querySelectorAll('input.table-filter').forEach(function (tableFilterInput) {
    var filteredTable = document.querySelector(tableFilterInput.getAttribute('data-filter-table'));
    if (!filteredTable) { return; }
    tableFilterInput.hidden = false;
    tableFilterInput.addEventListener('input', function () {
      var filterText = tableFilterInput.value.trim().toLowerCase();
      filteredTable.querySelectorAll('tbody tr').forEach(function (tableRow) {
        tableRow.hidden = filterText !== '' && tableRow.textContent.toLowerCase().indexOf(filterText) === -1;
      });
    });
  });

  // ---- ユーザーの編集（G-14）: 班は、選んだ所属の課の班だけを出す ----
  var userDepartmentSelect = document.getElementById('department_id');
  var userTeamSelect = document.getElementById('team_id');
  if (userDepartmentSelect && userTeamSelect) {
    var applyTeamOptions = function () {
      Array.prototype.forEach.call(userTeamSelect.options, function (teamOption) {
        if (teamOption.value === '') { return; }
        var isSameDepartment = teamOption.getAttribute('data-department-id') === userDepartmentSelect.value;
        teamOption.hidden = !isSameDepartment;
        teamOption.disabled = !isSameDepartment;
      });
      if (userTeamSelect.selectedOptions.length && userTeamSelect.selectedOptions[0].disabled) { userTeamSelect.value = ''; }
    };
    userDepartmentSelect.addEventListener('change', applyTeamOptions);
    applyTeamOptions();
  }

  // ---- コメントの定型文を本文に入れる（G-05。G-19 で課長が登録した文） ----
  document.addEventListener('change', function (changeEvent) {
    var templateSelect = changeEvent.target;
    if (!templateSelect.classList || !templateSelect.classList.contains('comment-template-select') || templateSelect.value === '') { return; }
    var commentTextarea = templateSelect.closest('form').querySelector('textarea[name="comment"]');
    commentTextarea.value = commentTextarea.value === '' ? templateSelect.value : commentTextarea.value + '\n' + templateSelect.value;
    commentTextarea.dispatchEvent(new Event('input', { bubbles: true }));
    templateSelect.value = '';
    commentTextarea.focus();
  });

  // ---- 確認の表示（data-confirm）と二重押しの防止 ----
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var submitter = e.submitter;
    var msg = (submitter && submitter.getAttribute('data-confirm')) || f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    if (submitter && submitter.getAttribute('data-warn-check')) {
      var warned = f.querySelectorAll('.field-warning:not([hidden])').length;
      if (warned && !window.confirm(M('客先名や個人名の注意が出ている欄が {count} か所あります。このまま送りますか？', { count: warned }))) {
        e.preventDefault(); return;
      }
    }
    f.querySelectorAll('input[type=checkbox][data-confirm-on]').forEach(function (cb) {
      if (cb.checked && !cb.defaultChecked && !window.confirm(M('{text}。よろしいですか？', { text: cb.getAttribute('data-confirm-on') }))) {
        e.preventDefault();
      }
    });
    if (e.defaultPrevented) { return; }
    if (f.dataset.submitting === '1') { e.preventDefault(); return; }
    leaving = true; // 送信・画面の移動は自分の操作なので、離れるときの確認を出さない
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
        setMessageText(span, j.ok ? j.text : M('表示できません'));
        btn.hidden = true;
      })
      .catch(function () { btn.textContent = M('通信エラー'); });
  });

  // ---- スマートフォンの幅では 1 問ずつ答える（APP-019・K-040）。「一覧」に切り替えられる。最後に答えの一覧を確かめて提出 ----
  var wizardForm = document.getElementById('report-form');
  var wizardNav = wizardForm ? wizardForm.querySelector('.wizard-nav') : null;
  if (wizardForm && wizardNav) {
    var wizardModeSwitch = wizardForm.querySelector('.wizard-mode-switch');
    var wizardToggleButton = wizardForm.querySelector('.wizard-toggle');
    var wizardProgress = wizardNav.querySelector('.wizard-progress');
    var wizardMessage = wizardNav.querySelector('.wizard-message');
    var wizardPrevButton = wizardNav.querySelector('.wizard-prev');
    var wizardNextButton = wizardNav.querySelector('.wizard-next');
    var narrowScreenQuery = window.matchMedia('(max-width: 720px)');
    var wizardStepIndex = 0;
    var isReviewStep = false;
    var readWizardMode = function () {
      try { return localStorage.getItem('reportEditMode') || 'step'; } catch (storageError) { return 'step'; }
    };
    var writeWizardMode = function (modeName) {
      try { localStorage.setItem('reportEditMode', modeName); } catch (storageError) { /* 保存できなくても動く */ }
    };
    // 1 問の単位: 案件ブロックの案件の欄（ブロックの先頭）と、出ている項目
    var wizardSteps = function () {
      var stepList = [];
      Array.prototype.forEach.call(wizardForm.querySelectorAll('fieldset.block'), function (blockFieldset) {
        var isNewBlock = blockFieldset.classList.contains('block-new');
        var blockProject = blockFieldset.querySelector('select[name$="[project]"]');
        var headerElements = Array.prototype.filter.call(blockFieldset.children, function (childElement) {
          return childElement.tagName !== 'LEGEND' && !childElement.classList.contains('field');
        });
        if (headerElements.length) { stepList.push({block: blockFieldset, elements: headerElements, isHeader: true}); }
        if (isNewBlock && blockProject && blockProject.value === '') { return; }   // 案件を選んでいない「追加する」欄の項目は飛ばす
        Array.prototype.forEach.call(blockFieldset.querySelectorAll('.field'), function (fieldElement) {
          if (!fieldElement.hidden) { stepList.push({block: blockFieldset, elements: [fieldElement], isHeader: false}); }
        });
      });
      return stepList;
    };
    var clearWizardMarks = function () {
      Array.prototype.forEach.call(wizardForm.querySelectorAll('.wizard-current, .wizard-current-block'), function (markedElement) {
        markedElement.classList.remove('wizard-current', 'wizard-current-block');
      });
    };
    var isStepEmptyRequired = function (wizardStep) {
      return wizardStep.elements.some(function (stepElement) {
        if (!stepElement.classList.contains('is-required')) { return false; }
        if (stepElement.querySelector('input[type=radio]')) { return !stepElement.querySelector('input[type=radio]:checked'); }
        var valueInput = stepElement.querySelector('input:not([type=hidden]):not([type=checkbox]), select, textarea');
        return valueInput ? String(valueInput.value).trim() === '' : false;
      });
    };
    var showWizardStep = function () {
      var stepList = wizardSteps();
      clearWizardMarks();
      wizardMessage.hidden = true;
      if (isReviewStep || !stepList.length) {
        wizardForm.classList.remove('wizard-active');
        wizardForm.classList.add('wizard-review');
        setMessageText(wizardProgress, M('答えの一覧です。確かめてから、保存・提出してください。'));
        wizardPrevButton.hidden = false;
        wizardNextButton.hidden = true;
        return;
      }
      wizardForm.classList.remove('wizard-review');
      wizardForm.classList.add('wizard-active');
      wizardStepIndex = Math.max(0, Math.min(wizardStepIndex, stepList.length - 1));
      var currentStep = stepList[wizardStepIndex];
      currentStep.block.classList.add('wizard-current-block');
      currentStep.elements.forEach(function (stepElement) { stepElement.classList.add('wizard-current'); });
      setMessageText(wizardProgress, M('{current} / {total} 問', {current: wizardStepIndex + 1, total: stepList.length}));
      wizardPrevButton.hidden = wizardStepIndex === 0;
      wizardNextButton.hidden = false;
      setMessageText(wizardNextButton, wizardStepIndex === stepList.length - 1 ? M('答えを確かめる') : M('次へ'));
      var focusTarget = currentStep.elements[0].querySelector('input:not([type=hidden]), select, textarea');
      if (focusTarget && document.activeElement !== focusTarget) { try { focusTarget.focus({preventScroll: true}); } catch (focusError) { focusTarget.focus(); } }
      wizardForm.scrollIntoView({block: 'start'});
    };
    var applyWizardMode = function () {
      var isStepMode = narrowScreenQuery.matches && readWizardMode() === 'step' && !wizardForm.querySelector('.field .field-error');   // 入力の誤りで戻ったときは一覧
      wizardNav.hidden = !isStepMode;
      wizardModeSwitch.hidden = !narrowScreenQuery.matches;
      setMessageText(wizardToggleButton, isStepMode ? M('一覧で表示する') : M('1 問ずつ表示する'));
      if (isStepMode) {
        showWizardStep();
      } else {
        clearWizardMarks();
        wizardForm.classList.remove('wizard-active', 'wizard-review');
      }
    };
    wizardNextButton.addEventListener('click', function () {
      var stepList = wizardSteps();
      if (stepList[wizardStepIndex] && isStepEmptyRequired(stepList[wizardStepIndex])) {
        setMessageText(wizardMessage, M('必須の項目です。入力してから次へ進んでください。'));
        wizardMessage.hidden = false;
        return;
      }
      if (wizardStepIndex >= stepList.length - 1) {
        isReviewStep = true;
      } else {
        wizardStepIndex++;
      }
      showWizardStep();
    });
    wizardPrevButton.addEventListener('click', function () {
      if (isReviewStep) {
        isReviewStep = false;
        wizardStepIndex = wizardSteps().length - 1;
      } else {
        wizardStepIndex = Math.max(0, wizardStepIndex - 1);
      }
      showWizardStep();
    });
    wizardToggleButton.addEventListener('click', function () {
      writeWizardMode(wizardNav.hidden ? 'step' : 'list');
      isReviewStep = false;
      applyWizardMode();
    });
    if (narrowScreenQuery.addEventListener) {
      narrowScreenQuery.addEventListener('change', applyWizardMode);
    }
    applyWizardMode();
  }

  // ---- 必須の未入力の数を、下の保存の欄に出す（APP-018。出ている必須の項目だけ数える） ----
  var requiredRemainingLabel = document.querySelector('.required-remaining');
  if (requiredRemainingLabel) {
    var requiredForm = requiredRemainingLabel.closest('form');
    var countRequiredRemaining = function () {
      var remainingCount = 0;
      Array.prototype.forEach.call(requiredForm.querySelectorAll('.field.is-required'), function (requiredField) {
        if (requiredField.offsetParent === null) { return; }   // 表示の条件で隠れている項目
        var newBlock = requiredField.closest('fieldset.block-new');
        if (newBlock) {
          var newBlockProject = newBlock.querySelector('select[name$="[project]"]');
          if (newBlockProject && newBlockProject.value === '') { return; }   // 案件を選んでいない「追加する」欄
        }
        var radioInputs = requiredField.querySelectorAll('input[type=radio]');
        if (radioInputs.length) {
          if (!requiredField.querySelector('input[type=radio]:checked')) { remainingCount++; }
          return;
        }
        var valueInput = requiredField.querySelector('input:not([type=hidden]):not([type=checkbox]), select, textarea');
        if (valueInput && String(valueInput.value).trim() === '') { remainingCount++; }
      });
      setMessageText(requiredRemainingLabel, remainingCount > 0 ? M('必須の未入力: {count}', {count: remainingCount}) : '');
      requiredRemainingLabel.hidden = remainingCount === 0;
    };
    requiredForm.addEventListener('input', countRequiredRemaining);
    requiredForm.addEventListener('change', countRequiredRemaining);
    countRequiredRemaining();
  }

  // ---- 下書きの自動保存（入力が変わったときだけ、60 秒ごと） ----
  if (form && form.getAttribute('data-autosave') === '1' && window.fetch && window.FormData && document.body.getAttribute('data-maintenance') !== '1') {
    var dirty = false;
    var stopped = false;
    var status = form.querySelector('.autosave-status');
    form.addEventListener('input', function () { dirty = true; });
    form.addEventListener('change', function () { dirty = true; });
    form.addEventListener('submit', function () { dirty = false; });
    window.setInterval(function () {
      if (!dirty || stopped) { return; }
      dirty = false;
      var seq = editSeq;
      var data = new FormData(form);
      data.set('action', 'autosave');
      data.set('request_key', randomHex(16));
      var ctrl = window.AbortController ? new AbortController() : null;
      var timer = window.setTimeout(function () { if (ctrl) { ctrl.abort(); } }, 30000);
      fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', signal: ctrl ? ctrl.signal : undefined })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.reason === 'conflict') {
            if (status) { setMessageText(status, M('同じ月の報告が別の画面で作られたため、自動保存を止めました。「下書き保存」を押してください')); }
            stopped = true;
            return;
          }
          if (status) { setMessageText(status, j.ok ? M('自動保存しました {time}', { time: j.saved_at }) : M('自動保存できませんでした（入力を確かめてください）')); }
          if (!j.ok) { dirty = true; }
          if (j.ok && seq === editSeq) { unsaved = false; } // 送ったあとに入力がなければ、保存済み
        })
        .catch(function () {
          dirty = true;
          if (status) { setMessageText(status, M('通信が切れたため自動保存できませんでした。入力は残っています')); }
        })
        .then(function () { window.clearTimeout(timer); });
    }, 60000);
  }

  // ---- よくある質問のチャット（G-25 §2.1〜§2.7。AI は使わない。サーバーの faq_search() が選んだ、載っている質問と答えを吹き出しで出す。
  //      曖昧な質問（「使い方がわからない」など）は分野を聞き返し、分野の質問から選んでもらう。
  //      システムに関係のない質問・不適切な表現には返答できないと返し、続けて 5 回になったら返事をしない（数えるのはサーバー）。
  //      聞いた質問と返した答えはサーバーが記録し、ここからは反応（解決した・解決しない）を送る） ----
  (function () {
    var chatPanel = document.getElementById('faq-chat');
    if (!chatPanel || !window.fetch) { return; }
    var chatLog = chatPanel.querySelector('.faq-chat-log');
    var chatForm = chatPanel.querySelector('form.faq-chat-input');
    var questionInput = chatForm.querySelector('input[name="q"]');
    var csrfInput = chatPanel.querySelector('.faq-chat-csrf');
    var askRole = chatPanel.getAttribute('data-ask-role');
    var askBlock = document.getElementById('faq-ask');
    if (askBlock && !askBlock.hasAttribute('data-has-error')) { askBlock.hidden = true; }
    var categoryRowTemplate = chatLog.querySelector('.chat-choices');   // サーバーが出した分野のボタンの並び（聞き返すときに写す）
    var lastQuestionText = '';
    var shownFaqIdList = [];   // この会話で答えを出した質問の番号（「解決しない」のあと、同じ質問を出さない）

    function scrollToEnd() { chatLog.scrollTop = chatLog.scrollHeight; }
    function addBubble(speaker, text) {
      var bubble = document.createElement('div');
      bubble.className = 'chat-bubble ' + (speaker === 'user' ? 'chat-user' : 'chat-bot');
      if (text) { setMessageText(bubble, text); }
      chatLog.appendChild(bubble);
      scrollToEnd();
      return bubble;
    }
    function addTextLine(parentElement, className, text) {
      var lineElement = document.createElement(className === 'chat-answer' ? 'div' : 'p');
      lineElement.className = className;
      lineElement.textContent = text;
      parentElement.appendChild(lineElement);
    }
    /** 選ぶボタンの並び（押したら並び全体を押せなくする） */
    function addChoices(choiceList) {
      var choiceRow = document.createElement('div');
      choiceRow.className = 'chat-choices';
      choiceList.forEach(function (choice) {
        var choiceButton = document.createElement('button');
        choiceButton.type = 'button';
        choiceButton.className = 'small';
        choiceButton.textContent = choice.label;
        choiceButton.addEventListener('click', function () {
          Array.prototype.forEach.call(choiceRow.querySelectorAll('button'), function (rowButton) { rowButton.disabled = true; });
          choiceButton.classList.add('chosen');
          choice.onChoose();
        });
        choiceRow.appendChild(choiceButton);
      });
      chatLog.appendChild(choiceRow);
      scrollToEnd();
    }
    /** 分野のボタンの並びを、もう一度出す（曖昧な質問・見つからないとき） */
    function addCategoryChoices() {
      if (!categoryRowTemplate) { return; }
      var categoryRow = categoryRowTemplate.cloneNode(true);
      categoryRow.classList.remove('chat-choices-done');
      Array.prototype.forEach.call(categoryRow.querySelectorAll('.chosen'), function (choiceLink) { choiceLink.classList.remove('chosen'); choiceLink.removeAttribute('aria-current'); });
      chatLog.appendChild(categoryRow);
      scrollToEnd();
    }
    function showAnswer(faqEntry) {
      if (shownFaqIdList.indexOf(faqEntry.id) < 0) { shownFaqIdList.push(faqEntry.id); }
      var answerBubble = addBubble('bot', '');
      addTextLine(answerBubble, 'chat-question', 'Q. ' + faqEntry.question);
      addTextLine(answerBubble, 'chat-answer', faqEntry.answer);
      scrollToEnd();
    }
    /** 反応を記録に送る（失敗しても画面は止めない） */
    function sendFeedback(logId, feedbackCode, faqId) {
      if (!logId || !csrfInput) { return; }
      var feedbackData = new FormData();
      feedbackData.append('csrf_token', csrfInput.value);
      feedbackData.append('action', 'chat_feedback');
      feedbackData.append('log_id', String(logId));
      feedbackData.append('feedback', feedbackCode);
      feedbackData.append('faq_id', faqId ? String(faqId) : '');
      fetch(chatForm.action, { method: 'POST', body: feedbackData, credentials: 'same-origin', headers: { Accept: 'application/json' } }).catch(function () {});
    }
    /** 問い合わせの失敗をサーバーの記録（app.log）に残す（サーバーに届かないときは残せない） */
    function reportClientError(failureKind, statusCode) {
      if (!csrfInput) { return; }
      var errorData = new FormData();
      errorData.append('csrf_token', csrfInput.value);
      errorData.append('action', 'chat_client_error');
      errorData.append('kind', failureKind);
      errorData.append('status', String(statusCode || 0));
      fetch(chatForm.action, { method: 'POST', body: errorData, credentials: 'same-origin', headers: { Accept: 'application/json' } }).catch(function () {});
    }
    /** チャットを開いたときと同じ状態に戻す（最初の吹き出しと分野のボタンだけを残す） */
    function resetChat() {
      Array.prototype.slice.call(chatLog.children).forEach(function (childElement) {
        if (childElement === askBlock) { return; }
        if (!childElement.classList.contains('chat-initial')) { childElement.remove(); }
      });
      if (categoryRowTemplate) {
        categoryRowTemplate.classList.remove('chat-choices-done');
        Array.prototype.forEach.call(categoryRowTemplate.querySelectorAll('.chosen'), function (choiceLink) { choiceLink.classList.remove('chosen'); choiceLink.removeAttribute('aria-current'); });
      }
      if (askBlock) {
        askBlock.hidden = true;
        var askTextarea = askBlock.querySelector('textarea[name="question"]');
        var askLogInput = askBlock.querySelector('input[name="chat_log_id"]');
        if (askTextarea) { askTextarea.value = ''; }
        if (askLogInput) { askLogInput.value = ''; }
        chatLog.appendChild(askBlock);
      }
      lastQuestionText = '';
      shownFaqIdList = [];
      questionInput.value = '';
      chatLog.scrollTop = 0;
      questionInput.focus();
    }
    function askSolved(logId, faqId) {
      addChoices([
        { label: M('解決した'), onChoose: function () {
          // 解決したら、チャットを開いたときと同じ状態に戻す（サーバーは、この反応で会話を終える）
          sendFeedback(logId, 'solved', faqId);
          resetChat();
        } },
        { label: M('解決しない'), onChoose: function () { sendFeedback(logId, 'unsolved', faqId); offerRelated(logId, faqId); } }
      ]);
    }
    /**
     * 解決しないとき: そこで終えず、関係がありそうな質問を出す（G-25 §2.15）。
     * 無いとき・「この中にない」を押したときは、くわしく聞き直す（offerNextSteps）
     */
    function offerRelated(logId, faqId) {
      var waitingBubble = addBubble('bot', M('探しています…'));
      waitingBubble.classList.add('chat-waiting');
      var requestUrl = new URL(chatForm.action, window.location.href);
      requestUrl.search = '?format=json&related=' + encodeURIComponent(String(faqId)) + '&exclude=' + encodeURIComponent(shownFaqIdList.join(','));
      fetch(requestUrl.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (response) { return response.json(); })
        .then(function (result) {
          waitingBubble.remove();
          var relatedList = (result && result.ok && result.matches) ? result.matches : [];
          if (relatedList.length === 0) { offerNextSteps(logId); return; }
          addBubble('bot', M('お役に立てず、すみません。関係がありそうな質問です。近いものはありますか？'));
          addChoices(relatedList.map(function (faqEntry) {
            return { label: faqEntry.question, onChoose: function () { addBubble('user', faqEntry.question); showAnswer(faqEntry); askSolved(logId, faqEntry.id); } };
          }).concat([{ label: M('この中にない'), onChoose: function () { offerNextSteps(logId); } }]));
        })
        .catch(function () { waitingBubble.remove(); offerNextSteps(logId); });
    }
    /** 関係がありそうな質問でも解決しないとき: くわしく聞き直し、言葉を変える・分野から選ぶ・質問として送る（課長・確認者）の道を出す */
    function offerNextSteps(logId) {
      addBubble('bot', M('困っていることを、もう少しくわしく教えてください。「どの画面で」「何をしようとして」「どうなったか（出た文など）」を入れると、近い答えを探しやすくなります。'));
      var retryChoice = { label: M('くわしく入れ直す'), onChoose: function () { questionInput.value = lastQuestionText; questionInput.focus(); } };
      var categoryChoice = { label: M('分野から選ぶ'), onChoose: addCategoryChoices };
      if (askRole === 'ask') {
        addChoices([retryChoice, categoryChoice, { label: M('質問として送る'), onChoose: function () { offerAsk(logId); } }]);
      } else {
        addChoices([retryChoice, categoryChoice, { label: M('だれに聞けばよいか'), onChoose: function () { offerAsk(logId); } }]);
      }
    }
    /** 答えが見つからないとき: 課長・確認者には送る欄（送ったことは、この記録に残す）、ほかの人には案内 */
    function offerAsk(logId) {
      if (askRole === 'ask' && askBlock) {
        var askTextarea = askBlock.querySelector('textarea[name="question"]');
        var askLogInput = askBlock.querySelector('input[name="chat_log_id"]');
        if (askTextarea) { askTextarea.value = lastQuestionText; }
        if (askLogInput) { askLogInput.value = logId ? String(logId) : ''; }
        askBlock.hidden = false;
        chatLog.appendChild(askBlock);
        scrollToEnd();
        if (askTextarea && lastQuestionText === '') { askTextarea.focus(); }
      } else if (askRole === 'staff') {
        addBubble('bot', M('システム管理者・補佐は質問を受ける側のため、ここからは送れません。答えを「＋ 質問を載せる」で足してください。'));
      } else {
        addBubble('bot', M('課長・確認者（課長代理・リーダー）に聞いてください。課長・確認者が、この画面からシステム管理者に質問を送れます。'));
      }
    }
    /** 質問の一覧から選んでもらう（選ぶと答えを出す。「この中にない」で送る欄・案内） */
    function addQuestionChoices(faqEntryList, logId) {
      addChoices(faqEntryList.map(function (faqEntry) {
        return { label: faqEntry.question, onChoose: function () { addBubble('user', faqEntry.question); showAnswer(faqEntry); askSolved(logId, faqEntry.id); } };
      }).concat([{ label: M('この中にない'), onChoose: function () { sendFeedback(logId, 'unsolved', null); offerNextSteps(logId); } }]));
    }
    /** サーバーに聞く（?format=json と、q か category。開いていた画面も付ける） */
    function fetchFaq(queryName, queryValue, onResult) {
      var waitingBubble = addBubble('bot', M('探しています…'));
      waitingBubble.classList.add('chat-waiting');
      var requestUrl = new URL(chatForm.action, window.location.href);
      var screenInput = chatForm.querySelector('input[name="screen"]');
      requestUrl.search = '?format=json&' + queryName + '=' + encodeURIComponent(queryValue)
        + (screenInput ? '&screen=' + encodeURIComponent(screenInput.value) : '');
      fetch(requestUrl.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (response) {
          return response.json()
            .catch(function () { return { ok: false, clientFailure: 'not_json' }; })
            .then(function (result) { result.httpStatus = response.status; return result; });
        })
        .then(function (result) {
          waitingBubble.remove();
          if (result.ok) {
            onResult(result);
            return;
          }
          if (result.reason === 'login' || result.httpStatus === 401) {
            // ログインが切れている（30 分操作しなかったときなど）。サーバーが記録に残している
            addBubble('bot', M('ログインの有効期限が切れました。画面を開き直して、もう一度ログインしてください。'));
            return;
          }
          addBubble('bot', M('通信エラー'));
          if (result.clientFailure) { reportClientError(result.clientFailure, result.httpStatus); }   // サーバーのエラーは、サーバーが記録に残している
        })
        .catch(function () {
          waitingBubble.remove();
          addBubble('bot', M('通信エラー'));
          reportClientError('network', 0);
        });
    }

    // 分野を選んだ: その分野の質問を出す
    chatLog.addEventListener('click', function (clickEvent) {
      var categoryLink = clickEvent.target.closest && clickEvent.target.closest('a[data-category]');
      if (!categoryLink) { return; }
      clickEvent.preventDefault();
      var categoryRow = categoryLink.parentNode;
      if (categoryRow.classList.contains('chat-choices-done') && categoryRow !== categoryRowTemplate) { return; }
      if (categoryRow !== categoryRowTemplate) { categoryRow.classList.add('chat-choices-done'); }
      // 押した印は、最後に押した 1 つだけに付ける（前に押したボタンは押す前の見た目に戻す）
      Array.prototype.forEach.call(chatLog.querySelectorAll('a[data-category].chosen'), function (chosenLink) {
        chosenLink.classList.remove('chosen');
        chosenLink.removeAttribute('aria-current');
      });
      categoryLink.classList.add('chosen');
      categoryLink.setAttribute('aria-current', 'true');
      var categoryLabel = categoryLink.textContent.replace(/（\d+）$/, '');
      lastQuestionText = '';
      if (askBlock) { askBlock.hidden = true; }
      addBubble('user', categoryLabel);
      fetchFaq('category', categoryLink.getAttribute('data-category'), function (result) {
        if (result.matches.length === 0) {
          addBubble('bot', M('この分野の質問は、まだ載っていません。'));
          offerAsk(result.log_id);
          return;
        }
        addBubble('bot', M('「{category}」の質問です。近いものを選んでください。', { category: categoryLabel }));
        addQuestionChoices(result.matches, result.log_id);
      });
    });

    // 知りたいことを入れた
    chatForm.addEventListener('submit', function (submitEvent) {
      submitEvent.preventDefault();
      var questionText = questionInput.value.trim();
      if (questionText === '') { questionInput.focus(); return; }
      lastQuestionText = questionText;
      questionInput.value = '';
      if (askBlock) { askBlock.hidden = true; }
      addBubble('user', questionText);
      fetchFaq('q', questionText, function (result) {
        if (result.ignored) { return; }   // 関係のない質問・不適切な表現が続いた: 返事をしない
        if (result.emergency) {
          // 命・体の緊急の状態: 119 番を案内する
          var emergencyBubble = addBubble('bot', M('命や体に関わる、急いだほうがよい状態かもしれません。少しでも危ないと感じたら、迷わず 119 番に電話して救急車を呼んでください。近くに人がいれば、すぐに助けを求めてください。救急車を呼ぶか迷うときは #7119（救急安心センター。地域によってはつながりません）、子どものことは #8000（子ども医療電話相談）に相談できます。このチャットでは、手当てのしかたにはお答えできません。'));
          emergencyBubble.classList.add('chat-care', 'chat-emergency');
          emergencyBubble.setAttribute('role', 'alert');
          return;
        }
        if (result.retirement) {
          // 自分の退職: 寄り添って上司（課長）への相談を促す（手続きは示さない）
          addBubble('bot', M('大切なことを書いてくれて、ありがとうございます。退職のことは、一人で抱えたり決めたりせず、まず上司（課長）に相談してください。気持ちや事情を話すことで、ほかの道が見つかることもあります。')).classList.add('chat-care');
          return;
        }
        if (result.distress) {
          // 心の不調らしい文: 気づかいの言葉（サーバーがシステム管理者・補佐に知らせる）。心配な言葉なら、近い質問の答えも出す
          // 上司からのハラスメントは、その上司ではなく、ほかの管理職・会社の相談窓口への相談をすすめる
          var careText = result.harassment
            ? M('つらい思いを書いてくれて、ありがとうございます。上司からのハラスメントは、その上司ではなく、ほかの管理職（別の課長・部長など）や、会社の相談窓口に相談してください。一人で抱え込まないでください。') + (result.distress === 'urgent' ? M('今すぐ危ないと感じるときは、ためらわずに周りの人に助けを求めてください。') : '')
            : (result.distress === 'urgent'
              ? M('つらい気持ちを書いてくれて、ありがとうございます。一人で抱えずに、上司やシステム管理者に相談してください。今すぐ危ないと感じるときは、ためらわずに周りの人に助けを求めてください。')
              : M('おつかれさまです。無理をせず、つらいときは一人で抱えずに、上司やシステム管理者に相談してください。'));
          var careBubble = addBubble('bot', careText);
          careBubble.classList.add('chat-care');
          if (result.matches.length > 0) {
            showAnswer(result.matches[0]);
            askSolved(result.log_id, result.matches[0].id);
          }
          return;
        }
        if (result.sensitive) {
          // 要配慮個人情報に触れる文: 寄り添う返事（文は記録しない）。ほかにはっきり知りたいことがあれば、その答えも出す
          addBubble('bot', M('大切なことを書いてくれて、ありがとうございます。体や家族のことなど、個人的な事情には、このチャットでは詳しくお答えできませんが、一人で抱えずに、上司や信頼できる人に相談してください。報告や締め日のことで困っているときは、課長に相談してください。')).classList.add('chat-care');
          if (result.matches.length > 0) {
            showAnswer(result.matches[0]);
            askSolved(result.log_id, result.matches[0].id);
          }
          return;
        }
        if (result.violence) {
          // 人への暴力を書いた文: 答えは出さず、カウンセリングを受けることをすすめる（システム管理者・補佐に知らせる）
          addBubble('bot', M('強い怒りや、人を傷つけたい気持ちを一人で抱えていませんか。カウンセラーや、会社の相談窓口・産業医など、話を聞いてもらえるところで、カウンセリングを受けることをおすすめします。気持ちを言葉にするだけでも、楽になることがあります。今すぐ自分や人を傷つけてしまいそうなときは、ためらわずに周りの人に助けを求めてください。')).classList.add('chat-care');
          return;
        }
        if (result.abusive) {
          addBubble('bot', M('不適切な表現が含まれているため、返答できません。'));
          return;
        }
        if (result.unrelated) {
          addBubble('bot', M('その質問には返答できません。'));
          return;
        }
        if (result.closing) {
          // 用件がない（「用件なし」「大丈夫です」）: 聞き返さずに受け止める
          addBubble('bot', M('わかりました。知りたいことがあるときは、いつでも入れてください。'));
          return;
        }
        if (result.opener) {
          // 呼びかけだけ（「大変です」）: 用件を聞き返す
          addBubble('bot', M('どういったご用件でしょうか。'));
          questionInput.focus();
          return;
        }
        if (result.vague) {
          addBubble('bot', M('どのことについて知りたいですか？　分野を選ぶか、「パスワード」「提出」「コメント」のように、知りたいことの言葉を入れてください。'));
          addCategoryChoices();
          return;
        }
        if (result.matches.length === 0) {
          var retryChoice = { label: M('言葉を変えて入れる'), onChoose: function () { questionInput.value = lastQuestionText; questionInput.focus(); } };
          var categoryChoice = { label: M('分野から選ぶ'), onChoose: addCategoryChoices };
          if (askRole === 'ask') {
            addBubble('bot', M('近い質問は見つかりませんでした。言葉を変えて入れるか、分野から選ぶか、質問として送ってください。'));
            addChoices([retryChoice, categoryChoice, { label: M('質問として送る'), onChoose: function () { offerAsk(result.log_id); } }]);
          } else {
            addBubble('bot', M('近い質問は見つかりませんでした。言葉を変えて入れるか、分野から選んでください。'));
            offerAsk(result.log_id);
            addChoices([retryChoice, categoryChoice]);
          }
          return;
        }
        if (result.context) {
          // 中身のない発言に、同じ会話の前の話題を補って答えた（G-25 §2.14）
          addBubble('bot', M('「{topic}」についての答えです。', { topic: result.context }));
        }
        showAnswer(result.matches[0]);
        if (result.matches.length > 1) {
          addBubble('bot', M('ほかに近い質問もあります。押すと答えを出します。'));
          addQuestionChoices(result.matches.slice(1), result.log_id);
          askSolved(result.log_id, result.matches[0].id);
        } else {
          askSolved(result.log_id, result.matches[0].id);
        }
      });
    });
    scrollToEnd();
  })();
})();
