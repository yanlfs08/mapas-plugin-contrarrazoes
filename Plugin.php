<?php

namespace Contrarrazoes;

use DateTime;
use MapasCulturais\App;
use MapasCulturais\Entities\ChatMessage;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\Entities\RegistrationStep;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasCulturais\i;

/**
 * Plugin de contrarrazões para a fase de recurso do Mapas Culturais.
 *
 * Modelo de dados (sem tabelas novas):
 *
 * - Fase de contrarrazões: uma Opportunity de primeiro nível, oculta (status -1),
 *   com o metadado `isCounterAppealPhase` e `counterAppealOf` = id da fase de recurso.
 *   A fase de recurso guarda o id da fase de contrarrazões em `counterAppealPhase`.
 * - Contrarrazão: uma Registration na fase de contrarrazões, com os metadados
 *   `counterAppealTarget` (id da inscrição de recurso contestada) e
 *   `counterAppealAuthorNumber` (número da inscrição do autor no edital).
 */
class Plugin extends \MapasCulturais\Plugin
{
    const VERSION = '1.4.4';

    /** Faixa de versões do Mapas Culturais em que o plugin foi testado */
    const TESTED_MAPAS_VERSIONS = '7.8';

    const ELIGIBILITY_ALL = 'all';
    const ELIGIBILITY_SAME_PHASE = 'samePhase';

    /**
     * Tipos de campo do formulário de recurso que o gestor pode tornar públicos.
     * Tipos que costumam carregar dados pessoais (CPF, CNPJ, e-mail, telefone, endereço,
     * dados bancários, pessoas, agentes e espaços) ficam de fora por padrão (LGPD).
     */
    const PUBLIC_FIELD_TYPES = [
        'text', 'textarea', 'select', 'checkboxes', 'checkbox', 'number',
        'currency', 'date', 'url', 'links', 'custom-table', 'municipio',
    ];

    function __construct(array $config = [])
    {
        $config += [
            // tipos de campo do recurso que podem ser publicados
            'publicFieldTypes' => self::PUBLIC_FIELD_TYPES,

            // trava o julgamento dos recursos (e a publicação do resultado da etapa de recurso)
            // até o fim do prazo de contrarrazões
            'lockAppealJudgement' => true,

            // não envia os e-mails automáticos do Mapas (módulo MailNotification) quando o candidato
            // abre ou envia uma contrarrazão ("Inscrição iniciada"/"Inscrição enviada")
            'disableCounterAppealMails' => true,

            // idem para os recursos (e-mail "start_data_collection_phase" na abertura do recurso).
            // Os e-mails das inscrições comuns continuam.
            'disableAppealMails' => true,
        ];

        parent::__construct($config);
    }

    /* ---------------------------------------------------------------------
     * E-mails
     * ------------------------------------------------------------------- */

    /**
     * Desliga os e-mails automáticos do Mapas para as contrarrazões e os recursos.
     *
     * A fase de contrarrazões é uma oportunidade de primeiro nível, então o módulo MailNotification
     * trata cada contrarrazão como uma inscrição comum: manda "start_registration" quando ela é criada
     * e "send_registration" quando é enviada. No recurso, manda "start_data_collection_phase" na
     * abertura. Todos passam pelos hooks abaixo, que cancelam o envio pela variável $enable.
     * Os e-mails do próprio módulo OpportunityAppealPhase já vêm desligados por padrão
     * (SEND_MAIL_OPPORTUNITY_APPEAL_PHASE=false).
     */
    protected function initMailHooks()
    {
        $skip_counter = (bool) $this->config['disableCounterAppealMails'];
        $skip_appeal = (bool) $this->config['disableAppealMails'];
        if (!$skip_counter && !$skip_appeal) {
            return;
        }

        $app = App::i();
        $cancel = function ($registration, &$enable) use ($skip_counter, $skip_appeal) {
            $opportunity = $registration instanceof Registration ? $registration->opportunity : null;
            if (!$opportunity) {
                return;
            }
            if ($skip_counter && self::meta($opportunity, 'isCounterAppealPhase')) {
                $enable = false;
            } elseif ($skip_appeal && $opportunity->isAppealPhase) {
                $enable = false;
            }
        };

        $app->hook('sendMailNotification.registrationStart:beforeEnqueue', function (&$registration, &$template, &$enable) use ($cancel) {
            $cancel($registration, $enable);
        });
        $app->hook('sendMailNotification.registrationSend', function (&$registration, &$template, &$enable) use ($cancel) {
            $cancel($registration, $enable);
        });
    }

    /* ---------------------------------------------------------------------
     * Painel ("Minhas inscrições")
     * ------------------------------------------------------------------- */

    /**
     * Esconde as contrarrazões das listagens de inscrições do painel.
     *
     * A fase de contrarrazões é uma oportunidade de primeiro nível, então cada contrarrazão
     * aparecia como uma inscrição comum em "Minhas inscrições" (abas, contador de rascunhos e
     * "Últimas inscrições"). Essas listagens consultam a API de inscrições sem filtrar por
     * oportunidade; nesse caso, as fases de contrarrazões são excluídas da consulta.
     * Consultas que filtram por oportunidade ou por id continuam inalteradas.
     */
    protected function initPanelHooks()
    {
        $app = App::i();

        $app->hook('ApiQuery(Registration).params', function (&$params) use ($app) {
            if (isset($params['opportunity']) || isset($params['id'])) {
                return;
            }

            $ids = $app->em->createQuery(
                "SELECT IDENTITY(m.owner) AS id
                   FROM MapasCulturais\\Entities\\OpportunityMeta m
                  WHERE m.key = 'isCounterAppealPhase' AND m.value = '1'"
            )->getSingleColumnResult();

            if ($ids) {
                $params['opportunity'] = '!IN(' . implode(',', array_map('intval', $ids)) . ')';
            }
        });
    }

    /* ---------------------------------------------------------------------
     * Inicialização
     * ------------------------------------------------------------------- */

    function _init()
    {
        $app = App::i();
        $self = $this;

        $this->checkMapasVersion();

        $app->hook('app.init:after', function () use ($app) {
            $app->view->enqueueStyle('app-v2', 'contrarrazoes', 'css/contrarrazoes.css');
        });

        $this->initPermissionHooks();
        $this->initEndpoints();
        $this->initJudgementLockHooks();
        $this->initTemplateHooks();
        $this->initMailHooks();
        $this->initPanelHooks();

        // a fase de contrarrazões não tem página própria: leva o gestor para a Configuração de fases
        // da oportunidade e o candidato para a linha do tempo da sua inscrição, com a lista aberta
        $app->hook('GET(opportunity.<<single|edit>>):before', function () use ($self, $app) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */
            $counter = $this->requestedEntity;
            if (!$counter instanceof Opportunity || !Plugin::meta($counter, 'isCounterAppealPhase')) {
                return;
            }

            $edital = $self->getAppealPhase($counter)?->parent?->firstPhase;
            if (!$edital) {
                return;
            }

            if ($counter->canUser('@control')) {
                $app->redirect($app->createUrl('opportunity', 'edit', [$edital->id]) . '#config');
            }

            if ($candidates = $self->getUserCandidateRegistrations($counter)) {
                $app->redirect($self->getCounterAppealListUrl($candidates[0], $counter));
            }

            $app->redirect($edital->singleUrl);
        });

        // 1.3.0: a contrarrazão enviada não tem tela própria para o autor. A ficha (/inscricao/{id}/,
        // para onde o Mapas leva depois do envio) devolve o autor à linha do tempo da inscrição dele,
        // com o modal aberto e a contrarrazão expandida. Gestor e comissão continuam vendo a ficha.
        $app->hook('GET(registration.<<view|single>>):before', function () use ($self, $app) {
            /** @var \MapasCulturais\Controllers\Registration $this */
            $registration = $this->requestedEntity;
            if (!$registration instanceof Registration || !Plugin::meta($registration->opportunity, 'isCounterAppealPhase')
                || $registration->status < Registration::STATUS_SENT || !Plugin::isOwnedBy($registration, $app->user)) {
                return;
            }

            $counter = $registration->opportunity;
            if ($counter->canUser('@control')) {
                return;
            }

            $author_number = (string) Plugin::meta($registration, 'counterAppealAuthorNumber');
            if ($author = $author_number ? $self->findAuthorRegistration($counter, $author_number) : null) {
                $app->redirect($self->getCounterAppealListUrl($author, $counter, $registration));
            }
        });

        // ao excluir a fase de recurso, exclui também a fase de contrarrazões.
        // A exclusão roda ao final da requisição, com o EntityManager limpo: fazê-la dentro
        // da exclusão da fase de recurso faz o Doctrine tentar regravar a fase removida.
        $app->hook('entity(Opportunity).destroy:before', function () use ($app) {
            /** @var Opportunity $this */
            if (!Plugin::meta($this, 'isAppealPhase') || !($counter_id = (int) Plugin::meta($this, 'counterAppealPhase'))) {
                return;
            }

            register_shutdown_function(function () use ($app, $counter_id) {
                try {
                    $app->em->clear();
                    $counter = $app->repo('Opportunity')->find($counter_id);
                    if (!$counter || !Plugin::meta($counter, 'isCounterAppealPhase')) {
                        return;
                    }

                    $app->disableAccessControl();
                    try {
                        $counter->destroy(true);
                    } finally {
                        $app->enableAccessControl();
                    }
                } catch (\Throwable $e) {
                    $app->log->error("Contrarrazoes: não foi possível excluir a fase de contrarrazões {$counter_id}: {$e->getMessage()}");
                }
            });
        });
    }

    function register()
    {
        $this->registerOpportunityMetadata('isCounterAppealPhase', [
            'label' => i::__('Indica se é uma fase de contrarrazões'),
            'type' => 'boolean',
        ]);

        $this->registerOpportunityMetadata('counterAppealOf', [
            'label' => i::__('Fase de recurso à qual as contrarrazões se referem'),
            'type' => 'integer',
        ]);

        $this->registerOpportunityMetadata('counterAppealPhase', [
            'label' => i::__('Fase de contrarrazões'),
            'type' => 'integer',
        ]);

        // OBSOLETO desde a 1.1.0: só inscritos na mesma etapa do recurso apresentam contrarrazões.
        // Continua registrado para não quebrar fases antigas que gravaram o valor, mas é ignorado.
        $this->registerOpportunityMetadata('counterAppealEligibility', [
            'label' => i::__('Quem pode apresentar contrarrazões (obsoleto)'),
            'type' => 'select',
            'default' => self::ELIGIBILITY_SAME_PHASE,
            'options' => [
                self::ELIGIBILITY_ALL => i::__('Todos os inscritos na oportunidade'),
                self::ELIGIBILITY_SAME_PHASE => i::__('Somente inscritos na mesma etapa do recurso'),
            ],
        ]);

        $this->registerOpportunityMetadata('counterAppealPublicFields', [
            'label' => i::__('Campos do recurso visíveis aos candidatos'),
            'type' => 'json',
        ]);

        // 1.3.0: nome dos proponentes nos recursos (e nas contrarrazões) exibidos aos candidatos.
        // Sem valor gravado (fases antigas) = exibe, como na 1.1.0. Veja Plugin::showNames().
        $this->registerOpportunityMetadata('counterAppealShowNames', [
            'label' => i::__('Exibir o nome dos proponentes aos candidatos'),
            'type' => 'boolean',
        ]);

        // 1.3.0: o autor do recurso pode ler as contrarrazões enviadas contra o seu recurso
        $this->registerOpportunityMetadata('counterAppealVisibleToAppellant', [
            'label' => i::__('O autor do recurso vê as contrarrazões recebidas'),
            'type' => 'boolean',
        ]);

        $this->registerRegistrationMetadata('counterAppealTarget', [
            'label' => i::__('Recurso contestado'),
            'type' => 'integer',
        ]);

        $this->registerRegistrationMetadata('counterAppealAuthorNumber', [
            'label' => i::__('Número da inscrição do autor da contrarrazão'),
            'type' => 'string',
        ]);
    }

    /**
     * Registra no log quando o Mapas está fora da faixa de versões testada.
     */
    function checkMapasVersion(): void
    {
        $app = App::i();
        $version_file = PROTECTED_PATH . 'version.txt';
        if (!file_exists($version_file)) {
            return;
        }

        $version = trim((string) file_get_contents($version_file));
        if ($version && !str_starts_with($version, self::TESTED_MAPAS_VERSIONS . '.')) {
            $app->log->warning(sprintf(
                'Plugin Contrarrazoes %s foi testado com o Mapas Culturais %s.x; versão instalada: %s',
                self::VERSION,
                self::TESTED_MAPAS_VERSIONS,
                $version
            ));
        }
    }

    /* ---------------------------------------------------------------------
     * Consultas auxiliares
     * ------------------------------------------------------------------- */

    /**
     * Lê um metadado sem passar pela verificação de dados privados.
     *
     * A leitura mágica (`$entity->chave`) chama `canUser('viewPrivateData')`, o que dispararia
     * os hooks de permissão deste plugin recursivamente. Por isso, dentro do plugin, os
     * metadados são lidos por aqui.
     */
    static function meta($entity, string $key)
    {
        if (!$entity) {
            return null;
        }

        $def = $entity->getRegisteredMetadata($key, true);
        if (!$def) {
            return null;
        }

        $value = $entity->getMetadata($key);

        if (is_callable($def->unserialize)) {
            $value = ($def->unserialize)($value, $entity, $def);
        }

        return $value;
    }

    /**
     * Retorna a fase de contrarrazões ligada a uma fase de recurso
     */
    function getCounterAppealPhase(?Opportunity $appeal_phase): ?Opportunity
    {
        if (!$appeal_phase || !($id = Plugin::meta($appeal_phase, 'counterAppealPhase'))) {
            return null;
        }

        $counter = App::i()->repo('Opportunity')->find((int) $id);

        if (!$counter || !Plugin::meta($counter, 'isCounterAppealPhase') || $appeal_phase->status !== Opportunity::STATUS_APPEAL_PHASE) {
            return null;
        }

        return $counter;
    }

    /**
     * Retorna a fase de recurso de uma fase de contrarrazões
     */
    function getAppealPhase(?Opportunity $counter): ?Opportunity
    {
        if (!$counter || !Plugin::meta($counter, 'isCounterAppealPhase') || !($id = Plugin::meta($counter, 'counterAppealOf'))) {
            return null;
        }

        $appeal = App::i()->repo('Opportunity')->find((int) $id);

        // fase de recurso na lixeira desativa as contrarrazões (sem apagar nada)
        if (!$appeal || !Plugin::meta($appeal, 'isAppealPhase') || $appeal->status !== Opportunity::STATUS_APPEAL_PHASE) {
            return null;
        }

        return $appeal;
    }

    /**
     * Fases de recurso de um edital (primeira fase), na ordem das fases
     *
     * @return Opportunity[]
     */
    function getAppealPhasesOfOpportunity(Opportunity $opportunity): array
    {
        $first_phase = $opportunity->firstPhase;
        $result = [];

        foreach ((array) $first_phase->allPhases as $phase) {
            $appeal_phase = $phase->appealPhase;
            if ($appeal_phase instanceof Opportunity && Plugin::meta($appeal_phase, 'isAppealPhase')) {
                $result[] = $appeal_phase;
            }
        }

        return $result;
    }

    /**
     * Indica se o julgamento dos recursos da fase está travado pelo prazo de contrarrazões
     */
    function isAppealJudgementLocked(Opportunity $appeal_phase): bool
    {
        if (!$this->config['lockAppealJudgement']) {
            return false;
        }

        $counter = $this->getCounterAppealPhase($appeal_phase);
        if (!$counter) {
            return false;
        }

        // sem prazo definido, o julgamento continua travado até o gestor configurar as datas
        if (!$counter->registrationTo) {
            return true;
        }

        return new DateTime() <= $counter->registrationTo;
    }

    /**
     * Nome dos proponentes visível aos candidatos (padrão: sim, como na 1.1.0)
     */
    function showNames(Opportunity $counter): bool
    {
        $value = Plugin::meta($counter, 'counterAppealShowNames');
        return $value === null || $value === '' ? true : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * O autor do recurso pode ler as contrarrazões recebidas (padrão: não)
     */
    function isVisibleToAppellant(Opportunity $counter): bool
    {
        return filter_var(Plugin::meta($counter, 'counterAppealVisibleToAppellant'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Rótulos de status das inscrições de uma fase (os da fase, se o gestor os personalizou)
     */
    function getStatusLabels(Opportunity $phase): array
    {
        $labels = [
            0 => i::__('Rascunho'),
            1 => i::__('Pendente'),
            2 => i::__('Inválida'),
            3 => i::__('Não selecionada'),
            8 => i::__('Suplente'),
            10 => i::__('Selecionada'),
        ];

        try {
            $custom = $phase->statusLabels;
            if (is_array($custom) || is_object($custom)) {
                foreach ((array) $custom as $status => $label) {
                    if (is_scalar($label) && $label !== '') {
                        $labels[(int) $status] = (string) $label;
                    }
                }
            }
        } catch (\Throwable $e) {
            // sem rótulos personalizados: fica o padrão
        }

        return $labels;
    }

    /**
     * O usuário é o dono da inscrição (por qualquer agente dele)
     */
    static function isOwnedBy(Registration $registration, $user): bool
    {
        return $user && !$user->is('guest') && $registration->owner?->user?->id === $user->id;
    }

    function getPublishLockMessage(Opportunity $appeal_phase): string
    {
        $counter = $this->getCounterAppealPhase($appeal_phase);
        if ($counter && $counter->registrationTo) {
            return sprintf(
                i::__('O resultado da etapa de recurso só pode ser publicado após o encerramento do prazo de contrarrazões (%s).'),
                $counter->registrationTo->format('d/m/Y H:i')
            );
        }

        return i::__('O resultado da etapa de recurso só pode ser publicado após o encerramento do prazo de contrarrazões, que ainda não foi definido.');
    }

    function getJudgementLockMessage(Opportunity $appeal_phase): string
    {
        $counter = $this->getCounterAppealPhase($appeal_phase);
        if ($counter && $counter->registrationTo) {
            return sprintf(
                i::__('O julgamento deste recurso só pode ser finalizado após o encerramento do prazo de contrarrazões (%s).'),
                $counter->registrationTo->format('d/m/Y H:i')
            );
        }

        return i::__('O julgamento deste recurso só pode ser finalizado após o encerramento do prazo de contrarrazões, que ainda não foi definido.');
    }

    /**
     * Fase que define quem pode apresentar contrarrazões: sempre a etapa avaliada que recebeu o
     * recurso (só quem está nessa etapa pode contestar). O metadado counterAppealEligibility é ignorado.
     */
    function getEligibilityBasePhase(Opportunity $counter): ?Opportunity
    {
        $appeal_phase = $this->getAppealPhase($counter);
        if (!$appeal_phase || !$appeal_phase->parent) {
            return null;
        }

        return $appeal_phase->parent;
    }

    /**
     * Inscrições do usuário que o habilitam a apresentar contrarrazões
     *
     * @return Registration[]
     */
    function getUserCandidateRegistrations(Opportunity $counter, $user = null): array
    {
        $app = App::i();
        $user = $user ?: $app->user;

        if (!$user || $user->is('guest')) {
            return [];
        }

        $base_phase = $this->getEligibilityBasePhase($counter);
        if (!$base_phase) {
            return [];
        }

        $query = $app->em->createQuery("
            SELECT r
            FROM MapasCulturais\Entities\Registration r
            JOIN r.owner a
            WHERE
                r.opportunity = :phase AND
                r.status > 0 AND
                a.user = :user
            ORDER BY r.id ASC");

        $query->setParameters(['phase' => $base_phase, 'user' => $user]);

        return $query->getResult();
    }

    /**
     * Recursos enviados na fase de recurso
     *
     * @return Registration[]
     */
    function getSentAppeals(Opportunity $appeal_phase): array
    {
        $app = App::i();

        $query = $app->em->createQuery("
            SELECT r
            FROM MapasCulturais\Entities\Registration r
            WHERE
                r.opportunity = :phase AND
                r.status > 0
            ORDER BY r.sentTimestamp ASC, r.id ASC");

        $query->setParameters(['phase' => $appeal_phase]);

        return $query->getResult();
    }

    /**
     * Contrarrazões de uma fase. Filtra por recurso e/ou status mínimo.
     *
     * @return Registration[]
     */
    function getCounterAppeals(Opportunity $counter, ?Registration $appeal = null, int $min_status = 0): array
    {
        $app = App::i();

        $registrations = $app->repo('Registration')->findBy(['opportunity' => $counter], ['id' => 'ASC']);

        return array_values(array_filter($registrations, function (Registration $registration) use ($appeal, $min_status) {
            if ($registration->status < $min_status) {
                return false;
            }

            if ($appeal && (int) Plugin::meta($registration, 'counterAppealTarget') !== (int) $appeal->id) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Contrarrazão já criada por um autor contra um recurso
     */
    function findCounterAppealBy(Opportunity $counter, Registration $appeal, string $author_number): ?Registration
    {
        foreach ($this->getCounterAppeals($counter, $appeal) as $registration) {
            if ((string) Plugin::meta($registration, 'counterAppealAuthorNumber') === $author_number) {
                return $registration;
            }
        }

        return null;
    }

    /**
     * Valida se uma inscrição (autor) pode apresentar contrarrazão contra um recurso.
     * Retorna null quando pode, ou a mensagem com o motivo.
     */
    function getCounterAppealDenialReason(Opportunity $counter, Registration $author, Registration $appeal, $user = null): ?string
    {
        $appeal_phase = $this->getAppealPhase($counter);

        if (!$appeal_phase || !$appeal->opportunity->equals($appeal_phase) || $appeal->status <= 0) {
            return i::__('O recurso informado não pertence a esta fase de contrarrazões.');
        }

        $candidate_ids = array_map(fn ($r) => $r->id, $this->getUserCandidateRegistrations($counter, $user));
        if (!in_array($author->id, $candidate_ids, true)) {
            return i::__('Somente inscritos na mesma etapa do recurso podem apresentar contrarrazões.');
        }

        if ((string) $author->number === (string) $appeal->number) {
            return i::__('Não é possível apresentar contrarrazão ao próprio recurso.');
        }

        return null;
    }

    /**
     * Campos do formulário de uma fase, na ordem de exibição
     *
     * @return array lista de ['name', 'title', 'type', 'isFile', 'config']
     */
    function getPhaseFormFields(Opportunity $phase, bool $only_public_types = false): array
    {
        $fields = [];

        foreach ($phase->registrationFieldConfigurations as $field) {
            if ($field->fieldType === 'section') {
                continue;
            }

            if ($only_public_types && !in_array($field->fieldType, $this->config['publicFieldTypes'], true)) {
                continue;
            }

            $fields[] = [
                'name' => $field->fieldName,
                'title' => $field->title,
                'type' => $field->fieldType,
                'isFile' => false,
                'order' => (int) $field->displayOrder,
            ];
        }

        foreach ($phase->registrationFileConfigurations as $file) {
            $fields[] = [
                'name' => $file->fileGroupName,
                'title' => $file->title,
                'type' => 'file',
                'isFile' => true,
                'order' => (int) $file->displayOrder,
            ];
        }

        usort($fields, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $fields;
    }

    /**
     * Valores dos campos de uma inscrição, prontos para exibição
     *
     * @param string[]|null $only_fields nomes dos campos a incluir (null = todos)
     * @param Opportunity|null $public_counter quando informado, os anexos usam a URL pública
     *                                         de download da fase de contrarrazões
     */
    function getRegistrationFieldValues(Registration $registration, ?array $only_fields = null, ?Opportunity $public_counter = null): array
    {
        $app = App::i();
        $result = [];

        // os campos a exibir já foram filtrados por quem chama; a leitura ignora a
        // restrição de metadados privados para que o candidato veja os campos públicos
        $app->disableAccessControl();
        try {
            $registration->opportunity->registerRegistrationMetadata();
            $files = $registration->files;
            $values = [];
            foreach ($this->getPhaseFormFields($registration->opportunity) as $field) {
                if (!$field['isFile']) {
                    $values[$field['name']] = Plugin::meta($registration, $field['name']);
                }
            }
        } finally {
            $app->enableAccessControl();
        }

        foreach ($this->getPhaseFormFields($registration->opportunity) as $field) {
            if ($only_fields !== null && !in_array($field['name'], $only_fields, true)) {
                continue;
            }

            $item = [
                'name' => $field['name'],
                'title' => $field['title'],
                'type' => $field['type'],
            ];

            if ($field['isFile']) {
                $group_files = $files[$field['name']] ?? [];
                if (!is_array($group_files)) {
                    $group_files = [$group_files];
                }

                $item['files'] = array_values(array_map(fn ($file) => [
                    'name' => $file->name,
                    'url' => $public_counter ? $this->getPublicAppealFileUrl($public_counter, $file) : $file->url,
                ], array_filter($group_files)));

                if (!$item['files']) {
                    continue;
                }
            } else {
                $value = self::formatValue($values[$field['name']] ?? null);
                if ($value === '') {
                    continue;
                }
                $item['value'] = $value;
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * Converte o valor de um campo em texto legível
     */
    static function formatValue($value): string
    {
        if (is_null($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? i::__('Sim') : i::__('Não');
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y');
        }

        $value = json_decode(json_encode($value), true);

        if (is_array($value) && array_is_list($value) && count(array_filter($value, 'is_scalar')) === count($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Dados mínimos de uma inscrição para o front-end
     */
    function serializeRegistration(Registration $registration): array
    {
        return [
            'id' => $registration->id,
            'number' => $registration->number,
            'status' => $registration->status,
            'agentName' => $registration->owner?->name,
            'sentTimestamp' => $registration->sentTimestamp?->format('Y-m-d H:i:s'),
            'singleUrl' => $registration->singleUrl,
            'editUrl' => $registration->editUrl,
        ];
    }

    function serializeCounterAppealPhase(Opportunity $counter): array
    {
        $data = $counter->jsonSerialize();
        $data['@entityType'] = 'opportunity';
        $data['isRegistrationOpen'] = $counter->isRegistrationOpen();
        $data['counterAppealEligibility'] = self::ELIGIBILITY_SAME_PHASE; // fixo desde a 1.1.0
        $data['counterAppealPublicFields'] = array_values((array) Plugin::meta($counter, 'counterAppealPublicFields'));
        $data['counterAppealShowNames'] = $this->showNames($counter);
        $data['counterAppealVisibleToAppellant'] = $this->isVisibleToAppellant($counter);
        // o formulário não é mais criado automaticamente (1.3.0): o gestor monta pelo "Configurar formulário"
        $data['formFieldsCount'] = count($this->getPhaseFormFields($counter));
        $appeal_phase = $this->getAppealPhase($counter);
        $data['judgementLocked'] = $appeal_phase ? $this->isAppealJudgementLocked($appeal_phase) : false;
        $data['formBuilderUrl'] = App::i()->createUrl('opportunity', 'formBuilder', [$counter->id]);

        return $data;
    }

    /* ---------------------------------------------------------------------
     * Permissões
     * ------------------------------------------------------------------- */

    function initPermissionHooks(): void
    {
        $app = App::i();
        $self = $this;

        // quem controla a fase de recurso controla a fase de contrarrazões
        $app->hook('can(Opportunity.<<@control|modify|view|viewPrivateData|viewRegistrations|remove|modifyRegistrationFields>>)', function ($user, &$result) use ($self) {
            /** @var Opportunity $this */
            if ($result || !Plugin::meta($this, 'isCounterAppealPhase')) {
                return;
            }

            if ($appeal_phase = $self->getAppealPhase($this)) {
                $result = $appeal_phase->canUser('@control', $user);
            }
        });

        // inclui os gestores do edital no cache de permissões da fase de contrarrazões
        $app->hook('entity(Opportunity).permissionCacheUsers', function (&$users) use ($self) {
            /** @var Opportunity $this */
            if (Plugin::meta($this, 'isCounterAppealPhase') && ($appeal_phase = $self->getAppealPhase($this))) {
                $users = array_merge($users, $appeal_phase->getUsersWithControl());
            }
        });

        // contrarrazões só podem ser criadas/enviadas vinculadas a um recurso.
        // (não usa can(Registration.create): o cache de permissões do Mapas compartilha o
        // resultado entre todas as inscrições novas de um mesmo usuário)
        $app->hook('entity(Registration).<<insert|send>>:before', function () use ($app) {
            /** @var Registration $this */
            if (Plugin::meta($this->opportunity, 'isCounterAppealPhase') && !Plugin::meta($this, 'counterAppealTarget')) {
                throw new PermissionDenied($app->user, $this, 'create', i::__('A contrarrazão deve ser criada a partir da lista de recursos.'));
            }
        });

        // gestores do edital e avaliadores do recurso podem ver as contrarrazões (e seus anexos)
        $app->hook('can(Registration.<<view|viewPrivateData|viewPrivateFiles|viewConsolidatedResult>>)', function ($user, &$result) use ($self, $app) {
            /** @var Registration $this */
            if ($result || !Plugin::meta($this->opportunity, 'isCounterAppealPhase')) {
                return;
            }

            $appeal_phase = $self->getAppealPhase($this->opportunity);
            if ($appeal_phase && $appeal_phase->canUser('@control', $user)) {
                $result = true;
                return;
            }

            // avaliadores do recurso. O autor do recurso também "vê" o próprio recurso, mas não entra
            // por aqui: ele só lê as contrarrazões se o gestor liberar (hook abaixo)
            $target_id = (int) Plugin::meta($this, 'counterAppealTarget');
            if ($target_id && ($appeal = $app->repo('Registration')->find($target_id)) && !Plugin::isOwnedBy($appeal, $user)) {
                $result = $appeal->canUser('view', $user);
            }
        });

        // autor do recurso: lê a contrarrazão enviada e baixa os anexos dela, quando o gestor libera
        // (sem viewPrivateData: os campos chegam a ele já filtrados pelos tipos publicáveis).
        // A configuração limpa o cache de permissões das contrarrazões (clearPermissionCaches).
        $app->hook('can(Registration.<<view|viewPrivateFiles>>)', function ($user, &$result) use ($self, $app) {
            /** @var Registration $this */
            if ($result || !Plugin::meta($this->opportunity, 'isCounterAppealPhase') || $this->status < Registration::STATUS_SENT) {
                return;
            }

            $target_id = (int) Plugin::meta($this, 'counterAppealTarget');
            $appeal = $target_id ? $app->repo('Registration')->find($target_id) : null;
            if ($appeal && Plugin::isOwnedBy($appeal, $user) && $self->isVisibleToAppellant($this->opportunity)) {
                $result = true;
            }
        });
    }

    /**
     * A lista de recursos fica visível a partir do início do prazo de contrarrazões
     */
    function isCounterAppealListVisible(Opportunity $counter): bool
    {
        return $counter->registrationFrom && new DateTime() >= $counter->registrationFrom;
    }

    /* ---------------------------------------------------------------------
     * Endpoints
     * ------------------------------------------------------------------- */

    function initEndpoints(): void
    {
        $app = App::i();
        $self = $this;

        /**
         * Cria a fase de contrarrazões de uma fase de recurso
         * POST /opportunity/createCounterAppealPhase/{id da fase de recurso}
         */
        $app->hook('POST(opportunity.createCounterAppealPhase)', function () use ($app, $self) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */
            $this->requireAuthentication();

            $appeal_phase = $this->requestedEntity;
            if (!$appeal_phase || !Plugin::meta($appeal_phase, 'isAppealPhase')) {
                $this->errorJson(i::__('A fase informada não é uma fase de recurso.'), 400);
            }

            $appeal_phase->checkPermission('@control');

            if ($existing = $self->getCounterAppealPhase($appeal_phase)) {
                $this->json($self->serializeCounterAppealPhase($existing));
                return;
            }

            $counter = $self->createCounterAppealPhase($appeal_phase);

            $this->json($self->serializeCounterAppealPhase($counter));
        });

        /**
         * Salva as configurações da fase de contrarrazões
         * POST /opportunity/saveCounterAppealConfig/{id da fase de contrarrazões}
         */
        $app->hook('POST(opportunity.saveCounterAppealConfig)', function () use ($app, $self) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */
            $this->requireAuthentication();

            $counter = $this->requestedEntity;
            if (!$counter || !Plugin::meta($counter, 'isCounterAppealPhase')) {
                $this->errorJson(i::__('A fase informada não é uma fase de contrarrazões.'), 400);
            }

            $counter->checkPermission('@control');
            $appeal_phase = $self->getAppealPhase($counter);
            $data = $this->data;

            // a elegibilidade não é mais configurável (sempre a mesma etapa do recurso):
            // counterAppealEligibility enviado por clientes antigos é ignorado

            if (array_key_exists('counterAppealPublicFields', $data)) {
                $allowed = array_map(fn ($f) => $f['name'], $self->getPhaseFormFields($appeal_phase, true));
                $requested = array_values(array_filter((array) ($data['counterAppealPublicFields'] ?: []), 'is_string'));
                $counter->counterAppealPublicFields = array_values(array_intersect($requested, $allowed));
            }

            foreach (['counterAppealShowNames', 'counterAppealVisibleToAppellant'] as $prop) {
                if (array_key_exists($prop, $data)) {
                    $counter->$prop = filter_var($data[$prop], FILTER_VALIDATE_BOOLEAN);
                }
            }

            // valida só as datas: as demais validações da oportunidade (área de interesse etc.)
            // não se aplicam a esta fase oculta
            foreach (['registrationFrom', 'registrationTo'] as $prop) {
                if (!array_key_exists($prop, $data)) {
                    continue;
                }

                if (!$data[$prop]) {
                    $counter->$prop = null;
                    continue;
                }

                try {
                    $counter->$prop = new DateTime($data[$prop]);
                } catch (\Exception $e) {
                    $this->errorJson([$prop => [i::__('O valor informado não é uma data válida')]], 400);
                }
            }

            if ($counter->registrationFrom && $counter->registrationTo && $counter->registrationFrom > $counter->registrationTo) {
                $this->errorJson(['registrationTo' => [i::__('A data final das contrarrazões deve ser maior ou igual à data inicial')]], 400);
            }

            $counter->save(true);
            $self->clearPermissionCaches($counter);

            $this->json($self->serializeCounterAppealPhase($counter));
        });

        /**
         * Exclui a fase de contrarrazões (e todas as contrarrazões recebidas)
         * POST /opportunity/deleteCounterAppealPhase/{id da fase de contrarrazões}
         */
        $app->hook('POST(opportunity.deleteCounterAppealPhase)', function () use ($app, $self) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */
            $this->requireAuthentication();

            $counter = $this->requestedEntity;
            if (!$counter || !Plugin::meta($counter, 'isCounterAppealPhase')) {
                $this->errorJson(i::__('A fase informada não é uma fase de contrarrazões.'), 400);
            }

            $counter->checkPermission('@control');
            $self->deleteCounterAppealPhase($counter);

            $this->json(true);
        });

        /**
         * Baixa um anexo de recurso marcado como público
         * GET /opportunity/counterAppealFile/{id da fase de contrarrazões}/file:{id do arquivo}
         */
        /**
         * 1.4.0: página de acompanhamento das contrarrazões de uma fase de recurso (gestor)
         * GET /opportunity/counterAppeals/{id da fase de recurso}
         * Aberta em nova aba pelo botão "Lista de contrarrazões" da aba Inscrições e Resultados.
         */
        $app->hook('GET(opportunity.counterAppeals)', function () use ($app, $self) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */
            $this->requireAuthentication();

            $appeal_phase = $this->requestedEntity;
            if (!$appeal_phase instanceof Opportunity || !Plugin::meta($appeal_phase, 'isAppealPhase')) {
                $app->pass();
            }

            $appeal_phase->checkPermission('@control');

            $edital = $appeal_phase->parent?->firstPhase ?: $appeal_phase->parent;
            $app->view->jsObject['config']['counterAppealTracking'] = $self->buildPhaseConfigItem($appeal_phase) + [
                'editUrl' => $edital ? $app->createUrl('opportunity', 'edit', [$edital->id]) . '#registrations' : null,
                'opportunityName' => $edital?->name,
            ];

            $this->render('counter-appeals', ['entity' => $appeal_phase, 'edital' => $edital]);
        });

        $app->hook('GET(opportunity.counterAppealFile)', function () use ($app, $self) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */
            $this->requireAuthentication();

            $counter = $this->requestedEntity;
            if (!$counter || !Plugin::meta($counter, 'isCounterAppealPhase')) {
                $app->pass();
            }

            $file = $app->repo('RegistrationFile')->find((int) ($this->data['file'] ?? 0));
            $appeal_phase = $self->getAppealPhase($counter);
            $appeal = $file?->owner;

            $allowed = $file && $appeal_phase
                && $appeal instanceof Registration
                && $appeal->opportunity->equals($appeal_phase)
                && $appeal->status > 0
                && in_array($file->group, (array) Plugin::meta($counter, 'counterAppealPublicFields'), true)
                && $self->isCounterAppealListVisible($counter)
                && ($counter->canUser('@control') || $self->getUserCandidateRegistrations($counter));

            if (!$allowed) {
                $this->errorJson(i::__('Você não tem permissão para baixar este arquivo.'), 403);
            }

            $path = $file->getPath();
            if (!file_exists($path)) {
                $app->pass();
            }

            header('Content-Description: File Transfer');
            header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file->name) . '"');
            header('Cache-Control: private, no-store');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        });

        /**
         * Cria (ou retorna) a contrarrazão de um candidato contra um recurso
         * POST /opportunity/createCounterAppealRegistration/{id da fase de contrarrazões}
         * dados: appeal_registration_id, author_registration_id
         */
        $app->hook('POST(opportunity.createCounterAppealRegistration)', function () use ($app, $self) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */
            $this->requireAuthentication();

            $counter = $this->requestedEntity;
            if (!$counter || !Plugin::meta($counter, 'isCounterAppealPhase')) {
                $this->errorJson(i::__('A fase informada não é uma fase de contrarrazões.'), 400);
            }

            $appeal = $app->repo('Registration')->find((int) ($this->data['appeal_registration_id'] ?? 0));
            $author = $app->repo('Registration')->find((int) ($this->data['author_registration_id'] ?? 0));

            if (!$appeal || !$author) {
                $this->errorJson(i::__('Informe o recurso e a sua inscrição.'), 400);
            }

            if ($reason = $self->getCounterAppealDenialReason($counter, $author, $appeal)) {
                $this->errorJson($reason, 403);
            }

            if ($existing = $self->findCounterAppealBy($counter, $appeal, (string) $author->number)) {
                $this->json($self->serializeRegistration($existing));
                return;
            }

            if (!$counter->isRegistrationOpen()) {
                $this->errorJson(i::__('O prazo para apresentar contrarrazões não está aberto.'), 403);
            }

            // o formulário não é criado automaticamente: sem campos, a contrarrazão sairia vazia
            if (!$self->getPhaseFormFields($counter)) {
                $this->errorJson(i::__('O formulário de contrarrazão ainda não foi configurado pela gestão do edital.'), 403);
            }

            $registration = new Registration();
            $registration->opportunity = $counter;
            $registration->owner = $author->owner;
            if ($author->proponentType && $counter->registrationProponentTypes) {
                $registration->proponentType = $author->proponentType;
            }
            $registration->counterAppealTarget = $appeal->id;
            $registration->counterAppealAuthorNumber = (string) $author->number;

            // as regras de quem pode criar já foram verificadas acima; o controle de acesso é
            // desligado porque o cache de permissões do Mapas compartilha o resultado de
            // `create` entre todas as inscrições novas de um mesmo usuário
            $app->disableAccessControl();
            try {
                $registration->save(true);
            } finally {
                $app->enableAccessControl();
            }

            $this->json($self->serializeRegistration($registration));
        });
    }

    /**
     * URL para baixar um anexo público de um recurso
     */
    function getPublicAppealFileUrl(Opportunity $counter, $file): string
    {
        return App::i()->createUrl('opportunity', 'counterAppealFile', [$counter->id, 'file' => $file->id]);
    }

    /**
     * Cria a fase de contrarrazões (sem campos no formulário)
     */
    function createCounterAppealPhase(Opportunity $appeal_phase): Opportunity
    {
        $app = App::i();
        $first_phase = $appeal_phase->parent->firstPhase;
        $class_name = $first_phase->getSpecializedClassName();

        $conn = $app->em->getConnection();
        $conn->beginTransaction();

        try {
            /** @var Opportunity $counter */
            $counter = new $class_name();
            $counter->status = Opportunity::STATUS_PHASE;
            $counter->name = mb_substr(sprintf(i::__('Contrarrazões — %s'), $appeal_phase->name), 0, 255);
            $counter->shortDescription = sprintf(i::__('Contrarrazões aos recursos de %s'), $first_phase->name);
            $counter->ownerEntity = $first_phase->ownerEntity;
            $counter->owner = $first_phase->owner;
            $counter->type = $first_phase->type;
            $counter->registrationProponentTypes = $first_phase->registrationProponentTypes;
            $counter->isDataCollection = true;
            $counter->isCounterAppealPhase = true;
            $counter->counterAppealOf = $appeal_phase->id;
            $counter->counterAppealEligibility = self::ELIGIBILITY_SAME_PHASE; // fixo desde a 1.1.0
            $counter->counterAppealPublicFields = [];
            $counter->counterAppealShowNames = true;
            $counter->counterAppealVisibleToAppellant = false;
            $counter->save(true);

            // só a etapa (vazia), que o editor de formulário do Mapas espera encontrar.
            // Os campos são criados pelo gestor em "Configurar formulário" (desde a 1.3.0).
            $step = new RegistrationStep();
            $step->name = i::__('Contrarrazão');
            $step->opportunity = $counter;
            $step->save(true);

            $appeal_phase->counterAppealPhase = $counter->id;
            $appeal_phase->save(true);

            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $app->em->clear();
            throw $e;
        }

        return $counter;
    }

    /**
     * Limpa o cache de permissões da fase de contrarrazões e das suas inscrições,
     * para que mudanças de configuração valham imediatamente
     */
    function clearPermissionCaches(Opportunity $counter): void
    {
        $counter->clearPermissionCache();
        foreach ($this->getCounterAppeals($counter) as $registration) {
            $registration->clearPermissionCache();
        }
    }

    function deleteCounterAppealPhase(Opportunity $counter): void
    {
        $app = App::i();

        if ($appeal_phase = $this->getAppealPhase($counter)) {
            $appeal_phase->counterAppealPhase = null;
            $appeal_phase->save(true);
        }

        // exclusão definitiva (sem passar pela lixeira), junto com as contrarrazões recebidas
        $app->disableAccessControl();
        try {
            $counter->destroy(true);
        } finally {
            $app->enableAccessControl();
        }
    }

    /* ---------------------------------------------------------------------
     * Trava do julgamento
     * ------------------------------------------------------------------- */

    function initJudgementLockHooks(): void
    {
        $app = App::i();
        $self = $this;

        // impede finalizar/enviar a avaliação do recurso dentro do prazo de contrarrazões.
        // A verificação é feita antes da ação do controlador, para responder com uma mensagem
        // clara e sem deixar travas de gravação pendentes.
        $app->hook('POST(registration.<<saveEvaluation|saveEvaluationAndChangeStatus|sendEvaluation>>):before', function () use ($self) {
            /** @var \MapasCulturais\Controllers\Registration $this */
            $is_final = $this->action === 'sendEvaluation' || ($this->urlData['status'] ?? null) === 'evaluated';
            if (!$is_final) {
                return;
            }

            $registration = $this->requestedEntity;
            $appeal_phase = $registration?->opportunity;
            if ($appeal_phase && Plugin::meta($appeal_phase, 'isAppealPhase') && $self->isAppealJudgementLocked($appeal_phase)) {
                $this->errorJson($self->getJudgementLockMessage($appeal_phase), 403);
            }
        });

        // proteção extra para outros caminhos que gravam a avaliação já finalizada
        $app->hook('entity(RegistrationEvaluation).save:before', function () use ($self, $app) {
            /** @var RegistrationEvaluation $this */
            if ($this->status < RegistrationEvaluation::STATUS_EVALUATED) {
                return;
            }

            $registration = $this->registration;
            $appeal_phase = $registration?->opportunity;
            if ($appeal_phase && Plugin::meta($appeal_phase, 'isAppealPhase') && $self->isAppealJudgementLocked($appeal_phase)) {
                // libera a trava de gravação aberta por Registration::saveUserEvaluation
                if ($this->user) {
                    $app->unlock("save-user-evauation--{$registration->id}--{$this->user->id}");
                }
                throw new PermissionDenied($app->user, $this, 'send', $self->getJudgementLockMessage($appeal_phase));
            }
        });

        // impede a decisão do recurso pelo chat (avaliação contínua com resposta do proponente)
        $app->hook('entity(ChatMessage).insert:before', function () use ($self, $app) {
            /** @var ChatMessage $this */
            $registration = $this->thread?->ownerEntity;
            if (!$registration instanceof Registration) {
                return;
            }

            $payload = $this->payload;
            $status = is_array($payload) ? ($payload['status'] ?? null) : ($payload->status ?? null);
            if ($status === null || $status === '') {
                return;
            }

            $appeal_phase = $registration->opportunity;
            if (Plugin::meta($appeal_phase, 'isAppealPhase') && $self->isAppealJudgementLocked($appeal_phase)) {
                throw new PermissionDenied($app->user, $this, 'create', $self->getJudgementLockMessage($appeal_phase));
            }
        });

        // impede alterar o status do recurso (deferir/indeferir) dentro do prazo
        $app->hook('entity(Registration).save:before', function () use ($self, $app) {
            /** @var Registration $this */
            if ($this->isNew() || !Plugin::meta($this->opportunity, 'isAppealPhase')) {
                return;
            }

            $original = $app->em->getUnitOfWork()->getOriginalEntityData($this);
            $old_status = $original['status'] ?? null;

            if ($old_status === null || (int) $old_status === (int) $this->status) {
                return;
            }

            // envio do recurso pelo candidato (rascunho -> enviado) é permitido
            if ((int) $old_status === Registration::STATUS_DRAFT && (int) $this->status === Registration::STATUS_SENT) {
                return;
            }

            if ($self->isAppealJudgementLocked($this->opportunity)) {
                throw new PermissionDenied($app->user, $this, 'changeStatus', $self->getJudgementLockMessage($this->opportunity));
            }
        });

        // aplicação em lote da avaliação contínua: barra antes de começar, com mensagem clara
        // (sem isso, a trava acima interromperia o lote no meio)
        $app->hook('POST(opportunity.applyEvaluationsContinuous):before', function () use ($self) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */
            $appeal_phase = $this->requestedEntity;
            if ($appeal_phase instanceof Opportunity && Plugin::meta($appeal_phase, 'isAppealPhase') && $self->isAppealJudgementLocked($appeal_phase)) {
                $this->errorJson($self->getJudgementLockMessage($appeal_phase), 403);
            }
        });

        // 1.3.0: com o prazo de contrarrazões aberto (ou sem prazo), o resultado da etapa de recurso
        // não é publicado. Pelo botão de publicar (resposta clara ao gestor)...
        $app->hook('ALL(opportunity.publishRegistrations):before', function () use ($self) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */
            $appeal_phase = $this->requestedEntity;
            if ($appeal_phase instanceof Opportunity && Plugin::meta($appeal_phase, 'isAppealPhase') && $self->isAppealJudgementLocked($appeal_phase)) {
                $this->errorJson($self->getPublishLockMessage($appeal_phase), 403);
            }
        });

        // ...e por qualquer outro caminho, como a publicação agendada (job PublishResult).
        // Opportunity::publishRegistrations() abre uma transação antes deste hook: desfaz antes de barrar.
        $app->hook('entity(Opportunity).publishRegistrations:before', function () use ($self, $app) {
            /** @var Opportunity $this */
            if (!Plugin::meta($this, 'isAppealPhase') || !$self->isAppealJudgementLocked($this)) {
                return;
            }

            if ($app->em->getConnection()->isTransactionActive()) {
                $app->em->rollback();
            }
            $app->log->warning("Contrarrazoes: publicação do resultado da fase de recurso {$this->id} barrada: prazo de contrarrazões aberto ou não definido.");
            throw new PermissionDenied($app->user, $this, 'publishRegistrations', $self->getPublishLockMessage($this));
        });
    }

    /* ---------------------------------------------------------------------
     * Telas
     * ------------------------------------------------------------------- */

    function initTemplateHooks(): void
    {
        $app = App::i();
        $self = $this;

        // dados da configuração das contrarrazões na edição da oportunidade (gestor).
        // Não imprime aba: o componente é encaixado na "Etapa de recurso" da aba Configuração de
        // fases, entre os acordeões "Recurso" e "Resposta do recurso" (veja o hook abaixo).
        $app->hook('template(opportunity.edit.tabs):end', function () use ($self) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $opportunity = $this->controller->requestedEntity;
            if (!$opportunity instanceof Opportunity || Plugin::meta($opportunity, 'isCounterAppealPhase') || !$opportunity->canUser('@control')) {
                return;
            }

            // importa o componente mesmo sem fase de recurso: se o gestor criar o recurso agora,
            // o acordeão "Contrarrazões" já aparece (com o botão de adicionar)
            $appeal_phases = $self->getAppealPhasesOfOpportunity($opportunity);

            $config = array_map(fn ($appeal_phase) => $self->buildPhaseConfigItem($appeal_phase), $appeal_phases);

            $this->jsObject['config']['counterAppealConfig'] = $config;
            $this->import('counter-appeal-config counter-appeal-registrations');
        });

        // O componente "opportunity-appeal-phase-config" (módulo OpportunityAppealPhase) não tem
        // ponto de encaixe entre os acordeões "Recurso" e "Resposta do recurso". Em vez de copiar o
        // template do núcleo, o plugin insere o acordeão "Contrarrazões" no template já renderizado,
        // logo antes de ele ir para a página ($TEMPLATES é impresso em mapas.printJsObject:after,
        // prioridade 10). Se a marcação mudar numa atualização do Mapas, o acordeão vai para o fim
        // da etapa de recurso e um aviso é registrado no log.
        $app->hook('mapas.printJsObject:after', function () use ($self, $app) {
            $templates = $app->components->templates ?? [];
            if (!isset($templates['opportunity-appeal-phase-config'], $templates['counter-appeal-config'])) {
                return;
            }
            $app->components->templates['opportunity-appeal-phase-config'] = $self->injectCounterAppealConfig($templates['opportunity-appeal-phase-config']);
        }, 5);

        // dados das contrarrazões para a linha do tempo da inscrição do candidato (aba Acompanhamento).
        // A contrarrazão é apresentada pela inscrição da página: quem tem mais de uma inscrição no
        // edital escolhe a inscrição ao abrir a página dela. Não imprime nada: só alimenta o
        // componente counter-appeal-timeline, encaixado na linha do tempo (hook abaixo).
        // /inscricao/{id}/ é renderizada pela ação "view" (registration/single.php); "single" fica por garantia
        $app->hook('template(registration.<<view|single>>.single-tab):begin', function () use ($self, $app) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $registration = $this->controller->requestedEntity;
            if (!$registration instanceof Registration || $app->user->is('guest')
                || Plugin::meta($registration->opportunity, 'isCounterAppealPhase')) {
                return;
            }

            // somente o dono da inscrição: o gestor acompanha pela aba Contrarrazões da oportunidade
            if ($registration->owner?->user?->id !== $app->user->id) {
                return;
            }

            $edital = $registration->opportunity->firstPhase;
            $phases = [];
            foreach ($self->getAppealPhasesOfOpportunity($edital) as $appeal_phase) {
                $counter = $self->getCounterAppealPhase($appeal_phase);
                if (!$counter || !$self->isCounterAppealListVisible($counter)) {
                    continue;
                }

                $phases[] = $self->buildPublicPhaseData($appeal_phase, $counter, $registration);
            }

            if (!$phases) {
                return;
            }

            $this->jsObject['config']['counterAppealList'] = [
                'isGuest' => false,
                'loginUrl' => null,
                'registrationNumber' => $registration->number,
                'phases' => $phases,
            ];
        });

        // bloco "[Contrarrazões]" na linha do tempo do candidato, ao final do item da fase que teve
        // recurso, ou seja, logo abaixo do botão "Solicitar recurso" / quadro "[Recurso]".
        // O componente só aparece para inscrições que estão na etapa do recurso (canSeeList).
        // Mesmo padrão do módulo OpportunityExecution do núcleo.
        $app->hook('component(opportunity-phases-timeline).item:end', function () {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $this->import('counter-appeal-timeline');
            echo '<counter-appeal-timeline :phase="item"></counter-appeal-timeline>';
        });

        // faixa informativa na contrarrazão: qual recurso está sendo contestado
        $app->hook('template(registration.<<view|edit|single>>.main-header):after', function () use ($self, $app) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $registration = $this->controller->requestedEntity;
            if (!$registration instanceof Registration || !Plugin::meta($registration->opportunity, 'isCounterAppealPhase')) {
                return;
            }

            $counter = $registration->opportunity;
            $appeal = ($id = (int) Plugin::meta($registration, 'counterAppealTarget')) ? $app->repo('Registration')->find($id) : null;

            // a lista de recursos fica na página da inscrição que apresentou a contrarrazão
            $author_number = (string) Plugin::meta($registration, 'counterAppealAuthorNumber');
            $author = $author_number ? $self->findAuthorRegistration($counter, $author_number) : null;

            $this->part('contrarrazoes/registration-banner', [
                'appeal' => $appeal,
                'counter' => $counter,
                'listUrl' => $author ? $self->getCounterAppealListUrl($author, $counter) : null,
                'showName' => $self->showNames($counter) || $counter->canUser('@control'),
            ]);
        });

        // contrarrazões na tela de avaliação do recurso (comissão)
        $app->hook('template(registration.evaluation.registration-appealPhase-evaluation-view):after', function () use ($self) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $appeal = $this->controller->requestedEntity;
            if (!$appeal instanceof Registration || !Plugin::meta($appeal->opportunity, 'isAppealPhase')) {
                return;
            }

            $appeal_phase = $appeal->opportunity;
            $counter = $self->getCounterAppealPhase($appeal_phase);
            if (!$counter) {
                return;
            }

            $items = [];
            foreach ($self->getCounterAppeals($counter, $appeal, Registration::STATUS_SENT) as $registration) {
                // o avaliador lê a contrarrazão aqui mesmo: sem link para a ficha/formulário dela
                $item = $self->serializeRegistration($registration);
                unset($item['singleUrl'], $item['editUrl']);
                $items[] = $item + [
                    'authorNumber' => Plugin::meta($registration, 'counterAppealAuthorNumber'),
                    'fields' => $self->getRegistrationFieldValues($registration),
                ];
            }

            $this->jsObject['config']['counterAppealEvaluation'] = [
                'appealNumber' => $appeal->number,
                'registrationFrom' => $counter->registrationFrom?->format('Y-m-d H:i:s'),
                'registrationTo' => $counter->registrationTo?->format('Y-m-d H:i:s'),
                'isOpen' => $counter->isRegistrationOpen(),
                'judgementLocked' => $self->isAppealJudgementLocked($appeal_phase),
                'lockMessage' => $self->isAppealJudgementLocked($appeal_phase) ? $self->getJudgementLockMessage($appeal_phase) : null,
                // a comissão sempre vê os nomes; estes dois avisam o que os candidatos veem
                'showNames' => $self->showNames($counter),
                'visibleToAppellant' => $self->isVisibleToAppellant($counter),
                'items' => $items,
            ];
            $this->import('counter-appeal-evaluation');
            $this->part('contrarrazoes/evaluation-block');
        });
    }

    /**
     * Dados de uma fase de recurso para o gestor: configuração das contrarrazões (aba Configuração
     * de fases), acompanhamento (aba Inscrições e Resultados) e a página da lista de contrarrazões.
     */
    function buildPhaseConfigItem(Opportunity $appeal_phase): array
    {
        $counter = $this->getCounterAppealPhase($appeal_phase);
        $item = [
            'appealPhase' => [
                'id' => $appeal_phase->id,
                'name' => $appeal_phase->name,
                'registrationFrom' => $appeal_phase->registrationFrom?->format('Y-m-d H:i:s'),
                'registrationTo' => $appeal_phase->registrationTo?->format('Y-m-d H:i:s'),
                'evaluatedPhaseName' => $appeal_phase->parent?->evaluationMethodConfiguration?->name ?: $appeal_phase->parent?->name,
                'sentAppeals' => count($this->getSentAppeals($appeal_phase)),
                'judgementLocked' => $this->isAppealJudgementLocked($appeal_phase),
            ],
            'publicFieldOptions' => array_values(array_map(fn ($f) => [
                'name' => $f['name'],
                'title' => $f['title'],
                'type' => $f['type'],
            ], $this->getPhaseFormFields($appeal_phase, true))),
            'counter' => null,
            'counterAppeals' => [],
            // 1.4.0: recursos enviados, para o acompanhamento na aba Inscrições e Resultados
            'appeals' => [],
        ];

        $status_labels = $this->getStatusLabels($appeal_phase);

        if ($counter) {
            $item['counter'] = $this->serializeCounterAppealPhase($counter);
            foreach ($this->getCounterAppeals($counter) as $registration) {
                $target = Plugin::meta($registration, 'counterAppealTarget')
                    ? App::i()->repo('Registration')->find((int) Plugin::meta($registration, 'counterAppealTarget'))
                    : null;
                $item['counterAppeals'][] = $this->serializeRegistration($registration) + [
                    'authorNumber' => Plugin::meta($registration, 'counterAppealAuthorNumber'),
                    'targetId' => $target?->id,
                    'targetNumber' => $target?->number,
                    'targetAgentName' => $target?->owner?->name,
                ];
            }
        }

        foreach ($this->getSentAppeals($appeal_phase) as $appeal) {
            $item['appeals'][] = $this->serializeRegistration($appeal) + [
                'statusLabel' => $status_labels[(int) $appeal->status] ?? (string) $appeal->status,
            ];
        }

        // 1.4.0: página com as listas de acompanhamento (abre em nova aba)
        $item['trackingUrl'] = App::i()->createUrl('opportunity', 'counterAppeals', [$appeal_phase->id]);

        return $item;
    }

    /**
     * Insere o acordeão "Contrarrazões" no template da etapa de recurso, entre "Recurso" e
     * "Resposta do recurso" (o primeiro </mc-accordion> do template é o fim do acordeão "Recurso").
     */
    function injectCounterAppealConfig(string $template): string
    {
        // o mesmo template é usado nas abas "Configuração de fases" (tab = config) e
        // "Inscrições e Resultados" (tab = registrations): um acordeão para cada aba
        $tag = '<counter-appeal-config v-if="tab === \'config\'" :appeal-phase-id="entity.id"></counter-appeal-config>'
            . "\n" . '<counter-appeal-registrations v-if="tab === \'registrations\'" :appeal-phase-id="entity.id"></counter-appeal-registrations>';

        if (str_contains($template, '<counter-appeal-config')) {
            return $template;
        }

        $marker = '</mc-accordion>';
        $position = strpos($template, $marker);
        if ($position !== false && substr_count($template, $marker) >= 2) {
            $position += strlen($marker);
            return substr($template, 0, $position) . "\n" . $tag . substr($template, $position);
        }

        // marcação inesperada: o acordeão vai para o fim da etapa de recurso
        App::i()->log->warning('Contrarrazoes: não encontrei o acordeão "Recurso" no template opportunity-appeal-phase-config; a configuração das contrarrazões foi colocada no fim da etapa de recurso.');
        $last = strrpos($template, '</div>');
        return $last === false ? $template . $tag : substr($template, 0, $last) . $tag . substr($template, $last);
    }

    /**
     * Endereço da linha do tempo da inscrição com a lista de recursos já aberta
     * (o componente counter-appeal-timeline abre o modal quando vê ?contrarrazoes={id da fase};
     * com &contrarrazao={id}, a lista já abre com essa contrarrazão expandida)
     */
    function getCounterAppealListUrl(Registration $registration, Opportunity $counter, ?Registration $expand = null): string
    {
        // usa o endereço da inscrição na 1ª fase (o mesmo de "Minhas inscrições"); a página é a mesma
        $first = $registration->opportunity->firstPhase;
        if ($first && !$registration->opportunity->equals($first)) {
            $registration = App::i()->repo('Registration')->findOneBy(['opportunity' => $first, 'number' => $registration->number]) ?: $registration;
        }

        return $registration->singleUrl . '?contrarrazoes=' . $counter->id
            . ($expand ? '&contrarrazao=' . $expand->id : '') . '#acompanhamento';
    }

    /**
     * Inscrição do edital com um número (a da fase que define a elegibilidade, ou a da primeira fase)
     */
    function findAuthorRegistration(Opportunity $counter, string $number): ?Registration
    {
        $app = App::i();
        $base_phase = $this->getEligibilityBasePhase($counter);
        $edital = $this->getAppealPhase($counter)?->parent?->firstPhase;

        foreach (array_filter([$base_phase, $edital]) as $phase) {
            if ($registration = $app->repo('Registration')->findOneBy(['opportunity' => $phase, 'number' => $number])) {
                return $registration;
            }
        }

        return null;
    }

    /**
     * Dados de uma fase de contrarrazões para a aba "Recursos e contrarrazões" da inscrição.
     *
     * @param Registration|null $registration inscrição cuja página está aberta: só ela pode ser a autora
     */
    function buildPublicPhaseData(Opportunity $appeal_phase, Opportunity $counter, ?Registration $registration = null): array
    {
        $app = App::i();
        $user = $app->user;

        $all_candidates = $this->getUserCandidateRegistrations($counter, $user);
        $candidates = $registration
            ? array_values(array_filter($all_candidates, fn ($r) => (string) $r->number === (string) $registration->number))
            : $all_candidates;
        $can_see_list = $candidates || $counter->canUser('@control');

        $data = [
            'counterId' => $counter->id,
            // fase avaliada que recebeu o recurso: é nela que o bloco aparece na linha do tempo
            'evaluatedPhaseId' => $appeal_phase->parent?->id,
            'appealPhaseName' => $appeal_phase->name,
            'evaluatedPhaseName' => $appeal_phase->parent?->evaluationMethodConfiguration?->name ?: $appeal_phase->parent?->name,
            'registrationFrom' => $counter->registrationFrom?->format('Y-m-d H:i:s'),
            'registrationTo' => $counter->registrationTo?->format('Y-m-d H:i:s'),
            'isOpen' => $counter->isRegistrationOpen(),
            'eligibility' => self::ELIGIBILITY_SAME_PHASE,
            'canSeeList' => $can_see_list,
            'authorRegistrations' => array_map(fn ($r) => [
                'id' => $r->id,
                'number' => $r->number,
                'agentName' => $r->owner?->name,
            ], $candidates),
            'appeals' => [],
        ];

        if (!$can_see_list) {
            return $data;
        }

        $public_fields = array_values((array) Plugin::meta($counter, 'counterAppealPublicFields'));
        $show_names = $this->showNames($counter) || $counter->canUser('@control');
        $visible_to_appellant = $this->isVisibleToAppellant($counter);
        // campos da contrarrazão que o autor do recurso pode ler: só os tipos publicáveis (LGPD) e anexos
        $counter_public_fields = array_map(fn ($f) => $f['name'], $this->getPhaseFormFields($counter, true));
        // recursos de qualquer inscrição do usuário contam como "seus" (não podem ser contestados por ele)
        $my_numbers = array_map(fn ($r) => (string) $r->number, $all_candidates);
        if ($registration) {
            $my_numbers[] = (string) $registration->number;
        }

        $data['showNames'] = $show_names;
        $data['visibleToAppellant'] = $visible_to_appellant;
        // sem campos no formulário, ninguém consegue iniciar uma contrarrazão
        $data['formReady'] = (bool) $this->getPhaseFormFields($counter);

        foreach ($this->getSentAppeals($appeal_phase) as $appeal) {
            $is_mine = in_array((string) $appeal->number, $my_numbers, true);

            // a contrarrazão do próprio candidato: o conteúdo enviado vai junto, para "Exibir contrarrazão"
            $mine = [];
            foreach ($candidates as $candidate) {
                if ($existing = $this->findCounterAppealBy($counter, $appeal, (string) $candidate->number)) {
                    $mine[] = $this->serializeRegistration($existing) + [
                        'authorNumber' => $candidate->number,
                        'fields' => $existing->status >= Registration::STATUS_SENT ? $this->getRegistrationFieldValues($existing) : [],
                    ];
                }
            }

            // contrarrazões recebidas pelo recurso do candidato (só se o gestor liberou)
            $received = [];
            if ($is_mine && $visible_to_appellant) {
                foreach ($this->getCounterAppeals($counter, $appeal, Registration::STATUS_SENT) as $counter_appeal) {
                    $author_number = (string) Plugin::meta($counter_appeal, 'counterAppealAuthorNumber');
                    $received[] = [
                        'id' => $counter_appeal->id,
                        'authorNumber' => $author_number,
                        'agentName' => $show_names ? $counter_appeal->owner?->name : null,
                        'sentTimestamp' => $counter_appeal->sentTimestamp?->format('Y-m-d H:i:s'),
                        'fields' => $this->getRegistrationFieldValues($counter_appeal, $counter_public_fields),
                    ];
                }
            }

            $data['appeals'][] = [
                'id' => $appeal->id,
                'number' => $appeal->number,
                'agentName' => $show_names || $is_mine ? $appeal->owner?->name : null,
                'sentTimestamp' => $appeal->sentTimestamp?->format('Y-m-d H:i:s'),
                'isMine' => $is_mine,
                'fields' => $public_fields ? $this->getRegistrationFieldValues($appeal, $public_fields, $counter) : [],
                'myCounterAppeals' => $mine,
                'receivedCounterAppeals' => $received,
            ];
        }

        return $data;
    }
}
