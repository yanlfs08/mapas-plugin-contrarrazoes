<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    counter-appeal-list
    mc-modal
');
?>
<!-- só aparece para inscrições que estão na etapa do recurso (as únicas que podem contestar) -->
<div v-if="data && data.canSeeList" class="opportunity-phases-timeline__request-appeal__box counter-appeal-timeline">
    <div class="item__dot-appeal-phase"> <span class="dot"></span> </div>
    <div class="item__content">
        <div class="item__content--title"> <?= i::__('[Contrarrazões]') ?> </div>
        <div class="item__content--description">
            <h5 class="semibold">
                <?= i::__('de') ?> {{ formatDate(data.registrationFrom) }} <?= i::__('a') ?> {{ formatDate(data.registrationTo) }}
                <span v-if="data.isOpen" class="counter-appeal-list__badge counter-appeal-list__badge--open"><?= i::__('aberto') ?></span>
                <span v-else class="counter-appeal-list__badge"><?= i::__('encerrado') ?></span>
            </h5>
        </div>

        <div class="opportunity-phases-timeline__box counter-appeal-timeline__box">
            <template v-if="data.canSeeList">
                <p class="counter-appeal-timeline__summary">
                    <template v-if="data.appeals.length == 1"><?= i::__('1 recurso apresentado nesta fase.') ?></template>
                    <template v-else>{{ data.appeals.length }} <?= i::__('recursos apresentados nesta fase.') ?></template>
                    <template v-if="sentCount == 1"> <?= i::__('Você enviou 1 contrarrazão por esta inscrição.') ?></template>
                    <template v-else-if="sentCount > 1"> <?= i::__('Você enviou') ?> {{ sentCount }} <?= i::__('contrarrazões por esta inscrição.') ?></template>
                </p>
                <p v-if="draftCount" class="counter-appeal-timeline__summary counter-appeal-timeline__summary--warning">
                    <template v-if="draftCount == 1"><?= i::__('1 contrarrazão iniciada e ainda não enviada.') ?></template>
                    <template v-else>{{ draftCount }} <?= i::__('contrarrazões iniciadas e ainda não enviadas.') ?></template>
                </p>

                <mc-modal ref="modal" title="<?= i::esc_attr__('Recursos e contrarrazões') ?>" classes="counter-appeal-timeline__modal">
                    <counter-appeal-list :counter-id="data.counterId" hide-header></counter-appeal-list>

                    <template #button="modal">
                        <div class="opportunity-phases-timeline__request-appeal">
                            <h5 v-if="data.isOpen && availableCount" class="bold opportunity-phases-timeline__label--lowercase">
                                <?= i::__('Algum recurso pode prejudicar a sua inscrição?') ?>
                            </h5>
                            <button class="button button--primary button--primary-outline" @click="modal.open()">
                                <template v-if="data.isOpen"><?= i::__('Ver recursos e apresentar contrarrazão') ?></template>
                                <template v-else><?= i::__('Ver recursos e contrarrazões') ?></template>
                            </button>
                        </div>
                    </template>
                </mc-modal>
            </template>
        </div>
    </div>
</div>
