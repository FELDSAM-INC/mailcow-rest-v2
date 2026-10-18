"""Compiles ban rules in Python's `re`, the dialect of mailcow's ban service (feature 011 R20).

Reads a JSON list of patterns on stdin and prints `[{"ok", "groups", "error"}, ...]` in the same
order. Run as `python3 -I -S -B resources/bin/validate-python-regex.py` (no site packages, no
bytecode files: the API's root file system is read only).
"""

import json
import re
import sys


def check(pattern):
    try:
        compiled = re.compile(pattern)
    except (re.error, TypeError, ValueError, OverflowError, RecursionError) as error:
        return {"ok": False, "groups": 0, "error": str(error)}
    return {"ok": True, "groups": compiled.groups, "error": None}


patterns = json.load(sys.stdin)
if not isinstance(patterns, list):
    sys.exit(2)
json.dump([check(p) if isinstance(p, str) else {"ok": False, "groups": 0, "error": "not a string"} for p in patterns], sys.stdout)
