<?php
/** @var array<int, array{path: string, label: string, description: string, statusList: array<int, array{text: string, isAttention: bool}>}> $cardList */
/** @var bool $canManageSystem 管理者・補佐か（システムの状態を出す） */
/** @var array{databaseBytes: int, latestBackupAt: ?string, latestBackupName: ?string, backupCount: int, isStale: bool}|null $backupStatus */
/** @var array{until: string, message: string, started_by: int, started_at: string}|null $maintenanceState */
/** @var string[] $maintenanceErrorTextList */
?>
<section class="card wide">
  <h1>管理のトップ</h1>
  <p class="note">管理の仕事ごとの入口と、今の状態です。目立つ札は、対応が要るものです。</p>
  <?php if ($canManageSystem && $backupStatus !== null): ?>
  <section class="system-status" aria-label="システムの状態">
    <h2>システムの状態</h2>
    <ul class="list compact">
      <li><span>DB の大きさ</span><span><?= h(backup_size_text($backupStatus['databaseBytes'])) ?></span></li>
      <li><span>最後のバックアップ</span><span>
        <?php if ($backupStatus['latestBackupAt'] === null): ?><span class="tag tag-open">まだありません</span>
        <?php else: ?><?= h(display_datetime($backupStatus['latestBackupAt'])) ?>（<?= (int) $backupStatus['backupCount'] ?> 個）<?php if ($backupStatus['isStale']): ?> <span class="tag tag-open"><?= (int) cfg('backup_warn_days') ?> 日より前</span><?php endif; ?><?php endif; ?>
      </span></li>
    </ul>
    <?php if ($backupStatus['isStale']): ?><p class="note">バックアップは、サーバーで <code>php app/bin/backup.php</code> を動かして作ります（定期実行に登録しておくと毎日作られます）。</p><?php endif; ?>
    <h3>メンテナンス</h3>
    <?php foreach ($maintenanceErrorTextList as $maintenanceErrorText): ?><div class="flash flash-error" role="alert"><?= h_lines($maintenanceErrorText) ?></div><?php endforeach; ?>
    <?php if ($maintenanceState !== null): ?>
      <p><span class="tag tag-open">メンテナンス中</span> <?= h(display_datetime($maintenanceState['until'])) ?> まで<?= $maintenanceState['message'] !== '' ? '（' . h($maintenanceState['message']) . '）' : '' ?></p>
      <form method="post" action="<?= h(url('/admin/index.php')) ?>" class="inline" data-confirm="<?= h(msg('メンテナンスを終えて、全員が保存などの操作をできるようにします。よろしいですか？')) ?>">
        <?= form_hidden() ?>
        <button type="submit" name="action" value="maintenance_end" class="primary">メンテナンスを終える</button>
      </form>
    <?php else: ?>
      <form method="post" action="<?= h(url('/admin/index.php')) ?>" class="filters maintenance-form" data-confirm="<?= h(msg('メンテナンス中にします。管理者・補佐以外の人は、終わるまで保存などの操作ができません。よろしいですか？')) ?>">
        <?= form_hidden() ?>
        <label for="maintenance_until">終わる日時</label>
        <input id="maintenance_until" name="until" type="datetime-local" value="<?= h(date('Y-m-d\TH:i', time() + 3600)) ?>" required>
        <input name="message" type="text" maxlength="<?= MAINTENANCE_MESSAGE_MAX_LENGTH ?>" placeholder="一言（例: 新しい版に入れ替えています）" aria-label="一言">
        <button type="submit" name="action" value="maintenance_start">メンテナンス中にする</button>
      </form>
      <p class="note">メンテナンス中は、管理者・補佐以外の人の画面に知らせを出し、保存などの操作を止めます。終わる日時を過ぎると自動で終わります。</p>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <div class="admin-card-list">
    <?php foreach ($cardList as $adminCard): ?>
      <a class="admin-card" href="<?= h(url($adminCard['path'])) ?>">
        <span class="admin-card-title"><?= h($adminCard['label']) ?></span>
        <span class="note"><?= h($adminCard['description']) ?></span>
        <span class="admin-card-status">
          <?php foreach ($adminCard['statusList'] as $cardStatus): ?>
            <span class="tag<?= $cardStatus['isAttention'] ? ' tag-open' : '' ?>"><?= h($cardStatus['text']) ?></span>
          <?php endforeach; ?>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
