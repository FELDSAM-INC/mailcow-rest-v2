#!/usr/bin/env python3
"""Build and scan a disposable mailcow stack; never accepts an external target URL."""
import argparse
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]


def run(*args, **kwargs):
    return subprocess.run(args, check=True, text=True, **kwargs)


def free_port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return str(listener.getsockname()[1])


def snapshot(destination):
    # A clean, local snapshot includes current tracked edits and new source files, but never
    # copies ignored .env files, credentials, dependencies or a developer's database volumes.
    files = subprocess.check_output(['git', 'ls-files', '-z', '--cached', '--others',
                                     '--exclude-standard'], cwd=ROOT).decode().split('\0')
    destination.mkdir()
    for filename in set(filter(None, files)):
        source = ROOT / filename
        if source.is_file() and not source.is_symlink():
            target = destination / filename
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, target)
    run('git', 'init', '-q', str(destination))
    run('git', '-C', str(destination), 'add', '.')
    run('git', '-C', str(destination), '-c', 'user.name=Security fixture',
        '-c', 'user.email=security@example.invalid', 'commit', '-qm', 'Disposable security snapshot')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--ui-dir', type=Path, default=ROOT.parent / 'mailcowui-ng')
    args = parser.parse_args()
    ui = args.ui_dir.resolve()
    if not (ui / 'scripts/smoke/stack-up.sh').is_file():
        parser.error('--ui-dir must be a mailcowui-ng checkout')
    reports = ROOT / 'security-reports/runtime'
    reports.mkdir(parents=True, exist_ok=True)
    project = 'mailcow-security-' + secrets.token_hex(6)
    network = project + '-isolated'
    zap = project + '-zap'
    image = json.loads((ROOT / 'security/tools.json').read_text())['zap']['image']
    run('docker', 'pull', image)
    with tempfile.TemporaryDirectory(prefix='mailcow-security-') as directory:
        work = Path(directory)
        snapshot(work / 'source')
        env = dict(os.environ, MAILCOW_REST_V2_DIR=str(work / 'source'),
                   SMOKE_WORKDIR=str(work / 'api'), SMOKE_PROJECT=project, SMOKE_API_REF='HEAD',
                   SMOKE_WEB_PORT=free_port(), SMOKE_WEB_TLS_PORT=free_port(),
                   SMOKE_KEYCLOAK_PORT=free_port(), SMOKE_MAILPIT_PORT=free_port(),
                   SMOKE_MAIL='1', SMOKE_IDP='0', SMOKE_COMPOSE_PROFILES='')
        compose = ['docker', 'compose', '-f', str(work / 'api/compose.yaml'), '-p', project]
        try:
            run('bash', str(ui / 'scripts/smoke/stack-up.sh'), env=env)
            run(*compose, 'exec', '-T', 'app', 'composer', 'contract:bundle')
            password = secrets.token_urlsafe(24)
            # Only this temporary stack receives the fixtures. APP_ENV is checked again in PHP.
            seed = (ROOT / 'scripts/security/zap/seed.php').read_text()
            result = run(*compose, 'exec', '-T', 'app', 'php', input=seed.replace('__PASSWORD__', password),
                         stdout=subprocess.PIPE)
            credentials = json.loads(result.stdout)
            credentials['password'] = password
            scan = work / 'scan'
            scan.mkdir(mode=0o700)
            (scan / 'credentials.json').write_text(json.dumps(credentials))
            (scan / 'credentials.json').chmod(0o600)
            shutil.copy2(work / 'api/build/contract/openapi.json', scan / 'openapi.json')
            shutil.copy2(ROOT / 'scripts/security/zap/driver.py', scan / 'driver.py')
            # Downloads/builds finish before isolating the target. Both the target and scanner
            # lose external egress, so active payloads cannot reach production or Internet hosts.
            run('docker', 'network', 'create', '--internal', network)
            for service in ['app', 'web', 'mariadb', 'redis', 'mailpit']:
                container = subprocess.check_output(compose + ['ps', '-q', service], text=True).strip()
                aliases = ['--alias', service]
                if service == 'app':
                    aliases += ['--alias', 'restv2-mailcow']
                run('docker', 'network', 'connect', *aliases, network, container)
                run('docker', 'network', 'disconnect', project + '_default', container)
            run('docker', 'network', 'connect', '--alias', 'ng-mailcow', network, project + '-ng')
            run('docker', 'network', 'disconnect', project + '_default', project + '-ng')
            run('docker', 'run', '-d', '--name', zap, '--network', network,
                '--user', f'{os.getuid()}:{os.getgid()}', '--cap-drop=ALL',
                '--security-opt=no-new-privileges',
                '-e', 'SE_OFFLINE=true', '-e', 'SE_AVOID_STATS=true',
                '-e', 'SE_CACHE_PATH=/zap/wrk/selenium',
                '-v', str(scan) + ':/zap/wrk', '-v', str(reports) + ':/reports', image,
                'java', '-Xmx1536m', '-Duser.home=/zap/wrk', '-jar', '/zap/zap-2.17.0.jar',
                '-daemon', '-dir', '/zap/wrk/state', '-host', '0.0.0.0', '-port', '8080',
                # Browser client integration is unused by the HTTP/API scans and initializes a
                # Firefox profile in the image user's home, which an arbitrary CI uid cannot write.
                '-config', 'extensions.extension(0).name=ExtensionClientIntegration',
                '-config', 'extensions.extension(0).enabled=false',
                '-config', 'api.disablekey=true', '-config', 'ascan.threadPerHost=2')
            run('docker', 'exec', zap, 'python3', '/zap/wrk/driver.py')
        finally:
            # No reports contain cookies, API keys, request bodies or response evidence.
            subprocess.run(['docker', 'rm', '-f', zap], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            subprocess.run(['bash', str(ui / 'scripts/smoke/stack-down.sh')], env=env, check=True)
            subprocess.run(['docker', 'network', 'rm', network], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


if __name__ == '__main__':
    main()
