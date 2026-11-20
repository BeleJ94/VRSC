<?php
/**
 * Opérations du préventif : chaque ligne est validée OK, Anomalie (observation obligatoire) ou N/A.
 * @var array $wo  @var array $checks  @var bool $canExecute  @var string $back
 */
if ($checks === []) {
    return;
}
$treated = count(array_filter($checks, static fn (array $c): bool => $c['status'] !== 'todo'));
$anomalies = count(array_filter($checks, static fn (array $c): bool => $c['status'] === 'nok'));
?>
<section class="card" id="checklist">
    <div class="card__title">
        <h2><?= e(t('work_orders.checklist')) ?></h2>
        <span class="badge<?= $treated === count($checks) ? ' badge--available' : '' ?>"><?= e(t('work_orders.checklist_progress', ['done' => $treated, 'total' => count($checks)])) ?></span>
        <?php if ($anomalies > 0): ?><span class="badge badge--breakdown"><?= e(t('work_orders.checklist_anomalies', ['count' => $anomalies])) ?></span><?php endif; ?>
    </div>
    <ol class="wochk">
        <?php foreach ($checks as $c): ?>
            <li class="wochk__item is-<?= e($c['status']) ?>">
                <div class="wochk__label">
                    <span class="wochk__num"><?= e($c['position']) ?></span>
                    <div>
                        <strong><?= e($c['label']) ?></strong>
                        <?php if ($c['status'] !== 'todo'): ?>
                            <div class="muted small"><?= e(t('check_status.' . $c['status'])) ?><?= $c['note'] ? ' · ' . e($c['note']) : '' ?><?= $c['checked_by_name'] ? ' · ' . e($c['checked_by_name']) . ', ' . e(format_date($c['checked_at'], true)) : '' ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($canExecute): ?>
                    <form method="post" action="<?= e(url('/work-orders/' . $wo['id'] . '/checks/' . $c['id'])) ?>" class="wochk__form" data-confirm-kind="apply" data-confirm-title="<?= e($c['label']) ?>">
                        <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                        <input type="text" name="note" class="input input--sm" maxlength="255" value="<?= e($c['note'] ?? '') ?>" placeholder="<?= e(t('work_orders.check_note_placeholder')) ?>" aria-label="<?= e(t('work_orders.check_note')) ?>">
                        <div class="wochk__choices" role="group" aria-label="<?= e($c['label']) ?>">
                            <?php foreach (['ok' => 'check', 'nok' => 'triangle-alert', 'na' => 'minus'] as $s => $icon): ?>
                                <button type="submit" name="status" value="<?= e($s) ?>" class="btn btn--sm wochk__btn wochk__btn--<?= e($s) ?><?= $c['status'] === $s ? ' is-active' : '' ?>" aria-pressed="<?= $c['status'] === $s ? 'true' : 'false' ?>"><i data-lucide="<?= e($icon) ?>"></i><?= e(t('check_status.' . $s)) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
