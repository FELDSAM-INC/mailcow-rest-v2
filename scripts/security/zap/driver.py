"""Runs inside ZAP's isolated container. Reports omit credentials and HTTP evidence."""
import copy
import http.cookiejar
import json
from pathlib import Path
import ssl
import time
import urllib.error
import urllib.request

from zapv2 import ZAPv2

BASE = 'https://web'
USER_AGENT = 'mailcow-disposable-security-check/1.0'
REPORTS = Path('/reports')
CREDENTIALS = json.loads(Path('/zap/wrk/credentials.json').read_text())
CHECKS = []


class Client:
    def __init__(self, key=None):
        self.jar = http.cookiejar.CookieJar()
        # The only target is our ephemeral nginx with a freshly generated self-signed certificate.
        context = ssl.create_default_context()
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar),
                                                 urllib.request.HTTPSHandler(context=context))
        self.headers = {'Accept': 'application/json', 'User-Agent': USER_AGENT}
        if key:
            self.headers['X-API-Key'] = key

    def request(self, path, method='GET', body=None, expected=(200,)):
        data = None if body is None else json.dumps(body).encode()
        headers = dict(self.headers)
        if body is not None:
            headers['Content-Type'] = 'application/json'
        request = urllib.request.Request(BASE + '/api/v2' + path, data=data,
                                         headers=headers, method=method)
        try:
            response = self.opener.open(request, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            status = response.status
            payload = response.read()
        if status not in expected:
            raise AssertionError(f'{method} {path}: expected {expected}, received {status}')
        return json.loads(payload) if payload else {}

    def login(self, username):
        self.headers['X-CSRF-Token'] = self.request('/csrf-token')['data']['token']
        result = self.request('/session', 'POST', {'username': username,
                                                  'password': CREDENTIALS['password']}, (201,))
        if result['data']['state'] != 'active':
            raise AssertionError('Fixture did not establish an active authenticated session')
        self.headers['X-CSRF-Token'] = self.request('/csrf-token')['data']['token']
        return self

    def cookie(self):
        return '; '.join(f'{cookie.name}={cookie.value}' for cookie in self.jar)


def boundary_checks():
    admin = Client().login('zapadmin')
    domain = Client().login('zapreseller')
    mailbox = Client().login('zapuser@example.org')
    admin.request('/mailboxes/foreign@foreign.example')
    for name, client in [('domainAdmin', domain), ('mailboxUser', mailbox)]:
        client.request('/mailboxes/foreign@foreign.example', expected=(403, 404))
        client.request('/mailboxes/foreign@foreign.example', 'PATCH',
                       {'displayName': 'unauthorized-change'}, (403, 404))
        CHECKS.append(name + ': foreign mailbox read/write denied')
    if admin.request('/mailboxes/foreign@foreign.example')['data']['displayName'] == 'unauthorized-change':
        raise AssertionError('Denied request changed the mailbox')
    domain.request('/domains/foreign.example', expected=(403, 404))
    listed = domain.request('/mailboxes')['data']
    if any(row.get('domain') == 'foreign.example' for row in listed):
        raise AssertionError('Foreign mailbox leaked in domain administrator list')
    CHECKS.append('domainAdmin: foreign domain and list isolation')
    readonly = Client(CREDENTIALS['readonly'])
    readonly.request('/me')
    readonly.request('/mailboxes/foreign@foreign.example', 'PATCH',
                     {'displayName': 'readonly-change'}, (403,))
    Client(CREDENTIALS['revoked']).request('/me', expected=(401,))
    CHECKS.append('API keys: read-only writes and revoked key access denied')
    return [('admin', admin, ['/domains', '/mailboxes', '/aliases', '/sync-jobs']),
            ('domainAdmin', domain, ['/domains', '/mailboxes', '/aliases', '/sync-jobs']),
            ('mailboxUser', mailbox, ['/me', '/me/preferences', '/me/mailbox', '/me/recovery-email'])]


def wait_until(predicate, seconds, description):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        if predicate():
            return
        time.sleep(2)
    raise RuntimeError('Timed out: ' + description)


def verify_scanner_identity(zap, role):
    response = zap.core.send_request('GET ' + BASE + '/api/v2/me HTTP/1.1\r\nHost: web\r\n\r\n')
    payload = json.loads(response[0]['responseBody']) if response else {}
    if payload.get('data', {}).get('role') != role:
        raise AssertionError('Scanner authentication failed for ' + role + ': ' + payload.get('type', 'no identity'))


def run():
    zap = ZAPv2(proxies={'http': 'http://127.0.0.1:8080', 'https': 'http://127.0.0.1:8080'})

    def ready():
        try:
            return bool(zap.core.version)
        except Exception:
            return False

    wait_until(ready, 120, 'ZAP startup')
    cases = boundary_checks()
    spec = json.loads(Path('/zap/wrk/openapi.json').read_text())
    coverage = []
    alerts = []
    for role, client, paths in cases:
        zap.core.new_session(name=role, overwrite=True)
        zap.replacer.remove_rule('fixture-cookie')
        zap.replacer.add_rule('fixture-cookie', True, 'REQ_HEADER', False, 'Cookie', client.cookie(),
                              url=r'https://web/.*')
        zap.replacer.remove_rule('fixture-user-agent')
        zap.replacer.add_rule('fixture-user-agent', True, 'REQ_HEADER', False, 'User-Agent', USER_AGENT,
                              url=r'https://web/.*')
        if client.request('/me')['data']['role'] != role:
            raise AssertionError('Authenticated role mismatch')
        verify_scanner_identity(zap, role)
        subset = copy.deepcopy(spec)
        subset['servers'] = [{'url': BASE + '/api/v2'}]
        subset['paths'] = {}
        for path in paths:
            client.request(path)
            # Active payloads use GET operations only. Mutating privilege boundaries are exercised
            # explicitly above and in PHPUnit, so a scan cannot invalidate its own fixture accounts.
            subset['paths'][path] = {'get': spec['paths'][path]['get']}
        target = Path('/zap/wrk/scan-' + role + '.json')
        target.write_text(json.dumps(subset))
        imported = zap.openapi.import_file(str(target), target=BASE + '/api/v2')
        if isinstance(imported, dict) and imported.get('code'):
            raise RuntimeError('ZAP OpenAPI import failed')
        seen = zap.core.urls(BASE + '/api/v2')
        for path in paths:
            if not any(url.split('?')[0] == BASE + '/api/v2' + path for url in seen):
                raise AssertionError('ZAP did not import ' + path)
        # Passive checks also cover the production frontend HTML and linked assets.
        zap.spider.scan(BASE + '/ng/', maxchildren=12, recurse=True, subtreeonly=True)
        wait_until(lambda: all(scan['progress'] == '100' for scan in zap.spider.scans), 180, 'UI spider')
        zap.ascan.set_option_max_rule_duration_in_mins(1)
        zap.ascan.set_option_max_scan_duration_in_mins(5)
        scan = zap.ascan.scan(BASE + '/api/v2', recurse=True, inscopeonly=False)
        wait_until(lambda: zap.ascan.status(scan) == '100', 360, role + ' active scan')
        wait_until(lambda: int(zap.pscan.records_to_scan) == 0, 120, role + ' passive scan')
        # Verify authentication through the scanner, not just the direct client, after attacks.
        verify_scanner_identity(zap, role)
        coverage.append({'role': role, 'paths': paths, 'activeMethods': ['GET'],
                         'maxRuleMinutes': 1, 'maxScanMinutes': 5, 'authenticatedAfterScan': True})
        for alert in zap.core.alerts(baseurl=BASE):
            # Drop HTTP bodies, parameters, payload evidence, query strings and session material.
            alerts.append({key: alert.get(key) for key in
                           ['pluginId', 'name', 'risk', 'confidence', 'cweid', 'solution', 'reference']}
                          | {'role': role, 'path': alert['url'].split('?')[0].removeprefix(BASE)})
        (REPORTS / 'zap.json').write_text(json.dumps({'coverage': coverage, 'checks': CHECKS,
                                                     'alerts': alerts}, indent=2) + '\n')
        print(role + ': authenticated scan complete', flush=True)
    high = [alert for alert in alerts if alert['risk'] == 'High']
    print(f'{len(CHECKS)} boundary checks; {len(coverage)} authenticated roles; {len(high)} high-risk alerts')
    if high:
        raise SystemExit(1)


if __name__ == '__main__':
    run()
