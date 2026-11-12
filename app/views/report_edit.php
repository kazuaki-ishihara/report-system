<?php
/** @var string $period */
/** @var array|null $report */
/** @var array $fields */
/** @var array $state */
/** @var array $errors */
/** @var array $notices */
/** @var array $projects */
/** @var array $words */
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
  <h1><?= h(period_label($period)) ?>の報告 <span class="tag"><?= h(report_status_label($status)) ?></span></h1>
  <?php foreach ($notices as $n): ?>
    <div class="flash flash-info"><?= h($n) ?></div>
  <?php endforeach; ?>
  <?php if ($errors): ?>
    <div class="flash flash-error" role="alert"><?= h($errors['general'] ?? '入力が必要な項目か、直す必要がある項目があります。') ?></div>
  <?php endif; ?>
  <?php if ($status === 'submitted'): ?>
    <p class="note">提出済みです。直して「保存」すると、変更の履歴が残り、確認者に「更新あり」と知らせます。出し直しの操作はいりません。</p>
  <?php endif; ?>

  <form method="post" action="<?= h(url('/reports/save.php')) ?>" class="form report-form" id="report-form"
        data-words="<?= h(json_encode($words, JSON_UNESCAPED_UNICODE)) ?>"
        data-autosave="<?= $isDraft ? '1' : '0' ?>">
    <?= form_hidden() ?>
    <input type="hidden" name="period" value="<?= h($period) ?>">

    <?php foreach ($blocks as $i => $b): $isNew = !empty($b['new']); ?>
      <fieldset class="block<?= $isNew ? ' block-new' : '' ?>" data-scope="block">
        <legend><?= $isNew ? '案件を追加するとき' : '案件 ' . ($i + 1) ?></legend>
        <label for="b<?= $i ?>_project">案件<?= $isNew ? '' : '<span class="badge badge-required">必須</span>' ?></label>
        <select id="b<?= $i ?>_project" name="b[<?= $i ?>][project]">
          <option value=""><?= $isNew ? '（追加しない）' : '選んでください' ?></option>
          <?php foreach ($projects as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $b['project_id'] ? 'selected' : '' ?>><?= h($p['code']) ?><?= $p['alias'] !== '' ? ' ' . h($p['alias']) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($e = $err('b' . $i . '_project')): ?><p class="field-error"><?= h($e) ?></p><?php endif; ?>
        <?php if ($isNew): ?>
          <p class="note">新しい案件に入ったときは、ここで案件を選んで入力します。もっと足すときは、保存すると欄が増えます。</p>
        <?php else: ?>
          <label class="check"><input type="checkbox" name="b[<?= $i ?>][remove]" value="1"> この案件を外す（終わった案件）</label>
        <?php endif; ?>
        <?php foreach ($blockFields as $f): $k = 'b' . $i . '_' . $f['id']; ?>
          <?= form_input_html($f, (string) ($b['values'][$f['id']] ?? ''), 'b[' . $i . '][f][' . $f['id'] . ']', $k, $err($k), !empty($state['warnings'][$k])) ?>
        <?php endforeach; ?>
      </fieldset>
    <?php endforeach; ?>

    <?php if ($reportFields): ?>
      <fieldset class="block" data-scope="report">
        <legend>報告全体</legend>
        <?php foreach ($reportFields as $f): $k = 'r_' . $f['id']; ?>
          <?= form_input_html($f, (string) ($state['report'][$f['id']] ?? ''), 'r[' . $f['id'] . ']', $k, $err($k), !empty($state['warnings'][$k])) ?>
        <?php endforeach; ?>
      </fieldset>
    <?php endif; ?>

    <p class="note autosave-status" aria-live="polite"></p>
    <div class="sticky-actions">
      <?php if ($isDraft): ?>
        <button type="submit" name="action" value="draft">下書き保存</button>
        <button type="submit" name="action" value="submit" class="primary" data-warn-check="1">提出する</button>
      <?php else: ?>
        <button type="submit" name="action" value="save" class="primary" data-warn-check="1">保存</button>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($status === 'submitted'): ?>
    <form method="post" action="<?= h(url('/reports/save.php')) ?>" class="inline" data-confirm="提出を取り下げて下書きに戻します。よろしいですか？">
      <?= form_hidden() ?>
      <input type="hidden" name="period" value="<?= h($period) ?>">
      <button type="submit" name="action" value="withdraw">提出を取り下げる</button>
    </form>
    <a href="<?= h(url('/reports/view.php?id=' . (int) $report['id'])) ?>">コメントとチェックを見る</a>
  <?php elseif ($status === 'draft'): ?>
    <form method="post" action="<?= h(url('/reports/save.php')) ?>" class="inline" data-confirm="この下書きを削除します。よろしいですか？">
      <?= form_hidden() ?>
      <input type="hidden" name="period" value="<?= h($period) ?>">
      <button type="submit" name="action" value="delete" class="danger">下書きを削除</button>
    </form>
  <?php endif; ?>
</section>
