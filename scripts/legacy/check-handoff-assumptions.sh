#!/usr/bin/env bash
#
# Checks the three legacy guards that make the webmail hand-off of feature 005 grant webmail and
# nothing else (research R3), for an upstream mailcow-dockerized tag:
#
#   1. data/web/json_api.php refuses `add`, `edit` and `delete` when
#      $_SESSION['mailcow_cc_api_access'] == 'ro' (the hand-off sets it), so /api/v1 never writes;
#   2. protect_route() in data/web/inc/functions.inc.php requires $_SESSION['mailcow_cc_role']
#      (the hand-off never sets it), so the legacy UI shows its sign-in page;
#   3. data/web/sogo-auth.php grants webmail to a session whose mailcow_cc_username is listed in
#      sogo-sso-user-allowed, without looking at mailcow_cc_role.
#
# Prints "ok" when all hold and fails otherwise. CI runs it for every supported release.
#
#   scripts/legacy/check-handoff-assumptions.sh 2026-09a
#
# MAILCOW_REPO is a mailcow-dockerized checkout (default ./source_code). Only tags are accepted.

set -euo pipefail

TAG="${1:-}"
if [[ -z "$TAG" ]]; then
    echo "usage: $0 <upstream tag>" >&2
    exit 64
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MAILCOW_REPO="${MAILCOW_REPO:-$ROOT/source_code}"

if ! git -C "$MAILCOW_REPO" rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    echo "error: '$TAG' is not a tag of $MAILCOW_REPO (branches and commits are refused)" >&2
    exit 65
fi

show() {
    git -C "$MAILCOW_REPO" show "$TAG:$1" | tr -d '\r'
}

fail() {
    echo "error ($TAG): $1" >&2
    exit 1
}

JSON_API="$(show data/web/json_api.php)"
FUNCTIONS="$(show data/web/inc/functions.inc.php)"
SOGO_AUTH="$(show data/web/sogo-auth.php)"

# 1. Each of the write actions starts with the read-only check.
for action in add delete edit; do
    if ! awk -v action="$action" '
        $0 ~ "case \"" action "\":" { inside = 1; lines = 0; next }
        inside { lines++; if ($0 ~ /\$_SESSION\[.mailcow_cc_api_access.\] == .ro./) { found = 1; exit } if (lines > 3) exit }
        END { exit found ? 0 : 1 }
    ' <<<"$JSON_API"; then
        fail "json_api.php does not refuse '$action' for mailcow_cc_api_access == 'ro'"
    fi
done

# 2. protect_route() refuses a session without a role.
if ! awk '
    /^function protect_route\(/ { inside = 1; lines = 0; next }
    inside { lines++; if ($0 ~ /if \(!isset\(\$_SESSION\[.mailcow_cc_role.\]\)\)/) { found = 1; exit } if (lines > 5) exit }
    END { exit found ? 0 : 1 }
' <<<"$FUNCTIONS"; then
    fail "protect_route() does not require mailcow_cc_role"
fi

# 3. The auth_request branch of sogo-auth.php grants on the allowed list and ignores the role.
BRANCH="$(awk '
    /strcasecmp\(substr\(\$_SERVER\[.HTTP_X_ORIGINAL_URI.\], 0, 9\), "\/SOGo\/so\/"\) === 0/ { inside = 1 }
    inside { print }
    inside && /^}/ { exit }
' <<<"$SOGO_AUTH")"

if [[ -z "$BRANCH" ]]; then
    fail "sogo-auth.php has no auth_request branch for /SOGo/so/"
fi
if ! grep -q "in_array(\$email, \$_SESSION\[\$session_var_user_allowed\])" <<<"$BRANCH"; then
    fail "sogo-auth.php does not grant on sogo-sso-user-allowed"
fi
if ! grep -q "\$session_var_user_allowed = 'sogo-sso-user-allowed';" <<<"$SOGO_AUTH"; then
    fail "sogo-auth.php does not use the session key sogo-sso-user-allowed"
fi
if grep -q "mailcow_cc_role" <<<"$BRANCH"; then
    fail "the auth_request branch of sogo-auth.php checks mailcow_cc_role"
fi

echo ok
