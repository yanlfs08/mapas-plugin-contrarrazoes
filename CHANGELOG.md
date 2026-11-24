# Changelog

## 1.2.0 - 2026-11-24

- **Resposta às contrarrazões.** Na tela de avaliação do recurso, o avaliador designado para o recurso responde a cada contrarrazão enviada, depois do fim do prazo de contrarrazões: escolhe a classificação e escreve a fundamentação. Cada contrarrazão mostra o selo *Pendente* ou *Respondida*. A resposta pode ser editada até o avaliador finalizar a avaliação.
- **Resposta obrigatória.** Sem responder a todas as contrarrazões, o avaliador não finaliza a avaliação nem decide pelo chat. Ninguém defere, indefere, invalida ou põe em suplência o recurso (inclusive pela aplicação em lote da avaliação contínua), e o resultado da etapa de recurso não é publicado. Configuração `requireCounterAppealResponse` (padrão `true`).
- **Classificação configurável.** O acordeão *Contrarrazões* ganhou a seção *Resposta às contrarrazões*: o gestor mantém o padrão (*Acolhida*, *Parcialmente acolhida*, *Não acolhida*), troca as opções (até 10) ou deixa a resposta só em texto. Configuração `defaultResponseResults`.
- **Publicidade.** Depois da publicação do resultado da etapa de recurso, a resposta aparece para o autor da contrarrazão (faixa da contrarrazão e modal da linha do tempo) e para o autor do recurso contestado (modal da linha do tempo, no *Seu recurso*). O nome do avaliador não é exibido aos candidatos.
- O gestor vê as respostas de todos os avaliadores na tabela de contrarrazões recebidas.
- **Sem nome dos candidatos nas telas dos candidatos.** A lista de recursos, a faixa da contrarrazão e as respostas recebidas mostram só o número da inscrição. O nome do proponente deixou de ser enviado aos candidatos, e a busca da lista passou a ser só pelo número. Gestores e avaliadores continuam vendo os nomes.
- A aplicação em lote da avaliação contínua e a publicação do resultado da etapa de recurso passam a respeitar também a trava do prazo de contrarrazões, com uma mensagem clara antes de começar.
- Novo endpoint `POST /registration/saveCounterAppealResponse/{id}` e novos metadados `counterAppealResponses` (privado) e `counterAppealResponseResults` na fase de contrarrazões. Não precisa de db-update.
- Testes: 72 verificações de API (29 novas).

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
