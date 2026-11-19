<?php
/**
 * Référentiels communs : un onglet par référentiel, recherche, liste paginée.
 * @var string $type  @var array $def  @var array $list  @var string $search  @var int|null $siteId  @var array $sites
 */
use App\Domain\ReferenceCatalog;

$canManage = $currentUser->can('reference.manage');
$query = array_filter(['q' => $search, 'site_id' => $siteId], static fn ($v) => $v !== null && $v !== '');
$cell = static function (array $row, string $name, array $field): string {
    $value = $row[$name] ?? null;
    return match ($field[0]) {
        'bool' => '<span class="badge badge--' . ((int) $value === 1 ? 'available' : 'neutral') . '">' . e(t((int) $value === 1 ? 'common.yes' : 'common.no')) . '</span>',
        'site', 'ref' => e($row[$name . '_label'] ?? ''),
        'select' => $value === null ? '' : e(t($field[2]['label'] . '.' . $value)),
        'date' => e(format_date($value)),
        default => $name === 'code' ? '<strong>' . e($value) . '</strong>' : e($value),
    };
};
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="library"></i></span>
        <div>
            <h1><?= e(t('nav.references')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('reference.subtitle')) ?></span></div>
        </div>
    </div>
    <?php if ($canManage): ?>
        <div class="page-header__actions">
            <a class="btn" href="<?= e(url("/references/$type/import")) ?>"><i data-lucide="file-up"></i><?= e(t('reference.import')) ?></a>
            <a class="btn btn--primary" href="<?= e(url("/references/$type/create", $siteId ? ['site_id' => $siteId] : [])) ?>"><i data-lucide="plus"></i><?= e(t('reference.new')) ?></a>
        </div>
    <?php endif; ?>
</div>

<nav class="chips" aria-label="<?= e(t('nav.references')) ?>">
    <?php foreach (ReferenceCatalog::types() as $other): ?>
        <a class="chip-btn" aria-pressed="<?= $other === $type ? 'true' : 'false' ?>" href="<?= e(url("/references/$other")) ?>"><?= e(t(ReferenceCatalog::labelKey($other))) ?></a>
    <?php endforeach; ?>
</nav>

<section class="card">
    <form class="filters" method="get" action="<?= e(url("/references/$type")) ?>">
        <div class="field">
            <label for="ref-q"><?= e(t('common.search')) ?></label>
            <input id="ref-q" type="search" name="q" class="input" value="<?= e($search) ?>" maxlength="100">
        </div>
        <?php if ($def['site_scoped'] && count($sites) > 1): ?>
            <div class="field">
                <label for="ref-site"><?= e(t('fields.site_id')) ?></label>
                <select id="ref-site" name="site_id" class="input">
                    <option value=""><?= e(t('common.all_sites')) ?></option>
                    <?php foreach ($sites as $site): ?>
                        <option value="<?= e($site['id']) ?>"<?= (int) $site['id'] === (int) $siteId ? ' selected' : '' ?>><?= e($site['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="field"><button type="submit" class="btn"><i data-lucide="search"></i><?= e(t('common.search')) ?></button></div>
    </form>

    <?php if ($list['rows'] === []): ?>
        <div class="empty-state">
            <i data-lucide="<?= e($def['icon']) ?>"></i>
            <p><?= e(t('reference.none')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table" data-reference-table="<?= e($type) ?>">
                <thead>
                    <tr>
                        <?php foreach ($def['list'] as $name): ?><th><?= e(t('fields.' . $name)) ?></th><?php endforeach; ?>
                        <?php if ($canManage): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($list['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($def['list'] as $name): ?><td><?= $cell($row, $name, $def['fields'][$name]) ?></td><?php endforeach; ?>
                            <?php if ($canManage): ?>
                                <td class="num"><a class="icon-btn" href="<?= e(url("/references/$type/{$row['id']}/edit")) ?>" title="<?= e(t('common.edit')) ?>" aria-label="<?= e(t('common.edit')) ?>"><i data-lucide="pencil"></i></a></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted small"><?= e(t('reference.count', ['count' => $list['total']])) ?></p>
        <?php if ($list['pages'] > 1): ?>
            <nav class="chips" aria-label="<?= e(t('reference.pages')) ?>">
                <?php for ($p = 1; $p <= $list['pages']; $p++): ?>
                    <a class="chip-btn" aria-pressed="<?= $p === $list['page'] ? 'true' : 'false' ?>" href="<?= e(url("/references/$type", $query + ['page' => $p])) ?>"><?= e($p) ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
