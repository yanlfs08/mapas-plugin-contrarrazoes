<?php
/**
 * Acordeão "Contrarrazões" da aba "Inscrições e Resultados" (acompanhamento do gestor),
 * entre os acordeões "Recurso" e "Resposta do recurso" (veja Plugin::injectCounterAppealConfig).
 * Mostra só o resumo; as listas abrem em nova aba (counter-appeal-tracking, página counterAppeals).
 *
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-accordion
    mc-alert
    mc-icon
');
?>
<div class="counter-appeal-config counter-appeal-registrations">
    <mc-accordion :withText="true">
        <template #title>
            <div class="opportunity-appeal-phase-config__title">
                <h3 class="bold"><?= i::__('Contrarrazões') ?></h3>
                <div class="info__type">
                    <span class="title">
                        <?= i::__('Tipo') ?>:
                        <span class="type"><?= i::__('Coleta de dados') ?></span>
                    </span>
                </div>
            </div>
            <div v-if="counter" class="dates opportunity-appeal-phase-config__dates">
                <div class="date">
                    <h6 class="date__title"> <?= i::__('Data de início') ?> </h6>
                    <h4 class="date__content">{{ counter.registrationFrom?.date('numeric year') }} {{ counter.registrationFrom?.time('numeric') }}</h4>
                </div>
                <div class="date">
                    <h6 class="date__title"> <?= i::__('Data final') ?> </h6>
                    <h4 class="date__content">{{ counter.registrationTo?.date('numeric year') }} {{ counter.registrationTo?.time('numeric') }}</h4>
                </div>
            </div>
            <div v-else class="dates opportunity-appeal-phase-config__dates">
                <div class="date">
                    <h6 class="date__title"> <?= i::__('Não configurada') ?> </h6>
                </div>
            </div>
        </template>
        <template #icon>
        </template>

        <template #content>
            <div class="counter-appeal-registrations__content">
                <mc-alert v-if="!counter" type="helper">
                    <?= i::__('Este recurso ainda não tem fase de contrarrazões. Para criar, use o acordeão "Contrarrazões" da aba "Configuração de fases".') ?>
                </mc-alert>

                <!-- resumo, no mesmo formato do "Resumo" do acordeão Recurso -->
                <div v-else class="counter-appeal-registrations__summary">
                    <div class="counter-appeal-registrations__summary-grid">
                        <div>
                            <h4 class="bold"><?= i::__('Prazo') ?></h4>
                            <p>
                                {{ formatDate(counter.registrationFrom) || '—' }} <?= i::__('a') ?> {{ formatDate(counter.registrationTo) || '—' }}
                                <span v-if="period === 'open'" class="counter-appeal-list__badge counter-appeal-list__badge--open"><?= i::__('aberto') ?></span>
                                <span v-else-if="period === 'notStarted'" class="counter-appeal-list__badge"><?= i::__('não iniciado') ?></span>
                                <span v-else-if="period === 'closed'" class="counter-appeal-list__badge"><?= i::__('encerrado') ?></span>
                                <span v-else class="counter-appeal-list__badge counter-appeal-registrations__badge--warning"><?= i::__('não definido') ?></span>
                            </p>
                            <br>
                            <h4 class="bold"><?= i::__('Resumo') ?></h4>
                            <p><?= i::__('Recursos enviados') ?>: <strong>{{ appeals.length }}</strong></p>
                            <p><?= i::__('Recursos que receberam contrarrazão') ?>: <strong>{{ contestedCount }}</strong> <?= i::__('de') ?> {{ appeals.length }}</p>
                            <p><?= i::__('Contrarrazões <strong>enviadas</strong>') ?>: <strong>{{ sent.length }}</strong></p>
                            <p><?= i::__('Contrarrazões em <strong>rascunho</strong>') ?>: <strong>{{ drafts.length }}</strong></p>
                            <p><?= i::__('Inscrições que apresentaram contrarrazão') ?>: <strong>{{ authorsCount }}</strong></p>

                            <div class="counter-appeal-registrations__actions">
                                <a :href="item.trackingUrl" target="_blank" class="button button--primary button--icon">
                                    <h4 class="semibold"><?= i::__('Lista de contrarrazões') ?></h4>
                                    <mc-icon name="external"></mc-icon>
                                </a>
                            </div>
                        </div>
                        <div class="counter-appeal-registrations__status">
                            <mc-alert v-if="locked" type="warning">
                                <template v-if="period === 'undefined'"><?= i::__('O prazo de contrarrazões não foi definido. Enquanto isso, o julgamento dos recursos e a publicação do resultado da etapa de recurso ficam travados.') ?></template>
                                <template v-else><?= i::__('Até o fim do prazo de contrarrazões, o julgamento dos recursos e a publicação do resultado da etapa de recurso ficam travados.') ?></template>
                            </mc-alert>
                            <mc-alert v-else type="success">
                                <?= i::__('Prazo de contrarrazões encerrado: a comissão já pode julgar os recursos e o resultado da etapa de recurso pode ser publicado.') ?>
                            </mc-alert>
                            <p v-if="drafts.length && period === 'closed'" class="counter-appeal-config__muted">
                                <?= i::__('Rascunhos não enviados até o fim do prazo não são considerados no julgamento.') ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </mc-accordion>
</div>
