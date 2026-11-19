<?php
/**
 * Formulaire générique d'un référentiel.
 * @var string $type  @var array $def  @var array $item  @var int|null $id  @var array $options
 */
?>
<div class="page-header"><h1><?= e($title) ?></h1></div>
<form method="post" action="<?= e(url($id ? "/references/$type/$id" : "/references/$type")) ?>" class="card form">
    <?= csrf_field() ?>
    <div class="form-grid">
        <?php foreach ($def['fields'] as $name => $field): ?>
            <?php
            $required = str_contains($field[1], 'required');
            $value = old($name, $item[$name] ?? null);
            ?>
            <?php if ($field[0] === 'bool'): ?>
                <div class="field">
                    <input type="hidden" name="<?= e($name) ?>" value="0">
                    <label class="check"><input type="checkbox" name="<?= e($name) ?>" value="1"<?= $value ? ' checked' : '' ?>> <?= e(t('fields.' . $name)) ?></label>
                </div>
            <?php elseif (in_array($field[0], ['select', 'site', 'ref'], true)): ?>
                <?= $view->partial('partials/field', [
                    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => 'select', 'value' => $value, 'required' => $required,
                    'options' => ($required && $field[0] === 'select' ? [] : ['' => '—']) + ($options[$name] ?? []),
                ]) ?>
            <?php else: ?>
                <?php preg_match('/max:(\d+)/', $field[1], $max); ?>
                <?= $view->partial('partials/field', [
                    'name' => $name, 'labelKey' => 'fields.' . $name, 'type' => $field[0], 'value' => $value, 'required' => $required,
                    'attrs' => isset($max[1]) && $field[0] !== 'date' ? ['maxlength' => $max[1]] : [],
                ]) ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url("/references/$type")) ?>"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn--primary"><i data-lucide="save"></i><?= e(t('common.save')) ?></button>
    </div>
</form>
