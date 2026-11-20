<?php
/**
 * Vue « Mes demandes » : états de besoins du compte connecté (en validation, à servir, exécutés, délai d'approbation),
 * avis à donner ; suivi étape par étape des dernières demandes, mes décaissements. Chaque élément ouvre son détail ou sa fiche.
 * @var array $dash  @var array $period  @var int|null $siteId
 */
$k = $dash['kpis'];
$from = $period['from'];
$to = $period['to'];
$months = $dash['trend_months'];
$kpi = static fn (array $args): string => $view->partial('dashboard/_kpi', $args);
$asAttr = static fn (array $spec): string => ' data-drill="' . e(json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
$mine = ['requested_by' => $dash['user_id']];
$edb = static fn (array $p, string $title, array $chips = []): array => drill_spec('purchase_requests', '/edb/data', $title, $p + $mine, '/edb', '/edb/{id}', $chips);
$statusChips = [['label' => t('common.all'), 'params' => []]];
foreach (\App\Repositories\PurchaseRepository::DISPLAY_STATUSES as $st) {
    $statusChips[] = ['label' => t('edb_status.' . $st), 'params' => ['status' => $st]];
}
$urgencyClass = ['critical' => 'bad', 'urgent' => 'warn', 'normal' => ''];
$lead = $k['lead'];
$steps = static function (array $r): array {
    // Émis → validé → servi / commandé → exécuté ; une demande annulée s'arrête là où elle en était.
    $done = 1 + (in_array($r['status'], ['approved', 'executed'], true) ? 1 : 0) + ((int) $r['in_progress'] === 1 || $r['status'] === 'executed' ? 1 : 0) + ($r['status'] === 'executed' ? 1 : 0);

    return ['done' => $done, 'cancelled' => $r['status'] === 'cancelled'];
};
$stepLabels = [t('dashboard.step_issued'), t('dashboard.step_approved'), t('dashboard.step_served'), t('dashboard.step_executed')];
?>
<div class="xkpis">
    <?= $kpi(['label' => t('dashboard.kpi_my_pending'), 'value' => e((string) (int) $k['pending']['value']), 'kpi' => $k['pending'], 'url' => '/edb?mine=1', 'hint' => t('dashboard.hint_my_pending'),
        'drill' => $edb(['status' => 'pending'], t('dashboard.kpi_my_pending'))]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_my_approved'), 'value' => e((string) (int) $k['approved']['value']), 'kpi' => $k['approved'], 'url' => '/edb?mine=1', 'hint' => t('dashboard.hint_my_approved'),
        'drill' => $edb(['status' => 'approved'], t('dashboard.kpi_my_approved'))]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_my_executed'), 'value' => e((string) (int) $k['executed']['value']), 'kpi' => $k['executed'], 'url' => '/edb?mine=1', 'hint' => t('dashboard.hint_my_executed'),
        'drill' => $edb(['status' => 'executed'], t('dashboard.kpi_my_executed')), 'months' => $months,
        'monthDrill' => static fn (string $m): array => $edb(['status' => 'executed', 'report' => 'executed', 'period' => $m], t('dashboard.kpi_my_executed') . ' — ' . $m)]) ?>
    <?= $kpi(['label' => t('dashboard.kpi_my_lead'), 'value' => $lead['empty'] ? '—' : e(format_number($lead['value'], 1)) . '<small>' . e(t('dashboard.days_unit')) . '</small>', 'kpi' => $lead, 'url' => '/edb?mine=1',
        'hint' => t('dashboard.hint_my_lead'), 'drill' => $edb([], t('dashboard.my_requests'), $statusChips)]) ?>
    <?php if ($k['approvals']['allowed']): ?>
        <?= $kpi(['label' => t('dashboard.kpi_my_approvals'), 'value' => e((string) (int) $k['approvals']['value']), 'kpi' => $k['approvals'], 'url' => '/approvals',
            'hint' => t('dashboard.hint_my_approvals'), 'tone' => $k['approvals']['value'] > 0 ? 'warn' : null]) ?>
    <?php endif; ?>
    <?= $kpi(['label' => t('dashboard.kpi_my_cancelled'), 'value' => e((string) (int) $k['cancelled']['value']), 'kpi' => $k['cancelled'], 'url' => '/edb?mine=1', 'hint' => t('dashboard.hint_my_cancelled'),
        'drill' => $edb(['status' => 'cancelled'], t('dashboard.kpi_my_cancelled'))]) ?>
</div>

<div class="xgrid">
    <section class="card xcard xgrid__wide">
        <div class="xcard__head"><h2><?= e(t('dashboard.my_follow_up')) ?></h2>
            <span class="xcard__actions">
                <?php if ($currentUser->can('edb.create')): ?><a class="btn btn--sm btn--primary" href="<?= e(url('/edb/create')) ?>"><i data-lucide="plus"></i><?= e(t('nav.new_edb')) ?></a><?php endif; ?>
                <button type="button" class="link-btn small"<?= $asAttr($edb([], t('dashboard.my_requests'), $statusChips)) ?>><?= e(t('common.see_all')) ?></button>
            </span></div>
        <?php if ($dash['recent'] === []): ?>
            <p class="xempty"><?= e(t('dashboard.no_my_request')) ?></p>
        <?php else: ?>
            <ul class="xtrack">
                <?php foreach ($dash['recent'] as $r): ?>
                    <?php $st = $steps($r); ?>
                    <li class="xrow" role="button" tabindex="0"<?= peek($r['reference'], $r['subject'], 'file-pen-line', [
                        [t('fields.status'), t('edb_status.' . $r['status']), $r['status'] === 'cancelled' ? 'bad' : ($r['status'] === 'executed' ? 'good' : 'warn')],
                        [t('fields.site_id'), $r['site']],
                        [t('dashboard.requested_on'), format_date($r['requested_at'])],
                        [t('dashboard.waiting_for'), $r['waiting_step'] ?? '—'],
                    ], '/edb/' . $r['id'], t('dashboard.open_edb')) ?>>
                        <span class="xtrack__head"><strong><?= e($r['reference']) ?></strong><span class="muted small"><?= e($r['subject']) ?></span></span>
                        <ol class="xsteps<?= $st['cancelled'] ? ' xsteps--cancelled' : '' ?>" aria-label="<?= e(t('dashboard.progress')) ?>">
                            <?php foreach ($stepLabels as $i => $label): ?>
                                <li class="<?= $i < $st['done'] ? 'is-done' : ($i === $st['done'] && !$st['cancelled'] ? 'is-current' : '') ?>"><span><?= e($label) ?></span></li>
                            <?php endforeach; ?>
                        </ol>
                        <span class="muted small xtrack__when"><?= $st['cancelled'] ? e(t('edb_status.cancelled')) : ($r['waiting_step'] ? e($r['waiting_step']) : e(format_date($r['requested_at']))) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if ($dash['payments'] !== null): ?>
    <section class="card xcard xgrid__tall">
        <div class="xcard__head"><h2><?= e(t('dashboard.my_payments')) ?></h2>
            <button type="button" class="link-btn small"<?= $asAttr(drill_spec('drill_payments', '/dashboard/drill/my-payments', t('dashboard.my_payments'), [], '/finance/payments', '{!link}', [], false)) ?>><?= e(t('common.see_all')) ?></button></div>
        <?php if ($dash['payments'] === []): ?>
            <p class="xempty"><?= e(t('dashboard.no_my_payment')) ?></p>
        <?php else: ?>
            <ul class="xlist">
                <?php foreach ($dash['payments'] as $p): ?>
                    <?php $tone = ['paid' => 'good', 'approved' => 'warn', 'rejected' => 'bad', 'cancelled' => ''][$p['status']] ?? ''; ?>
                    <li class="xrow" role="button" tabindex="0"<?= peek($p['reference'], $p['party'], 'banknote', [
                        [t('js.drill.amount'), format_money($p['amount'])],
                        [t('fields.status'), t('payment_status.' . $p['status']), $tone],
                        [t('dashboard.requested_on'), format_date($p['requested_at'])],
                        [t('js.drill.kind'), t('payment_kind.' . $p['kind'])],
                    ], '/finance/payments/' . $p['id'], t('dashboard.open_payment')) ?>>
                        <span class="xlist__main"><strong><?= e($p['reference']) ?></strong><span class="muted small"><?= e($p['party']) ?></span></span>
                        <span class="xlist__side"><strong><?= e(format_money($p['amount'])) ?></strong><span class="xpill xpill--<?= e($tone) ?>"><?= e(t('payment_status.' . $p['status'])) ?></span></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
