# Changelog

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
