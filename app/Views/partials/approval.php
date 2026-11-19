<?php
/**
 * Circuit d'approbation d'un document : actes déjà passés (qui, quand, à quel titre) et, si l'utilisateur
 * peut traiter l'étape courante, le formulaire de décision.
 * @var array|null $approval demande + actions (ApprovalService::forEntity)
 */
if ($approval === null) {
    return;
}
$statusBadge = ['pending' => 'waiting_part', 'approved' => 'available', 'rejected' => 'breakdown', 'cancelled' => 'neutral'];
$pending = $approval['status'] === 'pending';
$role = $pending ? \App\Domain\Role::tryFrom((string) $approval['step_role']) : null;
$siteId = (int) $approval['site_id'];
$isRequester = (int) ($approval['requested_by'] ?? 0) === $currentUser->id;
$holds = $role !== null && $currentUser->holds($role, $siteId) !== null;
$alreadyActed = in_array($currentUser->id, array_map('intval', array_column($approval['actions'], 'acted_by')), true);
$canOverride = $currentUser->canAt('approvals.override', $siteId);
$canAct = $pending && !$isRequester && $currentUser->can('approvals.use') && (($holds && !$alreadyActed) || $canOverride);
$needsOverride = $canAct && (!$holds || $alreadyActed);
?>
<section class="card" id="approval">
    <div class="card__title">
        <h2><?= e(t('approvals.circuit')) ?></h2>
        <span class="badge badge--<?= e($statusBadge[$approval['status']]) ?>"><?= e(t('approval_status.' . $approval['status'])) ?></span>
    </div>
    <p class="muted small"><?= e(t('approvals.requested_by', ['name' => $approval['requester'] ?? '—', 'date' => format_date($approval['requested_at'], true)])) ?></p>

    <?php if ($approval['actions'] !== []): ?>
        <div class="table-wrap">
            <table class="table table--compact">
                <thead>
                    <tr>
                        <th><?= e(t('approvals.step')) ?></th>
                        <th><?= e(t('fields.decision')) ?></th>
                        <th><?= e(t('approvals.actor')) ?></th>
                        <th><?= e(t('fields.date')) ?></th>
                        <th><?= e(t('fields.comment')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approval['actions'] as $action): ?>
                        <tr>
                            <td><?= e($action['step_name']) ?><br><span class="muted small"><?= e(t('approval_kind.' . $action['step_kind'])) ?></span></td>
                            <td><span class="badge badge--<?= $action['decision'] === 'favorable' ? 'available' : 'breakdown' ?>"><?= e(t('approval_decision.' . $action['decision'])) ?></span></td>
                            <td>
                                <strong><?= e($action['actor_name']) ?></strong><br>
                                <span class="muted small"><?= e(t('role.' . $action['acted_role'])) ?>
                                    <?php if ($action['delegated_from_name']): ?> · <?= e(t('approvals.by_delegation', ['name' => $action['delegated_from_name']])) ?><?php endif; ?>
                                </span>
                            </td>
                            <td class="nowrap"><?= e(format_date($action['acted_at'], true)) ?></td>
                            <td>
                                <?= e($action['comment']) ?>
                                <?php if ((int) $action['is_override'] === 1): ?><br><span class="badge badge--waiting_part"><?= e(t('approvals.override')) ?></span> <?= e($action['override_reason']) ?><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($pending): ?>
        <p><?= e(t('approvals.waiting', ['step' => $approval['step_name'], 'role' => t('role.' . $approval['step_role'])])) ?></p>
        <?php if ($isRequester): ?>
            <p class="muted small"><?= e(t('validation.approval_own_request')) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($canAct): ?>
        <form method="post" action="<?= e(url('/approvals/' . $approval['id'] . '/act')) ?>" class="form" data-approval-form>
            <?= csrf_field() ?>
            <div class="form-grid">
                <?= $view->partial('partials/field', ['name' => 'decision', 'labelKey' => 'fields.decision', 'type' => 'select', 'required' => true, 'value' => old('decision'),
                    'options' => ['favorable' => t('approval_decision.favorable'), 'unfavorable' => t('approval_decision.unfavorable')]]) ?>
                <?= $view->partial('partials/field', ['name' => 'comment', 'labelKey' => 'fields.comment', 'type' => 'text', 'value' => old('comment'),
                    'attrs' => ['maxlength' => 1000], 'hint' => t('approvals.comment_hint')]) ?>
                <?php if ($needsOverride): ?>
                    <?= $view->partial('partials/field', ['name' => 'override_reason', 'labelKey' => 'fields.override_reason', 'type' => 'text', 'required' => true,
                        'value' => old('override_reason'), 'class' => 'span-2', 'attrs' => ['maxlength' => 500], 'hint' => t('approvals.override_hint')]) ?>
                <?php endif; ?>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary"><i data-lucide="stamp"></i><?= e(t('approvals.record')) ?></button>
            </div>
        </form>
    <?php endif; ?>
</section>
