<?php
/**
 * Carte d'indicateur compacte : libellé, valeur, variation vs période précédente (couleur selon le sens favorable),
 * mini-courbe sur 6 mois, ligne d'aide ; toute la carte mène au détail.
 * @var string $label  @var string $value  @var array $kpi  @var string $url  @var string|null $hint  @var string|null $tone good|warn|bad
 */
$hint ??= null;
$tone ??= null;
$delta = $kpi['delta'];
$zero = abs((float) $kpi['value']) < 0.005;
$trendClass = 'flat';
if ($delta !== null && abs($delta) >= 0.5) {
    $up = $delta > 0;
    $trendClass = $kpi['better'] === 'neutral' ? 'neutral' : (($up && $kpi['better'] === 'up') || (!$up && $kpi['better'] === 'down') ? 'good' : 'bad');
}
$points = '';
$series = $kpi['series'] ?? [];
if (count($series) > 1 && max($series) > 0) {
    $max = max($series);
    $min = min($series);
    $range = max(0.0001, $max - $min);
    $step = 100 / (count($series) - 1);
    foreach ($series as $i => $v) {
        $points .= round($i * $step, 1) . ',' . round(26 - (($v - $min) / $range) * 22, 1) . ' ';
    }
}
?>
<a class="xkpi<?= $zero ? ' xkpi--zero' : '' ?><?= $tone ? ' xkpi--' . e($tone) : '' ?>" href="<?= e(url($url)) ?>">
    <span class="xkpi__label"><?= e($label) ?></span>
    <span class="xkpi__value"><?= $value ?></span>
    <span class="xkpi__foot">
        <?php if ($delta !== null): ?>
            <span class="xkpi__delta xkpi__delta--<?= e($trendClass) ?>" title="<?= e(t('dashboard.vs_previous')) ?>">
                <i data-lucide="<?= $trendClass === 'flat' ? 'minus' : ($delta > 0 ? 'trending-up' : 'trending-down') ?>"></i><?= e(($delta > 0 ? '+' : '') . format_number($delta, 1)) ?> %
            </span>
        <?php endif; ?>
        <?php if ($hint !== null): ?><span class="xkpi__hint"><?= e($hint) ?></span><?php endif; ?>
    </span>
    <?php if ($points !== ''): ?>
        <svg class="xkpi__spark" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true"><polyline points="<?= e(trim($points)) ?>"/></svg>
    <?php endif; ?>
</a>
