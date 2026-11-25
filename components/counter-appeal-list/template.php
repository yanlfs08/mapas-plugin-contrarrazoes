<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-alert
    mc-container
    mc-icon
');
?>
<mc-container class="counter-appeal-list">
    <main class="grid-12">
        <div v-for="phase in phases" :key="phase.counterId" class="col-12 counter-appeal-list__phase">
            <h3 v-if="!hideHeader" class="bold"><?= i::__('Contrarrazões aos recursos') ?> — {{ phase.evaluatedPhaseName }}</h3>
            <p v-if="!hideHeader" class="counter-appeal-list__period">
                <?= i::__('Prazo para apresentar contrarrazões') ?>:
                <strong>{{ formatDate(phase.registrationFrom) }}</strong> <?= i::__('a') ?> <strong>{{ formatDate(phase.registrationTo) }}</strong>
                <span v-if="phase.isOpen" class="counter-appeal-list__badge counter-appeal-list__badge--open"><?= i::__('aberto') ?></span>
                <span v-else class="counter-appeal-list__badge"><?= i::__('encerrado') ?></span>
            </p>

            <mc-alert v-if="isGuest" type="helper">
                <?= i::__('Entre na sua conta para ler os recursos e apresentar contrarrazões.') ?>
                <a :href="loginUrl" class="button button--primary button--sm"><?= i::__('Entrar') ?></a>
            </mc-alert>

            <mc-alert v-else-if="!phase.canSeeList" type="helper">
                <?= i::__('Somente inscrições que participam da mesma etapa dos recursos podem ler os recursos e apresentar contrarrazões. Esta inscrição não está nessa etapa.') ?>
            </mc-alert>

            <template v-else>
                <p class="counter-appeal-list__help">
                    <?= i::__('Leia os recursos apresentados pelos demais candidatos. Se algum deles puder prejudicar a sua situação no edital, você pode apresentar uma contrarrazão (uma por recurso) dentro do prazo.') ?>
                </p>
                <mc-alert v-if="registrationNumber" type="helper">
                    <?= i::__('As contrarrazões apresentadas aqui ficam vinculadas à inscrição') ?> <strong>{{ registrationNumber }}</strong>.
                    <?= i::__('Se você tiver outra inscrição neste edital, use a linha do tempo da outra inscrição para apresentar contrarrazões por ela.') ?>
                </mc-alert>

                <div v-if="phase.appeals.length > 5" class="field counter-appeal-list__filter">
                    <input type="search" v-model="filters[phase.counterId]" :placeholder="phase.showNames ? '<?= i::esc_attr__('Buscar por número da inscrição ou nome do proponente') ?>' : '<?= i::esc_attr__('Buscar por número da inscrição') ?>'">
                </div>

                <p v-if="!phase.appeals.length"><?= i::__('Nenhum recurso foi apresentado nesta fase.') ?></p>

                <!-- duas listas com título e divisória: o(s) recurso(s) do próprio inscrito e os demais -->
                <template v-for="group in appealGroups(phase)" :key="group.key">
                <div v-if="group.titled" :class="['counter-appeal-list__group-title', 'counter-appeal-list__group-title--' + group.key]">
                    <h4 class="bold">
                        <template v-if="group.key === 'mine'">
                            <template v-if="group.items.length > 1"><?= i::__('Seus recursos') ?></template>
                            <template v-else><?= i::__('Seu recurso') ?></template>
                        </template>
                        <template v-else><?= i::__('Recursos dos demais candidatos') ?></template>
                        <span class="counter-appeal-list__group-count">({{ group.items.length }})</span>
                    </h4>
                    <p v-if="group.key === 'mine' && phase.visibleToAppellant" class="counter-appeal-list__muted"><?= i::__('As contrarrazões que o seu recurso receber aparecem logo abaixo dele.') ?></p>
                    <p v-if="group.key === 'others' && phase.isOpen" class="counter-appeal-list__muted"><?= i::__('Se algum destes recursos puder prejudicar a sua inscrição, apresente uma contrarrazão.') ?></p>
                </div>
                <article v-for="appeal in group.items" :key="appeal.id"
                         :class="['counter-appeal-list__appeal', {'counter-appeal-list__appeal--mine': appeal.isMine}]">
                    <header class="counter-appeal-list__appeal-header">
                        <div>
                            <h4 class="bold"><?= i::__('Recurso da inscrição') ?> {{ appeal.number }}</h4>
                            <p class="counter-appeal-list__muted"><template v-if="appeal.agentName">{{ appeal.agentName }} · </template><?= i::__('enviado em') ?> {{ formatDate(appeal.sentTimestamp) }}</p>
                        </div>
                    </header>

                    <dl v-if="appeal.fields.length" class="counter-appeal-list__fields">
                        <template v-for="field in appeal.fields" :key="field.name">
                            <dt>{{ field.title }}</dt>
                            <dd v-if="field.files">
                                <a v-for="file in field.files" :key="file.url" :href="file.url" class="counter-appeal-list__file" target="_blank">
                                    <mc-icon name="download" size="sm"></mc-icon> {{ file.name }}
                                </a>
                            </dd>
                            <dd v-else class="counter-appeal-list__value">{{ field.value }}</dd>
                        </template>
                    </dl>

                    <!-- contrarrazões recebidas pelo recurso do próprio candidato (se a gestão liberou) -->
                    <section v-if="appeal.isMine && phase.visibleToAppellant" class="counter-appeal-list__received">
                        <h5 class="bold"><?= i::__('Contrarrazões recebidas por este recurso') ?> ({{ appeal.receivedCounterAppeals.length }})</h5>
                        <p v-if="!appeal.receivedCounterAppeals.length" class="counter-appeal-list__muted"><?= i::__('Nenhuma contrarrazão foi enviada a este recurso até o momento.') ?></p>
                        <div v-for="received in appeal.receivedCounterAppeals" :key="received.id" class="counter-appeal-list__counter">
                            <div class="counter-appeal-list__counter-header">
                                <span>
                                    <?= i::__('Contrarrazão da inscrição') ?> <strong>{{ received.authorNumber }}</strong><template v-if="received.agentName"> — {{ received.agentName }}</template>
                                    <small class="counter-appeal-list__muted"> · <?= i::__('enviada em') ?> {{ formatDate(received.sentTimestamp) }}</small>
                                </span>
                                <button class="button button--sm button--primary-outline" @click="toggle(received.id)">
                                    <template v-if="expanded[received.id]"><?= i::__('Ocultar contrarrazão') ?></template>
                                    <template v-else><?= i::__('Exibir contrarrazão') ?></template>
                                </button>
                            </div>
                            <dl v-if="expanded[received.id]" class="counter-appeal-list__fields">
                                <template v-for="field in received.fields" :key="field.name">
                                    <dt>{{ field.title }}</dt>
                                    <dd v-if="field.files">
                                        <a v-for="file in field.files" :key="file.url" :href="file.url" class="counter-appeal-list__file" target="_blank">
                                            <mc-icon name="download" size="sm"></mc-icon> {{ file.name }}
                                        </a>
                                    </dd>
                                    <dd v-else class="counter-appeal-list__value">{{ field.value }}</dd>
                                </template>
                                <dd v-if="!received.fields.length" class="counter-appeal-list__muted"><?= i::__('Sem conteúdo para exibir.') ?></dd>
                            </dl>
                        </div>
                    </section>

                    <!-- contrarrazão do candidato a este recurso: o conteúdo enviado aparece aqui, sem outra tela -->
                    <div v-for="mine in appeal.myCounterAppeals" :key="'mine-' + mine.id" class="counter-appeal-list__counter">
                        <div class="counter-appeal-list__counter-header">
                            <span v-if="mine.status > 0">
                                <?= i::__('Sua contrarrazão') ?><template v-if="phase.authorRegistrations.length > 1"> ({{ mine.authorNumber }})</template>
                                <small class="counter-appeal-list__muted"> · <?= i::__('enviada em') ?> {{ formatDate(mine.sentTimestamp) }}</small>
                            </span>
                            <span v-else><?= i::__('Sua contrarrazão ainda não foi enviada.') ?></span>
                            <button v-if="mine.status > 0" class="button button--sm button--primary-outline" @click="toggle(mine.id)">
                                <template v-if="expanded[mine.id]"><?= i::__('Ocultar contrarrazão') ?></template>
                                <template v-else><?= i::__('Exibir contrarrazão') ?></template>
                            </button>
                            <a v-else :href="mine.editUrl" class="button button--sm button--primary"><?= i::__('Continuar contrarrazão') ?></a>
                        </div>
                        <dl v-if="mine.status > 0 && expanded[mine.id]" class="counter-appeal-list__fields">
                            <template v-for="field in mine.fields" :key="field.name">
                                <dt>{{ field.title }}</dt>
                                <dd v-if="field.files">
                                    <a v-for="file in field.files" :key="file.url" :href="file.url" class="counter-appeal-list__file" target="_blank">
                                        <mc-icon name="download" size="sm"></mc-icon> {{ file.name }}
                                    </a>
                                </dd>
                                <dd v-else class="counter-appeal-list__value">{{ field.value }}</dd>
                            </template>
                        </dl>
                    </div>

                    <footer v-if="!appeal.isMine && phase.isOpen && availableAuthors(phase, appeal).length" class="counter-appeal-list__actions">
                        <template v-if="phase.formReady">
                            <select v-if="availableAuthors(phase, appeal).length > 1" v-model="selectedAuthor[appeal.id]" class="counter-appeal-list__author">
                                <option :value="undefined" disabled><?= i::__('Apresentar como...') ?></option>
                                <option v-for="author in availableAuthors(phase, appeal)" :key="author.id" :value="author.id">
                                    {{ author.number }}<template v-if="author.agentName"> — {{ author.agentName }}</template>
                                </option>
                            </select>
                            <button class="button button--primary button--sm" :disabled="processing === appeal.id" @click="createCounterAppeal(phase, appeal)">
                                <?= i::__('Apresentar contrarrazão') ?>
                            </button>
                        </template>
                        <span v-else class="counter-appeal-list__muted"><?= i::__('O formulário de contrarrazão ainda não foi disponibilizado pela gestão do edital.') ?></span>
                    </footer>
                </article>
                </template>
            </template>
        </div>
    </main>
</mc-container>
