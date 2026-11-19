<?php
/**
 * Délégations de fonction : liste (en cours, à venir, expirées, révoquées) et enregistrement.
 * @var array $delegations  @var array $users  @var array $sites  @var \App\Domain\Role[] $roles  @var bool $allSites
 */
$stateBadge = ['active' => 'available', 'upcoming' => 'maintenance', 'expired' => 'neutral', 'revoked' => 'breakdown'];
$userOptions = ['' => '—'];
foreach ($users as $user) {
    $userOptions[$user['id']] = $user['name'] . ' (' . t('role.' . $user['role']) . ')';
}
$roleOptions = ['' => '—'];
foreach ($roles as $role) {
    $roleOptions[$role->value] = t($role->labelKey());
}
$siteOptions = $allSites ? ['' => t('common.all_sites')] : [];
foreach ($sites as $site) {
    $siteOptions[$site['id']] = $site['name'];
}
$f = static fn (string $name, string $type, array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name),
]);
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="user-check"></i></span>
        <div>
            <h1><?= e(t('nav.delegations')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('delegations.subtitle')) ?></span></div>
        </div>
    </div>
</div>

<section class="card">
    <div class="card__title"><h2><?= e(t('delegations.new')) ?></h2></div>
    <form method="post" action="<?= e(url('/delegations')) ?>" class="form">
        <?= csrf_field() ?>
        <div class="form-grid">
            <?= $f('from_user_id', 'select', ['required' => true, 'options' => $userOptions]) ?>
            <?= $f('to_user_id', 'select', ['required' => true, 'options' => $userOptions]) ?>
            <?= $f('role', 'select', ['required' => true, 'options' => $roleOptions]) ?>
            <?= $f('site_id', 'select', ['options' => $siteOptions]) ?>
            <?= $f('starts_on', 'date', ['required' => true]) ?>
            <?= $f('ends_on', 'date', ['required' => true]) ?>
            <?= $f('reason', 'text', ['required' => true, 'class' => 'span-2', 'attrs' => ['maxlength' => 255]]) ?>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
        </div>
    </form>
</section>

<section class="card">
    <?php if ($delegations === []): ?>
        <div class="empty-state"><i data-lucide="user-check"></i><p><?= e(t('delegations.none')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= e(t('fields.from_user_id')) ?></th>
                        <th><?= e(t('fields.to_user_id')) ?></th>
                        <th><?= e(t('fields.role')) ?></th>
                        <th><?= e(t('fields.site_id')) ?></th>
                        <th><?= e(t('delegations.period')) ?></th>
                        <th><?= e(t('fields.reason')) ?></th>
                        <th><?= e(t('fields.status')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($delegations as $d): ?>
                        <tr>
                            <td><?= e($d['from_name']) ?></td>
                            <td><strong><?= e($d['to_name']) ?></strong></td>
                            <td><?= e(t('role.' . $d['role'])) ?></td>
                            <td><?= e($d['site'] ?? t('common.all_sites')) ?></td>
                            <td class="nowrap"><?= e(format_date($d['starts_on'])) ?> → <?= e(format_date($d['ends_on'])) ?></td>
                            <td><?= e($d['reason']) ?></td>
                            <td><span class="badge badge--<?= e($stateBadge[$d['state']]) ?>"><?= e(t('delegation_state.' . $d['state'])) ?></span></td>
                            <td class="num">
                                <?php if (in_array($d['state'], ['active', 'upcoming'], true)): ?>
                                    <form method="post" action="<?= e(url('/delegations/' . $d['id'] . '/revoke')) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn--sm"><i data-lucide="ban"></i><?= e(t('delegations.revoke')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
