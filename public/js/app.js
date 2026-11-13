// 月次報告: 画面の補助（JavaScript がなくても主な操作はできる）
(function () {
  'use strict';

  // ---- 文言（課長が画面で変えられる。layout.php が body の data-msgs で渡す。G17_messages.md） ----
  var displayTextByDefaultText = {};
  try {
    displayTextByDefaultText = JSON.parse(document.body.getAttribute('data-msgs') || '{}');
  } catch (parseError) { displayTextByDefaultText = {}; }
  /** 既定の文言から画面に出す文言を返す。placeholderValues の {name} に値を入れる */
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
      checkLabel.textContent = problemTextList.length ? problemTextList.join('。') + M('（保存はできますが、読みにくくなります）') : M('読みやすい組み合わせです');
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
    if (resultLabel) { resultLabel.textContent = resultText; }
  }
  document.addEventListener('click', function (e) {
    var generateButton = e.target.closest('.generate-password');
    if (generateButton) {
      var generatedPassword = generatePassword(16);
      [generateButton.getAttribute('data-target'), generateButton.getAttribute('data-confirm-target')].forEach(function (targetId) {
        var targetInput = targetId ? document.getElementById(targetId) : null;
        if (targetInput) {
          targetInput.value = generatedPassword;
          targetInput.type = 'text';
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
    var copyValue = copySource.value !== undefined ? copySource.value : copySource.textContent;
    if (!copyValue) {
      showCopyResult(copyButton, M('コピーする文字がありません'));
      return;
    }
    var onCopied = function () { showCopyResult(copyButton, M('コピーしました')); };
    var copyByCommand = function () {
      var copyArea = document.createElement('textarea');
      copyArea.value = copyValue;
      copyArea.setAttribute('readonly', '');
      copyArea.style.position = 'fixed';
      copyArea.style.opacity = '0';
      document.body.appendChild(copyArea);
      copyArea.select();
      var isCopied = false;
      try { isCopied = document.execCommand('copy'); } catch (copyError) { isCopied = false; }
      document.body.removeChild(copyArea);
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
      duplicateNote.textContent = M('「{code}」の内容を写しました。追加する案件を選んでください（保存するまで反映されません）', { code: sourceProjectCode });
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
        span.textContent = j.ok ? j.text : M('表示できません');
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
        wizardProgress.textContent = M('答えの一覧です。確かめてから、保存・提出してください。');
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
      wizardProgress.textContent = M('{current} / {total} 問', {current: wizardStepIndex + 1, total: stepList.length});
      wizardPrevButton.hidden = wizardStepIndex === 0;
      wizardNextButton.hidden = false;
      wizardNextButton.textContent = wizardStepIndex === stepList.length - 1 ? M('答えを確かめる') : M('次へ');
      var focusTarget = currentStep.elements[0].querySelector('input:not([type=hidden]), select, textarea');
      if (focusTarget && document.activeElement !== focusTarget) { try { focusTarget.focus({preventScroll: true}); } catch (focusError) { focusTarget.focus(); } }
      wizardForm.scrollIntoView({block: 'start'});
    };
    var applyWizardMode = function () {
      var isStepMode = narrowScreenQuery.matches && readWizardMode() === 'step' && !wizardForm.querySelector('.field .field-error');   // 入力の誤りで戻ったときは一覧
      wizardNav.hidden = !isStepMode;
      wizardModeSwitch.hidden = !narrowScreenQuery.matches;
      wizardToggleButton.textContent = isStepMode ? M('一覧で表示する') : M('1 問ずつ表示する');
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
        wizardMessage.textContent = M('必須の項目です。入力してから次へ進んでください。');
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
      requiredRemainingLabel.textContent = remainingCount > 0 ? M('必須の未入力: {count}', {count: remainingCount}) : '';
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
            if (status) { status.textContent = M('同じ月の報告が別の画面で作られたため、自動保存を止めました。「下書き保存」を押してください'); }
            stopped = true;
            return;
          }
          if (status) { status.textContent = j.ok ? M('自動保存しました {time}', { time: j.saved_at }) : M('自動保存できませんでした（入力を確かめてください）'); }
          if (!j.ok) { dirty = true; }
          if (j.ok && seq === editSeq) { unsaved = false; } // 送ったあとに入力がなければ、保存済み
        })
        .catch(function () {
          dirty = true;
          if (status) { status.textContent = M('通信が切れたため自動保存できませんでした。入力は残っています'); }
        })
        .then(function () { window.clearTimeout(timer); });
    }, 60000);
  }
})();
