<?php /** @var array $sites  @var array $filters  @var array $uncovered */ ?>
<div class="page-header">
    <h1><?= e(t('nav.preventive')) ?></h1>
    <div class="page-header__actions">
        <?= $view->partial('partials/export_buttons', ['table' => 'preventive', 'exportTitle' => t('nav.preventive')]) ?>
        <?php if ($currentUser->can('preventive.manage')): ?>
            <form method="post" action="<?= e(url('/preventive/generate')) ?>" data-confirm="<?= e(t('preventive.generate_confirm')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn"><i data-lucide="calendar-plus"></i><?= e(t('preventive.generate')) ?></button>
            </form>
            <a class="btn btn--primary" href="<?= e(url('/preventive/create')) ?>"><i data-lucide="plus"></i><?= e(t('preventive.new')) ?></a>
        <?php endif; ?>
    </div>
</div>
<?php if ($uncovered !== []): ?>
    <details class="card uncovered" id="uncovered">
        <summary class="uncovered__head">
            <span class="uncovered__icon"><i data-lucide="shield-alert"></i></span>
            <span><strong><?= e(t('preventive.uncovered_title', ['count' => count($uncovered)])) ?></strong>
                <span class="muted small"><?= e(t('preventive.uncovered_hint')) ?></span></span>
            <i data-lucide="chevron-down" class="uncovered__chevron"></i>
        </summary>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead><tr><th><?= e(t('fields.code')) ?></th><th><?= e(t('fields.name')) ?></th><th><?= e(t('fields.site_id')) ?></th><th><?= e(t('fields.status')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($uncovered as $eq): ?>
                    <tr>
                        <td><a href="<?= e(url('/equipment/' . $eq['id'])) ?>"><strong><?= e($eq['code']) ?></strong></a></td>
                        <td><?= e($eq['name']) ?></td>
                        <td><?= e($eq['site']) ?></td>
                        <td><?= status_badge($eq['status']) ?></td>
                        <td class="num">
                            <?php if ($currentUser->can('preventive.manage')): ?>
                                <a class="btn btn--sm" href="<?= e(url('/preventive/create?equipment_id=' . $eq['id'])) ?>"><i data-lucide="plus"></i><?= e(t('preventive.create_for')) ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
<?php endif; ?>
<section class="card">
    <form class="filters" data-filters-for="preventive">
        <?= $view->partial('partials/site_filter', ['sites' => $sites, 'selected' => $filters['site_id']]) ?>
        <label class="check"><input type="checkbox" name="due" value="1" data-filter<?= $filters['due'] === '1' ? ' checked' : '' ?>> <?= e(t('preventive.only_due')) ?></label>
    </form>
    <div class="table-wrap">
        <table class="display" style="width:100%" data-table="preventive" data-source="<?= e(url('/preventive/data')) ?>"<?= $currentUser->can('preventive.manage') ? ' data-row-link="' . e(url('/preventive/{id}/edit')) . '"' : '' ?>></table>
    </div>
</section>
