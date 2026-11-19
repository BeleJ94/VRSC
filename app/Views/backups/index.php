<?php
/**
 * Sauvegardes : état de santé, journal, lancement manuel, test de restauration.
 * @var array $backups  @var array $health  @var array $settings
 */
$size = static function (?int $bytes): string {
    if ($bytes === null) {
        return '—';
    }

    return $bytes >= 1048576 ? format_number($bytes / 1048576, 1) . ' Mo' : format_number(max(1, $bytes / 1024), 0) . ' Ko';
};
$originLabels = ['manual' => 'backup_origin.manual', 'scheduled' => 'backup_origin.scheduled', 'pre_restore' => 'backup_origin.pre_restore'];
$lastOk = $health['last_ok'];
$lastVerified = $health['last_verified'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="database-backup"></i></span>
        <div>
            <h1><?= e(t('nav.backups')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('backups.subtitle')) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions">
        <form method="post" action="<?= e(url('/backups')) ?>" data-confirm="<?= e(t('backups.create_confirm')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('backups.create')) ?></button>
        </form>
    </div>
</div>

<?php if (field_error('backup')): ?><div class="alert alert--error"><?= e(field_error('backup')) ?></div><?php endif; ?>
<?php if ($health['stale']): ?>
    <div class="alert alert--warning"><i data-lucide="alert-triangle"></i><span><?= e($lastOk === null ? t('backups.none_yet') : t('backups.stale', ['hours' => $health['max_age_hours']])) ?></span></div>
<?php endif; ?>
<?php if ($health['last_failed'] !== null): ?>
    <div class="alert alert--error"><i data-lucide="x-octagon"></i><span><?= e(t('backups.last_failed', ['date' => format_date($health['last_failed']['created_at'], true)])) ?> <?= e($health['last_failed']['error']) ?></span></div>
<?php endif; ?>

<div class="kpis kpis--compact">
    <div class="kpi<?= $health['stale'] ? ' kpi--alert' : '' ?>">
        <div class="kpi__label"><?= e(t('backups.last_ok')) ?></div>
        <div class="kpi__value" style="font-size:1.1rem"><?= $lastOk !== null ? e(format_date($lastOk['created_at'], true)) : '—' ?></div>
        <div class="kpi__hint"><?= $lastOk !== null ? e($size((int) $lastOk['size_bytes'])) : e(t('backups.none_yet')) ?></div>
    </div>
    <div class="kpi<?= $lastVerified === null ? ' kpi--alert' : '' ?>">
        <div class="kpi__label"><?= e(t('backups.last_verified')) ?></div>
        <div class="kpi__value" style="font-size:1.1rem"><?= $lastVerified !== null ? e(format_date($lastVerified['verified_at'], true)) : '—' ?></div>
        <div class="kpi__hint"><?= e($lastVerified !== null ? t('backups.verified_hint') : t('backups.never_verified')) ?></div>
    </div>
    <div class="kpi">
        <div class="kpi__label"><?= e(t('backups.retention')) ?></div>
        <div class="kpi__value" style="font-size:1.1rem"><?= e(t('backups.retention_days', ['days' => $settings['retention_days']])) ?></div>
        <div class="kpi__hint"><?= e($settings['copy_dir'] !== null ? t('backups.copy_on', ['dir' => $settings['copy_dir']]) : t('backups.copy_off')) ?></div>
    </div>
</div>

<section class="card">
    <?php if ($backups === []): ?>
        <div class="empty-state"><i data-lucide="database-backup"></i><p><?= e(t('backups.none_yet')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= e(t('fields.date')) ?></th>
                        <th><?= e(t('backups.origin')) ?></th>
                        <th><?= e(t('backups.content')) ?></th>
                        <th><?= e(t('fields.status')) ?></th>
                        <th><?= e(t('backups.restore_test')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <?php $available = $backup['status'] === 'ok' && $backup['pruned_at'] === null; ?>
                        <tr>
                            <td class="nowrap"><?= e(format_date($backup['created_at'], true)) ?><br><span class="muted small mono"><?= e($backup['file_name']) ?></span></td>
                            <td><?= e(t($originLabels[$backup['origin']])) ?><?= $backup['author'] ? '<br><span class="muted small">' . e($backup['author']) . '</span>' : '' ?></td>
                            <td>
                                <?php if ($backup['status'] === 'ok'): ?>
                                    <?= e(t('backups.content_detail', ['tables' => (int) $backup['table_count'], 'rows' => format_number($backup['row_count']), 'files' => (int) $backup['file_count']])) ?>
                                    <br><span class="muted small"><?= e($size((int) $backup['size_bytes'])) ?><?= (int) $backup['copied'] === 1 ? ' · ' . e(t('backups.copied')) : '' ?></span>
                                <?php else: ?>
                                    <span class="small"><?= e($backup['error']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($backup['status'] !== 'ok'): ?><span class="badge badge--breakdown"><?= e(t('backups.failed')) ?></span>
                                <?php elseif ($backup['pruned_at'] !== null): ?><span class="badge badge--neutral"><?= e(t('backups.pruned')) ?></span>
                                <?php else: ?><span class="badge badge--available"><?= e(t('backups.ok')) ?></span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($backup['verify_status'] === 'ok'): ?>
                                    <span class="badge badge--available"><?= e(t('backups.verify_passed')) ?></span>
                                <?php elseif ($backup['verify_status'] === 'failed'): ?>
                                    <span class="badge badge--breakdown"><?= e(t('backups.verify_failed')) ?></span>
                                <?php elseif ($backup['status'] === 'ok'): ?>
                                    <span class="badge badge--waiting_part"><?= e(t('backups.not_verified')) ?></span>
                                <?php endif; ?>
                                <?php if ($backup['verified_at']): ?>
                                    <br><span class="muted small"><?= e(format_date($backup['verified_at'], true)) ?><?= $backup['verifier'] ? ' · ' . e($backup['verifier']) : '' ?></span>
                                    <br><span class="small"><?= e($backup['verify_detail']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap" style="text-align:right">
                                <?php if ($available): ?>
                                    <form method="post" action="<?= e(url('/backups/' . $backup['id'] . '/verify')) ?>" style="display:inline" data-confirm="<?= e(t('backups.verify_confirm')) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn--sm"><i data-lucide="shield-check"></i><?= e(t('backups.verify')) ?></button>
                                    </form>
                                    <a class="icon-btn" href="<?= e(url('/backups/' . $backup['id'] . '/download')) ?>" title="<?= e(t('backups.download')) ?>" aria-label="<?= e(t('backups.download')) ?>"><i data-lucide="download"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <p class="hint small"><?= e(t('backups.rule', ['dir' => $settings['dir']])) ?></p>
</section>
