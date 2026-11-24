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
                    <input type="search" v-model="filters[phase.counterId]" placeholder="<?= i::esc_attr__('Buscar pelo número da inscrição') ?>">
                </div>

                <p v-if="!phase.appeals.length"><?= i::__('Nenhum recurso foi apresentado nesta fase.') ?></p>

                <article v-for="appeal in filteredAppeals(phase)" :key="appeal.id" class="counter-appeal-list__appeal">
                    <header class="counter-appeal-list__appeal-header">
                        <div>
                            <h4 class="bold"><?= i::__('Recurso da inscrição') ?> {{ appeal.number }}</h4>
                            <p class="counter-appeal-list__muted"><?= i::__('Enviado em') ?> {{ formatDate(appeal.sentTimestamp) }}</p>
                        </div>
                        <span v-if="appeal.isMine" class="counter-appeal-list__badge"><?= i::__('Seu recurso') ?></span>
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

                    <!-- respostas da comissão (depois da publicação do resultado do recurso) -->
                    <template v-for="mine in appeal.myCounterAppeals" :key="'resp-' + mine.id">
                        <section v-if="mine.responses?.length" class="counter-appeal-list__response">
                            <h5 class="bold">
                                <?= i::__('Resposta da comissão à sua contrarrazão') ?>
                                <template v-if="phase.authorRegistrations.length > 1"> ({{ mine.authorNumber }})</template>
                            </h5>
                            <div v-for="(response, index) in mine.responses" :key="index" class="counter-appeal-list__response-item">
                                <p v-if="response.result"><strong>{{ response.result }}</strong></p>
                                <p class="counter-appeal-list__value">{{ response.text }}</p>
                            </div>
                        </section>
                    </template>

                    <section v-if="appeal.isMine && appeal.receivedCounterAppeals?.length" class="counter-appeal-list__response">
                        <h5 class="bold"><?= i::__('Contrarrazões ao seu recurso e respostas da comissão') ?></h5>
                        <div v-for="received in appeal.receivedCounterAppeals" :key="received.id" class="counter-appeal-list__response-item">
                            <p class="counter-appeal-list__muted">
                                <?= i::__('Contrarrazão da inscrição') ?> {{ received.authorNumber }}
                            </p>
                            <template v-if="received.responses.length">
                                <div v-for="(response, index) in received.responses" :key="index">
                                    <p v-if="response.result"><strong>{{ response.result }}</strong></p>
                                    <p class="counter-appeal-list__value">{{ response.text }}</p>
                                </div>
                            </template>
                            <p v-else class="counter-appeal-list__muted"><?= i::__('Sem resposta registrada.') ?></p>
                        </div>
                    </section>

                    <footer class="counter-appeal-list__actions">
                        <template v-if="!appeal.isMine">
                            <a v-for="mine in appeal.myCounterAppeals" :key="mine.id" :href="mine.status > 0 ? mine.singleUrl : mine.editUrl"
                               :class="['button', 'button--sm', mine.status > 0 ? 'button--primary-outline' : 'button--primary']">
                                <template v-if="mine.status > 0"><?= i::__('Ver contrarrazão enviada') ?></template>
                                <template v-else><?= i::__('Continuar contrarrazão') ?></template>
                                <template v-if="phase.authorRegistrations.length > 1"> ({{ mine.authorNumber }})</template>
                            </a>

                            <template v-if="phase.isOpen && availableAuthors(phase, appeal).length">
                                <select v-if="availableAuthors(phase, appeal).length > 1" v-model="selectedAuthor[appeal.id]" class="counter-appeal-list__author">
                                    <option :value="undefined" disabled><?= i::__('Apresentar como...') ?></option>
                                    <option v-for="author in availableAuthors(phase, appeal)" :key="author.id" :value="author.id">
                                        {{ author.number }} — {{ author.agentName }}
                                    </option>
                                </select>
                                <button class="button button--primary button--sm" :disabled="processing === appeal.id" @click="createCounterAppeal(phase, appeal)">
                                    <?= i::__('Apresentar contrarrazão') ?>
                                </button>
                            </template>
                        </template>
                    </footer>
                </article>
            </template>
        </div>
    </main>
</mc-container>
