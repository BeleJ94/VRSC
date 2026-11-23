<?php
/**
 * Historique des modifications d'une entité : regroupé par jour, filtrable par type, 8 entrées puis « Afficher plus ».
 * Les valeurs sont traduites (statuts, décisions, oui/non, dates) et les champs techniques (identifiants) masqués.
 * @var array $activity  entrées du journal (récentes d'abord)
 * @var string|null $subject  libellé de l'entité (ex. code engin), retiré des résumés où il est redondant
 * @var string|null $entity  'equipment' | 'work_order' : sens des statuts
 */
if (empty($activity)) {
    return;
}
$subject ??= null;
$entity ??= null;
$i18n = \App\Core\I18n::current();

$categories = [
    'status' => ['activity', ['status', 'transition', 'done', 'archive', 'release_cancel', 'site_change']],
    'checks' => ['clipboard-check', ['check', 'fleet_check', 'reading', 'reading_backfill', 'reading_update', 'reading_delete']],
    'fuel' => ['fuel', ['fuel_refill', 'fluid_top_up', 'fluid_change']],
    'cost' => ['coins', ['cost_charge', 'cost_close', 'part_consumed', 'service_add', 'payment']],
    'documents' => ['file-text', ['document_add', 'document_update', 'document_delete', 'document_exempt', 'document_unexempt', 'document_renewal_start', 'document_renewal_end', 'photo_add', 'photo_delete', 'photo_primary', 'attachment_add']],
];
$categoryOf = static function (string $action) use ($categories): string {
    foreach ($categories as $key => [, $actions]) {
        if (in_array($action, $actions, true)) {
            return $key;
        }
    }

    return 'changes';
};
$icons = array_map(static fn (array $c): string => $c[0], $categories) + ['changes' => 'pencil'];

// Champs techniques : identifiants internes, déjà visibles autrement (ex. référence de l'OT dans le motif).
$hidden = static fn (string $key): bool => in_array($key, ['id', 'release', 'assigned_to', 'reported_by', 'created_by', 'checked_by', 'received_by', 'user'], true)
    || str_ends_with($key, '_id') || str_ends_with($key, '_ids');
$value = static function (string $key, mixed $v) use ($i18n, $entity): string {
    if ($v === null || $v === '') {
        return '—';
    }
    if (is_bool($v) || (str_starts_with($key, 'is_') && in_array((string) $v, ['0', '1'], true))) {
        return t((bool) $v ? 'js.yes' : 'js.no');
    }
    $s = (string) $v;
    $groups = $key === 'status'
        ? ($entity === 'work_order' ? ['wo_status', 'status'] : ['status', 'wo_status'])
        : ($key === 'decision' ? ['approval_status'] : ($key === 'priority' ? ['priority'] : ($key === 'type' ? ['wo_type'] : [])));
    foreach ($groups as $group) {
        if ($i18n->has("$group.$s")) {
            return t("$group.$s");
        }
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $s)) {
        return format_date($s, strlen($s) > 10);
    }

    return mb_strlen($s) > 120 ? mb_substr($s, 0, 117) . '…' : $s;
};
$summaryOf = static function (array $entry, bool $hasDiff) use ($subject): string {
    $summary = trim((string) ($entry['summary'] ?? ''));
    // Changement de statut : le détail (avant → après) dit déjà tout.
    if ($hasDiff && in_array($entry['action'], ['status', 'transition'], true)) {
        return '';
    }
    if ($subject !== null && $subject !== '') {
        $q = preg_quote($subject, '/');
        $summary = trim((string) preg_replace(["/^$q\s*[:–-]\s*/u", "/\s*[–-]\s*$q$/u"], '', $summary));
    }

    return $summary;
};

$counts = [];
$rows = [];
foreach ($activity as $entry) {
    $cat = $categoryOf((string) $entry['action']);
    $counts[$cat] = ($counts[$cat] ?? 0) + 1;
    $before = $entry['before_data'] ? (json_decode($entry['before_data'], true) ?: []) : [];
    $after = $entry['after_data'] ? (json_decode($entry['after_data'], true) ?: []) : [];
    $diff = [];
    if ($before && $after) {
        foreach ($after as $key => $new) {
            $key = (string) $key;
            if ($hidden($key) || is_array($new) || is_array($before[$key] ?? null)) {
                continue;
            }
            $diff[] = [$i18n->has("fields.$key") ? t("fields.$key") : $key, $value($key, $before[$key] ?? null), $value($key, $new)];
        }
    }
    $rows[] = ['entry' => $entry, 'cat' => $cat, 'diff' => $diff, 'summary' => $summaryOf($entry, $diff !== []), 'day' => substr((string) $entry['created_at'], 0, 10)];
}
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$dayLabel = static fn (string $d): string => $d === $today ? t('activity.today') : ($d === $yesterday ? t('activity.yesterday') : format_date($d));
?>
<section class="card act" id="history" data-activity data-activity-limit="8">
    <div class="card__title">
        <h2><?= e(t('activity.title')) ?> <span class="muted small"><?= e(count($rows)) ?></span></h2>
    </div>
    <?php if (count($counts) > 1): ?>
        <div class="act__filters" role="toolbar" aria-label="<?= e(t('activity.filter')) ?>">
            <button type="button" class="chip-btn is-active" data-act-filter="all" aria-pressed="true"><?= e(t('activity.filter_all')) ?> <span><?= e(count($rows)) ?></span></button>
            <?php foreach (array_keys($icons) as $cat): ?>
                <?php if (!empty($counts[$cat])): ?>
                    <button type="button" class="chip-btn" data-act-filter="<?= e($cat) ?>" aria-pressed="false"><i data-lucide="<?= e($icons[$cat]) ?>"></i><?= e(t('activity_cat.' . $cat)) ?> <span><?= e($counts[$cat]) ?></span></button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <ol class="act__list">
        <?php $lastDay = null; ?>
        <?php foreach ($rows as $row): ?>
            <?php $entry = $row['entry']; ?>
            <?php if ($row['day'] !== $lastDay): $lastDay = $row['day']; ?>
                <li class="act__day" data-act-day><?= e($dayLabel($row['day'])) ?></li>
            <?php endif; ?>
            <li class="act__item" data-act-item data-cat="<?= e($row['cat']) ?>">
                <span class="act__icon act__icon--<?= e($row['cat']) ?>"><i data-lucide="<?= e($icons[$row['cat']]) ?>"></i></span>
                <div class="act__body">
                    <div class="act__head">
                        <strong><?= e($i18n->has('action.' . $entry['action']) ? t('action.' . $entry['action']) : $entry['action']) ?></strong>
                        <?php if ($row['summary'] !== ''): ?><span class="act__summary"><?= e($row['summary']) ?></span><?php endif; ?>
                        <time datetime="<?= e($entry['created_at']) ?>"><?= e(substr((string) $entry['created_at'], 11, 5)) ?><?= $entry['user_name'] ? ' · ' . e($entry['user_name']) : '' ?></time>
                    </div>
                    <?php if ($row['diff'] !== []): ?>
                        <dl class="act__diff">
                            <?php foreach ($row['diff'] as [$label, $old, $new]): ?>
                                <div><dt><?= e($label) ?></dt><dd><?php if ($old !== '—'): ?><del><?= e($old) ?></del> <i data-lucide="arrow-right"></i> <?php endif; ?><ins><?= e($new) ?></ins></dd></div>
                            <?php endforeach; ?>
                        </dl>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
    <button type="button" class="btn btn--sm btn--ghost act__more" data-act-more hidden><i data-lucide="chevron-down"></i><?= e(t('activity.show_more')) ?></button>
</section>
