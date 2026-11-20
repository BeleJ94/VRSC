<?php
/**
 * Vue Finance : engagé, facturé, payé, factures à payer, décaissements à payer, trésorerie ;
 * dépenses par catégorie et par site, caisses, clôtures. Chaque chiffre ouvre son détail ou sa fiche.
 * @var array $dash  @var array $period  @var int|null $siteId
 */
$k = $dash['kpis'];
$q = $siteId !== null ? ['site_id' => $siteId] : [];
$from = $period['from'];
$to = $period['to'];
$months = $dash['trend_months'];
$bounds = static fn (string $m): array => ['from' => $m . '-01', 'to' => date('Y-m-t', (int) strtotime($m . '-01'))];
$kpi = static fn (array $args): string => $view->partial('dashboard/_kpi', $args);
$asAttr = static fn (array $spec): string => ' data-drill="' . e(json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
$commitments = static fn (array $p, string $title): array => drill_spec('drill_commitments', '/dashboard/drill/commitments', $title, $p + $q, '/finance/reports', '{!link}', [], false);
$payments = static fn (array $p, string $title, array $chips = []): array => drill_spec('drill_payments', '/dashboard/drill/payments', $title, $p + $q, '/finance/payments', '{!link}', $chips, false);
$invoices = static fn (array $p, string $title, array $chips = []): array => drill_spec('drill_invoices', '/dashboard/drill/invoices', $title, $p + $q, '/finance/invoices', '{!link}', $chips, false);
$maxCat = max(0.01, ...array_map(static fn (array $c): float => max((float) $c['engaged'], (float) $c['paid']), $dash['categories'] ?: [['engaged' => 0, 'paid' => 0]]));
$maxSite = max(0.01, ...array_map(static fn (array $s): float => max($s['engaged'], $s['paid']), $dash['sites'] ?: [['engaged' => 0, 'paid' => 0]]));
$categoryLabels = ['parts' => 'finance_category.parts', 'consumables' => 'finance_category.consumables', 'fuel' => 'finance_category.fuel', 'tyres' => 'finance_category.tyres',
    'services' => 'finance_category.services', 'materials' => 'finance_category.materials', 'maintenance' => 'finance_category.maintenance', 'other' => 'finance_category.other'];
$closing = $dash['closing'];
$unpaid = $k['unpaid'];
$toPay = $k['to_pay'];
$cashFields = array_map(static fn (array $r): array => [$r['name'], format_money($r['balance']) . ((int) $r['open_days'] > 0 ? ' · ' . t('closings.cash_open_days', ['count' => (int) $r['open_days']]) : ''),
    (int) $r['open_days'] > 0 ? 'warn' : ''], $dash['registers']);
?>
<div class="xkpis">
    <?= $kpi(['label' => t('dashboard.kpi_engaged'), 'value' => e(format_money($k['engaged']['value'])), 'kpi' => $k['engaged'], 'url' => '/finance/reports', 'hint' => t('dashboard.hint_engaged'),
        'drill' => $commitments(['from' => $from, 'to' => $to], t('dashboard.kpi_engaged')), 'months' => $months,
        'monthDrill' => static fn (string $m): array => $commitments($bounds($m), t('dashboard.kpi_engaged') . ' — ' . $m)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_invoiced'), 'value' => e(format_money($k['invoiced']['value'])), 'kpi' => $k['invoiced'], 'url' => '/finance/invoices', 'hint' => t('dashboard.hint_invoiced'),
        'drill' => $invoices(['from' => $from, 'to' => $to], t('dashboard.kpi_invoiced')), 'months' => $months,
        'monthDrill' => static fn (string $m): array => $invoices($bounds($m), t('dashboard.kpi_invoiced') . ' — ' . $m)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_paid'), 'value' => e(format_money($k['paid']['value'])), 'kpi' => $k['paid'], 'url' => '/finance/payments', 'hint' => t('dashboard.hint_paid'),
        'drill' => $payments(['status' => 'paid', 'date_field' => 'paid', 'from' => $from, 'to' => $to], t('dashboard.kpi_paid')), 'months' => $months,
        'monthDrill' => static fn (string $m): array => $payments(['status' => 'paid', 'date_field' => 'paid'] + $bounds($m), t('dashboard.kpi_paid') . ' — ' . $m)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_unpaid'), 'value' => e(format_money($unpaid['value'])), 'kpi' => $unpaid, 'url' => '/finance/invoices',
        'hint' => $unpaid['overdue_count'] > 0 ? t('dashboard.hint_unpaid_overdue', ['count' => $unpaid['overdue_count'], 'amount' => format_money($unpaid['overdue_amount'])]) : t('dashboard.hint_unpaid', ['count' => $unpaid['count']]),
        'tone' => $unpaid['overdue_count'] > 0 ? 'bad' : null,
        'drill' => $invoices(['unpaid' => '1'], t('dashboard.kpi_unpaid'), [['label' => t('common.all'), 'params' => []], ['label' => t('dashboard.chip_overdue'), 'params' => ['overdue' => '1']]])]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_to_pay'), 'value' => e(format_money($toPay['value'])), 'kpi' => $toPay, 'url' => '/finance/payments',
        'hint' => t('dashboard.hint_to_pay', ['count' => $toPay['count'], 'pending' => $toPay['pending_count']]), 'tone' => $toPay['count'] > 0 ? 'warn' : null,
        'drill' => $payments([], t('dashboard.kpi_to_pay'), [['label' => t('dashboard.chip_to_pay'), 'params' => ['status' => 'approved']], ['label' => t('dashboard.chip_in_approval'), 'params' => ['status' => 'pending']]])]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_cash'), 'value' => e(format_money($k['cash']['value'])), 'kpi' => $k['cash'], 'url' => '/finance/cash', 'hint' => t('dashboard.hint_cash', ['count' => $k['cash']['registers']]),
        'peek' => peek(t('dashboard.kpi_cash'), format_money($k['cash']['value']), 'wallet', $cashFields ?: [[t('closings.no_register'), '—']], '/finance/cash', t('dashboard.open_cash'))]) ?>
</div>

<div class="xgrid">
    <section class="card xcard">
        <div class="xcard__head"><h2><?= e(t('dashboard.by_category')) ?></h2><button type="button" class="link-btn small"<?= $asAttr($commitments(['from' => $from, 'to' => $to], t('dashboard.kpi_engaged'))) ?>><?= e(t('common.see_all')) ?></button></div>
        <?php if ($dash['categories'] === []): ?>
            <p class="xempty"><?= e(t('dashboard.no_spend')) ?></p>
        <?php else: ?>
            <div class="xlegend"><span class="xlegend__engaged"><?= e(t('dashboard.legend_engaged')) ?></span><span class="xlegend__paid"><?= e(t('dashboard.legend_paid')) ?></span></div>
            <ul class="xpairs">
                <?php foreach ($dash['categories'] as $c): ?>
                    <?php $label = t($categoryLabels[$c['category']] ?? 'finance_category.other'); ?>
                    <li class="xrow" role="button" tabindex="0"<?= $asAttr($commitments(['from' => $from, 'to' => $to, 'category' => $c['category']], t('dashboard.kpi_engaged') . ' — ' . $label)) ?>>
                        <span class="xpairs__label"><?= e($label) ?></span>
                        <span class="xpairs__bars">
                            <span class="xbar xbar--engaged"><span style="width: <?= e(round((float) $c['engaged'] / $maxCat * 100)) ?>%"></span></span>
                            <span class="xbar xbar--paid"><span style="width: <?= e(round((float) $c['paid'] / $maxCat * 100)) ?>%"></span></span>
                        </span>
                        <span class="xpairs__value"><?= e(format_money($c['engaged'])) ?><br><span class="muted"><?= e(format_money($c['paid'])) ?></span></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card xcard">
        <div class="xcard__head"><h2><?= e(t('dashboard.by_site')) ?></h2></div>
        <?php if ($dash['sites'] === []): ?>
            <p class="xempty"><?= e(t('dashboard.no_spend')) ?></p>
        <?php else: ?>
            <div class="xlegend"><span class="xlegend__engaged"><?= e(t('dashboard.legend_engaged')) ?></span><span class="xlegend__paid"><?= e(t('dashboard.legend_paid')) ?></span></div>
            <ul class="xpairs">
                <?php foreach ($dash['sites'] as $s): ?>
                    <li class="xrow" role="button" tabindex="0"<?= $asAttr(drill_spec('drill_commitments', '/dashboard/drill/commitments', t('dashboard.kpi_engaged') . ' — ' . $s['name'], ['from' => $from, 'to' => $to, 'site_id' => $s['id']], '/finance/reports', '{!link}', [], false)) ?>>
                        <span class="xpairs__label"><?= e($s['name']) ?></span>
                        <span class="xpairs__bars">
                            <span class="xbar xbar--engaged"><span style="width: <?= e(round($s['engaged'] / $maxSite * 100)) ?>%"></span></span>
                            <span class="xbar xbar--paid"><span style="width: <?= e(round($s['paid'] / $maxSite * 100)) ?>%"></span></span>
                        </span>
                        <span class="xpairs__value"><?= e(format_money($s['engaged'])) ?><br><span class="muted"><?= e(format_money($s['paid'])) ?></span></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card xcard">
        <div class="xcard__head"><h2><?= e(t('dashboard.registers')) ?></h2><a class="small" href="<?= e(url('/finance/cash')) ?>"><?= e(t('common.see_all')) ?></a></div>
        <?php if ($dash['registers'] === []): ?>
            <p class="xempty"><?= e(t('closings.no_register')) ?></p>
        <?php else: ?>
            <table class="table table--compact xtable">
                <tbody>
                    <?php foreach ($dash['registers'] as $r): ?>
                        <?php $regPeek = peek($r['name'], $r['site'], 'wallet', [
                            [t('dashboard.balance'), format_money($r['balance'])],
                            [t('dashboard.open_days'), (int) $r['open_days'] > 0 ? t('closings.cash_open_days', ['count' => (int) $r['open_days']]) : t('closings.cash_ok'), (int) $r['open_days'] > 0 ? 'warn' : 'good'],
                        ], '/finance/cash?register_id=' . $r['id'], t('dashboard.open_cash_journal')); ?>
                        <tr class="row-hover xrow"<?= $regPeek ?> role="button" tabindex="0">
                            <td><strong><?= e($r['name']) ?></strong></td>
                            <td><?= (int) $r['open_days'] > 0 ? '<span class="xpill xpill--warn">' . e(t('closings.cash_open_days', ['count' => (int) $r['open_days']])) . '</span>' : '<span class="xpill xpill--good">' . e(t('closings.cash_ok')) . '</span>' ?></td>
                            <td class="num"><strong><?= e(format_money($r['balance'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if ($dash['advances'] > 0.005): ?>
            <a class="xalert xalert--warn" href="<?= e(url('/finance/payments')) ?>"<?= $asAttr($payments(['kind' => 'advance', 'status' => 'paid'], t('dashboard.alert_advances_title'))) ?>><i data-lucide="hand-coins"></i><span><?= e(t('dashboard.alert_advances', ['amount' => format_money($dash['advances'])])) ?></span><i data-lucide="chevron-right" class="xalert__go"></i></a>
        <?php endif; ?>
        <div class="xclosing">
            <div class="xclosing__head"><span><?= e(t('dashboard.closing_title', ['period' => $closing['period']])) ?></span><strong><?= e($closing['done']) ?> / <?= e($closing['expected']) ?></strong></div>
            <span class="xbar xbar--<?= $closing['done'] >= $closing['expected'] ? 'good' : 'warn' ?>"><span style="width: <?= e($closing['expected'] > 0 ? round($closing['done'] / $closing['expected'] * 100) : 0) ?>%"></span></span>
            <a class="small" href="<?= e(url('/closings', ['period' => $closing['period']])) ?>"><?= e(t('dashboard.closing_open')) ?></a>
        </div>
    </section>
</div>
