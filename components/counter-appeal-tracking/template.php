<?php
/**
 * Listas de acompanhamento das contrarrazões (página aberta em nova aba pelo gestor).
 *
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-alert
    mc-icon
');
?>
<div class="counter-appeal-tracking">
    <mc-alert v-if="!counter" type="helper">
        <?= i::__('Este recurso ainda não tem fase de contrarrazões. Para criar, use o acordeão "Contrarrazões" da aba "Configuração de fases".') ?>
    </mc-alert>

    <template v-else>
        <!-- linha de situação: prazo e totais -->
        <div class="counter-appeal-tracking__status">
            <span>
                <?= i::__('Prazo') ?>: <strong>{{ formatDate(counter.registrationFrom) || '—' }}</strong> <?= i::__('a') ?> <strong>{{ formatDate(counter.registrationTo) || '—' }}</strong>
                <span v-if="period === 'open'" class="counter-appeal-list__badge counter-appeal-list__badge--open"><?= i::__('aberto') ?></span>
                <span v-else-if="period === 'notStarted'" class="counter-appeal-list__badge"><?= i::__('não iniciado') ?></span>
                <span v-else-if="period === 'closed'" class="counter-appeal-list__badge"><?= i::__('encerrado') ?></span>
                <span v-else class="counter-appeal-list__badge counter-appeal-registrations__badge--warning"><?= i::__('não definido') ?></span>
            </span>
            <span><?= i::__('Recursos enviados') ?>: <strong>{{ appeals.length }}</strong></span>
            <span><?= i::__('Contrarrazões enviadas') ?>: <strong>{{ sent.length }}</strong></span>
            <span><?= i::__('Em rascunho') ?>: <strong>{{ drafts.length }}</strong></span>
        </div>

        <!-- por recurso -->
        <section class="counter-appeal-tracking__section">
            <h3 class="bold"><?= i::__('Contrarrazões por recurso') ?></h3>
            <p v-if="!appealRows.length" class="counter-appeal-config__muted"><?= i::__('Nenhum recurso foi enviado nesta fase.') ?></p>
            <div v-else class="counter-appeal-config__table-wrapper">
                <table class="counter-appeal-config__table">
                    <thead>
                        <tr>
                            <th><?= i::__('Recurso') ?></th>
                            <th><?= i::__('Proponente') ?></th>
                            <th><?= i::__('Situação do recurso') ?></th>
                            <th><?= i::__('Enviadas') ?></th>
                            <th><?= i::__('Rascunhos') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in appealRows" :key="row.id" :class="{'counter-appeal-registrations__row--selected': String(filters.appeal) === String(row.id)}">
                            <td><a :href="row.singleUrl" target="_blank">{{ row.number }}</a></td>
                            <td>{{ row.agentName }}</td>
                            <td>{{ row.statusLabel }}</td>
                            <td><strong>{{ row.sentCount }}</strong></td>
                            <td>{{ row.draftCount }}</td>
                            <td>
                                <button v-if="row.sentCount || row.draftCount" class="button button--text button--sm" @click="filterByAppeal(row)">
                                    <?= i::__('Ver contrarrazões') ?>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- lista completa -->
        <section ref="list" class="counter-appeal-tracking__section">
            <div class="counter-appeal-registrations__list-header">
                <h3 class="bold"><?= i::__('Contrarrazões recebidas') ?></h3>
                <button class="button button--primary-outline button--sm button--icon" :disabled="!filtered.length" @click="exportCsv()">
                    <mc-icon name="download"></mc-icon> <?= i::__('Exportar planilha (CSV)') ?>
                </button>
            </div>

            <div v-if="counterAppeals.length" class="counter-appeal-registrations__filters">
                <div class="field">
                    <label><?= i::__('Recurso') ?></label>
                    <select v-model="filters.appeal">
                        <option value=""><?= i::__('Todos os recursos') ?></option>
                        <option v-for="row in appealRows" :key="row.id" :value="row.id">{{ row.number }} — {{ row.agentName }}</option>
                    </select>
                </div>
                <div class="field">
                    <label><?= i::__('Situação') ?></label>
                    <select v-model="filters.status">
                        <option value=""><?= i::__('Todas as situações') ?></option>
                        <option value="sent"><?= i::__('Enviadas') ?></option>
                        <option value="draft"><?= i::__('Em rascunho') ?></option>
                    </select>
                </div>
                <div class="field">
                    <label><?= i::__('Busca') ?></label>
                    <input type="search" v-model="filters.search" placeholder="<?= i::esc_attr__('Número da inscrição ou nome') ?>">
                </div>
                <button v-if="hasFilters" class="button button--text button--sm counter-appeal-registrations__clear" @click="clearFilters()"><?= i::__('Limpar filtros') ?></button>
            </div>

            <p v-if="counterAppeals.length" class="counter-appeal-config__muted">
                <?= i::__('Mostrando') ?> {{ filtered.length }} <?= i::__('de') ?> {{ counterAppeals.length }}.
            </p>

            <div v-if="filtered.length" class="counter-appeal-config__table-wrapper">
                <table class="counter-appeal-config__table">
                    <thead>
                        <tr>
                            <th><?= i::__('Contrarrazão') ?></th>
                            <th><?= i::__('Autor') ?></th>
                            <th><?= i::__('Recurso contestado') ?></th>
                            <th><?= i::__('Situação') ?></th>
                            <th><?= i::__('Enviada em') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="ca in filtered" :key="ca.id">
                            <td>{{ ca.number }}</td>
                            <td>{{ ca.agentName }} <small class="counter-appeal-config__muted">({{ ca.authorNumber }})</small></td>
                            <td>{{ ca.targetNumber }} <small class="counter-appeal-config__muted">{{ ca.targetAgentName }}</small></td>
                            <td>
                                <span :class="['counter-appeal-list__badge', {'counter-appeal-list__badge--open': ca.status > 0}]">{{ statusLabel(ca.status) }}</span>
                            </td>
                            <td>{{ formatDate(ca.sentTimestamp) }}</td>
                            <td><a :href="ca.singleUrl" target="_blank"><?= i::__('Ver') ?> <mc-icon name="external" size="sm"></mc-icon></a></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else-if="counterAppeals.length" class="counter-appeal-config__muted"><?= i::__('Nenhuma contrarrazão com esses filtros.') ?></p>
            <p v-else class="counter-appeal-config__muted"><?= i::__('Nenhuma contrarrazão recebida até o momento.') ?></p>
        </section>
    </template>
</div>
