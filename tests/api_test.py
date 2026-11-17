#!/usr/bin/env python3
"""
Testes de API do plugin de contrarrazões.

Rodam contra um Mapas de DESENVOLVIMENTO com o provedor de login Fake e o cenário criado por
`seed-scenario.php` (etapas base, appeals e committee). Os ids do cenário ficam num JSON
(veja `scenario.example.json`).

    MAPAS_URL=http://localhost:8080 python3 tests/api_test.py tests/scenario.json

Os testes alteram dados: restaure o banco do cenário antes de rodar de novo.
"""
import datetime as dt
import json
import sys

from mapas_client import BASE_URL, Mapas

results = []


def check(name, condition, detail=''):
    results.append((name, bool(condition)))
    print(('PASS' if condition else 'FAIL') + f'  {name}' + (f'  -> {detail}' if not condition and detail else ''))


def when(**delta):
    return (dt.datetime.now() + dt.timedelta(**delta)).strftime('%Y-%m-%d %H:%M:%S')


def main(path):
    sc = json.load(open(path))
    u = sc['users']
    first = sc['first']
    appeals = sc['appeals']
    appeal_phase = sc['appealPhase']

    gestor = Mapas(u['gestor'])
    avaliador = Mapas(u['avaliador'])
    c1, c3, c5, c6 = Mapas(u['c1']), Mapas(u['c3']), Mapas(u['c5']), Mapas(u['c6'])
    guest = Mapas()

    # --- criação da fase -------------------------------------------------------------
    code, _ = c5.post('opportunity', 'createCounterAppealPhase', appeal_phase)
    check('candidato não cria fase de contrarrazões', code == 403, code)

    code, counter = gestor.post('opportunity', 'createCounterAppealPhase', appeal_phase)
    check('gestor cria fase de contrarrazões', code == 200 and counter.get('isCounterAppealPhase'), counter)
    cid = counter['id']
    check('fase criada oculta (status -1)', counter.get('status') == -1, counter.get('status'))

    code, again = gestor.post('opportunity', 'createCounterAppealPhase', appeal_phase)
    check('criar de novo devolve a mesma fase', code == 200 and again.get('id') == cid)

    # --- trava do julgamento sem prazo ------------------------------------------------
    code, body = gestor.post('registration', 'setStatusTo', appeals['c3'], {'status': 10})
    check('julgamento travado enquanto o prazo não foi definido', code in (401, 403), (code, body))

    # --- configuração -------------------------------------------------------------------
    code, body = gestor.post('opportunity', 'saveCounterAppealConfig', cid, {
        'registrationFrom': when(days=2), 'registrationTo': when(days=1)})
    check('datas invertidas são rejeitadas', code == 400, (code, body))

    fields = sc['appealFields']
    code, body = gestor.post('opportunity', 'saveCounterAppealConfig', cid, {
        'counterAppealEligibility': 'all',
        'counterAppealPublicFields': [fields['text'], fields['cpf'], fields['file'], 'campo_inexistente'],
        'registrationFrom': when(hours=-1), 'registrationTo': when(days=3)})
    check('gestor salva configuração', code == 200, (code, body))
    check('CPF e campos inexistentes não podem ser públicos',
          sorted(body.get('counterAppealPublicFields', [])) == sorted([fields['text'], fields['file']]),
          body.get('counterAppealPublicFields'))
    check('prazo aberto', body.get('isRegistrationOpen') is True)

    code, _ = c5.post('opportunity', 'saveCounterAppealConfig', cid, {'counterAppealEligibility': 'samePhase'})
    check('candidato não altera configuração', code == 403, code)

    # --- aba pública ------------------------------------------------------------------------
    page = c5.get(f"/oportunidade/{sc['opportunity']}/").text
    check('aba de contrarrazões aparece para candidato', 'counter-appeal-list' in page)
    check('fundamentação pública aparece', 'A proposta atende a todos os crit' in page)
    check('CPF não aparece', '123.456.789-09' not in page)

    page = c6.get(f"/oportunidade/{sc['opportunity']}/").text
    check('quem não é inscrito não vê o conteúdo dos recursos', 'A proposta atende a todos os crit' not in page)

    # --- anexos públicos -----------------------------------------------------------------------
    file_c3 = sc['appealFiles']['c3']
    r = c5.get(f'/opportunity/counterAppealFile/{cid}/file:{file_c3}/')
    check('candidato baixa anexo público do recurso', r.status_code == 200 and r.content.startswith(b'%PDF'), r.status_code)
    r = c6.get(f'/opportunity/counterAppealFile/{cid}/file:{file_c3}/')
    check('não inscrito não baixa anexo', r.status_code == 403, r.status_code)
    r = guest.get(f'/opportunity/counterAppealFile/{cid}/file:{file_c3}/', allow_redirects=False)
    check('visitante não baixa anexo', r.status_code in (302, 401, 403), r.status_code)

    # --- criação de contrarrazões ---------------------------------------------------------------
    code, ca = c5.post('opportunity', 'createCounterAppealRegistration', cid,
                       {'appeal_registration_id': appeals['c3'], 'author_registration_id': first['c5']})
    check('eliminada na 1ª fase (C5) apresenta contrarrazão', code == 200 and ca.get('status') == 0, (code, ca))
    code, ca2 = c5.post('opportunity', 'createCounterAppealRegistration', cid,
                        {'appeal_registration_id': appeals['c3'], 'author_registration_id': first['c5']})
    check('uma contrarrazão por recurso (devolve a existente)', code == 200 and ca2.get('id') == ca.get('id'))

    code, body = c3.post('opportunity', 'createCounterAppealRegistration', cid,
                         {'appeal_registration_id': appeals['c3'], 'author_registration_id': first['c3']})
    check('autor não contesta o próprio recurso', code == 403, (code, body))

    code, body = c3.post('opportunity', 'createCounterAppealRegistration', cid,
                         {'appeal_registration_id': appeals['c2'], 'author_registration_id': first['c3']})
    check('autor de recurso contesta recurso de outro', code == 200, (code, body))

    code, body = c6.post('opportunity', 'createCounterAppealRegistration', cid,
                         {'appeal_registration_id': appeals['c3'], 'author_registration_id': first['c5']})
    check('não inscrito não apresenta contrarrazão', code == 403, (code, body))

    code, body = c5.post('opportunity', 'createCounterAppealRegistration', cid,
                         {'appeal_registration_id': appeals['c2'], 'author_registration_id': first['c1']})
    check('não usa inscrição de outra pessoa', code == 403, (code, body))

    code, _ = guest.post('opportunity', 'createCounterAppealRegistration', cid,
                         {'appeal_registration_id': appeals['c3'], 'author_registration_id': first['c5']})
    check('visitante não apresenta contrarrazão', code == 401, code)

    r = c5.s.post(f'{BASE_URL}/registration/index',
                  json={'opportunity': cid, 'ownerId': u['c5']}, headers={'Accept': 'application/json'})
    check('inscrição direta na fase de contrarrazões é bloqueada', r.status_code in (401, 403), (r.status_code, r.text[:200]))

    # --- elegibilidade: mesma etapa -----------------------------------------------------------
    code, _ = gestor.post('opportunity', 'saveCounterAppealConfig', cid, {'counterAppealEligibility': 'samePhase'})
    code, body = c5.post('opportunity', 'createCounterAppealRegistration', cid,
                         {'appeal_registration_id': appeals['c2'], 'author_registration_id': first['c5']})
    check('mesma etapa: eliminada na 1ª fase não pode', code == 403, (code, body))
    code, body = c1.post('opportunity', 'createCounterAppealRegistration', cid,
                         {'appeal_registration_id': appeals['c2'], 'author_registration_id': sc['merit']['c1']})
    check('mesma etapa: inscrita no Mérito pode', code == 200, (code, body))
    gestor.post('opportunity', 'saveCounterAppealConfig', cid, {'counterAppealEligibility': 'all'})

    # --- trava do julgamento dentro do prazo --------------------------------------------------
    code, body = gestor.post('registration', 'setStatusTo', appeals['c3'], {'status': 10})
    check('gestor não defere recurso dentro do prazo', code in (401, 403), (code, body))

    r = avaliador.s.post(f"{BASE_URL}/registration/saveEvaluation/{appeals['c3']}/status:evaluated",
                         json={'data': {'status': '10', 'obs': 'Deferido'}}, headers={'Accept': 'application/json'})
    check('avaliador não finaliza avaliação dentro do prazo', r.status_code in (401, 403), (r.status_code, r.text[:300]))

    r = avaliador.s.post(f"{BASE_URL}/registration/saveEvaluation/{appeals['c3']}/status:draft",
                         json={'data': {'status': '10', 'obs': 'Rascunho do parecer'}}, headers={'Accept': 'application/json'})
    check('avaliador salva rascunho dentro do prazo', r.status_code == 200, (r.status_code, r.text[:300]))

    # --- depois do prazo ------------------------------------------------------------------------
    code, body = gestor.post('opportunity', 'saveCounterAppealConfig', cid, {
        'registrationFrom': when(days=-3), 'registrationTo': when(minutes=-1)})
    check('gestor encerra o prazo', code == 200 and body.get('isRegistrationOpen') is False, (code, body))

    code, body = c3.post('opportunity', 'createCounterAppealRegistration', cid,
                         {'appeal_registration_id': appeals['c2'], 'author_registration_id': first['c3']})
    check('contrarrazão já iniciada continua acessível após o prazo', code == 200, (code, body))

    code, body = Mapas(u['c4']).post('opportunity', 'createCounterAppealRegistration', cid,
                                     {'appeal_registration_id': appeals['c3'], 'author_registration_id': first['c4']})
    check('nova contrarrazão após o prazo é bloqueada', code == 403, (code, body))

    r = avaliador.s.post(f"{BASE_URL}/registration/saveEvaluation/{appeals['c3']}/status:evaluated",
                         json={'data': {'status': '10', 'obs': 'Deferido'}}, headers={'Accept': 'application/json'})
    check('avaliador finaliza avaliação após o prazo', r.status_code == 200, (r.status_code, r.text[:300]))

    code, body = gestor.post('registration', 'setStatusTo', appeals['c3'], {'status': 10})
    check('gestor defere recurso após o prazo', code == 200, (code, body))

    # --- exclusão ---------------------------------------------------------------------------------
    code, _ = c5.post('opportunity', 'deleteCounterAppealPhase', cid)
    check('candidato não exclui a fase', code == 403, code)

    code, _ = gestor.post('opportunity', 'deleteCounterAppealPhase', cid)
    check('gestor exclui a fase', code == 200, code)
    page = c5.get(f"/oportunidade/{sc['opportunity']}/").text
    check('aba some após excluir a fase', 'counterAppealList' not in page)
    code, counter2 = gestor.post('opportunity', 'createCounterAppealPhase', appeal_phase)
    check('fase pode ser recriada depois de excluída', code == 200 and counter2.get('id') != cid, (code, counter2))

    failed = [name for name, ok in results if not ok]
    print(f'\n{len(results) - len(failed)}/{len(results)} testes passaram')
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1] if len(sys.argv) > 1 else 'tests/scenario.json'))
