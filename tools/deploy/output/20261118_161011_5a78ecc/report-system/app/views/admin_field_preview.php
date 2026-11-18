<?php
/** @var array $dept */
/** @var array $fields */
?>
<section class="card">
  <h1><?= h($dept['name']) ?>の様式（プレビュー）</h1>
  <p class="note">入力画面の見た目です。保存はできません。</p>
  <p><a href="<?= h(url('/admin/fields.php?dept=' . (int) $dept['id'])) ?>">← 項目マスタ</a></p>
  <form class="form" action="#" method="get" data-preview="1">
    <h2>案件 1</h2>
    <?php foreach ($fields as $f): if ($f['scope'] !== 'project' || $f['type'] === 'project') { continue; } ?>
      <?= form_input_html($f, '', 'p[' . $f['id'] . ']', 'p_' . $f['id']) ?>
    <?php endforeach; ?>
    <h2>報告全体</h2>
    <?php foreach ($fields as $f): if ($f['scope'] !== 'report') { continue; } ?>
      <?= form_input_html($f, '', 'r[' . $f['id'] . ']', 'r_' . $f['id']) ?>
    <?php endforeach; ?>
  </form>
</section>
