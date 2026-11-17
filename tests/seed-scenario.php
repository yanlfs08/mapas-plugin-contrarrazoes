<?php
/**
 * Cria um cenário de teste para o plugin de contrarrazões numa instalação de DESENVOLVIMENTO.
 * NUNCA execute em produção.
 *
 * Uso (a partir da raiz do Mapas, com as mesmas variáveis de ambiente do servidor):
 *   php src/plugins/Contrarrazoes/tests/seed-scenario.php base
 *   php src/plugins/Contrarrazoes/tests/seed-scenario.php appeals <id da fase de recurso>
 *
 * Etapa "base":
 *   - usuários: gestor, avaliador e candidatos C1..C5, mais um usuário sem inscrição (C6)
 *   - edital com avaliação na 1ª fase (Habilitação) e uma 2ª fase de avaliação (Mérito)
 *   - C1..C5 inscritos; C4 e C5 eliminados na Habilitação; C1, C2 e C3 seguem para o Mérito
 *   - no Mérito: C1 selecionado, C2 suplente, C3 não selecionado
 *
 * Etapa "appeals" (depois de criar a fase de recurso do Mérito):
 *   - formulário do recurso: fundamentação (texto longo), CPF e um anexo
 *   - C2 e C3 enviam recurso
 *
 * Saída: JSON com os ids criados.
 */

use MapasCulturais\App;
use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MapasCulturais\Entities\RegistrationFileConfiguration;
use MapasCulturais\Entities\RegistrationFile;

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = 'CLI';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = '8000';

$mapas_root = getenv('MAPAS_ROOT') ?: realpath(__DIR__ . '/../../../..');
require $mapas_root . '/public/bootstrap.php';

$app = App::i();
$app->disableAccessControl();

$step = $argv[1] ?? 'base';
$out = [];

function create_user(string $name, string $email)
{
    $app = App::i();
    $user = $app->repo('User')->findOneBy(['email' => $email]);
    if ($user) {
        return $user;
    }

    $user = new \MapasCulturais\Entities\User();
    $user->authProvider = 'Fake';
    $user->authUid = uniqid('fake-');
    $user->email = $email;
    $app->em->persist($user);
    $app->em->flush();

    $agent = new \MapasCulturais\Entities\Agent($user);
    $agent->name = $name;
    $agent->type = 1;
    $agent->save(true);

    $user->profile = $agent;
    $user->save(true);

    return $user;
}

if ($step === 'base') {
    $users = [
        'gestor' => create_user('Gestora do Edital', 'gestor@teste.local'),
        'avaliador' => create_user('Avaliador da Comissão', 'avaliador@teste.local'),
        'c1' => create_user('Candidata Um', 'c1@teste.local'),
        'c2' => create_user('Candidato Dois', 'c2@teste.local'),
        'c3' => create_user('Candidata Três', 'c3@teste.local'),
        'c4' => create_user('Candidato Quatro', 'c4@teste.local'),
        'c5' => create_user('Candidata Cinco', 'c5@teste.local'),
        'c6' => create_user('Pessoa Sem Inscrição', 'c6@teste.local'),
    ];

    foreach ($users as $key => $user) {
        $out['users'][$key] = ['id' => $user->id, 'agent' => $user->profile->id];
    }

    $gestor_agent = $users['gestor']->profile;

    // as entidades criadas recebem o perfil do usuário logado como responsável
    $app->auth->login($users['gestor']->id);

    // edital (primeira fase)
    $opportunity = new \MapasCulturais\Entities\AgentOpportunity();
    $opportunity->name = 'Edital de Teste - Contrarrazões';
    $opportunity->shortDescription = 'Edital criado pelo script de teste do plugin de contrarrazões';
    $opportunity->type = 9;
    $opportunity->ownerEntity = $gestor_agent;
    $opportunity->owner = $gestor_agent;
    $opportunity->registrationFrom = new DateTime('-30 days');
    $opportunity->registrationTo = new DateTime('-20 days');
    $opportunity->registrationProponentTypes = ['Pessoa Física'];
    $opportunity->status = 1;
    $opportunity->save(true);
    $opportunity = $opportunity->refreshed();

    // avaliação na primeira fase: Habilitação
    $emc1 = new EvaluationMethodConfiguration();
    $emc1->opportunity = $opportunity;
    $emc1->type = 'simple';
    $emc1->name = 'Habilitação';
    $emc1->evaluationFrom = new DateTime('-19 days');
    $emc1->evaluationTo = new DateTime('-15 days');
    $emc1->save(true);

    // segunda fase de avaliação: Mérito (cria uma oportunidade auxiliar)
    $emc2 = new EvaluationMethodConfiguration();
    $emc2->opportunity = $opportunity->refreshed();
    $emc2->type = 'simple';
    $emc2->name = 'Mérito';
    $emc2->evaluationFrom = new DateTime('-14 days');
    $emc2->evaluationTo = new DateTime('-8 days');
    $emc2->save(true);
    $merit_phase = $emc2->opportunity;

    // inscrições na primeira fase
    $results_first = ['c1' => 10, 'c2' => 10, 'c3' => 10, 'c4' => 3, 'c5' => 3];
    $registrations = [];
    foreach ($results_first as $key => $status) {
        $registration = new Registration();
        $registration->opportunity = $opportunity->refreshed();
        $registration->owner = $users[$key]->profile;
        $registration->proponentType = 'Pessoa Física';
        $registration->save(true);
        $registration->send();
        $registration = $registration->refreshed();
        $registration->setStatus($status);
        $registration->save(true);
        $registrations[$key] = $registration;
        $out['registrations']['first'][$key] = ['id' => $registration->id, 'number' => $registration->number];
    }

    // importa os selecionados para o Mérito
    $merit_phase = $merit_phase->refreshed();
    $merit_phase->importPreviousPhaseRegistrations();
    $app->em->clear();

    $merit_phase = $app->repo('Opportunity')->find($merit_phase->id);
    $results_merit = ['c1' => 10, 'c2' => 8, 'c3' => 3];
    foreach ($results_merit as $key => $status) {
        $number = $out['registrations']['first'][$key]['number'];
        $registration = $app->repo('Registration')->findOneBy(['opportunity' => $merit_phase, 'number' => $number]);
        if (!$registration) {
            fwrite(STDERR, "inscrição {$number} não foi importada para o Mérito\n");
            exit(1);
        }
        $registration->setStatus($status);
        $registration->save(true);
        $out['registrations']['merit'][$key] = ['id' => $registration->id, 'number' => $registration->number];
    }

    $out['opportunity'] = $opportunity->id;
    $out['meritPhase'] = $merit_phase->id;
    $out['meritEmc'] = $emc2->id;
}

if ($step === 'appeals') {
    $appeal_phase = $app->repo('Opportunity')->find((int) ($argv[2] ?? 0));
    if (!$appeal_phase || !$appeal_phase->isAppealPhase) {
        fwrite(STDERR, "informe o id da fase de recurso\n");
        exit(1);
    }

    $appeal_phase->registrationFrom = new DateTime('-7 days');
    $appeal_phase->registrationTo = new DateTime('+30 days');
    $appeal_phase->save(true);

    $step_entity = $app->repo('RegistrationStep')->findOneBy(['opportunity' => $appeal_phase]);

    $text = new RegistrationFieldConfiguration();
    $text->owner = $appeal_phase;
    $text->step = $step_entity;
    $text->title = 'Fundamentação do recurso';
    $text->fieldType = 'textarea';
    $text->required = true;
    $text->displayOrder = 1;
    $text->fieldOptions = [];
    $text->config = [];
    $text->categories = [];
    $text->save(true);

    $cpf = new RegistrationFieldConfiguration();
    $cpf->owner = $appeal_phase;
    $cpf->step = $step_entity;
    $cpf->title = 'CPF do responsável';
    $cpf->fieldType = 'cpf';
    $cpf->required = false;
    $cpf->displayOrder = 2;
    $cpf->fieldOptions = [];
    $cpf->config = [];
    $cpf->categories = [];
    $cpf->save(true);

    $file_config = new RegistrationFileConfiguration();
    $file_config->owner = $appeal_phase;
    $file_config->step = $step_entity;
    $file_config->title = 'Documento do recurso';
    $file_config->required = false;
    $file_config->displayOrder = 3;
    $file_config->save(true);

    $out['appealFields'] = [
        'text' => $text->fieldName,
        'cpf' => $cpf->fieldName,
        'file' => $file_config->fileGroupName,
    ];

    $appeal_phase = $appeal_phase->refreshed();
    $appeal_phase->registerRegistrationMetadata();

    // os recursos de C2 e C3 já devem ter sido criados pelo endpoint createAppealPhaseRegistration
    $appeals = $app->repo('Registration')->findBy(['opportunity' => $appeal_phase]);
    foreach ($appeals as $appeal) {
        $appeal->{$text->fieldName} = "Discordo da nota atribuída à inscrição {$appeal->number}.\nA proposta atende a todos os critérios do edital.";
        $appeal->{$cpf->fieldName} = '123.456.789-09';
        $appeal->save(true);

        $tmp = tempnam(sys_get_temp_dir(), 'recurso');
        file_put_contents($tmp, "%PDF-1.4\n% documento de teste do recurso {$appeal->number}\n");
        $file = new RegistrationFile([
            'name' => "recurso-{$appeal->number}.pdf",
            'type' => 'application/pdf',
            'tmp_name' => $tmp,
            'error' => 0,
            'size' => filesize($tmp),
        ]);
        $file->owner = $appeal;
        $file->group = $file_config->fileGroupName;
        $file->save(true);

        $appeal = $appeal->refreshed();
        $appeal->send();
        $out['appeals'][] = ['id' => $appeal->id, 'number' => $appeal->number, 'status' => $appeal->refreshed()->status, 'file' => $file->id];
    }
}

if ($step === 'committee') {
    // coloca o avaliador na comissão da fase de recurso e abre o período de avaliação
    $appeal_phase = $app->repo('Opportunity')->find((int) ($argv[2] ?? 0));
    $evaluator = $app->repo('User')->findOneBy(['email' => 'avaliador@teste.local']);
    if (!$appeal_phase || !$appeal_phase->isAppealPhase || !$evaluator) {
        fwrite(STDERR, "informe o id da fase de recurso (e rode a etapa base antes)\n");
        exit(1);
    }

    $emc = $appeal_phase->evaluationMethodConfiguration;
    $emc->evaluationFrom = new DateTime('-1 day');
    $emc->evaluationTo = new DateTime('+30 days');
    $emc->save(true);

    $relation = $emc->createAgentRelation($evaluator->profile, 'Comissão de recursos', true, true, true);
    $relation->status = \MapasCulturais\Entities\EvaluationMethodConfigurationAgentRelation::STATUS_ACTIVE;
    $relation->save(true);

    // distribui as inscrições de recurso para o avaliador
    $emc->refreshed()->redistributeCommitteeRegistrations();
    foreach ($app->repo('Registration')->findBy(['opportunity' => $appeal_phase]) as $registration) {
        if (!isset($registration->valuers[$evaluator->id])) {
            $app->em->getConnection()->update(
                'registration',
                ['valuers' => json_encode([$evaluator->id => 'Comissão de recursos'])],
                ['id' => $registration->id]
            );
        }
    }

    $appeal_phase->enqueueToPCacheRecreation();
    foreach ($app->repo('Registration')->findBy(['opportunity' => $appeal_phase]) as $registration) {
        $registration->enqueueToPCacheRecreation();
    }

    $out['emc'] = $emc->id;
    $out['evaluator'] = $evaluator->id;
}

$app->enableAccessControl();

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
