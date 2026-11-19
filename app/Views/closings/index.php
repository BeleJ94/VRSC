<?php
/**
 * Clôtures du mois par site, dans l'ordre conseillé : stock (fige les sorties valorisées), puis coûts par équipement
 * (qui en dépendent), et arrêtés de caisse (journaliers).
 * @var string $period  @var bool $ended  @var array $rows
 */
$prev = date('Y-m', (int) strtotime($period . '-01 -1 month'));
$next = date('Y-m', (int) strtotime($period . '-01 +1 month'));
$done = static fn (?array $closed): string => '<span class="badge badge--available">' . e(t('closings.done')) . '</span><br><span class="muted small">'
    . e(t('costs.closed_by', ['name' => $closed['closed_by_name'] ?? '—', 'date' => format_date($closed['closed_at'])])) . '</span>';
$total = 0;
$closedCount = 0;
foreach ($rows as $row) {
    $total += 2;
    $closedCount += ($row['stock']['closed'] !== null ? 1 : 0) + ($row['costs']['closed'] !== null ? 1 : 0);
}
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="lock"></i></span>
        <div>
            <h1><?= e(t('nav.closings')) ?> — <?= e($period) ?></h1>
            <div class="page-title__meta"><span><?= e(t('closings.subtitle')) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url('/closings', ['period' => $prev])) ?>"><i data-lucide="chevron-left"></i><?= e($prev) ?></a>
        <?php if ($next <= date('Y-m')): ?><a class="btn" href="<?= e(url('/closings', ['period' => $next])) ?>"><?= e($next) ?><i data-lucide="chevron-right"></i></a><?php endif; ?>
    </div>
</div>

<?php foreach (['period', 'note'] as $errorField): ?>
    <?php if (field_error($errorField)): ?><div class="alert alert--error"><?= e(field_error($errorField)) ?></div><?php endif; ?>
<?php endforeach; ?>
<?php if (!$ended): ?>
    <div class="alert alert--info"><i data-lucide="info"></i><span><?= e(t('closings.not_ended')) ?></span></div>
<?php endif; ?>

<div class="kpis kpis--compact">
    <div class="kpi<?= $ended && $closedCount < $total ? ' kpi--warn' : '' ?>">
        <div class="kpi__label"><?= e(t('closings.progress')) ?></div>
        <div class="kpi__value"><?= e($closedCount) ?> / <?= e($total) ?></div>
        <div class="kpi__hint"><?= e(t('closings.progress_hint')) ?></div>
    </div>
</div>

<section class="card">
    <?php if ($rows === []): ?>
        <div class="empty-state"><i data-lucide="building-2"></i><p><?= e(t('closings.no_site')) ?></p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th><?= e(t('fields.site_id')) ?></th>
                    <th>1. <?= e(t('closings.stock')) ?></th>
                    <th>2. <?= e(t('closings.costs')) ?></th>
                    <th><?= e(t('closings.cash')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $siteId = $row['site_id'];
                    $stock = $row['stock'];
                    $blockers = [];
                    if ($stock['earlier_open'] !== null) { $blockers[] = t('closings.earlier_open', ['period' => $stock['earlier_open']]); }
                    if ($stock['open_transfers'] > 0) { $blockers[] = t('closings.open_transfers', ['count' => $stock['open_transfers']]); }
                    if ($stock['open_inventories'] > 0) { $blockers[] = t('closings.open_inventories', ['count' => $stock['open_inventories']]); }
                    ?>
                    <tr>
                        <td><strong><?= e($row['site']) ?></strong></td>
                        <td>
                            <?php if ($stock['closed'] !== null): ?>
                                <?= $done($stock['closed']) ?>
                            <?php else: ?>
                                <span class="badge badge--waiting_part"><?= e(t('closings.open')) ?></span>
                                <?php foreach ($blockers as $blocker): ?><br><span class="small"><?= e($blocker) ?></span><?php endforeach; ?>
                                <?php if ($ended && $blockers === [] && $currentUser->canAt('stock.close', $siteId)): ?>
                                    <form method="post" action="<?= e(url('/stock/periods')) ?>" data-confirm="<?= e(t('closings.confirm_stock', ['site' => $row['site'], 'period' => $period])) ?>" class="closing-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="site_id" value="<?= e($siteId) ?>">
                                        <input type="hidden" name="period" value="<?= e($period) ?>">
                                        <input type="hidden" name="return" value="closings">
                                        <button type="submit" class="btn btn--sm btn--primary"><i data-lucide="lock"></i><?= e(t('closings.close_stock')) ?></button>
                                    </form>
                                <?php endif; ?>
                                <br><a class="small" href="<?= e(url('/stock', ['site_id' => $siteId, 'period' => $period])) ?>"><?= e(t('closings.see_stock')) ?></a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['costs']['closed'] !== null): ?>
                                <?= $done($row['costs']['closed']) ?>
                            <?php else: ?>
                                <span class="badge badge--waiting_part"><?= e(t('closings.open')) ?></span>
                                <?php if ($stock['closed'] === null): ?><br><span class="small muted"><?= e(t('closings.costs_after_stock')) ?></span><?php endif; ?>
                                <?php if ($ended && $currentUser->canAt('costs.close', $siteId)): ?>
                                    <form method="post" action="<?= e(url('/costs/close')) ?>" data-confirm="<?= e(t('closings.confirm_costs', ['site' => $row['site'], 'period' => $period])) ?>" class="closing-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="site_id" value="<?= e($siteId) ?>">
                                        <input type="hidden" name="period" value="<?= e($period) ?>">
                                        <input type="hidden" name="return" value="closings">
                                        <button type="submit" class="btn btn--sm btn--primary"><i data-lucide="lock"></i><?= e(t('closings.close_costs')) ?></button>
                                    </form>
                                <?php endif; ?>
                                <br><a class="small" href="<?= e(url('/costs', ['site_id' => $siteId, 'period' => $period])) ?>"><?= e(t('closings.see_costs')) ?></a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['registers'] === []): ?>
                                <span class="muted small"><?= e(t('closings.no_register')) ?></span>
                            <?php endif; ?>
                            <?php foreach ($row['registers'] as $register): ?>
                                <div class="closing-register">
                                    <strong><?= e($register['name']) ?></strong>
                                    <?php if ((int) $register['open_days'] === 0): ?>
                                        <span class="badge badge--available"><?= e(t('closings.cash_ok')) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge--waiting_part"><?= e(t('closings.cash_open_days', ['count' => (int) $register['open_days']])) ?></span>
                                    <?php endif; ?>
                                    <?php if (abs((float) $register['month_gap']) > 0.005): ?><span class="badge badge--breakdown"><?= e(t('closings.cash_gap', ['amount' => format_money($register['month_gap'])])) ?></span><?php endif; ?>
                                    <br><span class="muted small"><?= e($register['last_closing'] ? t('closings.last_closing', ['date' => format_date($register['last_closing'])]) : t('closings.never_closed')) ?></span>
                                    <?php if ($register['first_open_day'] !== null && $currentUser->canAt('cash.manage', $siteId)): ?>
                                        <br><a class="small" href="<?= e(url('/finance/cash', ['register_id' => $register['id'], 'date' => $register['first_open_day']])) ?>"><?= e(t('closings.close_day', ['date' => format_date($register['first_open_day'])])) ?></a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <p class="hint small"><?= e(t('closings.rule')) ?></p>
</section>
