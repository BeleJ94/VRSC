<?php
/**
 * Commandes fournisseurs à suivre (non soldées), de la livraison la plus proche à la plus lointaine ; fiche compacte au clic.
 * @var array $rows
 */
?>
<?php if ($rows === []): ?>
    <p class="xempty"><i data-lucide="check-circle-2"></i><?= e(t('dashboard.no_order')) ?></p>
<?php else: ?>
    <ul class="xlist">
        <?php foreach ($rows as $o): ?>
            <?php
            $days = $o['days'] !== null ? (int) $o['days'] : null;
            $tone = $days === null ? '' : ($days < 0 ? 'bad' : ($days <= 2 ? 'warn' : 'good'));
            $when = $days === null ? '—' : ($days < 0 ? t('dashboard.late_days', ['count' => -$days]) : ($days === 0 ? t('dashboard.today') : t('dashboard.in_days', ['count' => $days])));
            ?>
            <li class="xrow" role="button" tabindex="0"<?= peek($o['reference'], $o['supplier'], 'receipt-text', [
                [t('fields.site_id'), $o['site']],
                [t('fields.status'), t('order_status.' . $o['status'])],
                [t('js.drill.expected'), $o['expected_on'] ? format_date($o['expected_on']) : '—', $tone],
                [t('js.drill.amount'), format_money($o['total_amount'])],
            ], '/purchases/' . $o['id'], t('dashboard.open_order')) ?>>
                <span class="xlist__main"><strong><?= e($o['reference']) ?></strong><span class="muted small"><?= e($o['supplier']) ?> · <?= e($o['site']) ?></span></span>
                <span class="xlist__side"><strong><?= e(format_money($o['total_amount'])) ?></strong><span class="xpill xpill--<?= e($tone) ?>"><?= e($when) ?></span></span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
