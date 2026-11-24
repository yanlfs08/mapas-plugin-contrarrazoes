<?php

namespace Contrarrazoes;

use DateTime;
use MapasCulturais\App;
use MapasCulturais\Entities\ChatMessage;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationEvaluation;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MapasCulturais\Entities\RegistrationStep;
use MapasCulturais\Entities\User;
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
 * - Resposta à contrarrazão (desde a 1.2.0): gravada no metadado privado `counterAppealResponses`
 *   da fase de contrarrazões, no formato {id da contrarrazão: {id do avaliador: resposta}}. Fica na
 *   fase (e não na contrarrazão) porque o dono da contrarrazão lê os metadados privados da própria
 *   inscrição, e a resposta só pode chegar a ele depois da publicação do resultado do recurso.
 */
class Plugin extends \MapasCulturais\Plugin
{
    const VERSION = '1.2.0';

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

            // trava o julgamento dos recursos até o fim do prazo de contrarrazões
            'lockAppealJudgement' => true,

            // nome padrão do campo criado no formulário de contrarrazões
            'defaultFieldTitle' => i::__('Fundamentação da contrarrazão'),

            // o avaliador só finaliza o recurso depois de responder todas as contrarrazões enviadas
            'requireCounterAppealResponse' => true,

            // classificação padrão da resposta (o gestor pode trocar ou desligar em cada fase)
            'defaultResponseResults' => [
                i::__('Acolhida'),
                i::__('Parcialmente acolhida'),
                i::__('Não acolhida'),
            ],

            // tamanho máximo do texto da resposta
            'responseMaxLength' => 20000,
        ];

        parent::__construct($config);
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

        // opções de classificação da resposta: null = padrão do plugin; [] = só o texto
        $this->registerOpportunityMetadata('counterAppealResponseResults', [
            'label' => i::__('Classificação da resposta às contrarrazões'),
            'type' => 'json',
        ]);

        // respostas dos avaliadores: {id da contrarrazão: {id do usuário: {result, text, created, updated}}}
        $this->registerOpportunityMetadata('counterAppealResponses', [
            'label' => i::__('Respostas às contrarrazões'),
            'type' => 'json',
            'private' => true,
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

    /* ---------------------------------------------------------------------
     * Respostas às contrarrazões
     * ------------------------------------------------------------------- */

    /**
     * Opções de classificação da resposta. Lista vazia = a resposta é só o texto.
     *
     * @return string[]
     */
    function getResponseResultOptions(Opportunity $counter): array
    {
        $value = Plugin::meta($counter, 'counterAppealResponseResults');
        if ($value === null) {
            return array_values($this->config['defaultResponseResults']);
        }

        return array_values(array_filter(array_map('strval', (array) $value), fn ($v) => trim($v) !== ''));
    }

    /**
     * Normaliza a lista de opções enviada pelo gestor (sem vazias e sem repetidas)
     *
     * @return string[]
     */
    static function normalizeResultOptions($options): array
    {
        $result = [];
        foreach ((array) $options as $option) {
            if (!is_scalar($option)) {
                continue;
            }
            $option = mb_substr(trim((string) $option), 0, 100);
            if ($option !== '' && !in_array($option, $result, true)) {
                $result[] = $option;
            }
        }

        return array_slice($result, 0, 10);
    }

    /**
     * Todas as respostas da fase: {id da contrarrazão: {id do usuário: resposta}}
     *
     * @param bool $fresh lê direto do banco (usado dentro da trava de gravação)
     */
    function getAllResponses(Opportunity $counter, bool $fresh = false): array
    {
        if ($fresh) {
            $raw = App::i()->em->getConnection()->fetchOne(
                'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key ORDER BY id ASC LIMIT 1',
                ['id' => $counter->id, 'key' => 'counterAppealResponses']
            );
            $value = $raw ? json_decode((string) $raw, true) : [];
        } else {
            $value = json_decode(json_encode(Plugin::meta($counter, 'counterAppealResponses')), true);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Respostas de uma contrarrazão, indexadas pelo id do avaliador
     */
    function getResponses(Opportunity $counter, Registration $counter_appeal): array
    {
        $all = $this->getAllResponses($counter);
        $responses = $all[(string) $counter_appeal->id] ?? [];

        return is_array($responses) ? $responses : [];
    }

    function getUserResponse(Opportunity $counter, Registration $counter_appeal, $user): ?array
    {
        if (!$user || $user->is('guest')) {
            return null;
        }

        return $this->getResponses($counter, $counter_appeal)[(string) $user->id] ?? null;
    }

    /**
     * Grava (ou substitui) a resposta de um avaliador a uma contrarrazão.
     * As respostas de todos os avaliadores ficam num único metadado: a gravação é feita sob trava,
     * relendo o valor do banco, para que respostas simultâneas não se sobrescrevam.
     */
    function saveResponse(Opportunity $counter, Registration $counter_appeal, User $user, ?string $result, string $text): array
    {
        $app = App::i();
        $lock = "contrarrazoes-respostas-{$counter->id}";
        $app->lock($lock, 5);

        try {
            $all = $this->getAllResponses($counter, true);
            $ca_key = (string) $counter_appeal->id;
            $user_key = (string) $user->id;
            $now = (new DateTime())->format('Y-m-d H:i:s');

            $all[$ca_key] = is_array($all[$ca_key] ?? null) ? $all[$ca_key] : [];
            $response = [
                'result' => $result,
                'text' => $text,
                'created' => $all[$ca_key][$user_key]['created'] ?? $now,
                'updated' => $now,
            ];
            $all[$ca_key][$user_key] = $response;

            $app->disableAccessControl();
            try {
                $counter->counterAppealResponses = $all;
                $counter->saveMetadata(true);
            } finally {
                $app->enableAccessControl();
            }
        } finally {
            $app->unlock($lock);
        }

        return $response;
    }

    /**
     * O prazo de contrarrazões já terminou (todas as contrarrazões possíveis já chegaram)
     */
    function isCounterAppealPeriodOver(Opportunity $counter): bool
    {
        return $counter->registrationTo && new DateTime() > $counter->registrationTo;
    }

    /**
     * O resultado da fase de recurso foi publicado: as respostas passam a ser visíveis aos candidatos
     */
    function isAppealResultPublished(Opportunity $appeal_phase): bool
    {
        return (bool) $appeal_phase->publishedRegistrations;
    }

    /**
     * Avaliador designado para o recurso (não basta ser gestor)
     */
    function isAppealValuer(Registration $appeal, $user): bool
    {
        if (!$user || $user->is('guest')) {
            return false;
        }

        return $appeal->canUser('evaluateOnTime', $user);
    }

    /**
     * Motivo pelo qual o usuário não pode registrar ou alterar a resposta (null = pode)
     */
    function getResponseDenialReason(Opportunity $counter, Registration $appeal, $user): ?string
    {
        $appeal_phase = $appeal->opportunity;

        if (!$this->isAppealValuer($appeal, $user)) {
            return i::__('Somente o avaliador do recurso pode responder às contrarrazões.');
        }

        if (!$this->isCounterAppealPeriodOver($counter)) {
            return $counter->registrationTo
                ? sprintf(i::__('As respostas podem ser registradas depois do fim do prazo de contrarrazões (%s).'), $counter->registrationTo->format('d/m/Y H:i'))
                : i::__('As respostas podem ser registradas depois do fim do prazo de contrarrazões, que ainda não foi definido.');
        }

        if ($this->isAppealResultPublished($appeal_phase)) {
            return i::__('O resultado do recurso já foi publicado: a resposta não pode mais ser alterada.');
        }

        $evaluation = $appeal->getUserEvaluation($user);
        if ($evaluation && $evaluation->status >= RegistrationEvaluation::STATUS_EVALUATED) {
            return i::__('A avaliação deste recurso já foi finalizada: a resposta não pode mais ser alterada.');
        }

        return null;
    }

    /**
     * Contrarrazões enviadas a um recurso que ainda não têm resposta.
     *
     * @param User|null $user avaliador; null = contrarrazões sem resposta de nenhum avaliador
     * @return Registration[]
     */
    function getPendingCounterAppeals(Registration $appeal, $user = null): array
    {
        $counter = $this->getCounterAppealPhase($appeal->opportunity);
        if (!$counter) {
            return [];
        }

        $all = $this->getAllResponses($counter);

        return array_values(array_filter(
            $this->getCounterAppeals($counter, $appeal, Registration::STATUS_SENT),
            function (Registration $counter_appeal) use ($all, $user) {
                $responses = $all[(string) $counter_appeal->id] ?? [];
                if (!is_array($responses)) {
                    return true;
                }
                if ($user) {
                    return empty($responses[(string) $user->id]['text']);
                }
                return !array_filter($responses, fn ($r) => !empty($r['text']));
            }
        ));
    }

    /**
     * A resposta às contrarrazões é obrigatória para finalizar este recurso
     */
    function isResponseRequired(Opportunity $appeal_phase): bool
    {
        return $this->config['requireCounterAppealResponse']
            && Plugin::meta($appeal_phase, 'isAppealPhase')
            && $this->getCounterAppealPhase($appeal_phase);
    }

    /**
     * @param Registration[] $pending
     */
    function getPendingResponsesMessage(array $pending, ?Registration $appeal = null): string
    {
        $numbers = implode(', ', array_map(fn ($r) => (string) Plugin::meta($r, 'counterAppealAuthorNumber'), $pending));

        if ($appeal) {
            return sprintf(
                i::__('Responda às contrarrazões apresentadas ao recurso %s antes de finalizar o julgamento. Contrarrazões sem resposta: %s.'),
                $appeal->number,
                $numbers
            );
        }

        return sprintf(i::__('Responda às contrarrazões antes de finalizar o julgamento do recurso. Contrarrazões sem resposta: %s.'), $numbers);
    }

    /**
     * Respostas de uma contrarrazão para exibição aos candidatos (sem identificar o avaliador)
     */
    function serializePublicResponses(Opportunity $counter, Registration $counter_appeal): array
    {
        $result = [];
        foreach ($this->getResponses($counter, $counter_appeal) as $response) {
            if (empty($response['text'])) {
                continue;
            }
            $result[] = [
                'result' => $response['result'] ?? null,
                'text' => (string) $response['text'],
                'updated' => $response['updated'] ?? null,
            ];
        }

        usort($result, fn ($a, $b) => strcmp((string) $a['updated'], (string) $b['updated']));

        return $result;
    }

    /**
     * Respostas de uma contrarrazão para o gestor (com o nome do avaliador)
     */
    function serializeResponsesForManager(Opportunity $counter, Registration $counter_appeal): array
    {
        $app = App::i();
        $result = [];
        foreach ($this->getResponses($counter, $counter_appeal) as $user_id => $response) {
            if (empty($response['text'])) {
                continue;
            }
            $user = $app->repo('User')->find((int) $user_id);
            $result[] = [
                'evaluatorName' => $user?->profile?->name,
                'result' => $response['result'] ?? null,
                'text' => (string) $response['text'],
                'updated' => $response['updated'] ?? null,
            ];
        }

        return $result;
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
        $data['counterAppealResponseResults'] = $this->getResponseResultOptions($counter);
        $data['formBuilderUrl'] = App::i()->createUrl('opportunity', 'formBuilder', [$counter->id]);

        // as respostas têm tela própria; não vão para o objeto da fase no front-end
        unset($data['counterAppealResponses']);

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

        // as respostas às contrarrazões só são gravadas pelo endpoint saveCounterAppealResponse,
        // que confere se quem responde é o avaliador do recurso
        foreach (['PATCH(opportunity.single):before', 'PUT(opportunity.single):before'] as $hook) {
            $app->hook($hook, function () {
                /** @var \MapasCulturais\Controllers\Opportunity $this */
                if (array_key_exists('counterAppealResponses', (array) $this->postData)) {
                    $this->errorJson(i::__('As respostas às contrarrazões só podem ser registradas pela tela de avaliação do recurso.'), 403);
                }
            });
        }

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

            $target_id = (int) Plugin::meta($this, 'counterAppealTarget');
            if ($target_id && ($appeal = $app->repo('Registration')->find($target_id))) {
                $result = $appeal->canUser('view', $user);
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

            // classificação da resposta: counterAppealResponseClassify = false grava a lista vazia
            // (resposta só em texto); true exige ao menos uma opção
            if (array_key_exists('counterAppealResponseClassify', $data) || array_key_exists('counterAppealResponseResults', $data)) {
                $classify = filter_var($data['counterAppealResponseClassify'] ?? true, FILTER_VALIDATE_BOOLEAN);
                $options = $classify ? Plugin::normalizeResultOptions($data['counterAppealResponseResults'] ?? []) : [];

                if ($classify && !$options) {
                    $this->errorJson(['counterAppealResponseResults' => [i::__('Informe ao menos uma opção de classificação ou desmarque a classificação da resposta.')]], 400);
                }

                $counter->counterAppealResponseResults = $options;
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

        /**
         * Grava a resposta do avaliador do recurso a uma contrarrazão
         * POST /registration/saveCounterAppealResponse/{id da contrarrazão}
         * dados: result (uma das opções da fase, quando houver classificação), text
         */
        $app->hook('POST(registration.saveCounterAppealResponse)', function () use ($app, $self) {
            /** @var \MapasCulturais\Controllers\Registration $this */
            $this->requireAuthentication();

            $counter_appeal = $this->requestedEntity;
            $counter = $counter_appeal?->opportunity;
            if (!$counter_appeal instanceof Registration || !Plugin::meta($counter, 'isCounterAppealPhase')) {
                $this->errorJson(i::__('A inscrição informada não é uma contrarrazão.'), 400);
            }

            if ($counter_appeal->status < Registration::STATUS_SENT) {
                $this->errorJson(i::__('Só é possível responder a contrarrazões enviadas.'), 400);
            }

            $appeal_id = (int) Plugin::meta($counter_appeal, 'counterAppealTarget');
            $appeal = $appeal_id ? $app->repo('Registration')->find($appeal_id) : null;
            if (!$appeal || !$self->getAppealPhase($counter) || !$appeal->opportunity->equals($self->getAppealPhase($counter))) {
                $this->errorJson(i::__('O recurso contestado não foi encontrado.'), 400);
            }

            $user = $app->user;
            if ($reason = $self->getResponseDenialReason($counter, $appeal, $user)) {
                $this->errorJson($reason, 403);
            }

            $data = $this->data;
            $text = trim((string) (is_scalar($data['text'] ?? null) ? $data['text'] : ''));
            $result = is_scalar($data['result'] ?? null) ? trim((string) $data['result']) : '';
            $options = $self->getResponseResultOptions($counter);

            $errors = [];
            if ($text === '') {
                $errors['text'] = [i::__('Escreva a fundamentação da resposta.')];
            } else if (mb_strlen($text) > (int) $self->config['responseMaxLength']) {
                $errors['text'] = [sprintf(i::__('A resposta deve ter no máximo %s caracteres.'), $self->config['responseMaxLength'])];
            }

            if ($options) {
                if (!in_array($result, $options, true)) {
                    $errors['result'] = [i::__('Selecione a classificação da resposta.')];
                }
            } else {
                $result = '';
            }

            if ($errors) {
                $this->errorJson($errors, 400);
            }

            $response = $self->saveResponse($counter, $counter_appeal, $user, $result !== '' ? $result : null, $text);

            $this->json([
                'counterAppealId' => $counter_appeal->id,
                'response' => $response,
                'pending' => count($self->getPendingCounterAppeals($appeal, $user)),
            ]);
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
     * Cria a fase de contrarrazões, com um formulário padrão
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
            $counter->save(true);

            $step = new RegistrationStep();
            $step->name = i::__('Contrarrazão');
            $step->opportunity = $counter;
            $step->save(true);

            $field = new RegistrationFieldConfiguration();
            $field->owner = $counter;
            $field->step = $step;
            $field->title = $this->config['defaultFieldTitle'];
            $field->description = i::__('Apresente os argumentos contrários ao recurso.');
            $field->fieldType = 'textarea';
            $field->required = true;
            $field->displayOrder = 1;
            $field->fieldOptions = [];
            $field->config = [];
            $field->categories = [];
            $field->save(true);

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

            // o avaliador precisa responder todas as contrarrazões antes de finalizar
            if ($appeal_phase && $self->isResponseRequired($appeal_phase)) {
                $uid = $this->postData['uid'] ?? null;
                $user = $uid ? App::i()->repo('User')->find((int) $uid) : App::i()->user;
                if ($user && ($pending = $self->getPendingCounterAppeals($registration, $user))) {
                    $this->errorJson($self->getPendingResponsesMessage($pending), 403);
                }
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

            // só na finalização (avaliação que já estava finalizada pode ser regravada)
            $original = $this->isNew() ? [] : $app->em->getUnitOfWork()->getOriginalEntityData($this);
            $was_final = isset($original['status']) && (int) $original['status'] >= RegistrationEvaluation::STATUS_EVALUATED;

            if (!$was_final && $appeal_phase && $this->user && $self->isResponseRequired($appeal_phase)
                && ($pending = $self->getPendingCounterAppeals($registration, $this->user))) {
                $app->unlock("save-user-evauation--{$registration->id}--{$this->user->id}");
                throw new PermissionDenied($app->user, $this, 'send', $self->getPendingResponsesMessage($pending));
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

            // decisão pelo chat: quem decide precisa ter respondido todas as contrarrazões
            $user = $this->user ?: $app->user;
            if ($self->isResponseRequired($appeal_phase) && $user && !$user->is('guest')
                && ($pending = $self->getPendingCounterAppeals($registration, $user))) {
                throw new PermissionDenied($app->user, $this, 'create', $self->getPendingResponsesMessage($pending));
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

            // deferir/indeferir exige que toda contrarrazão enviada tenha resposta de algum avaliador
            $decisions = [Registration::STATUS_APPROVED, Registration::STATUS_NOTAPPROVED, Registration::STATUS_WAITLIST, Registration::STATUS_INVALID];
            if (in_array((int) $this->status, $decisions, true) && $self->isResponseRequired($this->opportunity)
                && ($pending = $self->getPendingCounterAppeals($this))) {
                throw new PermissionDenied($app->user, $this, 'changeStatus', $self->getPendingResponsesMessage($pending, $this));
            }
        });

        // aplicação em lote dos resultados da avaliação contínua e publicação do resultado:
        // confere antes de começar, para não aplicar só uma parte e responder com uma mensagem clara
        $app->hook('POST(opportunity.applyEvaluationsContinuous):before', function () use ($self, $app) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */
            $appeal_phase = $this->requestedEntity;
            if (!$appeal_phase instanceof Opportunity || !Plugin::meta($appeal_phase, 'isAppealPhase')) {
                return;
            }

            if ($self->isAppealJudgementLocked($appeal_phase)) {
                $this->errorJson($self->getJudgementLockMessage($appeal_phase), 403);
            }

            if (!$self->isResponseRequired($appeal_phase)) {
                return;
            }

            $from = $this->data['from'] ?? null;
            $to = (int) ($this->data['to'] ?? 0);
            $all = ($this->data['status'] ?? null) === 'all';
            $messages = [];
            foreach ($self->getSentAppeals($appeal_phase) as $appeal) {
                if ((string) $appeal->consolidatedResult !== (string) $from || (int) $appeal->status === $to) {
                    continue;
                }
                if (!$all && (int) $appeal->status !== Registration::STATUS_SENT) {
                    continue;
                }
                if ($pending = $self->getPendingCounterAppeals($appeal)) {
                    $messages[] = $self->getPendingResponsesMessage($pending, $appeal);
                }
            }

            if ($messages) {
                $this->errorJson(implode(' ', $messages), 403);
            }
        });

        $app->hook('ALL(opportunity.publishRegistrations):before', function () use ($self) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */
            $appeal_phase = $this->requestedEntity;
            if (!$appeal_phase instanceof Opportunity || !Plugin::meta($appeal_phase, 'isAppealPhase')) {
                return;
            }

            if ($self->isAppealJudgementLocked($appeal_phase)) {
                $this->errorJson($self->getJudgementLockMessage($appeal_phase), 403);
            }

            if (!$self->isResponseRequired($appeal_phase)) {
                return;
            }

            $messages = [];
            foreach ($self->getSentAppeals($appeal_phase) as $appeal) {
                if ($pending = $self->getPendingCounterAppeals($appeal)) {
                    $messages[] = $self->getPendingResponsesMessage($pending, $appeal);
                }
            }

            if ($messages) {
                $this->errorJson(implode(' ', $messages), 403);
            }
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

            $config = [];
            foreach ($appeal_phases as $appeal_phase) {
                $counter = $self->getCounterAppealPhase($appeal_phase);
                $item = [
                    'appealPhase' => [
                        'id' => $appeal_phase->id,
                        'name' => $appeal_phase->name,
                        'registrationFrom' => $appeal_phase->registrationFrom?->format('Y-m-d H:i:s'),
                        'registrationTo' => $appeal_phase->registrationTo?->format('Y-m-d H:i:s'),
                        'evaluatedPhaseName' => $appeal_phase->parent?->evaluationMethodConfiguration?->name ?: $appeal_phase->parent?->name,
                        'sentAppeals' => count($self->getSentAppeals($appeal_phase)),
                        'judgementLocked' => $self->isAppealJudgementLocked($appeal_phase),
                    ],
                    'publicFieldOptions' => array_values(array_map(fn ($f) => [
                        'name' => $f['name'],
                        'title' => $f['title'],
                        'type' => $f['type'],
                    ], $self->getPhaseFormFields($appeal_phase, true))),
                    'counter' => null,
                    'counterAppeals' => [],
                ];

                if ($counter) {
                    $item['counter'] = $self->serializeCounterAppealPhase($counter);
                    foreach ($self->getCounterAppeals($counter) as $registration) {
                        $target = Plugin::meta($registration, 'counterAppealTarget')
                            ? App::i()->repo('Registration')->find((int) Plugin::meta($registration, 'counterAppealTarget'))
                            : null;
                        $item['counterAppeals'][] = $self->serializeRegistration($registration) + [
                            'authorNumber' => Plugin::meta($registration, 'counterAppealAuthorNumber'),
                            'targetNumber' => $target?->number,
                            'targetAgentName' => $target?->owner?->name,
                            // o gestor vê as respostas de todos os avaliadores, com o nome de cada um
                            'responses' => $self->serializeResponsesForManager($counter, $registration),
                        ];
                    }
                }

                $config[] = $item;
            }

            $this->jsObject['config']['counterAppealConfig'] = $config;
            $this->jsObject['config']['counterAppealDefaultResponseResults'] = array_values($self->config['defaultResponseResults']);
            $this->import('counter-appeal-config');
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

            // resposta da comissão: visível depois da publicação do resultado do recurso
            $appeal_phase = $self->getAppealPhase($counter);
            $published = $appeal_phase && $self->isAppealResultPublished($appeal_phase) && $registration->status > 0;

            $this->part('contrarrazoes/registration-banner', [
                'appeal' => $appeal,
                'counter' => $counter,
                'listUrl' => $author ? $self->getCounterAppealListUrl($author, $counter) : null,
                'responses' => $published ? $self->serializePublicResponses($counter, $registration) : [],
                'resultPublished' => $published,
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

            // avaliador cuja avaliação está aberta: o próprio usuário ou, para o gestor, o de ?user=
            $app = App::i();
            $evaluator = $app->user;
            if (isset($this->controller->data['user']) && $appeal_phase->canUser('@control')) {
                $evaluator = $app->repo('User')->find((int) $this->controller->data['user']) ?: $app->user;
            }
            $is_own = $evaluator && $evaluator->equals($app->user);
            $deny_reason = $is_own
                ? $self->getResponseDenialReason($counter, $appeal, $evaluator)
                : i::__('Somente o próprio avaliador pode registrar ou alterar as respostas dele.');

            $items = [];
            $pending = 0;
            foreach ($self->getCounterAppeals($counter, $appeal, Registration::STATUS_SENT) as $registration) {
                $response = $self->getUserResponse($counter, $registration, $evaluator);
                if (empty($response['text'])) {
                    $pending++;
                }
                $items[] = $self->serializeRegistration($registration) + [
                    'authorNumber' => Plugin::meta($registration, 'counterAppealAuthorNumber'),
                    'fields' => $self->getRegistrationFieldValues($registration),
                    'response' => $response,
                ];
            }

            $this->jsObject['config']['counterAppealEvaluation'] = [
                'evaluatorName' => $evaluator?->profile?->name,
                'isOwnEvaluation' => $is_own,
                'canRespond' => $deny_reason === null,
                'respondDeniedReason' => $deny_reason,
                'resultOptions' => $self->getResponseResultOptions($counter),
                'responseRequired' => $self->isResponseRequired($appeal_phase),
                'responseMaxLength' => (int) $self->config['responseMaxLength'],
                'resultPublished' => $self->isAppealResultPublished($appeal_phase),
                'pendingCount' => $pending,
                'appealNumber' => $appeal->number,
                'registrationFrom' => $counter->registrationFrom?->format('Y-m-d H:i:s'),
                'registrationTo' => $counter->registrationTo?->format('Y-m-d H:i:s'),
                'isOpen' => $counter->isRegistrationOpen(),
                'judgementLocked' => $self->isAppealJudgementLocked($appeal_phase),
                'lockMessage' => $self->isAppealJudgementLocked($appeal_phase) ? $self->getJudgementLockMessage($appeal_phase) : null,
                'items' => $items,
            ];
            $this->import('counter-appeal-evaluation');
            $this->part('contrarrazoes/evaluation-block');
        });
    }

    /**
     * Insere o acordeão "Contrarrazões" no template da etapa de recurso, entre "Recurso" e
     * "Resposta do recurso" (o primeiro </mc-accordion> do template é o fim do acordeão "Recurso").
     */
    function injectCounterAppealConfig(string $template): string
    {
        $tag = '<counter-appeal-config v-if="tab === \'config\'" :appeal-phase-id="entity.id"></counter-appeal-config>';

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
     * (o componente counter-appeal-timeline abre o modal quando vê ?contrarrazoes={id da fase})
     */
    function getCounterAppealListUrl(Registration $registration, Opportunity $counter): string
    {
        // usa o endereço da inscrição na 1ª fase (o mesmo de "Minhas inscrições"); a página é a mesma
        $first = $registration->opportunity->firstPhase;
        if ($first && !$registration->opportunity->equals($first)) {
            $registration = App::i()->repo('Registration')->findOneBy(['opportunity' => $first, 'number' => $registration->number]) ?: $registration;
        }

        return $registration->singleUrl . '?contrarrazoes=' . $counter->id . '#acompanhamento';
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
        // recursos de qualquer inscrição do usuário contam como "seus" (não podem ser contestados por ele)
        $my_numbers = array_map(fn ($r) => (string) $r->number, $all_candidates);
        if ($registration) {
            $my_numbers[] = (string) $registration->number;
        }

        // as respostas da comissão só aparecem depois da publicação do resultado do recurso
        $published = $this->isAppealResultPublished($appeal_phase);
        $data['resultPublished'] = $published;

        foreach ($this->getSentAppeals($appeal_phase) as $appeal) {
            $mine = [];
            foreach ($candidates as $candidate) {
                if ($existing = $this->findCounterAppealBy($counter, $appeal, (string) $candidate->number)) {
                    $mine[] = $this->serializeRegistration($existing) + [
                        'authorNumber' => $candidate->number,
                        'responses' => $published && $existing->status > 0 ? $this->serializePublicResponses($counter, $existing) : [],
                    ];
                }
            }

            $is_mine = in_array((string) $appeal->number, $my_numbers, true);

            // o autor do recurso vê as respostas às contrarrazões apresentadas contra ele
            $received = [];
            if ($published && $is_mine) {
                foreach ($this->getCounterAppeals($counter, $appeal, Registration::STATUS_SENT) as $counter_appeal) {
                    $received[] = [
                        'id' => $counter_appeal->id,
                        'authorNumber' => Plugin::meta($counter_appeal, 'counterAppealAuthorNumber'),
                        'agentName' => $counter_appeal->owner?->name,
                        'sentTimestamp' => $counter_appeal->sentTimestamp?->format('Y-m-d H:i:s'),
                        'responses' => $this->serializePublicResponses($counter, $counter_appeal),
                    ];
                }
            }

            $data['appeals'][] = [
                'id' => $appeal->id,
                'number' => $appeal->number,
                'agentName' => $appeal->owner?->name,
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
