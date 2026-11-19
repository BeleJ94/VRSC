<?php
/**
 * Clôtures mensuelles par magasin : une période clôturée est verrouillée, ses soldes sont figés.
 * @var array $periods  @var bool $canClose
 */
$bySite = [];
foreach ($periods as $row) {
    $bySite[$row['site']][] = $row;
}
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="lock"></i></span>
        <div>
            <h1><?= e(t('stock.periods')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('stock.periods_subtitle')) ?></span></div>
        </div>
    </div>
</div>
<?= $view->partial('stock/_tabs', ['active' => 'periods']) ?>
<?php if (field_error('period')): ?><p class="error"><?= e(field_error('period')) ?></p><?php endif; ?>

<?php if ($bySite === []): ?>
    <section class="card"><p class="empty"><?= e(t('common.none')) ?></p></section>
<?php endif; ?>
<?php foreach ($bySite as $site => $rows): ?>
    <section class="card">
        <div class="card__title"><h2><?= e($site) ?></h2></div>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead><tr><th><?= e(t('fields.period')) ?></th><th><?= e(t('fields.status')) ?></th><th><?= e(t('stock.closed_by')) ?></th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?= e($row['period']) ?></strong></td>
                            <td>
                                <?php if ($row['closed_at'] !== null): ?>
                                    <span class="badge badge--neutral"><?= e(t('stock.period_closed')) ?></span>
                                <?php else: ?>
                                    <span class="badge badge--maintenance"><?= e(t('stock.period_open')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $row['closed_at'] !== null ? e($row['closed_by_name']) . ' · ' . e(format_date($row['closed_at'], true)) : '' ?></td>
                            <td class="num">
                                <a class="btn btn--sm" href="<?= e(url('/stock', ['site_id' => $row['site_id'], 'period' => $row['period']])) ?>"><i data-lucide="table"></i><?= e(t('stock.statement')) ?></a>
                                <?php if ($row['closed_at'] === null && $canClose && $currentUser->canAt('stock.close', (int) $row['site_id'])): ?>
                                    <form method="post" action="<?= e(url('/stock/periods')) ?>" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="site_id" value="<?= e($row['site_id']) ?>">
                                        <input type="hidden" name="period" value="<?= e($row['period']) ?>">
                                        <button type="submit" class="btn btn--sm btn--primary"><i data-lucide="lock"></i><?= e(t('stock.close')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>
