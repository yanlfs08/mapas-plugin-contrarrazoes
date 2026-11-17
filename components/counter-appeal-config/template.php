<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    entity-field
    mc-container
    mc-alert
    mc-confirm-button
    mc-icon
    mc-loading
');
?>
<mc-container>
<div class="counter-appeal-config">
    <div class="counter-appeal-config__intro">
        <h3 class="bold"><?= i::__('Contrarrazões aos recursos') ?></h3>
        <p><?= i::__('Na fase de contrarrazões, os candidatos leem os recursos apresentados por outros candidatos e podem contestá-los antes do julgamento pela comissão.') ?></p>
    </div>

    <div v-for="item in items" :key="item.appealPhase.id" class="counter-appeal-config__phase">
        <div class="counter-appeal-config__phase-header">
            <div>
                <h4 class="bold">{{ item.appealPhase.name }}</h4>
                <p class="counter-appeal-config__muted">
                    <?= i::__('Etapa avaliada') ?>: <strong>{{ item.appealPhase.evaluatedPhaseName }}</strong>
                    · <?= i::__('Recursos enviados') ?>: <strong>{{ item.appealPhase.sentAppeals }}</strong>
                </p>
            </div>
        </div>

        <!-- sem fase de contrarrazões -->
        <div v-if="!item.counterEntity" class="counter-appeal-config__empty">
            <button v-if="!item.processing" class="button button--primary" @click="createCounterAppealPhase(item)">
                <?= i::__('Adicionar contrarrazões') ?>
            </button>
            <mc-loading :condition="item.processing"><?= i::__('carregando') ?></mc-loading>
        </div>

        <!-- configuração -->
        <div v-else class="counter-appeal-config__content">
            <section class="counter-appeal-config__section">
                <h5 class="bold"><?= i::__('Prazo para apresentar contrarrazões') ?></h5>
                <div class="grid-12">
                    <entity-field :entity="item.counterEntity" prop="registrationFrom" label="<?= i::esc_attr__('Início') ?>" classes="col-6 sm:col-12"></entity-field>
                    <entity-field :entity="item.counterEntity" prop="registrationTo" label="<?= i::esc_attr__('Término') ?>" classes="col-6 sm:col-12"></entity-field>
                </div>
                <mc-alert v-if="item.appealPhase.judgementLocked" type="warning">
                    <?= i::__('O julgamento dos recursos desta fase fica travado até o término do prazo de contrarrazões. Sem prazo definido, o julgamento continua travado.') ?>
                </mc-alert>
            </section>

            <section class="counter-appeal-config__section">
                <h5 class="bold"><?= i::__('Quem pode apresentar contrarrazões') ?></h5>
                <label class="counter-appeal-config__option">
                    <input type="radio" value="all" v-model="item.form.counterAppealEligibility">
                    <?= i::__('Todos os inscritos na oportunidade (inclusive quem não avançou de fase)') ?>
                </label>
                <label class="counter-appeal-config__option">
                    <input type="radio" value="samePhase" v-model="item.form.counterAppealEligibility">
                    <?= i::__('Somente inscritos na mesma etapa do recurso') ?> ({{ item.appealPhase.evaluatedPhaseName }})
                </label>
                <p class="counter-appeal-config__muted"><?= i::__('Em qualquer caso, o autor de um recurso não pode apresentar contrarrazão ao próprio recurso, e cada candidato apresenta no máximo uma contrarrazão por recurso.') ?></p>
            </section>

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
                <p v-else class="counter-appeal-config__muted"><?= i::__('O formulário de recurso não tem campos que possam ser publicados.') ?></p>
            </section>

            <div class="counter-appeal-config__actions">
                <button class="button button--primary" :disabled="item.saving" @click="saveConfig(item)">
                    <?= i::__('Salvar configurações') ?>
                </button>
                <a :href="item.counterEntity.formBuilderUrl" class="button button--primary-outline button--icon" target="_blank">
                    <?= i::__('Configurar formulário de contrarrazões') ?> <mc-icon name="external" size="sm"></mc-icon>
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
                                <td><a :href="ca.singleUrl" target="_blank"><?= i::__('Ver') ?></a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="counter-appeal-config__muted"><?= i::__('Nenhuma contrarrazão recebida até o momento.') ?></p>
            </section>
        </div>
    </div>
</div>
</mc-container>
