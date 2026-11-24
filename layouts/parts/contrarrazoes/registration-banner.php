<?php
/**
 * Faixa exibida no formulário e na ficha de uma contrarrazão.
 *
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 * @var MapasCulturais\Entities\Registration|null $appeal
 * @var MapasCulturais\Entities\Opportunity $counter
 * @var string|null $listUrl
 * @var array $responses respostas da comissão (só depois da publicação do resultado do recurso)
 * @var bool $resultPublished
 */

use MapasCulturais\i;

$e = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
$responses = $responses ?? [];
$resultPublished = $resultPublished ?? false;
?>
<div class="counter-appeal-banner" v-pre>
    <div class="counter-appeal-banner__content">
        <strong><?= i::__('Contrarrazão ao recurso da inscrição') ?> <?= $e($appeal?->number) ?></strong>
        <?php if ($counter->registrationTo): ?>
            <span class="counter-appeal-banner__deadline">
                · <?= i::__('prazo até') ?> <?= $e($counter->registrationTo->format('d/m/Y H:i')) ?>
            </span>
        <?php endif; ?>
        <?php if ($listUrl): ?>
            <a href="<?= $e($listUrl) ?>" class="counter-appeal-banner__link"><?= i::__('Ver os recursos') ?></a>
        <?php endif; ?>

        <?php if ($resultPublished): ?>
            <div class="counter-appeal-banner__response">
                <strong><?= i::__('Resposta da comissão') ?></strong>
                <?php if ($responses): ?>
                    <?php foreach ($responses as $response): ?>
                        <?php if (!empty($response['result'])): ?><p><strong><?= $e($response['result']) ?></strong></p><?php endif; ?>
                        <p><?= $e($response['text']) ?></p>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p><?= i::__('Sem resposta registrada.') ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
