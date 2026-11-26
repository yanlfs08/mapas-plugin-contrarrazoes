# Changelog

## 1.4.5 - 2026-11-26

- O gestor do edital (administrador da oportunidade que não é o dono) consegue criar a fase de contrarrazões.
  - Antes só o administrador do sistema conseguia: a fase nova recebe o mesmo agente dono do edital, e para o gestor o Mapas transformava isso num pedido de troca de dono, deixando o dono vazio (`null value in column "agent_id"`).
  - A permissão continua sendo `@control` na fase de recurso, checada antes; só a gravação roda com o controle de acesso desligado.

## 1.4.4 - 2026-11-26

- As contrarrazões não aparecem mais em **Minhas inscrições** no painel do candidato.
  - A fase de contrarrazões é uma oportunidade de primeiro nível, por isso cada contrarrazão aparecia como uma inscrição comum nas abas *Não enviadas*, *Enviadas* e *Com permissão*, no aviso de rascunhos e nas últimas inscrições do painel inicial.
  - O plugin passa a excluir as fases de contrarrazões das consultas à API de inscrições que não filtram por oportunidade nem por id. Consultas por oportunidade ou por id continuam iguais.

## 1.4.3 - 2026-11-25

- As contrarrazões não disparam mais e-mails automáticos do Mapas.
  - A fase de contrarrazões é uma oportunidade de primeiro nível, por isso o módulo MailNotification mandava ao candidato "inscrição iniciada" (*start_registration*) e "inscrição enviada" (*send_registration*) a cada contrarrazão.
  - A nova configuração `disableCounterAppealMails` (padrão `true`) cancela os dois.
  - Os e-mails das inscrições comuns continuam.

## 1.4.2 - 2026-11-25

- Os recursos não disparam mais e-mails automáticos do Mapas.
  - Antes, o módulo MailNotification enviava ao candidato o e-mail *start_data_collection_phase* sempre que um recurso era aberto.
  - A nova configuração `disableAppealMails` (padrão `true`) cancela esse e-mail e o de envio, só para as fases de recurso.
  - Os e-mails das inscrições comuns e das contrarrazões continuam.
- A tela de avaliação do recurso não tem mais o link **abrir** para a ficha/formulário de cada contrarrazão. O avaliador lê os campos e anexos no próprio bloco, e o endereço da ficha não é mais enviado à página.

## 1.4.1 - 2026-11-25

- **Destaque do próprio recurso** no modal *Recursos e contrarrazões* da linha do tempo, com o laranja das oportunidades do Mapas (`--mc-secondary-500`):
  - a lista é dividida em dois blocos com título, quantidade e divisória: *Seu recurso* (em laranja, primeiro) e *Recursos dos demais candidatos* (em verde, a cor padrão do Mapas);
  - quem não apresentou recurso continua vendo uma lista única, sem títulos;
  - o card do próprio recurso tem borda e fundo laranja.
- As contrarrazões recebidas, quando liberadas, ficam em cartões brancos dentro do destaque.

## 1.4.0 - 2026-11-25

- **Acompanhamento na aba Inscrições e Resultados.** A etapa de recurso dessa aba ganhou o acordeão **Contrarrazões**, entre *Recurso* e *Resposta do recurso* (componente `counter-appeal-registrations`). Ele mostra só o essencial, no formato do *Resumo* do acordeão *Recurso*:
  - o prazo, com o selo de situação;
  - o aviso da trava do julgamento e da publicação;
  - o resumo: recursos enviados, recursos contestados, contrarrazões enviadas e em rascunho, inscrições que contestaram;
  - o botão **Lista de contrarrazões**.
- **Página "Lista de contrarrazões"**, aberta em nova aba (`GET /opportunity/counterAppeals/{fase de recurso}`, só para o gestor; componente `counter-appeal-tracking`). Ela traz:
  - as contrarrazões por recurso, com a situação do recurso;
  - a lista completa, com filtros por recurso, situação e busca (`?recurso=` abre filtrada);
  - a exportação em CSV da lista filtrada.
- A tabela de contrarrazões recebidas saiu da aba **Configuração de fases**. No lugar dela fica a contagem e o aviso de onde acompanhar.
- Novos `Plugin::getStatusLabels()` (rótulos de status da própria fase de recurso) e `Plugin::buildPhaseConfigItem()` (dados compartilhados entre a edição da oportunidade e a página).
- README: seção do acompanhamento com capturas e dois novos problemas comuns: cache do navegador depois de atualizar, e o *Solicitar recurso* que só aparece com o resultado da fase publicado.

## 1.3.0 - 2026-11-25

Baseada na 1.1.0. **Não inclui** a resposta do avaliador às contrarrazões da 1.2.0.

- **Contrarrazões visíveis ao autor do recurso (configurável).** Nova opção *O autor do recurso pode ver as contrarrazões que o recurso dele recebeu* (metadado `counterAppealVisibleToAppellant`, padrão desligado). Ligada, as contrarrazões enviadas aparecem no modal da linha do tempo, junto do *Seu recurso*, com o botão **Exibir contrarrazão**. Só vão os campos de tipos publicáveis (LGPD) e os anexos, que ele pode baixar. Desligada, o autor do recurso não abre a contrarrazão nem os anexos (antes ele conseguia abrir a ficha pelo link direto).
- **Nome dos proponentes configurável.** Nova opção *Exibir o nome dos proponentes nos recursos e nas contrarrazões* (metadado `counterAppealShowNames`, padrão ligado, como antes). Desligada, os candidatos veem só o número da inscrição (lista de recursos, contrarrazões recebidas e faixa do formulário). Gestão e comissão sempre veem o nome.
- **Formulário não é mais criado automaticamente.** A fase de contrarrazões nasce só com a etapa vazia; o gestor cria os campos em *Configurar formulário*. Enquanto o formulário não tiver campos, o acordeão mostra um aviso e ninguém consegue iniciar uma contrarrazão. A configuração `defaultFieldTitle` foi removida.
- **Sem tela separada para quem enviou.** A contrarrazão enviada aparece no próprio modal da linha do tempo, pelo botão **Exibir contrarrazão** (no lugar do link *Ver contrarrazão enviada*). A ficha da contrarrazão (inclusive a página para onde o Mapas leva depois do envio) devolve o autor à linha do tempo, com o modal aberto e a contrarrazão expandida (`&contrarrazao={id}`). Gestão e comissão continuam vendo a ficha.
- **Sem botão "Salvar configurações".** O acordeão *Contrarrazões* salva sozinho (datas, campos visíveis e publicidade), como os demais campos da etapa de recurso, e avisa *Modificações salvas*.
- **Prazo de contrarrazões aberto impede a publicação do resultado da etapa de recurso**, pelo botão de publicar e pela publicação agendada (job `PublishResult`). Sem prazo definido, também não publica. A aplicação em lote da avaliação contínua respeita a trava com mensagem clara antes de começar.
- **Tela do avaliador:** o bloco *Contrarrazões apresentadas a este recurso* ganhou uma linha que diz o que os candidatos veem neste edital (se os nomes aparecem e se o autor do recurso lê as contrarrazões). A comissão continua vendo nomes e todos os campos.
- README reescrito, com capturas de cada tela (gestor, candidato, autor do recurso e avaliador) nos modos com e sem nomes.
- Testes: roteiro externo com 28 verificações de API e conferência da interface no navegador. Os roteiros de `tests/` ainda são os da 1.1.0.

## 1.1.0 - 2026-11-18

- A aba pública **Recursos e contrarrazões** da página da oportunidade foi removida. No lugar dela, a linha do tempo da inscrição do candidato (aba *Acompanhamento*) ganhou o bloco **[Contrarrazões]**, na fase que recebeu recurso, logo abaixo do botão **Solicitar recurso** (ou do quadro *[Recurso]*). O bloco mostra o prazo e um resumo, e o botão **Ver recursos e apresentar contrarrazão** abre a lista de recursos num modal.
- A contrarrazão passa a ser apresentada pela inscrição da página. Quem tem mais de uma inscrição no edital escolhe a inscrição ao abrir a linha do tempo dela. O seletor "Apresentar como..." deixou de ser necessário.
- A opção **Quem pode apresentar contrarrazões** foi removida. Agora só os inscritos na mesma etapa do recurso podem apresentar contrarrazões, e quem foi eliminado antes não vê o bloco. O metadado `counterAppealEligibility` é ignorado: fases antigas configuradas com "todos os inscritos" passam a seguir a nova regra.
- Os dados só são enviados para o dono da inscrição. O gestor acompanha as contrarrazões na configuração da etapa de recurso (veja abaixo).
- Os recursos de qualquer inscrição do próprio usuário continuam marcados como "Seu recurso" e não podem ser contestados por ele.
- O link "Ver os recursos" da faixa da contrarrazão e o redirecionamento da URL da fase de contrarrazões levam à linha do tempo da inscrição do candidato, com o modal já aberto (`?contrarrazoes={id}`). O gestor vai para a edição da oportunidade.
- A aba **Contrarrazões** da edição da oportunidade foi removida. A configuração passou para a aba **Configuração de fases**, dentro da *Etapa de recurso*, como o acordeão **Contrarrazões** entre **Recurso** e **Resposta do recurso**, no mesmo formato dos outros dois (tipo, datas e "Expandir"). O acordeão aparece também numa fase de recurso criada na hora, sem recarregar a página.
- O redirecionamento da URL da fase de contrarrazões leva o gestor para a aba **Configuração de fases**.
- Testes atualizados para a linha do tempo, o modal, a nova posição da configuração e a elegibilidade fixa (43 verificações de API).

## 1.0.0 - 2026-11-17

Primeira versão, testada no Mapas Culturais 7.8.22.

- Fase de contrarrazões vinculada à fase de recurso nativa, criada pela aba **Contrarrazões** da edição da oportunidade.
- Configuração de prazo, de quem pode apresentar (todos os inscritos ou só a mesma etapa) e dos campos do recurso visíveis aos candidatos (com bloqueio de campos com dados pessoais).
- Aba pública **Recursos e contrarrazões** com a lista de recursos, os campos liberados, o download dos anexos públicos e o botão para apresentar contrarrazão.
- Regras: uma contrarrazão por recurso por inscrição; o autor não contesta o próprio recurso; contrarrazão só criada dentro do prazo e a partir da lista.
- Bloco de contrarrazões na tela de avaliação do recurso.
- Trava do julgamento do recurso (avaliação final, chat da avaliação contínua e mudança de status) até o fim do prazo de contrarrazões.
- Faixa no formulário e na ficha da contrarrazão identificando o recurso contestado.
- Testes de API (39 verificações) e de interface (Playwright).
