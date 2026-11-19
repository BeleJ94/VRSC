<?php
/** @var array $item  @var int|null $id  @var \App\Domain\Role[] $roles  @var array $sites  @var int $minPassword */
$roleOptions = [];
foreach ($roles as $role) { $roleOptions[$role->value] = t($role->labelKey()); }
$f = static fn (string $name, string $type = 'text', array $extra = []) => $view->partial('partials/field', $extra + [
    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $type, 'value' => old($name, $item[$name] ?? null),
]);
$selectedSites = array_map('intval', (array) old('site_ids', $item['site_ids'] ?? []));
$active = (bool) old('is_active', $item['is_active'] ?? true);
$allSites = (bool) old('all_sites', $item['all_sites'] ?? false);
// Fonctions supplémentaires par site : lignes existantes + 3 lignes vides
$extraRoles = array_values(array_filter((array) old('extra_roles', $item['extra_roles'] ?? []), static fn ($r) => is_array($r) && ($r['role'] ?? '') !== ''));
$extraRoles = array_merge($extraRoles, array_fill(0, 3, ['role' => '', 'site_id' => null]));
?>
<div class="page-header">
    <h1><?= e($title) ?></h1>
    <?php if ($id !== null && $currentUser->can('activity.view')): ?>
        <div class="page-header__actions">
            <a class="btn" href="<?= e(url('/activity', ['user_id' => $id])) ?>"><i data-lucide="history"></i><?= e(t('activity.user_activity')) ?></a>
        </div>
    <?php endif; ?>
</div>
<form method="post" action="<?= e(url($id ? "/users/$id" : '/users')) ?>" class="card form" autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-grid">
        <?= $f('first_name', 'text', ['required' => true, 'attrs' => ['maxlength' => 80]]) ?>
        <?= $f('last_name', 'text', ['required' => true, 'attrs' => ['maxlength' => 80]]) ?>
        <?= $f('email', 'email', ['required' => true, 'attrs' => ['maxlength' => 190]]) ?>
        <?= $view->partial('partials/field', ['name' => 'password', 'labelKey' => 'fields.password', 'type' => 'password', 'value' => '', 'required' => $id === null,
            'attrs' => ['minlength' => $minPassword, 'autocomplete' => 'new-password'],
            'hint' => $id === null ? t('users.password_hint', ['min' => $minPassword]) : t('users.password_keep')]) ?>
        <?= $f('role', 'select', ['required' => true, 'options' => $roleOptions]) ?>
        <?= $f('locale', 'select', ['required' => true, 'options' => ['fr' => 'Français', 'en' => 'English']]) ?>
        <div class="field span-2">
            <span class="label"><?= e(t('fields.site_ids')) ?></span>
            <input type="hidden" name="all_sites" value="0">
            <label class="check"><input type="checkbox" name="all_sites" value="1"<?= $allSites ? ' checked' : '' ?>> <?= e(t('fields.all_sites')) ?></label>
            <div class="checklist">
                <?php foreach ($sites as $site): ?>
                    <label class="check"><input type="checkbox" name="site_ids[]" value="<?= e($site['id']) ?>"<?= in_array((int) $site['id'], $selectedSites, true) ? ' checked' : '' ?>> <?= e($site['name']) ?></label>
                <?php endforeach; ?>
            </div>
            <?php if (field_error('site_ids')): ?><span class="error"><?= e(field_error('site_ids')) ?></span><?php endif; ?>
        </div>
        <div class="field span-2">
            <span class="label"><?= e(t('users.extra_roles')) ?></span>
            <span class="hint"><?= e(t('users.extra_roles_hint')) ?></span>
            <?php foreach ($extraRoles as $i => $extra): ?>
                <div class="filters" data-extra-role>
                    <select name="extra_roles[<?= e($i) ?>][role]" class="input" aria-label="<?= e(t('fields.role')) ?>">
                        <option value="">—</option>
                        <?php foreach ($roleOptions as $value => $label): ?>
                            <?php if ($value !== 'admin'): ?><option value="<?= e($value) ?>"<?= ($extra['role'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <select name="extra_roles[<?= e($i) ?>][site_id]" class="input" aria-label="<?= e(t('fields.site_id')) ?>">
                        <option value=""><?= e(t('common.all_sites')) ?></option>
                        <?php foreach ($sites as $site): ?>
                            <option value="<?= e($site['id']) ?>"<?= (int) ($extra['site_id'] ?? 0) === (int) $site['id'] ? ' selected' : '' ?>><?= e($site['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            <?php if (field_error('extra_roles')): ?><span class="error"><?= e(field_error('extra_roles')) ?></span><?php endif; ?>
        </div>
        <div class="field">
            <input type="hidden" name="is_active" value="0">
            <label class="check"><input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>> <?= e(t('fields.is_active')) ?></label>
        </div>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/users')) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>
