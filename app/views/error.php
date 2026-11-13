<?php /** @var string $message */ ?>
<section class="card">
  <h1>エラー</h1>
  <p><?= h_lines($message) ?></p>
  <p><a href="<?= h(url('/index.php')) ?>">ホームへ戻る</a></p>
</section>
