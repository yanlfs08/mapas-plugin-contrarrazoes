<?php
/**
 * Faixa exibida no formulário e na ficha de uma contrarrazão.
 *
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 * @var MapasCulturais\Entities\Registration|null $appeal
 * @var MapasCulturais\Entities\Opportunity $counter
 * @var string|null $listUrl
 * @var bool $showName nome do autor do recurso (configuração "Exibir o nome dos proponentes")
 */

use MapasCulturais\i;

$e = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
?>
<div class="counter-appeal-banner" v-pre>
    <div class="counter-appeal-banner__content">
        <strong><?= i::__('Contrarrazão ao recurso da inscrição') ?> <?= $e($appeal?->number) ?></strong>
        <?php if (($showName ?? true) && $appeal?->owner): ?> —<?= $e($appeal->owner->name) ?><?php endif; ?>
        <?php if ($counter->registrationTo): ?>
            <span class="counter-appeal-banner__deadline">
                · <?= i::__('prazo até') ?> <?= $e($counter->registrationTo->format('d/m/Y H:i')) ?>
            </span>
        <?php endif; ?>
        <?php if ($listUrl): ?>
            <a href="<?= $e($listUrl) ?>" class="counter-appeal-banner__link"><?= i::__('Ver os recursos') ?></a>
        <?php endif; ?>
    </div>
</div>
