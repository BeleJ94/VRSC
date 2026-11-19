<?php
/**
 * Grille de saisie des points de contrôle (check-list préalable ou procès-verbal d'arrivée).
 * @var array|null $previous points du contrôle précédent (valeurs proposées)  @var bool $arrival
 */
use App\Services\FleetTransferService;

$oldResults = (array) old('results', []);
$oldNotes = (array) old('notes', []);
?>
<?php foreach (['results', 'notes'] as $errorField): ?>
    <?php if (field_error($errorField)): ?><p class="error"><?= e(field_error($errorField)) ?></p><?php endif; ?>
<?php endforeach; ?>
<div class="table-wrap">
    <table class="table table--compact">
        <thead>
            <tr>
                <th><?= e(t('fleet.item')) ?></th>
                <?php if ($arrival): ?><th><?= e(t('fleet.at_departure')) ?></th><?php endif; ?>
                <th><?= e(t('fleet.result')) ?></th>
                <th><?= e(t('fleet.item_note')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (FleetTransferService::ITEMS as $item): ?>
                <?php $before = $previous[$item]['result'] ?? null; $value = $oldResults[$item] ?? ($arrival ? ($before ?? 'ok') : 'ok'); ?>
                <tr>
                    <td><strong><?= e(t('fleet_item.' . $item)) ?></strong></td>
                    <?php if ($arrival): ?><td><?= $before !== null ? e(t('fleet_result.' . $before)) : '—' ?></td><?php endif; ?>
                    <td>
                        <select name="results[<?= e($item) ?>]" class="input" aria-label="<?= e(t('fleet_item.' . $item)) ?>">
                            <?php foreach (FleetTransferService::RESULTS as $result): ?>
                                <option value="<?= e($result) ?>"<?= $value === $result ? ' selected' : '' ?>><?= e(t('fleet_result.' . $result)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" name="notes[<?= e($item) ?>]" class="input" maxlength="255" value="<?= e($oldNotes[$item] ?? '') ?>" aria-label="<?= e(t('fleet.item_note')) ?>"></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="field">
    <label for="f-photos-<?= $arrival ? 'arrival' : 'check' ?>"><?= e(t('fleet.photos')) ?></label>
    <input id="f-photos-<?= $arrival ? 'arrival' : 'check' ?>" type="file" name="photos[]" class="input" accept="image/jpeg,image/png,image/webp" multiple>
</div>
