<?php
/**
 * Prestations externes d'un OT (atelier, dépannage, expertise) : elles entrent dans le coût de l'engin.
 * @var array $wo  @var array $services  @var bool $canAddService
 */
?>
<section class="card" id="services">
    <div class="card__title"><h2><?= e(t('work_orders.services')) ?></h2></div>
    <?php foreach (['description', 'amount', 'service_date'] as $errorField): ?>
        <?php if (field_error($errorField)): ?><p class="error"><?= e(field_error($errorField)) ?></p><?php endif; ?>
    <?php endforeach; ?>
    <?php if ($services === []): ?>
        <p class="empty"><?= e(t('work_orders.no_service')) ?></p>
    <?php else: ?>
        <table class="table table--compact">
            <tbody>
                <?php foreach ($services as $service): ?>
                    <tr>
                        <td><strong><?= e($service['description']) ?></strong><br><span class="muted small"><?= e(implode(' · ', array_filter([$service['supplier'], format_date($service['service_date']), $service['document_ref']]))) ?></span></td>
                        <td class="num"><?= $service['amount'] !== null ? e(format_money($service['amount'])) : '<span class="badge badge--waiting_part">' . e(t('costs.missing')) . '</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if ($canAddService): ?>
        <form method="post" action="<?= e(url('/work-orders/' . $wo['id'] . '/services')) ?>" class="form">
            <?= csrf_field() ?>
            <?= $view->partial('partials/field', ['name' => 'description', 'labelKey' => 'fields.description', 'type' => 'text', 'required' => true, 'value' => old('description'), 'attrs' => ['maxlength' => 190]]) ?>
            <?= $view->partial('partials/field', ['name' => 'amount', 'labelKey' => 'fields.amount', 'type' => 'number', 'value' => old('amount'), 'attrs' => ['min' => '0.01', 'step' => '0.01'], 'hint' => t('work_orders.service_amount_hint')]) ?>
            <?= $view->partial('partials/field', ['name' => 'document_ref', 'labelKey' => 'fields.document_ref', 'type' => 'text', 'value' => old('document_ref'), 'attrs' => ['maxlength' => 60]]) ?>
            <div class="form-actions"><button type="submit" class="btn btn--sm"><i data-lucide="plus"></i><?= e(t('work_orders.add_service')) ?></button></div>
        </form>
    <?php endif; ?>
</section>
