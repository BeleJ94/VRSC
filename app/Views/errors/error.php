<?php /** @var int $status  @var string $message  @var string|null $debug */ ?>
<div class="auth__card">
    <div class="auth__brand"><img src="<?= e(asset('img/logo-vrsc.svg')) ?>" alt="VRSC" width="150" height="47"> ETHAN VRSC</div>
    <h1><?= e((string) $status) ?></h1>
    <p><?= e($message) ?></p>
    <a class="btn btn--primary" href="<?= e(url('/')) ?>"><?= e(t('common.home')) ?></a>
    <?php if (!empty($debug)): ?>
        <pre style="margin-top: 16px; font-size: 12px; white-space: pre-wrap; overflow-x: auto;"><?= e($debug) ?></pre>
    <?php endif; ?>
</div>
