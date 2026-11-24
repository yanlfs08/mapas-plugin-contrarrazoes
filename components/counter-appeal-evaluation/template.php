<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-alert
    mc-icon
');
?>
<div class="counter-appeal-evaluation">
    <h4 class="bold"><?= i::__('Contrarrazões apresentadas a este recurso') ?> ({{ items.length }})</h4>
    <p class="counter-appeal-evaluation__muted">
        <?= i::__('Prazo de contrarrazões') ?>: {{ formatDate(config.registrationFrom) }} <?= i::__('a') ?> {{ formatDate(config.registrationTo) }}
        <template v-if="config.isOpen"> · <strong><?= i::__('prazo aberto: novas contrarrazões ainda podem chegar') ?></strong></template>
    </p>

    <mc-alert v-if="config.judgementLocked" type="warning">{{ config.lockMessage }}</mc-alert>

    <p v-if="!items.length" class="counter-appeal-evaluation__muted"><?= i::__('Nenhuma contrarrazão foi enviada a este recurso.') ?></p>

    <template v-else>
        <p class="counter-appeal-evaluation__progress">
            <strong>{{ answeredCount }} <?= i::__('de') ?> {{ items.length }}</strong>
            <template v-if="config.isOwnEvaluation"> <?= i::__('respondidas por você') ?></template>
            <template v-else> <?= i::__('respondidas por') ?> {{ config.evaluatorName }}</template>
        </p>
        <mc-alert v-if="config.responseRequired && pendingCount && config.isOwnEvaluation && !config.judgementLocked" type="helper">
            <?= i::__('Responda a todas as contrarrazões antes de finalizar a avaliação deste recurso. A resposta fica visível para o autor da contrarrazão e para o autor do recurso depois da publicação do resultado.') ?>
        </mc-alert>
        <mc-alert v-if="!config.canRespond && config.respondDeniedReason && !config.judgementLocked" type="helper">{{ config.respondDeniedReason }}</mc-alert>
    </template>

    <article v-for="item in items" :key="item.id" class="counter-appeal-evaluation__item">
        <header>
            <strong>{{ item.agentName }}</strong>
            <span class="counter-appeal-evaluation__muted"> · <?= i::__('inscrição') ?> {{ item.authorNumber }} · <?= i::__('enviada em') ?> {{ formatDate(item.sentTimestamp) }}</span>
            <span v-if="item.response?.text" class="counter-appeal-evaluation__badge counter-appeal-evaluation__badge--done"><?= i::__('Respondida') ?></span>
            <span v-else class="counter-appeal-evaluation__badge counter-appeal-evaluation__badge--pending"><?= i::__('Pendente') ?></span>
            <a :href="item.singleUrl" target="_blank" class="counter-appeal-evaluation__link"><?= i::__('abrir') ?> <mc-icon name="external" size="sm"></mc-icon></a>
        </header>
        <dl class="counter-appeal-evaluation__fields">
            <template v-for="field in item.fields" :key="field.name">
                <dt>{{ field.title }}</dt>
                <dd v-if="field.files">
                    <a v-for="file in field.files" :key="file.url" :href="file.url" target="_blank">
                        <mc-icon name="download" size="sm"></mc-icon> {{ file.name }}
                    </a>
                </dd>
                <dd v-else class="counter-appeal-evaluation__value">{{ field.value }}</dd>
            </template>
        </dl>

        <section class="counter-appeal-evaluation__response">
            <h5 class="bold"><?= i::__('Resposta à contrarrazão') ?></h5>

            <!-- formulário -->
            <div v-if="config.canRespond && item.editing" class="counter-appeal-evaluation__form">
                <div v-if="hasResultOptions" class="field">
                    <label class="semibold"><?= i::__('Classificação') ?> <span class="required">*</span></label>
                    <div class="counter-appeal-evaluation__options">
                        <label v-for="option in config.resultOptions" :key="option" class="counter-appeal-evaluation__option">
                            <input type="radio" :name="'counter-appeal-result-' + item.id" :value="option" v-model="item.form.result"> {{ option }}
                        </label>
                    </div>
                    <small v-if="item.errors.result" class="field__error">{{ item.errors.result }}</small>
                </div>
                <div class="field">
                    <label class="semibold" :for="'counter-appeal-text-' + item.id"><?= i::__('Fundamentação') ?> <span class="required">*</span></label>
                    <textarea :id="'counter-appeal-text-' + item.id" v-model="item.form.text" rows="6" :maxlength="config.responseMaxLength"></textarea>
                    <small v-if="item.errors.text" class="field__error">{{ item.errors.text }}</small>
                </div>
                <div class="counter-appeal-evaluation__actions">
                    <button class="button button--primary button--sm" :disabled="item.saving" @click="save(item)"><?= i::__('Salvar resposta') ?></button>
                    <button v-if="item.response" class="button button--text button--sm" :disabled="item.saving" @click="cancel(item)"><?= i::__('Cancelar') ?></button>
                </div>
            </div>

            <!-- resposta registrada -->
            <div v-else-if="item.response?.text" class="counter-appeal-evaluation__answer">
                <p v-if="item.response.result"><strong>{{ item.response.result }}</strong></p>
                <p class="counter-appeal-evaluation__value">{{ item.response.text }}</p>
                <p class="counter-appeal-evaluation__muted"><?= i::__('Atualizada em') ?> {{ formatDate(item.response.updated) }}</p>
                <button v-if="config.canRespond" class="button button--text button--sm" @click="edit(item)">
                    <mc-icon name="edit" size="sm"></mc-icon> <?= i::__('Editar resposta') ?>
                </button>
            </div>

            <p v-else class="counter-appeal-evaluation__muted"><?= i::__('Ainda sem resposta.') ?></p>
        </section>
    </article>
</div>
