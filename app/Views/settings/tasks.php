<?php
/**
 * Tâches planifiées quotidiennes : santé, dernier passage, lancement manuel, installation et journal.
 * @var list<array> $tasks  @var list<array> $recent  @var int $maxAge
 */
$tone = ['ok' => 'available', 'late' => 'maintenance', 'failed' => 'breakdown', 'never' => 'maintenance'];
$problems = array_filter($tasks, static fn (array $t): bool => $t['health'] !== 'ok');
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="clock"></i></span>
        <div>
            <h1><?= e(t('nav.scheduled_tasks')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('tasks.subtitle', ['hours' => $maxAge])) ?></span></div>
        </div>
    </div>
</div>

<?php if (field_error('task')): ?><div class="alert alert--error"><?= e(field_error('task')) ?></div><?php endif; ?>
<?php if ($problems !== []): ?>
    <div class="alert alert--warning"><i data-lucide="alert-triangle"></i><span><?= e(t('tasks.problems', ['count' => count($problems)])) ?></span></div>
<?php endif; ?>

<div class="task-grid">
    <?php foreach ($tasks as $task): ?>
        <section class="card task-card task-card--<?= e($task['health']) ?>">
            <div class="task-card__head">
                <span class="task-card__icon"><i data-lucide="<?= e($task['icon']) ?>"></i></span>
                <div>
                    <h2><?= e(t('task_name.' . $task['key'])) ?></h2>
                    <div class="muted small"><?= e(t('tasks.daily_at', ['time' => $task['time']])) ?></div>
                </div>
                <span class="badge badge--<?= e($tone[$task['health']]) ?>"><?= e(t('task_health.' . $task['health'])) ?></span>
            </div>
            <p class="muted small"><?= e(t('task_desc.' . $task['key'])) ?></p>
            <dl class="task-card__dl">
                <dt><?= e(t('tasks.last_run')) ?></dt>
                <dd><?= $task['last'] ? e(format_date($task['last']['started_at'], true)) . ' · ' . e(t('task_origin.' . $task['last']['origin'])) : '—' ?></dd>
                <dt><?= e(t('tasks.last_ok')) ?></dt>
                <dd><?= $task['last_ok'] ? e(format_date($task['last_ok']['started_at'], true)) : '—' ?></dd>
                <?php if ($task['last'] && $task['last']['message']): ?>
                    <dt><?= e(t('tasks.result')) ?></dt><dd><?= e($task['last']['message']) ?></dd>
                <?php endif; ?>
            </dl>
            <div class="task-card__actions">
                <?php if ($task['key'] === 'backup'): ?>
                    <a class="btn btn--sm" href="<?= e(url('/backups')) ?>"><i data-lucide="database-backup"></i><?= e(t('tasks.open_backups')) ?></a>
                <?php else: ?>
                    <form method="post" action="<?= e(url('/settings/tasks/' . $task['key'] . '/run')) ?>" data-confirm="<?= e(t('task_confirm.' . $task['key'])) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn--sm"><i data-lucide="play"></i><?= e(t('tasks.run_now')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<section class="card">
    <div class="card__title"><h2><i data-lucide="terminal" class="i-inline"></i><?= e(t('tasks.install_title')) ?></h2></div>
    <p><?= e(t('tasks.install_intro')) ?></p>
    <pre class="code-block">powershell -ExecutionPolicy Bypass -File bin\install-tasks.ps1</pre>
    <p class="muted small"><?= e(t('tasks.install_more')) ?></p>
    <pre class="code-block">powershell -ExecutionPolicy Bypass -File bin\install-tasks.ps1 -List
powershell -ExecutionPolicy Bypass -File bin\install-tasks.ps1 -Uninstall</pre>
</section>

<section class="card">
    <div class="card__title"><h2><?= e(t('tasks.history')) ?></h2></div>
    <?php if ($recent === []): ?>
        <p class="empty"><?= e(t('tasks.no_run')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead><tr><th><?= e(t('tasks.col_task')) ?></th><th><?= e(t('tasks.col_started')) ?></th><th><?= e(t('tasks.col_origin')) ?></th><th><?= e(t('fields.status')) ?></th><th><?= e(t('tasks.result')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?= e(t('task_name.' . $r['task'])) ?></td>
                        <td class="nowrap"><?= e(format_date($r['started_at'], true)) ?></td>
                        <td><?= e(t('task_origin.' . $r['origin'])) ?></td>
                        <td><span class="badge badge--<?= e(['ok' => 'available', 'failed' => 'breakdown', 'running' => 'maintenance'][$r['status']]) ?>"><?= e(t('task_run.' . $r['status'])) ?></span></td>
                        <td class="small"><?= e($r['message'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
