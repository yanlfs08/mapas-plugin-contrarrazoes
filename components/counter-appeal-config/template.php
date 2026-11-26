<?php
/**
 * Acordeão "Contrarrazões" encaixado na "Etapa de recurso" da aba Configuração de fases,
 * entre os acordeões "Recurso" e "Resposta do recurso" (veja Plugin::injectCounterAppealConfig).
 *
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    entity-field
    mc-accordion
    mc-alert
    mc-confirm-button
    mc-icon
    mc-loading
');
?>
<div v-if="item" class="counter-appeal-config">
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
            <div v-if="item.counterEntity" class="dates opportunity-appeal-phase-config__dates">
                <div class="date">
                    <h6 class="date__title"> <?= i::__('Data de início') ?> </h6>
                    <h4 class="date__content">{{ item.counterEntity.registrationFrom?.date('numeric year') }} {{ item.counterEntity.registrationFrom?.time('numeric') }}</h4>
                </div>
                <div class="date">
                    <h6 class="date__title"> <?= i::__('Data final') ?> </h6>
                    <h4 class="date__content">{{ item.counterEntity.registrationTo?.date('numeric year') }} {{ item.counterEntity.registrationTo?.time('numeric') }}</h4>
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
            <div class="counter-appeal-config__content">
                <p class="counter-appeal-config__muted">
                    <?= i::__('Na fase de contrarrazões, os candidatos leem os recursos apresentados por outros candidatos e podem contestá-los antes do julgamento pela comissão.') ?>
                    <template v-if="item.appealPhase.sentAppeals !== undefined">
                        <?= i::__('Recursos enviados') ?>: <strong>{{ item.appealPhase.sentAppeals }}</strong>.
                    </template>
                </p>

                <!-- sem fase de contrarrazões -->
                <div v-if="!item.counterEntity" class="counter-appeal-config__empty">
                    <button v-if="!item.processing" class="button button--primary" @click="createCounterAppealPhase(item)">
                        <?= i::__('Adicionar contrarrazões') ?>
                    </button>
                    <mc-loading :condition="item.processing"><?= i::__('carregando') ?></mc-loading>
                </div>

                <!-- configuração -->
                <template v-else>
                    <section class="counter-appeal-config__section">
                        <h3 class="bold"><?= i::__('Configuração da fase') ?></h3>
                        <div class="opportunity-appeal-phase-config__datepicker">
                            <entity-field :entity="item.counterEntity" prop="registrationFrom" label="<?= i::esc_attr__('Início') ?>" classes="col-6 sm:col-12"></entity-field>
                            <entity-field :entity="item.counterEntity" prop="registrationTo" label="<?= i::esc_attr__('Término') ?>" classes="col-6 sm:col-12"></entity-field>
                        </div>
                        <p class="counter-appeal-config__muted"><?= i::__('Sugestão: comece depois do fim do prazo de recurso e termine antes do início da resposta do recurso.') ?></p>
                        <mc-alert v-if="item.appealPhase.judgementLocked" type="warning">
                            <?= i::__('Enquanto o prazo de contrarrazões não terminar, o julgamento dos recursos desta fase fica travado e o resultado da etapa de recurso não pode ser publicado. Sem prazo definido, os dois continuam travados.') ?>
                        </mc-alert>
                    </section>

                    <p class="counter-appeal-config__muted">
                        <?= i::__('Podem apresentar contrarrazões os inscritos na mesma etapa do recurso') ?><template v-if="item.appealPhase.evaluatedPhaseName"> ({{ item.appealPhase.evaluatedPhaseName }})</template>.
                        <?= i::__('O autor de um recurso não pode contestar o próprio recurso, e cada inscrição apresenta no máximo uma contrarrazão por recurso.') ?>
                    </p>

                    <p class="counter-appeal-config__muted">
                        <?= i::__('Os candidatos veem todos os campos e anexos do formulário de recurso, menos os de dados pessoais (CPF, CNPJ, e-mail, telefone, endereço, dados bancários, pessoas, agentes e espaços), por proteção de dados (LGPD).') ?>
                    </p>

                    <section class="counter-appeal-config__section">
                        <h5 class="bold"><?= i::__('Publicidade') ?></h5>
                        <label class="counter-appeal-config__option">
                            <input type="checkbox" v-model="item.form.counterAppealShowNames">
                            <?= i::__('Exibir o nome dos proponentes nos recursos e nas contrarrazões') ?>
                        </label>
                        <p class="counter-appeal-config__muted counter-appeal-config__hint">
                            <?= i::__('Desmarcado, os candidatos veem apenas o número da inscrição. A gestão e a comissão sempre veem o nome.') ?>
                        </p>
                        <label class="counter-appeal-config__option">
                            <input type="checkbox" v-model="item.form.counterAppealVisibleToAppellant">
                            <?= i::__('O autor do recurso pode ver as contrarrazões que o recurso dele recebeu') ?>
                        </label>
                        <p class="counter-appeal-config__muted counter-appeal-config__hint">
                            <?= i::__('As contrarrazões enviadas aparecem para ele na linha do tempo da inscrição, junto do "Seu recurso". Só são exibidos os campos de tipos publicáveis e os anexos.') ?>
                        </p>
                    </section>

                    <section class="counter-appeal-config__section">
                        <h5 class="bold"><?= i::__('Formulário da contrarrazão') ?></h5>
                        <mc-alert v-if="!item.formFieldsCount" type="warning">
                            <?= i::__('O formulário da contrarrazão ainda não tem campos. Crie os campos em "Configurar formulário" antes de abrir o prazo: sem eles, os candidatos não conseguem apresentar contrarrazões.') ?>
                        </mc-alert>
                        <p v-else class="counter-appeal-config__muted">
                            {{ item.formFieldsCount }} <?= i::__('campo(s) no formulário.') ?>
                            <?= i::__('Depois de alterar o formulário, recarregue esta página para atualizar a contagem.') ?>
                        </p>
                    </section>

                    <p class="counter-appeal-config__muted counter-appeal-config__autosave">
                        <?= i::__('As alterações desta seção são salvas automaticamente.') ?>
                        <span v-if="item.saving"><?= i::__('Salvando...') ?></span>
                        <span v-else-if="item.saved"><?= i::__('Salvo.') ?></span>
                    </p>

                    <div class="counter-appeal-config__actions">
                        <a :href="item.counterEntity.formBuilderUrl" class="button button--icon button--primary button--md" target="_blank">
                            <?= i::__('Configurar formulário') ?> <mc-icon name="external" size="sm"></mc-icon>
                        </a>
                        <mc-confirm-button @confirm="deleteCounterAppealPhase(item)">
                            <template #message>
                                <p><?= i::__('Tem certeza que deseja excluir a fase de contrarrazões?') ?></p>
                                <mc-alert type="warning">
                                    <strong><?= i::__('ATENÇÃO') ?>:</strong> <?= i::__('TODAS as contrarrazões recebidas serão excluídas permanentemente.') ?>
                                </mc-alert>
                            </template>
                            <template #button="modal">
                                <button class="button button--text button--sm counter-appeal-config__delete" @click="modal.open()">
                                    <mc-icon name="trash"></mc-icon> <?= i::__('Excluir contrarrazões') ?>
                                </button>
                            </template>
                        </mc-confirm-button>
                    </div>

                    <section class="counter-appeal-config__section">
                        <h5 class="bold"><?= i::__('Acompanhamento') ?></h5>
                        <p class="counter-appeal-config__muted">
                            {{ sentCount(item) }} <?= i::__('contrarrazão(ões) enviada(s) e') ?> {{ item.counterAppeals.length - sentCount(item) }} <?= i::__('em rascunho.') ?>
                            <?= i::__('A lista completa, os números por recurso e a exportação ficam na aba') ?> <strong><?= i::__('Inscrições e Resultados') ?></strong>, <?= i::__('no acordeão "Contrarrazões" desta mesma etapa de recurso.') ?>
                        </p>
                    </section>
                </template>
            </div>
        </template>
    </mc-accordion>
</div>
