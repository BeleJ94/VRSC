<?php
/**
 * Import contrôlé d'un référentiel : le fichier est lu dans le navigateur (js/references-import.js),
 * le serveur contrôle chaque ligne puis n'importe que les lignes nouvelles et valides.
 * @var string $type  @var array $columns [champ => libellé]  @var array $history  @var int $maxRows
 */
$view->share('pageScripts', ['js/references-import.js']);
?>
<div class="page-header">
    <h1><?= e($title) ?></h1>
    <div class="page-header__actions">
        <a class="btn" href="<?= e(url("/references/$type")) ?>"><i data-lucide="arrow-left"></i><?= e(t('common.back')) ?></a>
    </div>
</div>

<section class="card" data-import
         data-preview-url="<?= e(url("/references/$type/import/preview")) ?>"
         data-import-url="<?= e(url("/references/$type/import")) ?>"
         data-list-url="<?= e(url("/references/$type")) ?>"
         data-max-rows="<?= e($maxRows) ?>">
    <div class="card__title"><h2><?= e(t('import.step_file')) ?></h2></div>
    <p class="muted"><?= e(t('import.help', ['max' => $maxRows])) ?></p>
    <div class="table-wrap">
        <table class="table table--compact">
            <thead><tr><?php foreach ($columns as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead>
            <tbody><tr><?php foreach ($columns as $name => $label): ?><td class="muted small mono"><?= e($name) ?></td><?php endforeach; ?></tr></tbody>
        </table>
    </div>
    <div class="filters">
        <div class="field">
            <label for="import-file"><?= e(t('import.file')) ?></label>
            <input id="import-file" type="file" class="input" accept=".xlsx,.csv" data-import-file>
        </div>
    </div>
    <p class="error" data-import-error hidden></p>

    <div data-import-result hidden>
        <div class="card__title"><h2><?= e(t('import.step_check')) ?></h2></div>
        <p data-import-summary></p>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead>
                    <tr>
                        <th><?= e(t('import.line')) ?></th>
                        <th><?= e(t('fields.code')) ?></th>
                        <th><?= e(t('fields.name')) ?></th>
                        <th><?= e(t('fields.status')) ?></th>
                        <th><?= e(t('import.detail')) ?></th>
                    </tr>
                </thead>
                <tbody data-import-lines></tbody>
            </table>
        </div>
        <div class="form-actions">
            <button type="button" class="btn btn--primary" data-import-confirm disabled><i data-lucide="check"></i><?= e(t('import.confirm')) ?></button>
        </div>
    </div>
</section>

<section class="card">
    <div class="card__title"><h2><?= e(t('import.history')) ?></h2></div>
    <?php if ($history === []): ?>
        <p class="empty"><?= e(t('import.no_history')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead>
                    <tr>
                        <th><?= e(t('import.date')) ?></th>
                        <th><?= e(t('import.by')) ?></th>
                        <th><?= e(t('import.file')) ?></th>
                        <th class="num"><?= e(t('import_status.new')) ?></th>
                        <th class="num"><?= e(t('import_status.duplicate')) ?></th>
                        <th class="num"><?= e(t('import_status.invalid')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $log): ?>
                        <tr>
                            <td class="nowrap"><?= e(format_date($log['imported_at'], true)) ?></td>
                            <td><?= e($log['imported_by_name']) ?></td>
                            <td><?= e($log['file_name']) ?></td>
                            <td class="num"><?= e($log['rows_created']) ?></td>
                            <td class="num"><?= e($log['rows_skipped']) ?></td>
                            <td class="num"><?= e($log['rows_invalid']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
