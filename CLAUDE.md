# CLAUDE.md — Plugin de Contrarrazões (Mapas Culturais)

Contexto para o Claude Code trabalhar neste repositório. Responda em português do Brasil.

## O que é

Plugin do Mapas Culturais (v7.8.x, tema BaseV2) que acrescenta uma **fase de contrarrazões** à fase de recurso nativa (módulo `OpportunityAppealPhase`). Uso: Mapa Cultural BH (FMC/PBH), servidores Debian 12. Versão testada do Mapas: **7.8.22**.

Instalação: este repositório inteiro vira a pasta `src/plugins/Contrarrazoes` do Mapas. Para ativar, acrescente `'Contrarrazoes'` em `config['plugins']` e limpe o cache. O plugin não cria tabelas nem precisa de db-update.

## Requisitos funcionais (definidos pelo usuário)

- **Elegibilidade:** **sempre** os inscritos na mesma etapa do recurso (a fase avaliada, `getEligibilityBasePhase()`). Não é configurável desde a 1.1.0: o metadado `counterAppealEligibility` continua registrado só por compatibilidade e é ignorado (inclusive se um cliente antigo o enviar).
- **Uma contrarrazão por recurso** por inscrição. O mesmo candidato pode contestar vários recursos.
- **Autor não contesta o próprio recurso.**
- **Trava do julgamento:** a comissão não finaliza a avaliação, ninguém defere/indefere o recurso e o resultado da etapa de recurso não é publicado (botão, `applyEvaluationsContinuous` e job `PublishResult`, via `entity(Opportunity).publishRegistrations:before`) antes do fim do prazo de contrarrazões. Sem prazo definido, fica travado.
- **Publicidade:** o gestor escolhe quais campos do formulário de recurso ficam visíveis. Tipos com dados pessoais nunca são publicáveis (LGPD); veja `PUBLIC_FIELD_TYPES`.
- **Nome dos proponentes (1.3.0):** configurável (`counterAppealShowNames`, padrão exibe). Gestão e comissão sempre veem.
- **Autor do recurso vê as contrarrazões (1.3.0):** configurável (`counterAppealVisibleToAppellant`, padrão não). Só campos publicáveis + anexos; permissão `view|viewPrivateFiles` (nunca `viewPrivateData`) no hook próprio.
- **Formulário (1.3.0):** não é criado automaticamente; sem campos, `createCounterAppealRegistration` recusa.
- **Sem tela para quem enviou (1.3.0):** a contrarrazão enviada é exibida no modal da linha do tempo; `GET(registration.<<view|single>>)` da contrarrazão redireciona o autor para lá (`&contrarrazao={id}`).
- **Configuração sem botão Salvar (1.3.0):** o acordeão salva sozinho (debounce de 1,5 s), como os `entity-field :autosave` do núcleo.
- **Base:** a 1.3.0 parte da 1.1.0; a resposta do avaliador (1.2.0) não está nela.
- **Acompanhamento (1.4.0):** a aba *Inscrições e Resultados* tem só o resumo (`counter-appeal-registrations`, injetado com `v-if="tab === 'registrations'"` no mesmo template `opportunity-appeal-phase-config`). As listas ficam na página `GET opportunity.counterAppeals/{fase de recurso}` (view `views/opportunity/counter-appeals.php`, componente `counter-appeal-tracking`, só `@control`), aberta em nova aba. A aba *Configuração de fases* só configura.
- **Notificar o autor do recurso NÃO foi pedido.** O plugin não envia e-mails.

## Arquitetura

- `Plugin.php`: toda a lógica (metadados, hooks de permissão, endpoints, trava do julgamento, template hooks).
- **Fase de contrarrazões** = `Opportunity` de **primeiro nível** (sem parent), status `-1` (`STATUS_PHASE`), com os metadados `isCounterAppealPhase`, `counterAppealOf` e `counterAppealPublicFields` (`counterAppealEligibility` é obsoleto). A fase de recurso guarda `counterAppealPhase`.
  - Por que primeiro nível: `registration/single.php` faz `$entity->firstPhase`. Como filha da fase de recurso, `firstPhase` não existiria e a página quebraria.
  - Por que status -1: `Registration::canUserView/canUserSend` só aceitam oportunidades com status em `[-1, 1, -20]`. O -20 dispara a lógica nativa de recurso, e o 1 aparece nas buscas.
  - O `OpportunityPhases` cria automaticamente uma "Publicação final do resultado" filha. Isso é inofensivo.
- **Contrarrazão** = `Registration` na fase de contrarrazões, com os metadados `counterAppealTarget` (id do recurso) e `counterAppealAuthorNumber` (número da inscrição do autor).
- **Endpoints** (hooks em controladores existentes; no JS use `entity.invoke(action, data)`):
  - `POST opportunity.createCounterAppealPhase/{id da fase de recurso}`
  - `POST opportunity.saveCounterAppealConfig/{id da fase de contrarrazões}` (datas e campos públicos)
  - `POST opportunity.deleteCounterAppealPhase/{id}`
  - `POST opportunity.createCounterAppealRegistration/{id}` (`appeal_registration_id`, `author_registration_id`)
  - `GET opportunity.counterAppealFile/{id}/file:{fileId}` (download dos anexos públicos do recurso)
- **Telas:**
  - `template(opportunity.edit.tabs):end` → não imprime aba: monta `jsObject.config.counterAppealConfig` e importa `counter-appeal-config`.
  - `mapas.printJsObject:after` (prioridade 5, antes do `$TEMPLATES` ser impresso) → `Plugin::injectCounterAppealConfig()` insere `<counter-appeal-config :appeal-phase-id="entity.id">` no template `opportunity-appeal-phase-config` do núcleo, logo depois do primeiro `</mc-accordion>` (acordeão "Recurso"), antes de "Resposta do recurso". O componente do núcleo não tem ponto de encaixe ali: **não copie o template do núcleo**. Se a marcação mudar, o acordeão vai para o fim da etapa de recurso e um aviso vai para o log. Candidato a PR upstream: `applyComponentHook` entre os dois acordeões.
  - `template(registration.<<view|single>>.single-tab):begin` → não imprime nada: monta `jsObject.config.counterAppealList` para a inscrição da página (a autora da contrarrazão é sempre ela, mesmo número). Só para o dono da inscrição. A ação da página `/inscricao/{id}/` é `view`, não `single`.
  - `component(opportunity-phases-timeline).item:end` → bloco **[Contrarrazões]** (`counter-appeal-timeline`) na linha do tempo, no item da fase avaliada (`evaluatedPhaseId`), logo abaixo do "Solicitar recurso" de `registration-status`. Fica no item (mesmo padrão do módulo `OpportunityExecution`) e só aparece quando `canSeeList` é verdadeiro, ou seja, para inscrições que estão na etapa do recurso. O botão abre um `mc-modal` com `counter-appeal-list` (props `counter-id` e `hide-header`). `?contrarrazoes={id}` na URL abre o modal sozinho.
  - Não há lista de recursos na página pública da oportunidade (removida na 1.1.0).
  - `template(registration.evaluation.registration-appealPhase-evaluation-view):after` → bloco da comissão (`counter-appeal-evaluation`)
  - `template(registration.<<view|edit|single>>.main-header):after` → faixa com o recurso contestado
  - Os dados vão para o Vue via `$this->jsObject['config'][...]`.

## Armadilhas já resolvidas (não reintroduzir)

1. **Nunca ler metadados com `$entity->chave` dentro de hooks `can(...)`.** A leitura mágica chama `canUser('viewPrivateData')`, o que gera recursão infinita. Use sempre `Plugin::meta($entity, 'chave')`.
2. **Não usar `can(Registration.create)` para bloquear a criação.** O cache de permissões do Mapas usa a chave `"Classe:" + id`, e uma entidade nova não tem id, então o resultado é compartilhado entre todas as inscrições novas do usuário. Bloqueie em `entity(Registration).<<insert|send>>:before` e salve com `disableAccessControl()` depois de validar as regras no endpoint.
3. **Regras que dependem de tempo** (prazo, trava) não podem ficar em hooks `can(...)`, porque o resultado fica em cache por até um dia. A trava usa `POST(registration.<<saveEvaluation|...>>):before`, `entity(RegistrationEvaluation).save:before`, `entity(ChatMessage).insert:before` e `entity(Registration).save:before`, comparando o status original via UnitOfWork.
4. **Lançar exceção dentro de `saveUserEvaluation`** deixa a trava `save-user-evauation--{reg}--{user}` presa por 10 s. Por isso a checagem principal é feita antes da ação do controlador.
5. **Metadado `json` não pode ter `'default' => []`.** O desserializador recebe o array e quebra o `jsonSerialize` de TODAS as oportunidades.
6. **Excluir a fase de contrarrazões dentro da exclusão da fase de recurso** trava o Doctrine (reprocessa a entidade removida). A exclusão em cascata roda em `register_shutdown_function`, com `em->clear()`.
7. **`delete()` em Opportunity é soft delete (lixeira).** Para excluir de verdade, use `destroy()`.
8. **Campos do formulário** (`field_N`) só existem depois de `$opportunity->registerRegistrationMetadata()`. Eles são privados, então a leitura é feita com o controle de acesso desligado, e só dos campos permitidos.
9. Na edição da oportunidade aparece o aviso Vue `compiler-21` / `Cannot destructure property 'bum'`. Ele **já existe sem o plugin** (é do núcleo) e não deve ser atribuído ao plugin.

## Testes

Os testes só rodam em DESENVOLVIMENTO, com o provedor de login `Fake` habilitado. Eles criam usuários, editais e inscrições.

1. Cenário: `php src/plugins/Contrarrazoes/tests/seed-scenario.php base`
   - Depois crie a fase de recurso do "Mérito" (`createAppealPhase`) e os recursos de C2 e C3 (`createAppealPhaseRegistration`).
   - Rode as etapas `appeals <id>` e `committee <id>`.
2. Monte `tests/scenario.json` (modelo em `tests/scenario.example.json`). No avaliador, a relação da comissão precisa de `has_control = true` e a inscrição precisa ter `valuers` preenchido.
3. Tire um snapshot do banco (`pg_dump -Fc`) para restaurar entre execuções.
4. Rode `python3 tests/api_test.py tests/scenario.json`. O esperado é 43/43.
5. Rode `python3 tests/e2e_test.py tests/scenario.json /tmp/capturas` (Playwright + Chrome).
6. Para usuários de teste com perfil incompleto, use `'app.redirect_profile_validate' => false` na configuração local, senão eles são redirecionados para a edição do perfil.

Datas: o front envia a hora local (`McDate.sql()`), igual ao núcleo. O navegador e o servidor devem estar no mesmo fuso (America/Sao_Paulo).

## Convenções

- Textos de interface em pt-BR, com `i::__()` no PHP e `texts.php` + `Utils.getTexts()` no JS.
- Commits em português. Atualize o `CHANGELOG.md` e a `VERSION` em `Plugin.php` a cada versão.
- Não alterar arquivos do núcleo do Mapas. Se faltar um ponto de encaixe, documente e proponha um PR upstream.
