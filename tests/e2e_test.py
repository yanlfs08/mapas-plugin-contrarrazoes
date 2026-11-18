#!/usr/bin/env python3
"""
Teste de interface (Playwright + Chrome) do plugin de contrarrazões.

Pré-requisitos: Mapas de DESENVOLVIMENTO com login Fake e o cenário de `seed-scenario.php`
(etapas base, appeals e committee), SEM fase de contrarrazões criada.

    MAPAS_URL=http://localhost:8080 python3 tests/e2e_test.py tests/scenario.json /tmp/capturas

Fluxo: gestor cria e configura a fase -> candidata eliminada na 1ª fase lê os recursos e
apresenta contrarrazão -> autora do recurso vê o próprio recurso sem botão -> avaliador vê a
contrarrazão e a trava do julgamento.
"""
import datetime as dt
import json
import os
import re
import sys

from playwright.sync_api import expect, sync_playwright

BASE_URL = os.environ.get('MAPAS_URL', 'http://localhost:8080').rstrip('/')
CHROME = os.environ.get('CHROME_PATH', '/usr/bin/google-chrome')


def main(scenario_path, shots_dir):
    sc = json.load(open(scenario_path))
    os.makedirs(shots_dir, exist_ok=True)
    u = sc['users']
    opp = sc['opportunity']
    step = [0]

    def shot(page, name):
        page.wait_for_timeout(1200)  # espera o fade-in da página
        step[0] += 1
        path = os.path.join(shots_dir, f'{step[0]:02d}-{name}.png')
        page.screenshot(path=path, full_page=True)
        print('  captura:', path)

    def login(context, user_id):
        page = context.new_page()
        page.goto(f'{BASE_URL}/autenticacao/fakeLogin/?fake_authentication_user_id={user_id}&isAjax=true')
        return page

    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=CHROME, args=['--no-proxy-server', '--lang=pt-BR'])

        # 1. gestor cria e configura a fase de contrarrazões
        print('1. gestor configura a fase')
        ctx = browser.new_context(viewport={'width': 1366, 'height': 900}, locale='pt-BR', timezone_id='UTC')
        page = login(ctx, u['gestor'])
        page.goto(f'{BASE_URL}/gestao-de-oportunidade/{opp}/#config')
        page.wait_for_load_state('networkidle')
        take_control = page.get_by_role('button', name='Assumir controle')
        if take_control.count():  # a oportunidade pode estar bloqueada por outra sessão de edição
            take_control.click()
            page.wait_for_load_state('networkidle')

        # Configuração de fases → fase avaliada (Mérito) → Etapa de recurso → acordeão "Contrarrazões"
        # (entre os acordeões "Recurso" e "Resposta do recurso")
        merit = page.locator('li').filter(has=page.get_by_role('heading', name='Mérito', exact=True)).first
        merit.get_by_text('Expandir').first.click()
        config = page.locator('.counter-appeal-config')
        expect(config).to_be_visible(timeout=15000)
        titles = page.locator('.opportunity-appeal-phase-config__appeals h3.bold').all_inner_texts()
        order = [t.split('\n')[0].strip() for t in titles if t.strip()]
        assert order.index('Contrarrazões') == order.index('Recurso') + 1 < order.index('Resposta do recurso'), order
        config.get_by_text('Expandir').first.click()
        shot(page, 'gestor-etapa-de-recurso')

        config.get_by_role('button', name='Adicionar contrarrazões').click()
        expect(config.get_by_text('Quem pode apresentar contrarrazões')).to_be_visible(timeout=15000)

        start = (dt.datetime.now() - dt.timedelta(days=1))
        end = (dt.datetime.now() + dt.timedelta(days=3))
        set_datetime(config, 'registrationFrom', start)
        set_datetime(config, 'registrationTo', end)

        config.get_by_label('Fundamentação do recurso').check()
        config.get_by_label('Documento do recurso').check()
        expect(config.get_by_label('CPF do responsável')).to_have_count(0)
        config.get_by_role('button', name='Salvar configurações').click()
        expect(page.get_by_text('Configurações salvas')).to_be_visible(timeout=15000)
        shot(page, 'gestor-configuracao-salva')
        ctx.close()

        # 2. candidata que está na etapa do recurso apresenta contrarrazão ao recurso de C3
        print('2. candidata C1 (no Mérito, sem recurso) apresenta contrarrazão')
        ctx = browser.new_context(viewport={'width': 1366, 'height': 900}, locale='pt-BR', timezone_id='UTC')
        page = login(ctx, u['c1'])
        registration_c1 = f"{BASE_URL}/inscricao/{sc['first']['c1']}/"
        open_counter_appeals(page, registration_c1)
        expect(page.get_by_text('Recurso da inscrição').first).to_be_visible(timeout=15000)
        expect(page.get_by_text('A proposta atende a todos os critérios do edital.').first).to_be_visible()
        expect(page.get_by_text('123.456.789-09')).to_have_count(0)
        shot(page, 'candidata-lista-de-recursos')

        card = page.locator('.counter-appeal-list__appeal').filter(has_text=sc['appealNumbers']['c3'])
        card.get_by_role('button', name='Apresentar contrarrazão').click()
        # sai da página da inscrição da C1 e vai para o formulário da contrarrazão
        page.wait_for_url(lambda url: '/inscricao/' in url and f"/{sc['first']['c1']}/" not in url, timeout=20000)
        page.wait_for_load_state('networkidle')
        shot(page, 'candidata-formulario-contrarrazao')

        expect(page.get_by_text('Contrarrazão ao recurso da inscrição')).to_be_visible()
        page.get_by_role('button', name='Próxima etapa').click()
        page.locator('textarea').first.fill('O recurso não apresenta fato novo. A nota da inscrição contestada deve ser mantida.')
        page.get_by_role('button', name='Salvar', exact=True).click()
        page.wait_for_timeout(2000)
        page.get_by_role('button', name='Enviar formulário').click()
        page.get_by_role('button', name='Enviar agora').click()
        page.wait_for_timeout(5000)
        shot(page, 'candidata-contrarrazao-enviada')

        open_counter_appeals(page, registration_c1)
        card = page.locator('.counter-appeal-list__appeal').filter(has_text=sc['appealNumbers']['c3'])
        expect(card.get_by_text('Ver contrarrazão enviada')).to_be_visible(timeout=15000)
        expect(card.get_by_role('button', name='Apresentar contrarrazão')).to_have_count(0)
        shot(page, 'candidata-lista-apos-envio')
        ctx.close()

        # 3. autora do recurso vê o próprio recurso sem botão
        print('3. autora do recurso (C3)')
        ctx = browser.new_context(viewport={'width': 1366, 'height': 900}, locale='pt-BR', timezone_id='UTC')
        page = login(ctx, u['c3'])
        open_counter_appeals(page, f"{BASE_URL}/inscricao/{sc['first']['c3']}/")
        own =page.locator('.counter-appeal-list__appeal').filter(has_text=sc['appealNumbers']['c3'])
        expect(own.get_by_text('Seu recurso')).to_be_visible(timeout=15000)
        expect(own.get_by_role('button', name='Apresentar contrarrazão')).to_have_count(0)
        other = page.locator('.counter-appeal-list__appeal').filter(has_text=sc['appealNumbers']['c2'])
        expect(other.get_by_role('button', name='Apresentar contrarrazão')).to_be_visible()
        shot(page, 'autora-ve-proprio-recurso')
        ctx.close()

        # 4. não inscrito
        print('4. pessoa sem inscrição (C6)')
        ctx = browser.new_context(viewport={'width': 1366, 'height': 900}, locale='pt-BR', timezone_id='UTC')
        page = login(ctx, u['c6'])
        page.goto(f'{BASE_URL}/oportunidade/{opp}/')
        page.wait_for_load_state('networkidle')
        expect(page.locator('.counter-appeal-list')).to_have_count(0)
        expect(page.get_by_text('A proposta atende a todos os critérios do edital.')).to_have_count(0)
        shot(page, 'nao-inscrito')
        ctx.close()

        # 4b. eliminada antes da etapa do recurso: não vê o bloco [Contrarrazões]
        print('4b. eliminada na 1ª fase (C5)')
        ctx = browser.new_context(viewport={'width': 1366, 'height': 900}, locale='pt-BR', timezone_id='UTC')
        page = login(ctx, u['c5'])
        page.goto(f"{BASE_URL}/inscricao/{sc['first']['c5']}/#acompanhamento")
        page.wait_for_load_state('networkidle')
        expect(page.locator('.counter-appeal-timeline')).to_have_count(0)
        shot(page, 'eliminada-sem-bloco')
        ctx.close()

        # 5. avaliador vê a contrarrazão e a trava do julgamento
        print('5. avaliador do recurso')
        ctx = browser.new_context(viewport={'width': 1366, 'height': 900}, locale='pt-BR', timezone_id='UTC')
        page = login(ctx, u['avaliador'])
        page.goto(f"{BASE_URL}/avaliacao/{sc['appeals']['c3']}/")
        expect(page.get_by_text('Contrarrazões apresentadas a este recurso')).to_be_visible(timeout=20000)
        expect(page.get_by_text('O recurso não apresenta fato novo.')).to_be_visible()
        expect(page.get_by_text('só pode ser finalizado após o encerramento do prazo de contrarrazões').first).to_be_visible()
        shot(page, 'avaliador-ve-contrarrazao')
        ctx.close()

        browser.close()
    print('\nE2E concluído sem falhas')
    return 0


def open_counter_appeals(page, registration_url):
    """Abre a inscrição, acha o bloco [Contrarrazões] na linha do tempo e abre o modal com os recursos."""
    page.goto(f'{registration_url}#acompanhamento')
    block = page.locator('.counter-appeal-timeline')
    expect(block).to_be_visible(timeout=15000)
    # o bloco fica no item da fase avaliada, abaixo do "Solicitar recurso" quando ele existe
    block.get_by_role('button', name=re.compile('^Ver recursos')).click()
    expect(page.locator('.modal-content .counter-appeal-list')).to_be_visible(timeout=10000)


def set_datetime(scope, prop, value):
    """Preenche um entity-field de data e hora (campo de data e campo de hora com máscara) dentro de scope."""
    page = scope.page if hasattr(scope, 'page') else scope
    field = scope.locator(f'[data-field="{prop}"]').first
    date_input = field.locator('input.date-input')
    date_input.click()
    date_input.press_sequentially(value.strftime('%d%m%Y'), delay=30)
    date_input.press('Tab')
    time_input = field.locator('input.time-input')
    time_input.click()
    time_input.press_sequentially(value.strftime('%H%M'), delay=30)
    time_input.press('Tab')
    page.wait_for_timeout(300)


if __name__ == '__main__':
    sys.exit(main(sys.argv[1] if len(sys.argv) > 1 else 'tests/scenario.json',
                  sys.argv[2] if len(sys.argv) > 2 else '/tmp/capturas-contrarrazoes'))
