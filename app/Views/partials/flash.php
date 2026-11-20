<?php if (!empty($flashSuccess)): ?>
    <div class="alert alert--success" role="status" data-flash="success"><i data-lucide="check-circle-2"></i><div><?= e($flashSuccess) ?></div></div>
<?php endif; ?>
<?php if (!empty($flashError)): ?>
    <?php $flashErrors = array_values(array_unique(array_filter(array_map(static fn ($m): string => is_array($m) ? implode(' ', $m) : (string) $m, \App\Core\FormState::errors())))); ?>
    <div class="alert alert--error" role="alert" data-flash="error"><i data-lucide="alert-triangle"></i><div><span data-flash-message><?= e($flashError) ?></span><?php if ($flashErrors !== []): ?><ul class="alert__list"><?php foreach ($flashErrors as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul><?php endif; ?></div></div>
<?php endif; ?>
