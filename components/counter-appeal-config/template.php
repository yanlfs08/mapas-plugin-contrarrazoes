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
                            <?= i::__('O julgamento dos recursos desta fase fica travado até o término do prazo de contrarrazões. Sem prazo definido, o julgamento continua travado.') ?>
                        </mc-alert>
                    </section>

                    <p class="counter-appeal-config__muted">
                        <?= i::__('Podem apresentar contrarrazões os inscritos na mesma etapa do recurso') ?><template v-if="item.appealPhase.evaluatedPhaseName"> ({{ item.appealPhase.evaluatedPhaseName }})</template>.
                        <?= i::__('O autor de um recurso não pode contestar o próprio recurso, e cada inscrição apresenta no máximo uma contrarrazão por recurso.') ?>
                    </p>

                    <section class="counter-appeal-config__section">
                        <h5 class="bold"><?= i::__('Campos do recurso visíveis para os candidatos') ?></h5>
                        <p class="counter-appeal-config__muted"><?= i::__('Marque o que os candidatos poderão ler de cada recurso. O número da inscrição e o nome do proponente são sempre exibidos.') ?></p>
                        <mc-alert type="helper">
                            <?= i::__('Por proteção de dados pessoais (LGPD), campos de CPF, CNPJ, e-mail, telefone, endereço, dados bancários, pessoas, agentes e espaços não podem ser publicados.') ?>
                        </mc-alert>
                        <div v-if="item.publicFieldOptions.length" class="counter-appeal-config__fields">
                            <label v-for="field in item.publicFieldOptions" :key="field.name" class="counter-appeal-config__option">
                                <input type="checkbox" :value="field.name" v-model="item.form.counterAppealPublicFields">
                                {{ field.title }} <small class="counter-appeal-config__muted">({{ fieldTypeLabel(field.type) }})</small>
                            </label>
                        </div>
                        <p v-else class="counter-appeal-config__muted">
                            <?= i::__('O formulário de recurso não tem campos que possam ser publicados. Se você acabou de configurar o formulário do recurso, recarregue a página.') ?>
                        </p>
                    </section>

                    <section class="counter-appeal-config__section">
                        <h5 class="bold"><?= i::__('Resposta às contrarrazões') ?></h5>
                        <p class="counter-appeal-config__muted">
                            <?= i::__('Depois do prazo de contrarrazões, o avaliador do recurso responde a cada contrarrazão na tela de avaliação. A resposta é obrigatória para finalizar o recurso e fica visível para o autor da contrarrazão e para o autor do recurso depois da publicação do resultado da etapa de recurso.') ?>
                        </p>
                        <label class="counter-appeal-config__option">
                            <input type="checkbox" v-model="item.form.counterAppealResponseClassify" @change="onClassifyChange(item)">
                            <?= i::__('Classificar a contrarrazão na resposta') ?>
                        </label>
                        <p v-if="!item.form.counterAppealResponseClassify" class="counter-appeal-config__muted">
                            <?= i::__('A resposta terá apenas o texto da fundamentação.') ?>
                        </p>
                        <div v-else class="counter-appeal-config__results">
                            <p class="counter-appeal-config__muted"><?= i::__('Opções que o avaliador escolhe ao responder (até 10):') ?></p>
                            <div v-for="(option, index) in item.form.counterAppealResponseResults" :key="index" class="counter-appeal-config__result">
                                <input type="text" v-model="item.form.counterAppealResponseResults[index]" maxlength="100">
                                <button class="button button--text button--sm counter-appeal-config__delete" @click="removeResultOption(item, index)" title="<?= i::esc_attr__('Remover opção') ?>">
                                    <mc-icon name="trash"></mc-icon>
                                </button>
                            </div>
                            <button v-if="item.form.counterAppealResponseResults.length < 10" class="button button--text button--sm" @click="addResultOption(item)">
                                <mc-icon name="add"></mc-icon> <?= i::__('Adicionar opção') ?>
                            </button>
                            <p class="counter-appeal-config__muted"><?= i::__('Mudar as opções não altera as respostas já registradas.') ?></p>
                        </div>
                    </section>

                    <div class="counter-appeal-config__actions">
                        <button class="button button--primary" :disabled="item.saving" @click="saveConfig(item)">
                            <?= i::__('Salvar configurações') ?>
                        </button>
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
                        <h5 class="bold"><?= i::__('Contrarrazões recebidas') ?> ({{ sentCount(item) }} <?= i::__('enviadas') ?>, {{ item.counterAppeals.length - sentCount(item) }} <?= i::__('em rascunho') ?>)</h5>
                        <div v-if="item.counterAppeals.length" class="counter-appeal-config__table-wrapper">
                            <table class="counter-appeal-config__table">
                                <thead>
                                    <tr>
                                        <th><?= i::__('Inscrição') ?></th>
                                        <th><?= i::__('Autor') ?></th>
                                        <th><?= i::__('Recurso contestado') ?></th>
                                        <th><?= i::__('Situação') ?></th>
                                        <th><?= i::__('Enviada em') ?></th>
                                        <th><?= i::__('Resposta') ?></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="ca in item.counterAppeals" :key="ca.id">
                                        <td>{{ ca.number }}</td>
                                        <td>{{ ca.agentName }} <small class="counter-appeal-config__muted">({{ ca.authorNumber }})</small></td>
                                        <td>{{ ca.targetNumber }} <small class="counter-appeal-config__muted">{{ ca.targetAgentName }}</small></td>
                                        <td>{{ statusLabel(ca.status) }}</td>
                                        <td>{{ formatDate(ca.sentTimestamp) }}</td>
                                        <td>
                                            {{ responseLabel(ca) }}
                                            <details v-if="ca.responses?.length" class="counter-appeal-config__response">
                                                <summary><?= i::__('ver') ?></summary>
                                                <div v-for="(response, index) in ca.responses" :key="index">
                                                    <small class="counter-appeal-config__muted">{{ response.evaluatorName }} · {{ formatDate(response.updated) }}</small>
                                                    <p v-if="response.result"><strong>{{ response.result }}</strong></p>
                                                    <p class="counter-appeal-list__value">{{ response.text }}</p>
                                                </div>
                                            </details>
                                        </td>
                                        <td><a :href="ca.singleUrl" target="_blank"><?= i::__('Ver') ?></a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="counter-appeal-config__muted"><?= i::__('Nenhuma contrarrazão recebida até o momento.') ?></p>
                    </section>
                </template>
            </div>
        </template>
    </mc-accordion>
</div>
