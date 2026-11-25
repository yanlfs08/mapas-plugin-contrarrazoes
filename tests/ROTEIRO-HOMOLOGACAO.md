# Roteiro de homologação: Contrarrazões 1.2.0

Este roteiro confere pelo navegador cada mudança da versão 1.2.0:

- a resposta do avaliador às contrarrazões;
- a resposta obrigatória;
- a classificação configurável;
- a publicação da resposta;
- o anonimato entre candidatos.

O cenário é criado pelo script `tests/cenario-homologacao.php`. **Use só no ambiente de desenvolvimento.**

## 0. Preparação

1. Ponha a branch `contrarrazoes-1.2.0` na pasta do plugin usada no build e reconstrua a imagem. O plugin fica dentro da imagem:

   ```bash
   cd /srv/mapasculturaisbh/plugins/Contrarrazoes
   git fetch origin && git checkout contrarrazoes-1.2.0 && git pull
   cd /srv/mapasculturaisbh
   docker compose build mapasculturais && docker compose up -d mapasculturais
   ```

2. (Recomendado) Tire uma cópia do banco antes, para poder voltar atrás. Ajuste o usuário e o nome do banco se forem outros:

   ```bash
   docker compose exec -T db pg_dump -U mapas -Fc mapas > ~/antes-homologacao-contrarrazoes.dump
   ```

3. Crie o cenário e **guarde a saída**. Ela traz os ids, os links e a tabela de usuários:

   ```bash
   docker compose exec mapasculturais php /var/www/src/plugins/Contrarrazoes/tests/cenario-homologacao.php criar
   ```

   Para outra senha, use `docker compose exec -e SENHA_TESTE='OutraSenha#1' mapasculturais php ...`.

4. Entre com e-mail e senha. Use janelas anônimas ou navegadores diferentes para ficar logado como várias pessoas ao mesmo tempo.

   | Pessoa | Login | Papel |
   |---|---|---|
   | Gestora Teste | `gestora@contrarrazoes.teste` | dona do concurso (não é avaliadora) |
   | Avaliador Teste | `avaliador@contrarrazoes.teste` | comissão de recursos |
   | Candidata Ana | `ana@contrarrazoes.teste` | selecionada; contestou os recursos de Bruno e Carla |
   | Candidato Bruno | `bruno@contrarrazoes.teste` | suplente; tem recurso; contrarrazão em rascunho contra Carla |
   | Candidata Carla | `carla@contrarrazoes.teste` | não selecionada; tem recurso |
   | Candidato Davi | `davi@contrarrazoes.teste` | não selecionado; tem recurso sem contrarrazão |
   | Candidata Elisa | `elisa@contrarrazoes.teste` | não selecionada; contestou o recurso de Bruno |

   Senha padrão de todos: `Contrarrazoes@2026`.

   > No servidor de desenvolvimento, o login com reCAPTCHA depende da saída para `www.google.com` (problema conhecido da rede da PBH). Se o captcha não carregar, o problema é esse, e não o plugin.

**Situação inicial:**

- O resultado da Seleção está publicado.
- O prazo de recurso está encerrado. Bruno, Carla e Davi enviaram recursos.
- O prazo de contrarrazões está **aberto** por 7 dias.
- Contrarrazões enviadas: Ana e Elisa contra o recurso de Bruno; Ana contra o recurso de Carla.
- Contrarrazão em rascunho: Bruno contra o recurso de Carla.

---

## Parte 1: prazo de contrarrazões aberto

### 1.1 Anonimato na lista de recursos (Ana)

1. Entre como **Ana**. Abra a inscrição dela no concurso (Painel → Minhas inscrições).
2. Na linha do tempo, no bloco **Contrarrazões**, clique em **Ver recursos e contrarrazões**.

**Esperado:**
- [ ] Os 3 recursos aparecem como "Recurso da inscrição on-…", com a data de envio e **sem o nome** de Bruno, Carla ou Davi.
- [ ] Aparecem a fundamentação e o anexo de cada recurso. O **CPF não aparece**.
- [ ] Nos recursos de Bruno e Carla aparece **Ver contrarrazão enviada**. No de Davi aparece **Apresentar contrarrazão**.
- [ ] Ainda não aparece nenhuma resposta da comissão.

> A busca por número da inscrição só aparece a partir de 6 recursos. Com 3 recursos, ela não aparece.

### 1.2 Nova contrarrazão pela tela (Elisa contra Davi)

1. Entre como **Elisa** e abra o mesmo modal.
2. No recurso de Davi, clique em **Apresentar contrarrazão**. Preencha a fundamentação e envie.

**Esperado:**
- [ ] A faixa no topo da contrarrazão mostra "Contrarrazão ao recurso da inscrição on-…" e o prazo, **sem o nome de Davi**.
- [ ] De volta ao modal, o recurso de Davi passa a mostrar **Ver contrarrazão enviada**.

### 1.3 Autor de recurso (Bruno)

1. Entre como **Bruno** e abra o modal.

**Esperado:**
- [ ] O recurso dele aparece como **Seu recurso**, sem botão.
- [ ] Os outros recursos aparecem sem nomes.
- [ ] Ainda não aparece nada sobre respostas.

### 1.4 Configuração da classificação (Gestora)

1. Entre como **Gestora**. Edite o concurso e vá até a etapa de recurso. Abra o acordeão **Contrarrazões**.

**Esperado:**
- [ ] Existe a seção **Resposta às contrarrazões**, com **Classificar a contrarrazão na resposta** marcado e três opções: *Acolhida*, *Parcialmente acolhida* e *Não acolhida*.
- [ ] Na tabela de contrarrazões recebidas, a coluna **Resposta** mostra **Pendente** nas enviadas e "—" no rascunho de Bruno.
- [ ] A Gestora **vê os nomes** dos candidatos nessa tabela. Isso é esperado: só os candidatos não veem.

2. Teste a validação: remova as três opções, deixe a caixa marcada e clique em salvar.
   - [ ] Aparece o erro "Informe ao menos uma opção de classificação…".
3. Recarregue a página sem salvar. As três opções voltam.

### 1.5 Avaliador com o prazo aberto

1. Entre como **Avaliador**. Abra a avaliação do recurso de **Bruno** (o link está na saída do script).

**Esperado:**
- [ ] O bloco **Contrarrazões apresentadas a este recurso (2)** mostra as contrarrazões de Ana e Elisa, com nome, selo **Pendente** e "0 de 2 respondidas por você".
- [ ] O formulário de resposta **não aparece**. Cada contrarrazão mostra "Ainda sem resposta.", e o aviso amarelo explica que o julgamento só pode ser finalizado depois do fim do prazo de contrarrazões.
- [ ] Ao tentar finalizar a avaliação, a trava do julgamento bloqueia: o prazo de contrarrazões ainda está aberto. Esse comportamento já existia antes.

---

## Parte 2: prazo encerrado, respostas e obrigatoriedade

Encerre o prazo de contrarrazões. O id do concurso está na saída do script:

```bash
docker compose exec mapasculturais php /var/www/src/plugins/Contrarrazoes/tests/cenario-homologacao.php prazo <id do concurso> encerrar
```

Para reabrir o prazo e refazer a parte 1, use `abrir` no lugar de `encerrar`.

### 2.1 Resposta obrigatória: avaliador

1. Como **Avaliador**, recarregue a avaliação do recurso de Bruno. Escolha um resultado e tente **finalizar** sem responder.

**Esperado:**
- [ ] A finalização é recusada com "Responda às contrarrazões antes de finalizar o julgamento do recurso. Contrarrazões sem resposta: on-…, on-…" (as inscrições de Ana e Elisa).

### 2.2 Resposta obrigatória: gestora

1. Como **Gestora**, abra a lista de inscrições da **fase de recurso** e tente mudar o status do recurso de Bruno (por exemplo, para selecionado).
   - [ ] A mudança é recusada com a lista das contrarrazões sem resposta.
2. Tente **publicar o resultado** da fase de recurso.
   - [ ] A publicação é recusada com a lista dos recursos que têm contrarrazão sem resposta.

### 2.3 Responder, validar e editar (Avaliador)

No recurso de Bruno, na contrarrazão de **Ana**:

1. Clique em **Salvar resposta** sem escolher a classificação.
   - [ ] Aparece "Selecione a classificação da resposta."
2. Escolha **Não acolhida** e salve com a fundamentação vazia.
   - [ ] Aparece "Escreva a fundamentação da resposta."
3. Escreva a fundamentação e salve.
   - [ ] O selo muda para **Respondida**, aparece "1 de 2 respondidas por você", e a resposta é exibida com a data.
4. Clique em **Editar resposta**, mude o texto e salve.
   - [ ] O texto novo aparece e a data é atualizada.

### 2.4 Resposta só em texto (Gestora + Avaliador)

1. Como **Gestora**, no acordeão Contrarrazões, **desmarque** "Classificar a contrarrazão na resposta" e salve.
   - [ ] Aparece "A resposta terá apenas o texto da fundamentação."
2. Como **Avaliador**, recarregue o recurso de Bruno. Responda à contrarrazão de **Elisa**.
   - [ ] O formulário não tem opções de classificação, só a fundamentação.
   - [ ] A resposta à Ana continua mostrando **Não acolhida**: mudar a configuração não altera respostas já registradas.
3. Como **Gestora**, marque de novo a classificação.
   - [ ] As três opções padrão voltam preenchidas. Troque-as, por exemplo, por *Procedente* e *Improcedente*, e salve.
   - [ ] Na tabela de contrarrazões, a coluna **Resposta** mostra o resultado de cada uma. Em **ver** aparecem o texto e o **nome do avaliador**: a Gestora vê quem respondeu.

### 2.5 Nada aparece para os candidatos antes da publicação

1. Como **Ana**, abra o modal e a página da contrarrazão dela contra Bruno.
   - [ ] Nenhuma resposta aparece.
2. Como **Bruno**, abra o modal.
   - [ ] Nenhuma resposta aparece.

### 2.6 Rascunho não conta

1. Como **Avaliador**, abra a avaliação do recurso de **Carla**.
   - [ ] Aparece só a contrarrazão **enviada** de Ana. O rascunho de Bruno não aparece e não é exigido.

### 2.7 Finalizar e travar a resposta

1. Como **Avaliador**, finalize a avaliação do recurso de **Bruno**. Agora as duas contrarrazões estão respondidas.
   - [ ] A finalização é aceita.
   - [ ] A resposta passa a só leitura, com o aviso "A avaliação deste recurso já foi finalizada: a resposta não pode mais ser alterada."
2. Responda às contrarrazões dos recursos de **Carla** e de **Davi** (a de Elisa, criada no passo 1.2) e finalize as duas avaliações.

---

## Parte 3: publicação do resultado do recurso

1. Como **Gestora**, na fase de recurso, aplique o resultado das avaliações às inscrições, se os status ainda não mudaram sozinhos. Depois, **publique o resultado** da fase de recurso.
   - [ ] A publicação é aceita, porque todas as contrarrazões enviadas têm resposta.

### 3.1 Autora da contrarrazão (Ana)

1. Abra o modal **Ver recursos e contrarrazões**.
   - [ ] Nos recursos de Bruno e Carla aparece **Resposta da comissão à sua contrarrazão**, com a classificação e o texto.
   - [ ] O nome do avaliador não aparece.
   - [ ] Na linha do tempo aparece o aviso "A comissão publicou as respostas às contrarrazões…".
2. Abra a página da contrarrazão contra Bruno.
   - [ ] A faixa do topo mostra **Resposta da comissão** com a resposta.

### 3.2 Autor do recurso (Bruno)

1. Abra o modal.
   - [ ] Em **Seu recurso** aparece **Contrarrazões ao seu recurso e respostas da comissão**, com duas entradas "Contrarrazão da inscrição on-…" e as respostas.
   - [ ] **Não aparecem os nomes** de Ana e Elisa nem o conteúdo das contrarrazões, só o número e a resposta.

### 3.3 Demais candidatos

- [ ] **Carla** vê, no recurso dela, a resposta à contrarrazão de Ana. O rascunho de Bruno não aparece.
- [ ] **Davi** vê a resposta à contrarrazão de Elisa.
- [ ] **Elisa** vê a resposta à contrarrazão dela contra Bruno e não vê as respostas dadas à Ana.

### 3.4 Depois de publicado

1. Como **Avaliador**, abra qualquer recurso.
   - [ ] As respostas estão só para leitura.

---

## O que cada parte confere

| Mudança da 1.2.0 | Passos |
|---|---|
| Resposta do avaliador (formulário, selos, contador, edição) | 1.5, 2.3, 2.7 |
| Só o avaliador do recurso responde, só depois do prazo | 1.5, 2.3 (a Gestora não tem o formulário) |
| Resposta obrigatória (finalizar, status, publicar) | 2.1, 2.2, 3 |
| Classificação configurável (padrão, só texto, opções próprias, validação) | 1.4, 2.4 |
| Rascunho não exige resposta | 2.6 |
| Resposta travada depois de finalizar ou publicar | 2.7, 3.4 |
| Respostas invisíveis antes da publicação | 2.5 |
| Respostas para o autor da contrarrazão e para o autor do recurso depois da publicação | 3.1, 3.2, 3.3 |
| Anonimato entre candidatos (sem nomes) | 1.1, 1.2, 1.3, 3.2 |
| Gestora vê nomes e respostas de todos os avaliadores | 1.4, 2.4 |

## Começar de novo

Rodar `criar` de novo cria **outro** concurso, com data e hora no nome. Os usuários são reaproveitados e a senha é redefinida. Os concursos antigos podem ir para a lixeira pela tela. Para voltar o banco ao estado de antes, restaure a cópia do passo 0.2.
