<?php
/** @var string $period */
/** @var array|null $report */
/** @var array $fields */
/** @var array $state */
/** @var array $errors */
/** @var array $notices */
/** @var array $projects */
/** @var array $words */
/** @var array $copySources */
/** @var array $previousComparison 前月との比べ（report_previous_comparison() の戻り値） */
$status = $report['status'] ?? null;
$isDraft = $status === null || $status === 'draft';
$blockFields = array_values(array_filter($fields, function ($f) { return $f['scope'] === 'project' && $f['type'] !== 'project'; }));
$reportFields = array_values(array_filter($fields, function ($f) { return $f['scope'] === 'report'; }));
$blocks = $state['blocks'];
$blocks[] = ['project_id' => 0, 'values' => [], 'new' => true];
$err = function (string $k) use ($errors): ?string { return $errors[$k] ?? null; };
?>
<section class="card">
  <p><a href="<?= h(url('/index.php')) ?>">← ホーム</a></p>
  <h1><?= h(period_label($period)) ?>の報告 <?= report_status_tag_html($status) ?></h1>
  <?php foreach ($notices as $n): ?>
    <div class="flash flash-info edit-notice"><?= h_lines($n) ?></div>
  <?php endforeach; ?>
  <?php if ($errors): ?>
    <div class="flash flash-error" role="alert"><?= h_lines($errors['general'] ?? '入力が必要な項目か、直す必要がある項目があります。') ?></div>
  <?php endif; ?>
  <?php if ($isDraft && !empty($state['carry_from'])): ?>
    <form method="post" action="<?= h(url('/reports/save.php')) ?>" class="unchanged-box"
          data-confirm="<?= h($status === 'draft' ? msg('前月と同じ内容で提出します。この下書きの内容は前月の内容に置き換わります。よろしいですか？') : msg('前月と同じ内容で提出します。よろしいですか？')) ?>">
      <?= form_hidden() ?>
      <input type="hidden" name="period" value="<?= h($period) ?>">
      <p>前月から変更がない場合は、このボタンで提出できます（入力は不要です）。</p>
      <button type="submit" name="action" value="unchanged" class="primary big">前月から変更なしで提出</button>
    </form>
  <?php endif; ?>
  <?php if ($isDraft && $copySources): ?>
    <form method="get" action="<?= h(url('/reports/edit.php')) ?>" class="copy-box"
          data-confirm="<?= h(msg('選んだ報告の内容を入力欄に写します。「全体」を選んだときは、いま入力欄にある内容と置き換わります（保存するまで反映されません）。よろしいですか？')) ?>">
      <input type="hidden" name="period" value="<?= h($period) ?>">
      <label for="copy_target">過去の報告の内容を写す</label>
      <select id="copy_target" name="copy_target">
        <?php foreach ($copySources as $copySourceReport): $copySourceLabel = period_label((string) $copySourceReport['period']) . '（' . report_status_label((string) $copySourceReport['status']) . '）'; ?>
          <optgroup label="<?= h($copySourceLabel) ?>">
            <option value="<?= (int) $copySourceReport['id'] ?>" <?= (int) $copySourceReport['id'] === (int) ($state['copy_from'] ?? 0) ? 'selected' : '' ?>><?= h($copySourceLabel) ?> の全体</option>
            <?php foreach ($copySourceReport['projects'] as $copySourceProject): ?>
              <option value="<?= (int) $copySourceReport['id'] ?>-<?= (int) $copySourceProject['id'] ?>"><?= h(period_label((string) $copySourceReport['period'])) ?> の <?= h($copySourceProject['code']) ?><?= $copySourceProject['alias'] !== '' ? ' ' . h($copySourceProject['alias']) : '' ?> だけ</option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
      <button type="submit">写す</button>
      <p class="note">「全体」は入力欄の内容を置き換えます。「○○ だけ」は、その案件を今の入力に足します（同じ案件があれば置き換えます）。どちらも自由記述を含むすべての項目を写します。終了した案件は写しません。</p>
    </form>
  <?php endif; ?>
  <?php if ($status === 'submitted'): ?>
    <p class="note">提出済みです。内容を直して「保存」すると、変更の履歴が残り、確認者の画面に「更新あり」と表示されます。もう一度提出する必要はありません。</p>
  <?php endif; ?>

  <form method="post" action="<?= h(url('/reports/save.php')) ?>" class="form report-form" id="report-form"
        data-words="<?= h(json_encode($words, JSON_UNESCAPED_UNICODE)) ?>"
        data-autosave="<?= $isDraft ? '1' : '0' ?>" data-leave-warn="1"
        data-unsaved="<?= ($errors || !empty($state['copy_from']) || !empty($state['unsaved'])) ? '1' : '0' ?>">
    <?= form_hidden() ?>
    <input type="hidden" name="period" value="<?= h($period) ?>">
    <?php if (!empty($state['copy_from'])): ?><input type="hidden" name="copy_from" value="<?= (int) $state['copy_from'] ?>"><?php endif; ?>

    <?php foreach ($blocks as $i => $b): $isNew = !empty($b['new']); ?>
      <fieldset class="block<?= $isNew ? ' block-new' : '' ?>" data-scope="block">
        <legend><?= $isNew ? '案件を追加するとき' : '案件 ' . ($i + 1) ?>
          <?php if (!$isNew && $previousComparison['previousReport'] !== null && !isset($previousComparison['previousProjectCodeById'][(int) $b['project_id']])): ?><span class="tag tag-changed"><?= h(msg('前月にない案件')) ?></span><?php endif; ?></legend>
        <label for="b<?= $i ?>_project"><?= $isNew ? '' : '<span class="badge badge-required">必須</span>' ?><span class="field-label-text">案件</span></label>
        <select id="b<?= $i ?>_project" name="b[<?= $i ?>][project]">
          <option value=""><?= $isNew ? '（追加しない）' : '選んでください' ?></option>
          <?php foreach ($projects as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $b['project_id'] ? 'selected' : '' ?>><?= h($p['code']) ?><?= $p['alias'] !== '' ? ' ' . h($p['alias']) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($e = $err('b' . $i . '_project')): ?><p class="field-error"><?= h_lines($e) ?></p><?php endif; ?>
        <?php if ($isNew): ?>
          <p class="note">新しい案件に入ったときは、ここで案件を選んで入力してください。さらに追加する場合は、一度保存すると入力欄が増えます。</p>
        <?php else: ?>
          <label class="check"><input type="checkbox" name="b[<?= $i ?>][remove]" value="1"> この案件を外す（終わった案件）</label>
          <button type="button" class="small duplicate-block" data-source-block-index="<?= $i ?>" hidden>この内容で案件を追加</button>
        <?php endif; ?>
        <?php foreach ($blockFields as $f): $k = 'b' . $i . '_' . $f['id']; ?>
          <?= form_input_html($f, (string) ($b['values'][$f['id']] ?? ''), 'b[' . $i . '][f][' . $f['id'] . ']', $k, $err($k), !empty($state['warnings'][$k])) ?>
          <?= $isNew ? '' : report_previous_value_html($previousComparison, $f, (int) $b['project_id'], (string) ($b['values'][$f['id']] ?? '')) ?>
        <?php endforeach; ?>
      </fieldset>
    <?php endforeach; ?>

    <?php if ($reportFields): ?>
      <fieldset class="block" data-scope="report">
        <legend>報告全体</legend>
        <?php foreach ($reportFields as $f): $k = 'r_' . $f['id']; ?>
          <?= form_input_html($f, (string) ($state['report'][$f['id']] ?? ''), 'r[' . $f['id'] . ']', $k, $err($k), !empty($state['warnings'][$k])) ?>
          <?= report_previous_value_html($previousComparison, $f, 0, (string) ($state['report'][$f['id']] ?? '')) ?>
        <?php endforeach; ?>
      </fieldset>
    <?php endif; ?>

    <p class="note autosave-status" aria-live="polite"></p>
    <div class="wizard-nav" hidden>
      <p class="wizard-progress" aria-live="polite"></p>
      <p class="field-error wizard-message" aria-live="assertive" hidden></p>
      <div class="wizard-buttons">
        <button type="button" class="wizard-prev">戻る</button>
        <button type="button" class="wizard-next primary">次へ</button>
      </div>
    </div>
    <p class="wizard-mode-switch" hidden><button type="button" class="link-button wizard-toggle"></button></p>
    <div class="sticky-actions">
      <span class="required-remaining" hidden aria-live="polite"></span>
      <?php if ($isDraft): ?>
        <button type="submit" name="action" value="draft">下書き保存</button>
        <button type="submit" name="action" value="submit" class="primary" data-warn-check="1">提出する</button>
      <?php else: ?>
        <button type="submit" name="action" value="save" class="primary" data-warn-check="1">保存</button>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($status === 'submitted'): ?>
    <form method="post" action="<?= h(url('/reports/save.php')) ?>" class="inline" data-confirm="<?= h(msg('提出を取り下げて下書きに戻します。よろしいですか？')) ?>">
      <?= form_hidden() ?>
      <input type="hidden" name="period" value="<?= h($period) ?>">
      <button type="submit" name="action" value="withdraw">提出を取り下げる</button>
    </form>
    <a href="<?= h(url('/reports/view.php?id=' . (int) $report['id'])) ?>">コメントとチェックを見る</a>
  <?php elseif ($status === 'draft'): ?>
    <form method="post" action="<?= h(url('/reports/save.php')) ?>" class="inline" data-confirm="<?= h(msg('この下書きを削除します。よろしいですか？')) ?>">
      <?= form_hidden() ?>
      <input type="hidden" name="period" value="<?= h($period) ?>">
      <button type="submit" name="action" value="delete" class="danger">下書きを削除</button>
    </form>
  <?php endif; ?>
</section>
