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
        page.goto(f'{BASE_URL}/gestao-de-oportunidade/{opp}/#contrarrazoes')
        page.wait_for_load_state('networkidle')
        take_control = page.get_by_role('button', name='Assumir controle')
        if take_control.count():  # a oportunidade pode estar bloqueada por outra sessão de edição
            take_control.click()
            page.wait_for_load_state('networkidle')
        page.get_by_text('Contrarrazões', exact=True).first.click()
        expect(page.get_by_text('Contrarrazões aos recursos')).to_be_visible()
        shot(page, 'gestor-aba-contrarrazoes')

        page.get_by_role('button', name='Adicionar contrarrazões').click()
        expect(page.get_by_text('Prazo para apresentar contrarrazões')).to_be_visible(timeout=15000)

        start = (dt.datetime.now() - dt.timedelta(days=1))
        end = (dt.datetime.now() + dt.timedelta(days=3))
        set_datetime(page, 'registrationFrom', start)
        set_datetime(page, 'registrationTo', end)

        page.get_by_label('Fundamentação do recurso').check()
        page.get_by_label('Documento do recurso').check()
        expect(page.get_by_label('CPF do responsável')).to_have_count(0)
        page.get_by_role('button', name='Salvar configurações').click()
        expect(page.get_by_text('Configurações salvas')).to_be_visible(timeout=15000)
        shot(page, 'gestor-configuracao-salva')
        ctx.close()

        # 2. candidata eliminada na 1ª fase apresenta contrarrazão ao recurso de C3
        print('2. candidata C5 apresenta contrarrazão')
        ctx = browser.new_context(viewport={'width': 1366, 'height': 900}, locale='pt-BR', timezone_id='UTC')
        page = login(ctx, u['c5'])
        page.goto(f'{BASE_URL}/oportunidade/{opp}/#contrarrazoes')
        expect(page.get_by_text('Recurso da inscrição').first).to_be_visible(timeout=15000)
        expect(page.get_by_text('A proposta atende a todos os critérios do edital.').first).to_be_visible()
        expect(page.get_by_text('123.456.789-09')).to_have_count(0)
        shot(page, 'candidata-lista-de-recursos')

        card = page.locator('.counter-appeal-list__appeal').filter(has_text=sc['appealNumbers']['c3'])
        card.get_by_role('button', name='Apresentar contrarrazão').click()
        page.wait_for_url('**/inscricao/**', timeout=20000)
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

        page.goto(f'{BASE_URL}/oportunidade/{opp}/#contrarrazoes')
        card = page.locator('.counter-appeal-list__appeal').filter(has_text=sc['appealNumbers']['c3'])
        expect(card.get_by_text('Ver contrarrazão enviada')).to_be_visible(timeout=15000)
        expect(card.get_by_role('button', name='Apresentar contrarrazão')).to_have_count(0)
        shot(page, 'candidata-lista-apos-envio')
        ctx.close()

        # 3. autora do recurso vê o próprio recurso sem botão
        print('3. autora do recurso (C3)')
        ctx = browser.new_context(viewport={'width': 1366, 'height': 900}, locale='pt-BR', timezone_id='UTC')
        page = login(ctx, u['c3'])
        page.goto(f'{BASE_URL}/oportunidade/{opp}/#contrarrazoes')
        own = page.locator('.counter-appeal-list__appeal').filter(has_text=sc['appealNumbers']['c3'])
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
        page.goto(f'{BASE_URL}/oportunidade/{opp}/#contrarrazoes')
        expect(page.get_by_text('Somente inscritos nesta oportunidade podem ler os recursos')).to_be_visible(timeout=15000)
        shot(page, 'nao-inscrito')
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


def set_datetime(page, prop, value):
    """Preenche um entity-field de data e hora (campo de data e campo de hora com máscara)."""
    field = page.locator(f'[data-field="{prop}"]').first
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
