"""Cliente HTTP mínimo para testar o plugin num Mapas com o provedor de login Fake (desenvolvimento)."""
import json
import os
import requests

BASE_URL = os.environ.get('MAPAS_URL', 'http://localhost:8080').rstrip('/')


class Mapas:
    def __init__(self, user_id=None):
        self.s = requests.Session()
        self.s.trust_env = False  # nunca usar proxy para o servidor local
        if user_id:
            self.login(user_id)

    def login(self, user_id):
        r = self.s.get(f'{BASE_URL}/autenticacao/fakeLogin/', params={'fake_authentication_user_id': user_id, 'isAjax': 'true'})
        r.raise_for_status()
        return r

    def post(self, controller, action, entity_id, data=None):
        url = f'{BASE_URL}/{controller}/{action}/{entity_id}'
        r = self.s.post(url, json=data or {}, headers={'Accept': 'application/json'})
        try:
            body = r.json()
        except ValueError:
            body = r.text
        return r.status_code, body

    def get(self, path, **kwargs):
        return self.s.get(f'{BASE_URL}{path}', **kwargs)
