<?php
/**
 * Nouvel état de besoins : site, imputation (service, projet, centre de coûts, engin), urgence, motif, lignes.
 * @var array $sites  @var array $departments  @var array $costCenters  @var array $projects [site => [id => libellé]]
 * @var array $equipment [site => [id => libellé]]  @var array $parts  @var int|null $siteId
 */
use App\Repositories\PurchaseRepository;

$flat = static function (array $bySite): array {
    $out = ['' => '—'];
    foreach ($bySite as $options) {
        $out += $options;
    }
    return $out;
};
$labels = static fn (array $values, string $group): array => array_combine($values, array_map(static fn (string $v) => t($group . '.' . $v), $values));
$partOptions = ['' => t('edb.free_line')];
foreach ($parts as $part) {
    $partOptions[$part['id']] = $part['reference'] . ' – ' . $part['name'] . ' (' . $part['unit'] . ')';
}
$oldLines = array_values(array_filter((array) old('lines', []), 'is_array'));
$rows = array_merge($oldLines, array_fill(0, max(1, 6 - count($oldLines)), []));
$f = static fn (string $name, string $type, array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $extra['default'] ?? null),
]);
?>
<div class="page-header"><h1><?= e($title) ?></h1></div>
<form method="post" action="<?= e(url('/edb')) ?>" class="card form">
    <?= csrf_field() ?>
    <p class="muted"><?= e(t('edb.form_help')) ?></p>
    <div class="form-grid">
        <?= $f('site_id', 'select', ['required' => true, 'default' => $siteId, 'options' => (count($sites) === 1 ? [] : ['' => '—']) + array_column($sites, 'name', 'id')]) ?>
        <?= $f('subject', 'text', ['required' => true, 'attrs' => ['maxlength' => 150]]) ?>
        <?= $f('urgency', 'select', ['required' => true, 'default' => 'normal', 'options' => $labels(PurchaseRepository::URGENCIES, 'urgency')]) ?>
        <?= $f('category', 'select', ['required' => true, 'default' => 'parts', 'options' => $labels(PurchaseRepository::CATEGORIES, 'edb_category')]) ?>
        <?= $f('reason', 'textarea', ['required' => true, 'class' => 'span-2', 'attrs' => ['maxlength' => 500, 'rows' => 2]]) ?>
        <?= $f('department_id', 'select', ['options' => ['' => '—'] + $departments]) ?>
        <?= $f('cost_center_id', 'select', ['options' => ['' => '—'] + $costCenters]) ?>
        <?= $f('project_id', 'select', ['options' => $flat($projects)]) ?>
        <?= $f('equipment_id', 'select', ['options' => $flat($equipment)]) ?>
    </div>

    <h2 class="section-title"><i data-lucide="list"></i><?= e(t('edb.lines')) ?></h2>
    <?php if (field_error('lines')): ?><p class="error"><?= e(field_error('lines')) ?></p><?php endif; ?>
    <div class="table-wrap">
        <table class="table table--compact">
            <thead>
                <tr>
                    <th><?= e(t('stock.article')) ?></th>
                    <th><?= e(t('edb.free_description')) ?></th>
                    <th class="num"><?= e(t('fields.quantity')) ?></th>
                    <th><?= e(t('fields.unit')) ?></th>
                    <th class="num"><?= e(t('edb.est_unit_cost')) ?></th>
                </tr>
            </thead>
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
                        <td><input type="text" name="lines[<?= e($i) ?>][description]" class="input" maxlength="190" value="<?= e($row['description'] ?? '') ?>" aria-label="<?= e(t('edb.free_description')) ?>"></td>
                        <td class="num"><input type="number" name="lines[<?= e($i) ?>][quantity]" class="input" min="0" step="0.01" value="<?= e($row['quantity'] ?? '') ?>" aria-label="<?= e(t('fields.quantity')) ?>"></td>
                        <td><input type="text" name="lines[<?= e($i) ?>][unit]" class="input" maxlength="10" value="<?= e($row['unit'] ?? '') ?>" aria-label="<?= e(t('fields.unit')) ?>"></td>
                        <td class="num"><input type="number" name="lines[<?= e($i) ?>][est_unit_cost]" class="input" min="0" step="0.0001" value="<?= e($row['est_unit_cost'] ?? '') ?>" aria-label="<?= e(t('edb.est_unit_cost')) ?>"></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint small"><?= e(t('edb.lines_hint')) ?></p>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/edb')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="send"></i><?= e(t('edb.submit')) ?></button>
    </div>
</form>
