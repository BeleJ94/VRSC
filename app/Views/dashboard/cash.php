<?php
/**
 * Vue Caisse : trésorerie, décaissements à payer, payés sur la période, mouvements du jour, journées à arrêter, écarts ;
 * caisses (solde, mouvements du jour), décaissements à payer, derniers paiements. Chaque élément ouvre son détail ou sa fiche.
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
$payments = static fn (array $p, string $title, array $chips = []): array => drill_spec('drill_payments', '/dashboard/drill/payments', $title, $p + $q, '/finance/payments', '{!link}', $chips, false);
$registerFields = static fn (array $r): array => [
    [t('dashboard.balance'), format_money($r['balance'])],
    [t('dashboard.today_in'), format_money($r['today_in'])],
    [t('dashboard.today_out'), format_money($r['today_out'])],
    [t('dashboard.open_days'), (int) $r['open_days'] > 0 ? t('closings.cash_open_days', ['count' => (int) $r['open_days']]) : t('closings.cash_ok'), (int) $r['open_days'] > 0 ? 'warn' : 'good'],
];
$paymentPeek = static fn (array $p): string => peek($p['reference'], $p['party'], 'banknote', [
    [t('js.drill.amount'), format_money($p['amount'])],
    [t('fields.site_id'), $p['site']],
    [t('fields.status'), t('payment_status.' . $p['status']), $p['status'] === 'approved' ? 'warn' : ($p['status'] === 'paid' ? 'good' : '')],
    [t('js.drill.kind'), t('payment_kind.' . $p['kind'])],
], '/finance/payments/' . $p['id'], $p['status'] === 'approved' ? t('dashboard.open_to_pay') : t('dashboard.open_payment'));
?>
<div class="xkpis">
    <?= $kpi(['label' => t('dashboard.kpi_cash'), 'value' => e(format_money($k['cash']['value'])), 'kpi' => $k['cash'], 'url' => '/finance/cash', 'hint' => t('dashboard.hint_cash', ['count' => $k['cash']['registers']]),
        'peek' => peek(t('dashboard.kpi_cash'), format_money($k['cash']['value']), 'wallet',
            array_map(static fn (array $r): array => [$r['name'], format_money($r['balance'])], $dash['registers']) ?: [[t('closings.no_register'), '—']], '/finance/cash', t('dashboard.open_cash'))]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_to_pay'), 'value' => e(format_money($k['to_pay']['value'])), 'kpi' => $k['to_pay'], 'url' => '/finance/payments',
        'hint' => t('dashboard.hint_to_pay_count', ['count' => $k['to_pay']['count']]), 'tone' => $k['to_pay']['count'] > 0 ? 'warn' : null,
        'drill' => $payments(['status' => 'approved'], t('dashboard.todo_payments_title'))]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_paid'), 'value' => e(format_money($k['paid']['value'])), 'kpi' => $k['paid'], 'url' => '/finance/payments', 'hint' => t('dashboard.hint_paid'),
        'drill' => $payments(['status' => 'paid', 'date_field' => 'paid', 'from' => $from, 'to' => $to], t('dashboard.kpi_paid')), 'months' => $months,
        'monthDrill' => static fn (string $m): array => $payments(['status' => 'paid', 'date_field' => 'paid'] + $bounds($m), t('dashboard.kpi_paid') . ' — ' . $m)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_today_out'), 'value' => e(format_money($k['today_out']['value'])), 'kpi' => $k['today_out'], 'url' => '/finance/cash',
        'hint' => t('dashboard.hint_today_in', ['amount' => format_money($k['today_out']['in'])]),
        'drill' => $payments(['status' => 'paid', 'date_field' => 'paid', 'from' => date('Y-m-d'), 'to' => date('Y-m-d')], t('dashboard.kpi_today_out'))]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_open_days'), 'value' => e((string) (int) $k['open_days']['value']), 'kpi' => $k['open_days'], 'url' => '/closings',
        'hint' => t('dashboard.hint_open_days'), 'tone' => $k['open_days']['value'] > 0 ? 'warn' : null]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_gaps'), 'value' => e(format_money($k['gaps']['value'])), 'kpi' => $k['gaps'], 'url' => '/finance/cash',
        'hint' => t('dashboard.hint_gaps', ['count' => $k['gaps']['days']]), 'tone' => abs($k['gaps']['value']) > 0.005 ? 'warn' : null]) ?>
</div>

<div class="xgrid">
    <section class="card xcard xgrid__wide">
        <div class="xcard__head"><h2><?= e(t('dashboard.registers')) ?></h2><a class="small" href="<?= e(url('/finance/cash')) ?>"><?= e(t('common.see_all')) ?></a></div>
        <?php if ($dash['registers'] === []): ?>
            <p class="xempty"><?= e(t('closings.no_register')) ?></p>
        <?php else: ?>
            <div class="xregs">
                <?php foreach ($dash['registers'] as $r): ?>
                    <div class="xreg xrow" role="button" tabindex="0"<?= peek($r['name'], $r['site'], 'wallet', $registerFields($r), '/finance/cash?register_id=' . $r['id'], t('dashboard.open_cash_journal')) ?>>
                        <span class="xreg__name"><?= e($r['name']) ?></span>
                        <strong class="xreg__balance"><?= e(format_money($r['balance'])) ?></strong>
                        <span class="xreg__flows"><span class="xreg__in">+ <?= e(format_money($r['today_in'])) ?></span><span class="xreg__out">− <?= e(format_money($r['today_out'])) ?></span></span>
                        <?= (int) $r['open_days'] > 0 ? '<span class="xpill xpill--warn">' . e(t('closings.cash_open_days', ['count' => (int) $r['open_days']])) . '</span>' : '<span class="xpill xpill--good">' . e(t('closings.cash_ok')) . '</span>' ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card xcard xgrid__tall">
        <div class="xcard__head"><h2><?= e(t('dashboard.todo_payments_title')) ?></h2>
            <button type="button" class="link-btn small"<?= $asAttr($payments(['status' => 'approved'], t('dashboard.todo_payments_title'))) ?>><?= e(t('common.see_all')) ?></button></div>
        <?php if ($dash['to_pay'] === []): ?>
            <p class="xempty"><i data-lucide="check-circle-2"></i><?= e(t('dashboard.nothing_to_pay')) ?></p>
        <?php else: ?>
            <ul class="xlist">
                <?php foreach ($dash['to_pay'] as $p): ?>
                    <li class="xrow" role="button" tabindex="0"<?= $paymentPeek($p) ?>>
                        <span class="xlist__main"><strong><?= e($p['reference']) ?></strong><span class="muted small"><?= e($p['party']) ?> · <?= e($p['site']) ?></span></span>
                        <span class="xlist__side"><strong><?= e(format_money($p['amount'])) ?></strong><span class="muted small"><?= e(format_date($p['approved_at'])) ?></span></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card xcard xgrid__wide">
        <div class="xcard__head"><h2><?= e(t('dashboard.last_payments')) ?></h2>
            <button type="button" class="link-btn small"<?= $asAttr($payments(['status' => 'paid', 'date_field' => 'paid', 'from' => $from, 'to' => $to], t('dashboard.kpi_paid'))) ?>><?= e(t('common.see_all')) ?></button></div>
        <?php if ($dash['paid_list'] === []): ?>
            <p class="xempty"><?= e(t('dashboard.no_payment')) ?></p>
        <?php else: ?>
            <ul class="xlist xlist--grid">
                <?php foreach ($dash['paid_list'] as $p): ?>
                    <li class="xrow" role="button" tabindex="0"<?= $paymentPeek($p) ?>>
                        <span class="xlist__main"><strong><?= e($p['reference']) ?></strong><span class="muted small"><?= e($p['party']) ?> · <?= e(format_date($p['paid_at'])) ?></span></span>
                        <strong><?= e(format_money($p['amount'])) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
