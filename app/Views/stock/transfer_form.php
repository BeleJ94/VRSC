<?php
/**
 * Demande de transfert intermagasins. La demande n'autorise pas la sortie : elle part en autorisation.
 * @var array $sites  @var \App\Security\SiteScope $ownSites  @var array $parts  @var array $carriers  @var int|null $partId  @var array $availability
 */
$siteOptions = ['' => '—'] + array_column($sites, 'name', 'id');
$partOptions = ['' => '—'];
foreach ($parts as $part) {
    $partOptions[$part['id']] = $part['reference'] . ' – ' . $part['name'] . ' (' . $part['unit'] . ')';
}
$oldLines = array_values(array_filter((array) old('lines', $partId !== null ? [['part_id' => $partId, 'quantity' => '']] : []), 'is_array'));
$rows = array_merge($oldLines, array_fill(0, max(1, 5 - count($oldLines)), ['part_id' => '', 'quantity' => '']));
$defaultTo = $ownSites->isAll() ? null : ($ownSites->siteIds()[0] ?? null);
?>
<div class="page-header"><h1><?= e($title) ?></h1></div>

<?php if ($availability !== []): ?>
    <section class="card">
        <div class="card__title"><h2><?= e(t('stock.availability')) ?></h2></div>
        <ul class="bars">
            <?php $max = max(array_map(static fn (array $a) => (float) $a['available'], $availability)); ?>
            <?php foreach ($availability as $a): ?>
                <li><span class="bars__label"><?= e($a['site']) ?></span><span class="bars__track"><span style="width: <?= e(round((float) $a['available'] / max($max, 0.01) * 100)) ?>%"></span></span><span class="bars__value"><?= e(format_qty($a['available'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<form method="post" action="<?= e(url('/stock/transfers')) ?>" class="card form">
    <?= csrf_field() ?>
    <p class="muted"><?= e(t('stock.transfer_help')) ?></p>
    <div class="form-grid">
        <?= $view->partial('partials/field', ['name' => 'from_site_id', 'labelKey' => 'fields.from_site_id', 'type' => 'select', 'required' => true,
            'value' => old('from_site_id', $availability[0]['site_id'] ?? null), 'options' => $siteOptions]) ?>
        <?= $view->partial('partials/field', ['name' => 'to_site_id', 'labelKey' => 'fields.to_site_id', 'type' => 'select', 'required' => true,
            'value' => old('to_site_id', $defaultTo), 'options' => $siteOptions]) ?>
        <?= $view->partial('partials/field', ['name' => 'reason', 'labelKey' => 'fields.reason', 'type' => 'text', 'required' => true,
            'value' => old('reason'), 'class' => 'span-2', 'attrs' => ['maxlength' => 255]]) ?>
        <?= $view->partial('partials/field', ['name' => 'carrier_id', 'labelKey' => 'fields.carrier_id', 'type' => 'select',
            'value' => old('carrier_id'), 'options' => ['' => '—'] + $carriers]) ?>
    </div>

    <h2 class="section-title"><i data-lucide="list"></i><?= e(t('stock.lines')) ?></h2>
    <?php if (field_error('lines')): ?><p class="error"><?= e(field_error('lines')) ?></p><?php endif; ?>
    <div class="table-wrap">
        <table class="table table--compact">
            <thead><tr><th><?= e(t('stock.article')) ?></th><th class="num"><?= e(t('fields.quantity')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($rows as $i => $row): ?>
                    <tr>
                        <td>
                            <select name="lines[<?= e($i) ?>][part_id]" class="input" aria-label="<?= e(t('stock.article')) ?>">
                                <?php foreach ($partOptions as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= (string) ($row['part_id'] ?? '') === (string) $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="num"><input type="number" name="lines[<?= e($i) ?>][quantity]" class="input" min="0" step="0.01" value="<?= e($row['quantity'] ?? '') ?>" aria-label="<?= e(t('fields.quantity')) ?>"></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/stock/transfers')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="send"></i><?= e(t('stock.transfer_submit')) ?></button>
    </div>
</form>
