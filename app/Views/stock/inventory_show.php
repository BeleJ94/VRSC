<?php
/**
 * Inventaire : stock théorique photographié, quantités comptées, écarts justifiés, approbation.
 * Tant que les écarts ne sont pas approuvés, le stock n'est pas modifié.
 * @var array $inventory  @var array $lines  @var array|null $approval  @var bool $editable
 */
$badge = ['draft' => 'maintenance', 'submitted' => 'waiting_part', 'approved' => 'available', 'rejected' => 'breakdown', 'cancelled' => 'neutral'];
$gapValue = 0.0;
$gapCount = 0;
foreach ($lines as $line) {
    if ($line['gap'] !== null && abs((float) $line['gap']) > 0.0001) {
        $gapCount++;
        $gapValue += (float) ($line['gap_value'] ?? 0);
    }
}
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="clipboard-check"></i></span>
        <div>
            <h1><?= e(t('stock.inventory')) ?> <?= e($inventory['reference']) ?></h1>
            <div class="page-title__meta">
                <span><?= e($inventory['site']) ?> · <?= e(format_date($inventory['counted_on'])) ?> · <?= e($inventory['creator']) ?></span>
                <span class="badge badge--<?= e($badge[$inventory['status']]) ?>"><?= e(t('inventory_status.' . $inventory['status'])) ?></span>
            </div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/stock/inventories')) ?>"><i data-lucide="arrow-left"></i><?= e(t('common.back')) ?></a>
        <button type="button" class="btn" data-print><i data-lucide="printer"></i><?= e(t('stock.print')) ?></button>
    </div>
</div>

<div class="kpis kpis--compact">
    <div class="kpi"><div class="kpi__label"><?= e(t('stock.lines')) ?></div><div class="kpi__value"><?= e(count($lines)) ?></div></div>
    <div class="kpi<?= $gapCount > 0 ? ' kpi--warn' : '' ?>"><div class="kpi__label"><?= e(t('stock.gaps')) ?></div><div class="kpi__value"><?= e($gapCount) ?></div></div>
    <div class="kpi"><div class="kpi__label"><?= e(t('stock.gap_value')) ?></div><div class="kpi__value"><?= e(format_money($gapValue)) ?></div></div>
</div>

<?php if ($inventory['status'] === 'submitted'): ?>
    <p class="muted"><?= e(t('stock.inventory_pending')) ?></p>
<?php elseif ($inventory['status'] === 'rejected'): ?>
    <p class="muted"><?= e(t('stock.inventory_rejected')) ?></p>
<?php endif; ?>

<?php if ($editable): ?><form method="post" action="<?= e(url('/stock/inventories/' . $inventory['id'] . '/counts')) ?>"><?= csrf_field() ?><?php endif; ?>
<section class="card">
    <?php if ($lines === []): ?>
        <p class="empty"><?= e(t('stock.inventory_no_lines')) ?></p>
    <?php else: ?>
        <?php if (field_error('justification') || field_error('quantity')): ?><p class="error"><?= e(field_error('justification') ?? field_error('quantity')) ?></p><?php endif; ?>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead>
                    <tr>
                        <th><?= e(t('stock.article')) ?></th>
                        <th><?= e(t('fields.location')) ?></th>
                        <th class="num"><?= e(t('stock.qty_theoretical')) ?></th>
                        <th class="num"><?= e(t('stock.qty_counted')) ?></th>
                        <th class="num"><?= e(t('stock.gap')) ?></th>
                        <th><?= e(t('fields.justification')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lines as $line): ?>
                        <?php $hasGap = $line['gap'] !== null && abs((float) $line['gap']) > 0.0001; ?>
                        <tr>
                            <td><strong><?= e($line['reference']) ?></strong><br><span class="muted"><?= e($line['name']) ?></span></td>
                            <td><?= e($line['location'] ?? '') ?></td>
                            <td class="num"><?= e(format_qty($line['qty_theoretical'])) ?> <?= e($line['unit']) ?></td>
                            <td class="num">
                                <?php if ($editable): ?>
                                    <input type="number" class="input" name="counted[<?= e($line['part_id']) ?>]" min="0" step="0.01" value="<?= e($line['qty_counted'] !== null ? (float) $line['qty_counted'] : '') ?>" aria-label="<?= e(t('stock.qty_counted')) ?>">
                                <?php else: ?>
                                    <?= $line['qty_counted'] !== null ? e(format_qty($line['qty_counted'])) : '<span class="muted">' . e(t('stock.not_counted')) . '</span>' ?>
                                <?php endif; ?>
                            </td>
                            <td class="num<?= $hasGap ? ' overdue' : '' ?>"><?= $line['gap'] !== null ? e(((float) $line['gap'] > 0 ? '+' : '') . format_qty($line['gap'])) : '—' ?>
                                <?php if ($hasGap && $line['gap_value'] !== null): ?><br><span class="muted small"><?= e(format_money($line['gap_value'])) ?></span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($editable): ?>
                                    <input type="text" class="input" name="justification[<?= e($line['part_id']) ?>]" maxlength="255" value="<?= e($line['justification']) ?>" aria-label="<?= e(t('fields.justification')) ?>">
                                <?php else: ?>
                                    <?= e($line['justification']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($editable): ?>
        <p class="hint small"><?= e(t('stock.inventory_rule')) ?></p>
        <div class="form-actions">
            <button type="submit" class="btn"><i data-lucide="save"></i><?= e(t('stock.inventory_save')) ?></button>
            <button type="submit" name="submit" value="1" class="btn btn--primary"><i data-lucide="send"></i><?= e(t('stock.inventory_submit')) ?></button>
        </div>
    <?php endif; ?>
</section>
<?php if ($editable): ?></form><?php endif; ?>

<?= $view->partial('partials/approval', ['approval' => $approval]) ?>

<?php if ($editable): ?>
    <form method="post" action="<?= e(url('/stock/inventories/' . $inventory['id'] . '/cancel')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--ghost"><i data-lucide="trash-2"></i><?= e(t('stock.inventory_cancel')) ?></button>
    </form>
<?php endif; ?>
