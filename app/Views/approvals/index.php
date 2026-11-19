<?php
/** Mes validations : demandes en attente que l'utilisateur peut traiter avec ses rôles. @var array $inbox */
?>
<div class="page-header">
    <div class="page-title">
        <span class="page-title__icon"><i data-lucide="stamp"></i></span>
        <div>
            <h1><?= e(t('nav.approvals')) ?></h1>
            <div class="page-title__meta"><span><?= e(t('approvals.subtitle')) ?></span></div>
        </div>
    </div>
</div>
<section class="card">
    <?php if ($inbox === []): ?>
        <div class="empty-state empty-state--ok"><i data-lucide="check-circle-2"></i><p><?= e(t('approvals.none')) ?></p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= e(t('fields.reference')) ?></th>
                        <th><?= e(t('approvals.flow')) ?></th>
                        <th><?= e(t('fields.site_id')) ?></th>
                        <th><?= e(t('approvals.step')) ?></th>
                        <th><?= e(t('approvals.requester')) ?></th>
                        <th><?= e(t('fields.date')) ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inbox as $request): ?>
                        <tr>
                            <td><strong><?= e($request['reference'] ?? '#' . $request['entity_id']) ?></strong></td>
                            <td><?= e($request['flow_name']) ?><?= $request['amount'] !== null ? '<br><span class="muted small">' . e(format_money($request['amount'])) . '</span>' : '' ?></td>
                            <td><?= e($request['site']) ?></td>
                            <td><?= e($request['step_name']) ?><br><span class="muted small"><?= e(t('approval_kind.' . $request['step_kind'])) ?></span></td>
                            <td><?= e($request['requester']) ?></td>
                            <td class="nowrap"><?= e(format_date($request['requested_at'], true)) ?></td>
                            <td class="num">
                                <?php if ($request['page'] !== null): ?>
                                    <a class="btn btn--sm btn--primary" href="<?= e(url($request['page'])) ?>#approval"><i data-lucide="arrow-right"></i><?= e(t('approvals.open')) ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
