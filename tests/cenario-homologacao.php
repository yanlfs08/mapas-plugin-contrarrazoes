<?php
/**
 * Cenário de HOMOLOGAÇÃO do plugin de contrarrazões: cria usuários com senha e um concurso completo
 * para conferir, pelo navegador, cada mudança da versão 1.2.0 (roteiro em tests/ROTEIRO-HOMOLOGACAO.md).
 *
 * Só para ambiente de DESENVOLVIMENTO. Nunca rode em produção.
 *
 * Uso, a partir de /srv/mapasculturaisbh (o plugin já precisa estar na imagem):
 *
 *   docker compose exec mapasculturais php /var/www/src/plugins/Contrarrazoes/tests/cenario-homologacao.php criar
 *   docker compose exec mapasculturais php /var/www/src/plugins/Contrarrazoes/tests/cenario-homologacao.php prazo <id do concurso> encerrar
 *   docker compose exec mapasculturais php /var/www/src/plugins/Contrarrazoes/tests/cenario-homologacao.php prazo <id do concurso> abrir
 *
 * Senha de todos os usuários: variável SENHA_TESTE ou, se ela não existir, Contrarrazoes@2026
 *   docker compose exec -e SENHA_TESTE='OutraSenha#1' mapasculturais php ...
 *
 * "criar" sempre cria um concurso NOVO (com data e hora no nome). Os usuários são reaproveitados pelo
 * e-mail: rodar de novo só redefine a senha e completa o perfil deles.
 *
 * O que "criar" monta:
 *   - usuários: gestora (dona do concurso), avaliador (comissão de recursos) e cinco candidatos
 *   - concurso com inscrições encerradas e uma fase de avaliação "Seleção", com resultado publicado:
 *       Ana selecionada, Bruno suplente, Carla, Davi e Elisa não selecionados
 *   - fase de recurso (prazo de recurso já encerrado), com recursos ENVIADOS por Bruno, Carla e Davi.
 *     Formulário do recurso: fundamentação (texto), CPF (nunca público) e um anexo
 *   - comissão de recursos: o avaliador, com os três recursos distribuídos para ele
 *   - fase de contrarrazões com o prazo ABERTO (7 dias), fundamentação e anexo do recurso públicos e a
 *     classificação padrão da resposta
 *   - contrarrazões ENVIADAS: Ana e Elisa contra o recurso de Bruno; Ana contra o recurso de Carla.
 *     Contrarrazão em RASCUNHO: Bruno contra o recurso de Carla (não exige resposta).
 *     O recurso de Davi fica sem contrarrazão, para você criar uma pela tela.
 */

use Contrarrazoes\Plugin as ContrarrazoesPlugin;
use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\EvaluationMethodConfigurationAgentRelation;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MapasCulturais\Entities\RegistrationFile;
use MapasCulturais\Entities\RegistrationFileConfiguration;
use MapasCulturais\Entities\RegistrationStep;
use MapasCulturais\Entities\User;

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = 'CLI';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? '127.0.0.1';
$_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? '80';

$mapas_root = getenv('MAPAS_ROOT') ?: realpath(__DIR__ . '/../../../..');
require $mapas_root . '/public/bootstrap.php';

const DOMINIO = 'contrarrazoes.teste';
const PREFIXO_CONCURSO = 'Concurso de Teste — Contrarrazões';

$app = App::i();
$app->disableAccessControl();

$comando = $argv[1] ?? 'ajuda';
$senha = getenv('SENHA_TESTE') ?: 'Contrarrazoes@2026';

/* -------------------------------------------------------------------------
 * Funções de apoio
 * ----------------------------------------------------------------------- */

function falhar(string $mensagem): void
{
    fwrite(STDERR, "\nERRO: {$mensagem}\n");
    exit(1);
}

function passo(string $mensagem): void
{
    echo "• {$mensagem}\n";
}

function plugin(): ContrarrazoesPlugin
{
    foreach ((array) App::i()->plugins as $plugin) {
        if ($plugin instanceof ContrarrazoesPlugin) {
            return $plugin;
        }
    }
    falhar("o plugin Contrarrazoes não está ativo (confira config['plugins'] e se a imagem foi reconstruída).");
}

/** Faz o script agir como o usuário informado (as entidades criadas ficam no nome dele) */
function agir_como(?User $user): void
{
    $auth = App::i()->auth;
    $method = new ReflectionMethod($auth, 'setAuthenticatedUser');
    $method->setAccessible(true);
    $method->invoke($auth, $user);
}

function cpf_valido(): string
{
    $n = [];
    for ($i = 0; $i < 9; $i++) {
        $n[] = random_int(0, 9);
    }
    for ($t = 9; $t < 11; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) {
            $soma += $n[$i] * (($t + 1) - $i);
        }
        $n[] = ((10 * $soma) % 11) % 10;
    }
    return vsprintf('%d%d%d.%d%d%d.%d%d%d-%d%d', $n);
}

/**
 * Cria (ou reaproveita pelo e-mail) um usuário com senha do MultipleLocalAuth e perfil completo
 */
function usuario(string $nome, string $email, string $senha, string $descricao): User
{
    $app = App::i();
    $user = $app->repo('User')->findOneBy(['email' => $email]);

    if (!$user) {
        $user = new User();
        $user->setAuthProvider('local');
        if (!$user->authProvider) {
            $user->authProvider = 1;
        }
        $user->authUid = $email;
        $user->email = $email;
        $user->status = 1;
        $app->em->persist($user);
        $app->em->flush();

        $agent = new Agent($user);
        $agent->name = $nome;
        $agent->type = 1;
        $agent->status = 1;
        $agent->shortDescription = $descricao;
        $agent->save(true);

        $user->profile = $agent;
        $user->save(true);
        $novo = true;
    } else {
        $novo = false;
    }

    // perfil completo, para o Mapas não redirecionar para a edição do perfil a cada página
    $agent = $user->profile;
    $agent->status = 1;
    if (!$agent->shortDescription) {
        $agent->shortDescription = $descricao;
    }
    if ($taxonomia = $app->getRegisteredTaxonomyBySlug('area')) {
        $terms = (array) $agent->terms;
        if (empty($terms['area'])) {
            $terms['area'] = [$taxonomia->restrictedTerms ? reset($taxonomia->restrictedTerms) : 'Outros'];
            $agent->terms = $terms;
        }
    }
    foreach (['cpf', 'documento'] as $chave) {
        if ($agent->getRegisteredMetadata($chave) && !$agent->$chave) {
            $agent->$chave = cpf_valido();
        }
    }
    $agent->save(true);

    // senha do login por e-mail e senha (metadados registrados pelo plugin MultipleLocalAuth)
    if (!$user->getRegisteredMetadata('localAuthenticationPassword', true)) {
        falhar('o plugin MultipleLocalAuth não está ativo: não há como definir a senha dos usuários.');
    }
    $user->setMetadata('localAuthenticationPassword', password_hash($senha, PASSWORD_DEFAULT));
    foreach (['accountIsActive' => '1', 'loginAttemp' => '0', 'timeBlockedloginAttemp' => '0'] as $chave => $valor) {
        if ($user->getRegisteredMetadata($chave, true)) {
            $user->setMetadata($chave, $valor);
        }
    }
    $user->save(true);

    $user->createPermissionsCacheForUsers([$user]);
    $agent->createPermissionsCacheForUsers([$user]);

    if ($erros = $agent->validationErrors) {
        $lista = implode(', ', array_keys($erros));
        echo "  ATENÇÃO: o perfil de {$email} ainda tem campos obrigatórios vazios ({$lista}). "
            . "Se o Mapas mandar completar o perfil ao entrar, preencha esses campos.\n";
    }

    passo(($novo ? 'usuário criado: ' : 'usuário reaproveitado (senha redefinida): ') . $email);

    return $user;
}

function concurso(int $id): Opportunity
{
    $opportunity = App::i()->repo('Opportunity')->find($id);
    if (!$opportunity || !str_starts_with((string) $opportunity->name, PREFIXO_CONCURSO)) {
        falhar("o id {$id} não é de um concurso criado por este script.");
    }
    return $opportunity;
}

function fase_de_recurso(Opportunity $opportunity): Opportunity
{
    $appeal_phase = App::i()->repo('Opportunity')->findOneBy([
        'parent' => $opportunity,
        'status' => Opportunity::STATUS_APPEAL_PHASE,
    ]);
    if (!$appeal_phase) {
        falhar("o concurso {$opportunity->id} não tem fase de recurso.");
    }
    return $appeal_phase;
}

function data(?DateTime $data): string
{
    return $data ? $data->format('d/m/Y H:i') : '—';
}

/* -------------------------------------------------------------------------
 * Comando: prazo <id do concurso> encerrar|abrir
 * ----------------------------------------------------------------------- */

if ($comando === 'prazo') {
    $opportunity = concurso((int) ($argv[2] ?? 0));
    $acao = $argv[3] ?? '';
    if (!in_array($acao, ['encerrar', 'abrir'], true)) {
        falhar('informe "encerrar" ou "abrir".');
    }

    $plugin = plugin();
    $counter = $plugin->getCounterAppealPhase(fase_de_recurso($opportunity));
    if (!$counter) {
        falhar('o concurso não tem fase de contrarrazões.');
    }

    $counter->registrationTo = $acao === 'encerrar' ? new DateTime('-1 minute') : new DateTime('+7 days');
    if ($acao === 'abrir' && (!$counter->registrationFrom || $counter->registrationFrom > new DateTime())) {
        $counter->registrationFrom = new DateTime('-30 minutes');
    }
    $counter->save(true);
    $plugin->clearPermissionCaches($counter);

    echo "\nPrazo de contrarrazões: " . data($counter->registrationFrom) . ' a ' . data($counter->registrationTo)
        . ($acao === 'encerrar' ? " (ENCERRADO)\n" : " (ABERTO)\n");
    $app->enableAccessControl();
    exit(0);
}

/* -------------------------------------------------------------------------
 * Comando: criar
 * ----------------------------------------------------------------------- */

if ($comando !== 'criar') {
    echo "Uso:\n"
        . "  php tests/cenario-homologacao.php criar\n"
        . "  php tests/cenario-homologacao.php prazo <id do concurso> encerrar|abrir\n";
    exit($comando === 'ajuda' ? 0 : 1);
}

$plugin = plugin();
echo "\nCriando o cenário de homologação do plugin de contrarrazões\n\n";

// --- usuários ------------------------------------------------------------------------------
$pessoas = [
    'gestora' => ['Gestora Teste', 'gestora', 'Gestora do concurso de teste de contrarrazões.'],
    'avaliador' => ['Avaliador Teste', 'avaliador', 'Avaliador da comissão de recursos do concurso de teste.'],
    'ana' => ['Candidata Ana', 'ana', 'Candidata de teste (contrarrazões).'],
    'bruno' => ['Candidato Bruno', 'bruno', 'Candidato de teste (contrarrazões).'],
    'carla' => ['Candidata Carla', 'carla', 'Candidata de teste (contrarrazões).'],
    'davi' => ['Candidato Davi', 'davi', 'Candidato de teste (contrarrazões).'],
    'elisa' => ['Candidata Elisa', 'elisa', 'Candidata de teste (contrarrazões).'],
];

$ids = [];
foreach ($pessoas as $chave => [$nome, $login, $descricao]) {
    $ids[$chave] = usuario($nome, "{$login}@" . DOMINIO, $senha, $descricao)->id;
}

$u = fn (string $chave): User => $app->repo('User')->find($ids[$chave]);
agir_como($u('gestora'));

// --- concurso e fase de avaliação ----------------------------------------------------------------
$nome_concurso = PREFIXO_CONCURSO . ' (' . (new DateTime())->format('d/m H:i') . ')';
$gestora = $u('gestora')->profile;

$opportunity = new \MapasCulturais\Entities\AgentOpportunity();
$opportunity->name = $nome_concurso;
$opportunity->shortDescription = 'Concurso criado pelo script de homologação do plugin de contrarrazões. Pode ser enviado para a lixeira depois dos testes.';
$opportunity->type = 10; // Concurso
$opportunity->ownerEntity = $gestora;
$opportunity->owner = $gestora;
$opportunity->registrationFrom = new DateTime('-40 days');
$opportunity->registrationTo = new DateTime('-30 days');
$opportunity->registrationProponentTypes = ['Pessoa Física'];
$opportunity->status = 1;
$opportunity->save(true);
$opportunity_id = $opportunity->id;
passo("concurso criado: {$nome_concurso} (id {$opportunity_id})");

$emc = new EvaluationMethodConfiguration();
$emc->opportunity = $opportunity->refreshed();
$emc->type = 'simple';
$emc->name = 'Seleção';
$emc->evaluationFrom = new DateTime('-29 days');
$emc->evaluationTo = new DateTime('-20 days');
$emc->save(true);
passo('fase de avaliação "Seleção" criada');

// --- inscrições e resultado ------------------------------------------------------------------
$resultado = ['ana' => 10, 'bruno' => 8, 'carla' => 3, 'davi' => 3, 'elisa' => 3];
$inscricoes = [];
foreach ($resultado as $chave => $status) {
    $registration = new Registration();
    $registration->opportunity = $app->repo('Opportunity')->find($opportunity_id);
    $registration->owner = $u($chave)->profile;
    $registration->proponentType = 'Pessoa Física';
    $registration->save(true);
    $registration->send();
    $registration = $registration->refreshed();
    $registration->setStatus($status);
    $registration->save(true);
    $inscricoes[$chave] = ['id' => $registration->id, 'number' => $registration->number];
}
passo('inscrições enviadas e avaliadas: Ana selecionada, Bruno suplente, Carla, Davi e Elisa não selecionados');

$app->repo('Opportunity')->find($opportunity_id)->publishRegistrations();
$app->em->clear();
agir_como($u('gestora'));
passo('resultado da Seleção publicado');

// --- fase de recurso (mesma lógica do endpoint createAppealPhase do núcleo) ------------------------
$opportunity = $app->repo('Opportunity')->find($opportunity_id);
$class_name = $opportunity->getSpecializedClassName();
$phase_name = $opportunity->evaluationMethodConfiguration?->name ?? $opportunity->name;

$appeal_phase = new $class_name();
$appeal_phase->parent = $opportunity;
$appeal_phase->status = Opportunity::STATUS_APPEAL_PHASE;
$appeal_phase->name = \OpportunityAppealPhase\Module::buildAppealPhaseName($phase_name);
$appeal_phase->ownerEntity = $opportunity->ownerEntity;
$appeal_phase->type = $opportunity->type;
$appeal_phase->registrationCategories = $opportunity->registrationCategories;
$appeal_phase->registrationRanges = $opportunity->registrationRanges;
$appeal_phase->registrationProponentTypes = $opportunity->registrationProponentTypes;
$appeal_phase->isDataCollection = true;
$appeal_phase->isAppealPhase = true;
$appeal_phase->showPreviousPhaseEvaluationDetails = true;
$appeal_phase->registrationFrom = new DateTime('-15 days');
$appeal_phase->registrationTo = new DateTime('+1 day'); // encerrado logo abaixo, depois do envio dos recursos
$appeal_phase->save(true);

$step = new RegistrationStep();
$step->name = '';
$step->opportunity = $appeal_phase;
$step->save(true);

$opportunity->appealPhase = $appeal_phase;
$opportunity->save(true);

$appeal_emc = new EvaluationMethodConfiguration();
$appeal_emc->opportunity = $appeal_phase;
$appeal_emc->type = 'continuous';
$appeal_emc->publishEvaluationDetails = true;
$appeal_emc->evaluationFrom = new DateTime('-1 day');
$appeal_emc->evaluationTo = new DateTime('+30 days');
$appeal_emc->save(true);
$appeal_phase->evaluationMethodConfiguration = $appeal_emc;
$appeal_phase_id = $appeal_phase->id;
passo("fase de recurso criada (id {$appeal_phase_id})");

// formulário do recurso
$campo_texto = new RegistrationFieldConfiguration();
$campo_texto->owner = $appeal_phase;
$campo_texto->step = $step;
$campo_texto->title = 'Fundamentação do recurso';
$campo_texto->fieldType = 'textarea';
$campo_texto->required = true;
$campo_texto->displayOrder = 1;
$campo_texto->fieldOptions = [];
$campo_texto->config = [];
$campo_texto->categories = [];
$campo_texto->save(true);

$campo_cpf = new RegistrationFieldConfiguration();
$campo_cpf->owner = $appeal_phase;
$campo_cpf->step = $step;
$campo_cpf->title = 'CPF do responsável';
$campo_cpf->fieldType = 'cpf';
$campo_cpf->required = false;
$campo_cpf->displayOrder = 2;
$campo_cpf->fieldOptions = [];
$campo_cpf->config = [];
$campo_cpf->categories = [];
$campo_cpf->save(true);

$campo_anexo = new RegistrationFileConfiguration();
$campo_anexo->owner = $appeal_phase;
$campo_anexo->step = $step;
$campo_anexo->title = 'Documento do recurso';
$campo_anexo->required = false;
$campo_anexo->displayOrder = 3;
$campo_anexo->save(true);

$appeal_phase = $appeal_phase->refreshed();
$appeal_phase->registerRegistrationMetadata();
passo('formulário do recurso: fundamentação, CPF e anexo');

// recursos de Bruno, Carla e Davi (mesma lógica do endpoint createAppealPhaseRegistration)
$textos_recurso = [
    'bruno' => "Fiquei como suplente por diferença de 0,5 ponto.\nO critério de impacto social não considerou as oficinas gratuitas previstas no plano de trabalho.",
    'carla' => "A comissão não considerou o portfólio enviado no anexo 2.\nPeço a revisão da nota de qualificação técnica.",
    'davi' => "A nota de viabilidade foi zerada sem justificativa.\nO orçamento apresentado segue o modelo do edital.",
];
$recursos = [];
foreach ($textos_recurso as $chave => $texto) {
    $base = $app->repo('Registration')->find($inscricoes[$chave]['id']);

    $appeal = new Registration();
    $appeal->opportunity = $appeal_phase;
    $appeal->category = $base->category;
    $appeal->proponentType = $base->proponentType;
    $appeal->range = $base->range;
    $appeal->owner = $base->owner;
    $appeal->number = $base->number;
    $appeal->save(true);

    $appeal->{$campo_texto->fieldName} = $texto;
    $appeal->{$campo_cpf->fieldName} = cpf_valido();
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
    $file->group = $campo_anexo->fileGroupName;
    $file->save(true);

    $appeal = $appeal->refreshed();
    $appeal->send();
    $recursos[$chave] = ['id' => $appeal->id, 'number' => $appeal->number];
}
passo('recursos enviados por Bruno, Carla e Davi');

// comissão de recursos
$appeal_phase = $app->repo('Opportunity')->find($appeal_phase_id);
$appeal_emc = $appeal_phase->evaluationMethodConfiguration;
$avaliador = $u('avaliador');
$relation = $appeal_emc->createAgentRelation($avaliador->profile, 'Comissão de recursos', true, true, true);
$relation->status = EvaluationMethodConfigurationAgentRelation::STATUS_ACTIVE;
$relation->save(true);
$appeal_emc->refreshed()->redistributeCommitteeRegistrations();

foreach ($recursos as $recurso) {
    $appeal = $app->repo('Registration')->find($recurso['id']);
    if (!isset(((array) $appeal->valuers)[$avaliador->id])) {
        $app->em->getConnection()->update(
            'registration',
            ['valuers' => json_encode([$avaliador->id => 'Comissão de recursos'])],
            ['id' => $appeal->id]
        );
    }
}
passo('comissão de recursos: Avaliador Teste, com os três recursos distribuídos');

// encerra o prazo de recurso (o de contrarrazões vem depois)
$appeal_phase = $app->repo('Opportunity')->find($appeal_phase_id);
$appeal_phase->registrationTo = new DateTime('-1 hour');
$appeal_phase->save(true);
passo('prazo de recurso encerrado');

// --- fase de contrarrazões -------------------------------------------------------------------
$counter = $plugin->createCounterAppealPhase($appeal_phase);
$counter = $app->repo('Opportunity')->find($counter->id);
$counter->registrationFrom = new DateTime('-30 minutes');
$counter->registrationTo = new DateTime('+7 days');
$counter->counterAppealPublicFields = array_map(
    fn ($campo) => $campo['name'],
    $plugin->getPhaseFormFields($app->repo('Opportunity')->find($appeal_phase_id), true)
);
$counter->save(true);
$counter_id = $counter->id;
passo("fase de contrarrazões criada (id {$counter_id}), prazo aberto até " . data($counter->registrationTo));

// contrarrazões
$counter->registerRegistrationMetadata();
$campo_contrarrazao = $app->repo('RegistrationFieldConfiguration')->findOneBy(['owner' => $counter])?->fieldName;
if (!$campo_contrarrazao) {
    falhar('a fase de contrarrazões foi criada sem o campo de fundamentação.');
}

$contrarrazoes = [];
$criar_contrarrazao = function (string $autor, string $alvo, string $texto, bool $enviar) use ($app, $counter_id, $inscricoes, $recursos, $campo_contrarrazao, &$contrarrazoes) {
    $counter = $app->repo('Opportunity')->find($counter_id);
    $counter->registerRegistrationMetadata();
    $base = $app->repo('Registration')->find($inscricoes[$autor]['id']);

    $registration = new Registration();
    $registration->opportunity = $counter;
    $registration->owner = $base->owner;
    if ($base->proponentType && $counter->registrationProponentTypes) {
        $registration->proponentType = $base->proponentType;
    }
    $registration->counterAppealTarget = $recursos[$alvo]['id'];
    $registration->counterAppealAuthorNumber = (string) $base->number;
    $registration->save(true);

    $registration->$campo_contrarrazao = $texto;
    $registration->save(true);

    if ($enviar) {
        $registration = $registration->refreshed();
        $registration->send();
    }

    $contrarrazoes[] = [
        'autor' => $autor,
        'alvo' => $alvo,
        'id' => $registration->id,
        'situacao' => $enviar ? 'enviada' : 'rascunho',
    ];
};

$criar_contrarrazao('ana', 'bruno', "O plano de trabalho de Bruno não prevê oficinas gratuitas: elas aparecem só no recurso.\nA nota de impacto social deve ser mantida.", true);
$criar_contrarrazao('elisa', 'bruno', "O edital veda a inclusão de novas atividades na fase de recurso (item 9.3).", true);
$criar_contrarrazao('ana', 'carla', "O anexo 2 citado no recurso foi enviado fora do prazo de inscrição.", true);
$criar_contrarrazao('bruno', 'carla', "Rascunho: ainda vou completar esta contrarrazão.", false);
passo('contrarrazões enviadas: Ana e Elisa → recurso de Bruno; Ana → recurso de Carla. Rascunho: Bruno → recurso de Carla');

// --- cache de permissões (sem depender da fila do pcache) ----------------------------------------------
$app->em->clear();
agir_como($u('gestora'));
$falhas_pcache = 0;
$entidades = [
    $app->repo('Opportunity')->find($opportunity_id),
    $app->repo('Opportunity')->find($appeal_phase_id),
    $app->repo('Opportunity')->find($counter_id),
];
foreach ([$opportunity_id, $appeal_phase_id, $counter_id] as $phase_id) {
    foreach ($app->repo('Registration')->findBy(['opportunity' => $phase_id]) as $registration) {
        $entidades[] = $registration;
    }
}
foreach ($entidades as $entidade) {
    try {
        $entidade->createPermissionsCacheForUsers();
    } catch (\Throwable $e) {
        $falhas_pcache++;
        echo "  ATENÇÃO: não foi possível gerar o cache de permissões de {$entidade}: {$e->getMessage()}\n";
    }
}
passo('cache de permissões gerado' . ($falhas_pcache ? " ({$falhas_pcache} falhas, veja acima)" : ''));

agir_como(null);
$app->enableAccessControl();

/* -------------------------------------------------------------------------
 * Resumo
 * ----------------------------------------------------------------------- */

$url = fn (string $controller, string $action, array $params = []) => $app->createUrl($controller, $action, $params);
$nome = fn (string $chave) => $pessoas[$chave][0];
$num = fn (string $chave) => $inscricoes[$chave]['number'];

echo "\n==========================================================================\n";
echo " CENÁRIO CRIADO\n";
echo "==========================================================================\n\n";
echo "Concurso:              {$nome_concurso}\n";
echo "  id do concurso:      {$opportunity_id}\n";
echo "  id da fase recurso:  {$appeal_phase_id}\n";
echo "  id das contrarraz.:  {$counter_id}\n";
echo "  página:              " . $url('opportunity', 'single', [$opportunity_id]) . "\n";
echo "  edição (gestora):    " . $url('opportunity', 'edit', [$opportunity_id]) . "\n\n";

echo "Senha de todos os usuários: {$senha}\n\n";
printf("%-22s %-30s %-16s %s\n", 'Pessoa', 'E-mail (login)', 'Inscrição', 'Papel no teste');
$papeis = [
    'gestora' => 'dona do concurso (não é avaliadora)',
    'avaliador' => 'comissão de recursos (responde às contrarrazões)',
    'ana' => 'selecionada; contestou os recursos de Bruno e Carla',
    'bruno' => 'suplente; tem recurso; rascunho contra o recurso de Carla',
    'carla' => 'não selecionada; tem recurso',
    'davi' => 'não selecionado; tem recurso sem contrarrazão',
    'elisa' => 'não selecionada; contestou o recurso de Bruno',
];
foreach ($pessoas as $chave => [$n, $login]) {
    printf("%-22s %-30s %-16s %s\n", $n, "{$login}@" . DOMINIO, $inscricoes[$chave]['number'] ?? '—', $papeis[$chave]);
}

echo "\nRecursos (tela de avaliação do Avaliador Teste):\n";
foreach ($recursos as $chave => $recurso) {
    echo "  {$nome($chave)} ({$recurso['number']}): " . $url('registration', 'evaluation', [$recurso['id']]) . "\n";
}

echo "\nContrarrazões:\n";
foreach ($contrarrazoes as $c) {
    echo "  {$nome($c['autor'])} → recurso de {$nome($c['alvo'])} ({$c['situacao']}): " . $url('registration', 'view', [$c['id']]) . "\n";
}

echo "\nPara encerrar o prazo de contrarrazões (parte 2 do roteiro):\n";
echo "  docker compose exec mapasculturais php /var/www/src/plugins/Contrarrazoes/tests/cenario-homologacao.php prazo {$opportunity_id} encerrar\n\n";
