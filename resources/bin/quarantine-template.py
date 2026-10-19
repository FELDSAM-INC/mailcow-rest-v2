"""Checks and renders a quarantine notification template as the legacy notifier does (feature 006
research R7, FR-070, FR-071).

Reads one JSON document on stdin, `{"template": ..., "datasets": [{"meta", "username", "counter",
"hostname", "quarantine_acl"}, ...]}`, and prints one JSON document: `{"ok": true, "renders":
[{"html", "text"}, ...]}` (one per dataset), or `{"ok": false, "reason", "line", "message"}` for
the first failure. It makes exactly the calls of quarantine_notify.py:
`SandboxedEnvironment().from_string(template)`, `template.render(meta=..., username=...,
counter=..., hostname=..., quarantine_acl=...)` and `html2text.html2text(html)`. `created` of each
item becomes a datetime, as MySQLdb hands it to the notifier.

Reasons: `syntax` (TemplateSyntaxError, with its line), `security` (the sandbox's SecurityError),
`render` (any other exception; the notifier crashes on some of them). The caller bounds the run in
time and output and starts it as `python3 -I -B` with an empty environment.
"""

import datetime
import json
import sys
import traceback

import html2text
from jinja2.exceptions import SecurityError, TemplateSyntaxError
from jinja2.sandbox import SandboxedEnvironment


def template_line(exc):
    line = getattr(exc, 'lineno', None) if isinstance(exc, TemplateSyntaxError) else None
    for frame in traceback.extract_tb(exc.__traceback__):
        if frame.filename == '<template>':
            line = frame.lineno
    return line


def failure(exc):
    if isinstance(exc, TemplateSyntaxError):
        reason = 'syntax'
    elif isinstance(exc, SecurityError):
        reason = 'security'
    else:
        reason = 'render'
    return {'ok': False, 'reason': reason, 'line': template_line(exc), 'message': ('%s: %s' % (type(exc).__name__, exc))[:500]}


def item(raw):
    created = raw.get('created')
    if isinstance(created, str):
        raw = dict(raw, created=datetime.datetime.strptime(created, '%Y-%m-%d %H:%M:%S'))
    return raw


def run(request):
    try:
        template = SandboxedEnvironment().from_string(request['template'])
    except Exception as exc:
        return failure(exc)
    renders = []
    for dataset in request['datasets']:
        try:
            html = template.render(
                meta=[item(m) for m in dataset['meta']],
                username=dataset['username'],
                counter=dataset['counter'],
                hostname=dataset['hostname'],
                quarantine_acl=dataset['quarantine_acl'],
            )
            text = html2text.html2text(html)
        except Exception as exc:
            return failure(exc)
        renders.append({'html': html, 'text': text})
    return {'ok': True, 'renders': renders}


result = run(json.loads(sys.stdin.buffer.read().decode('utf-8')))
sys.stdout.buffer.write(json.dumps(result).encode('ascii'))
