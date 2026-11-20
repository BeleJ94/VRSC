<?php
/**
 * Carte d'indicateur compacte : libellé, valeur, variation vs période précédente (couleur selon le sens favorable),
 * mini-courbe sur 6 mois, ligne d'aide ; toute la carte mène au détail.
 * @var string $label  @var string $value  @var array $kpi  @var string $url  @var string|null $hint  @var string|null $tone good|warn|bad
 * @var array|null $drill fenêtre de détail  @var list<string> $months mois des points  @var callable|null $monthDrill mois → spécification
 */
$hint ??= null;
$drill ??= null;
$months ??= [];
$monthDrill ??= null;
$peek ??= null; // attribut data-peek (fiche compacte) quand l'indicateur ne se détaille pas en tableau
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
$attrs = $drill !== null ? ' role="button" tabindex="0" data-drill="' . e(json_encode($drill, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"'
    : ($peek !== null ? ' role="button" tabindex="0"' . $peek : '');
$interactive = $attrs !== '';
?>
<<?= $interactive ? 'div' : 'a' ?> class="xkpi<?= $zero ? ' xkpi--zero' : '' ?><?= $tone ? ' xkpi--' . e($tone) : '' ?>"<?= $interactive ? $attrs . ' title="' . e(t('dashboard.click_detail')) . '"' : ' href="' . e(url($url)) . '"' ?>>
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
        <?php if ($monthDrill !== null && count($months) === count($series)): ?>
            <span class="xkpi__points">
                <?php foreach ($series as $i => $v): ?>
                    <?php $m = $months[$i]; ?>
                    <span class="xkpi__point" style="left: <?= e(round($i * $step, 1)) ?>%; top: <?= e(round(26 - (($v - $min) / $range) * 22, 1) / 28 * 100) ?>%" role="button" tabindex="0"
                          title="<?= e($m . ' : ' . format_money($v)) ?>" data-drill="<?= e(json_encode($monthDrill($m), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"></span>
                <?php endforeach; ?>
            </span>
        <?php endif; ?>
    <?php endif; ?>
</<?= $interactive ? 'div' : 'a' ?>>
