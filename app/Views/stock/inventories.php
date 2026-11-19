<?php
/**
 * Inventaires physiques : ouverture d'un inventaire par magasin et suivi (comptage, soumis, approuvé, refusé).
 * @var array $inventories  @var array $sites sites où l'utilisateur tient l'inventaire
 */
$badge = ['draft' => 'maintenance', 'submitted' => 'waiting_part', 'approved' => 'available', 'rejected' => 'breakdown', 'cancelled' => 'neutral'];
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="clipboard-check"></i></span>
        <div>
            <h1><?= e(t('stock.inventories')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('stock.inventories_subtitle')) ?></span></div>
        </div>
    </div>
</div>
<?= $view->partial('stock/_tabs', ['active' => 'inventories']) ?>

<?php if ($sites !== []): ?>
    <section class="card">
        <div class="card__title"><h2><?= e(t('stock.inventory_new')) ?></h2></div>
        <form method="post" action="<?= e(url('/stock/inventories')) ?>" class="filters">
            <?= csrf_field() ?>
            <?= $view->partial('partials/field', ['name' => 'site_id', 'labelKey' => 'fields.site_id', 'type' => 'select', 'required' => true,
                'value' => old('site_id', count($sites) === 1 ? $sites[0]['id'] : null), 'options' => (count($sites) === 1 ? [] : ['' => '—']) + array_column($sites, 'name', 'id')]) ?>
            <?= $view->partial('partials/field', ['name' => 'counted_on', 'labelKey' => 'fields.counted_on', 'type' => 'date',
                'value' => old('counted_on', date('Y-m-d')), 'attrs' => ['max' => date('Y-m-d')]]) ?>
            <?= $view->partial('partials/field', ['name' => 'note', 'labelKey' => 'fields.note', 'type' => 'text', 'value' => old('note'), 'attrs' => ['maxlength' => 255]]) ?>
            <div class="field"><button type="submit" class="btn btn--primary"><i data-lucide="plus"></i><?= e(t('stock.inventory_open')) ?></button></div>
        </form>
    </section>
<?php endif; ?>

<section class="card">
    <?php if ($inventories === []): ?>
        <div class="empty-state"><i data-lucide="clipboard-check"></i><p><?= e(t('stock.inventories_none')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= e(t('fields.reference')) ?></th>
                        <th><?= e(t('fields.site_id')) ?></th>
                        <th><?= e(t('fields.counted_on')) ?></th>
                        <th><?= e(t('stock.opened_by')) ?></th>
                        <th class="num"><?= e(t('stock.lines')) ?></th>
                        <th class="num"><?= e(t('stock.gaps')) ?></th>
                        <th><?= e(t('fields.status')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventories as $inv): ?>
                        <tr>
                            <td><a href="<?= e(url('/stock/inventories/' . $inv['id'])) ?>"><strong><?= e($inv['reference']) ?></strong></a></td>
                            <td><?= e($inv['site']) ?></td>
                            <td class="nowrap"><?= e(format_date($inv['counted_on'])) ?></td>
                            <td><?= e($inv['creator']) ?></td>
                            <td class="num"><?= e($inv['line_count']) ?></td>
                            <td class="num<?= (int) $inv['gap_count'] > 0 ? ' overdue' : '' ?>"><?= e($inv['gap_count']) ?></td>
                            <td><span class="badge badge--<?= e($badge[$inv['status']]) ?>"><?= e(t('inventory_status.' . $inv['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
