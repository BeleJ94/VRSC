<?php
/**
 * Journal de caisse quotidien : solde d'ouverture, écritures, solde de clôture, arrêté avec comptage physique.
 * @var array $registers  @var array|null $register  @var string $date  @var array|null $journal  @var bool $canManage  @var array $sites
 */
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="wallet"></i></span>
        <div>
            <h1><?= e(t('finance.cash')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('finance.cash_subtitle')) ?></span></div>
        </div>
    </div>
    <div class="page-header__actions"><button type="button" class="btn" data-print><i data-lucide="printer"></i><?= e(t('stock.print')) ?></button></div>
</div>
<?= $view->partial('finance/_tabs', ['active' => 'cash']) ?>

<?php foreach (['amount', 'closing_date', 'gap_note', 'name', 'site_id'] as $errorField): ?>
    <?php if (field_error($errorField)): ?><p class="error"><?= e(field_error($errorField)) ?></p><?php endif; ?>
<?php endforeach; ?>

<?php if ($registers !== []): ?>
<section class="card">
    <form method="get" action="<?= e(url('/finance/cash')) ?>" class="filters">
        <div class="field">
            <label for="cash-register"><?= e(t('fields.cash_register_id')) ?></label>
            <select id="cash-register" name="register_id" class="input">
                <?php foreach ($registers as $r): ?>
                    <option value="<?= e($r['id']) ?>"<?= $register !== null && (int) $r['id'] === (int) $register['id'] ? ' selected' : '' ?>><?= e($r['site'] . ' – ' . $r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="cash-date"><?= e(t('fields.date')) ?></label>
            <input id="cash-date" type="date" name="date" class="input" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>">
        </div>
        <div class="field"><button type="submit" class="btn"><i data-lucide="search"></i><?= e(t('finance.show_day')) ?></button></div>
    </form>
</section>
<?php endif; ?>

<?php if ($register !== null && $journal !== null): ?>
<div class="kpis kpis--compact">
    <div class="kpi"><div class="kpi__label"><?= e(t('finance.opening')) ?></div><div class="kpi__value"><?= e(format_money($journal['opening'])) ?></div></div>
    <div class="kpi"><div class="kpi__label"><?= e(t('finance.cash_in')) ?></div><div class="kpi__value"><?= e(format_money($journal['total_in'])) ?></div></div>
    <div class="kpi"><div class="kpi__label"><?= e(t('finance.cash_out')) ?></div><div class="kpi__value"><?= e(format_money($journal['total_out'])) ?></div></div>
    <div class="kpi kpi--hero"><div class="kpi__label"><?= e(t('finance.closing')) ?></div><div class="kpi__value"><?= e(format_money($journal['closing'])) ?></div>
        <div class="kpi__hint"><?= $journal['closed'] !== null ? e(t('finance.day_closed', ['name' => $journal['closed']['closed_by_name']])) : e(t('finance.day_open')) ?></div></div>
</div>

<section class="card">
    <div class="card__title"><h2><?= e($register['site'] . ' – ' . $register['name']) ?> · <?= e(format_date($date)) ?></h2></div>
    <?php if ($journal['entries'] === []): ?>
        <p class="empty"><?= e(t('finance.no_entries')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead><tr><th><?= e(t('fields.reference')) ?></th><th><?= e(t('fields.label')) ?></th><th class="num"><?= e(t('finance.cash_in')) ?></th><th class="num"><?= e(t('finance.cash_out')) ?></th><th class="num"><?= e(t('finance.balance')) ?></th><th><?= e(t('approvals.actor')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($journal['entries'] as $entry): ?>
                        <tr>
                            <td class="mono"><?= e($entry['reference']) ?></td>
                            <td><?= e($entry['label']) ?><?php if ($entry['payment_request_id']): ?> <a class="icon-btn" href="<?= e(url('/finance/payments/' . $entry['payment_request_id'])) ?>" title="<?= e($entry['payment_reference']) ?>" aria-label="<?= e($entry['payment_reference']) ?>"><i data-lucide="external-link"></i></a><?php endif; ?></td>
                            <td class="num"><?= $entry['direction'] === 'in' ? e(format_money($entry['amount'])) : '' ?></td>
                            <td class="num"><?= $entry['direction'] === 'out' ? e(format_money($entry['amount'])) : '' ?></td>
                            <td class="num"><strong><?= e(format_money($entry['balance_after'])) ?></strong></td>
                            <td class="small"><?= e($entry['user']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($journal['closed'] !== null): ?>
        <p><?= e(t('finance.counted', ['amount' => format_money($journal['closed']['counted_balance'])])) ?>
            <?php if (abs((float) $journal['closed']['gap']) > 0.005): ?> · <span class="badge badge--breakdown"><?= e(t('finance.gap', ['amount' => format_money($journal['closed']['gap'])])) ?></span> <?= e($journal['closed']['gap_note']) ?><?php endif; ?>
        </p>
    <?php endif; ?>
</section>

<?php if ($canManage && $journal['closed'] === null): ?>
<div class="grid grid--2">
    <?php if ($date === date('Y-m-d')): ?>
    <section class="card">
        <div class="card__title"><h2><?= e(t('finance.manual_entry')) ?></h2></div>
        <form method="post" action="<?= e(url('/finance/cash/' . $register['id'] . '/entries')) ?>" class="form">
            <?= csrf_field() ?>
            <?= $view->partial('partials/field', ['name' => 'direction', 'labelKey' => 'fields.direction', 'type' => 'select', 'required' => true, 'value' => old('direction', 'in'),
                'options' => ['in' => t('finance.entry_in'), 'out' => t('finance.entry_out')]]) ?>
            <?= $view->partial('partials/field', ['name' => 'amount', 'labelKey' => 'fields.amount', 'type' => 'number', 'required' => true, 'value' => old('amount'), 'attrs' => ['min' => '0.01', 'step' => '0.01']]) ?>
            <?= $view->partial('partials/field', ['name' => 'label', 'labelKey' => 'fields.label', 'type' => 'text', 'required' => true, 'value' => old('label'), 'attrs' => ['maxlength' => 255]]) ?>
            <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="plus"></i><?= e(t('finance.add_entry')) ?></button></div>
        </form>
    </section>
    <?php endif; ?>
    <section class="card">
        <div class="card__title"><h2><?= e(t('finance.close_day')) ?></h2></div>
        <form method="post" action="<?= e(url('/finance/cash/' . $register['id'] . '/close')) ?>" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="closing_date" value="<?= e($date) ?>">
            <p class="muted small"><?= e(t('finance.close_help', ['amount' => format_money($journal['closing'])])) ?></p>
            <?= $view->partial('partials/field', ['name' => 'counted_balance', 'labelKey' => 'fields.counted_balance', 'type' => 'number', 'required' => true, 'value' => old('counted_balance', $journal['closing']), 'attrs' => ['min' => '0', 'step' => '0.01']]) ?>
            <?= $view->partial('partials/field', ['name' => 'gap_note', 'labelKey' => 'fields.gap_note', 'type' => 'text', 'value' => old('gap_note'), 'attrs' => ['maxlength' => 255], 'hint' => t('finance.gap_hint')]) ?>
            <div class="form-actions"><button type="submit" class="btn btn--primary"><i data-lucide="lock"></i><?= e(t('finance.close_day')) ?></button></div>
        </form>
    </section>
</div>
<?php endif; ?>
<?php elseif ($registers === []): ?>
    <section class="card"><div class="empty-state"><i data-lucide="wallet"></i><p><?= e(t('finance.cash_none')) ?></p></div></section>
<?php endif; ?>

<?php if ($sites !== []): ?>
<section class="card">
    <div class="card__title"><h2><?= e(t('finance.new_register')) ?></h2></div>
    <form method="post" action="<?= e(url('/finance/cash')) ?>" class="filters">
        <?= csrf_field() ?>
        <?= $view->partial('partials/field', ['name' => 'site_id', 'labelKey' => 'fields.site_id', 'type' => 'select', 'required' => true, 'value' => old('site_id'),
            'options' => (count($sites) === 1 ? [] : ['' => '—']) + array_column($sites, 'name', 'id')]) ?>
        <?= $view->partial('partials/field', ['name' => 'name', 'labelKey' => 'fields.name', 'type' => 'text', 'required' => true, 'value' => old('name', t('finance.default_register')), 'attrs' => ['maxlength' => 80]]) ?>
        <div class="field"><button type="submit" class="btn"><i data-lucide="plus"></i><?= e(t('finance.create_register')) ?></button></div>
    </form>
</section>
<?php endif; ?>
