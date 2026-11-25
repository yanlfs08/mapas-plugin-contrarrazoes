<?php
/**
 * Página de acompanhamento das contrarrazões de uma fase de recurso (gestor).
 * GET /opportunity/counterAppeals/{id da fase de recurso} — veja Plugin::initEndpoints().
 *
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 * @var \MapasCulturais\App $app
 * @var \MapasCulturais\Entities\Opportunity $entity fase de recurso
 * @var \MapasCulturais\Entities\Opportunity|null $edital primeira fase do edital
 */

use MapasCulturais\i;

$this->layout = 'entity';
$this->addOpportunityBreadcramb(i::__('Lista de contrarrazões'), $entity);
$this->import('
    counter-appeal-tracking
    mc-breadcrumb
    mc-icon
');

$e = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
$config = $this->jsObject['config']['counterAppealTracking'] ?? [];
?>
<div class="main-app counter-appeal-page">
    <mc-breadcrumb></mc-breadcrumb>

    <header class="counter-appeal-page__header" v-pre>
        <div>
            <h2 class="bold"><?= i::__('Lista de contrarrazões') ?></h2>
            <p class="counter-appeal-page__subtitle">
                <?= $e($config['opportunityName'] ?? '') ?>
                <?php if (!empty($config['appealPhase']['evaluatedPhaseName'])): ?>
                    · <?= i::__('Recurso da fase') ?> <?= $e($config['appealPhase']['evaluatedPhaseName']) ?>
                <?php endif; ?>
            </p>
        </div>
        <?php if (!empty($config['editUrl'])): ?>
            <a class="button button--primary-outline" href="<?= $e($config['editUrl']) ?>">
                ← <?= i::__('Voltar') ?>
            </a>
        <?php endif; ?>
    </header>

    <div class="counter-appeal-page__container">
        <counter-appeal-tracking></counter-appeal-tracking>
    </div>
</div>
