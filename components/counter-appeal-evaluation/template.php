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

    <article v-for="item in items" :key="item.id" class="counter-appeal-evaluation__item">
        <header>
            <strong>{{ item.agentName }}</strong>
            <span class="counter-appeal-evaluation__muted"> · <?= i::__('inscrição') ?> {{ item.authorNumber }} · <?= i::__('enviada em') ?> {{ formatDate(item.sentTimestamp) }}</span>
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
    </article>
</div>
