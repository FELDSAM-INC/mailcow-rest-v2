"""Harness of scripts/legacy/generate-template-goldens.sh (feature 006 T009, research R7).

Parses the template on stdin exactly as the legacy notifier quarantine_notify.py does
(`SandboxedEnvironment().from_string(...)`), renders it with each validation dataset
(`template.render(meta=..., username=..., counter=..., hostname=..., quarantine_acl=...)`) and
converts the HTML with `html2text.html2text(html)`. It prints one JSON document: whether the
template parsed, and for each dataset whether it rendered, the exception class and template line of
a failure, and the output. `created` is a datetime, as MySQLdb hands it to the notifier.
"""

import datetime
import json
import sys
import traceback

import html2text
from jinja2.sandbox import SandboxedEnvironment

HOSTNAME = sys.argv[1]
QHASH = '0' * 64


def item(n, action, score):
    return {
        'qhash': QHASH,
        'id': n,
        'subject': 'Sample message %d' % n,
        'score': score,
        'sender': 'sender%d@example.net' % n,
        'created': datetime.datetime(2026, 1, 1, 8, 0, 0),
        'action': action,
    }


DATASETS = [
    {'meta': [item(1, 'reject', 7.5)], 'username': 'user@example.org', 'counter': 1, 'hostname': HOSTNAME, 'quarantine_acl': 0},
    {'meta': [item(1, 'reject', 7.5), item(2, 'add header', 16.2), item(3, 'rewrite subject', 9.1)], 'username': 'user@example.org', 'counter': 3, 'hostname': HOSTNAME, 'quarantine_acl': 1},
]


def template_line(exc):
    line = getattr(exc, 'lineno', None)
    for frame in traceback.extract_tb(exc.__traceback__):
        if frame.filename == '<template>':
            line = frame.lineno
    return line


def serialisable(dataset):
    return {**dataset, 'meta': [{**m, 'created': str(m['created'])} for m in dataset['meta']]}


source = sys.stdin.read()
result = {'datasets': [serialisable(d) for d in DATASETS]}
env = SandboxedEnvironment()
try:
    template = env.from_string(source)
except Exception as exc:
    result.update({'parsed': False, 'exception': type(exc).__name__, 'line': getattr(exc, 'lineno', None)})
else:
    result['parsed'] = True
    result['renders'] = []
    for dataset in DATASETS:
        try:
            html = template.render(meta=dataset['meta'], username=dataset['username'], counter=dataset['counter'], hostname=dataset['hostname'], quarantine_acl=dataset['quarantine_acl'])
        except Exception as exc:
            result['renders'].append({'rendered': False, 'exception': type(exc).__name__, 'line': template_line(exc)})
        else:
            result['renders'].append({'rendered': True, 'html': html, 'text': html2text.html2text(html)})
print(json.dumps(result, ensure_ascii=False))
