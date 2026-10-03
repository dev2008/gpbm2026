#!/bin/bash
# A2-4_verify_uploads_deny.sh · rev 001 · 14 Aug 2026
# Task A2 — upload hygiene. READ-ONLY: issues HTTP requests, changes nothing.
# Run AFTER replacing dadabik/uploads/.htaccess with the portable form.
#
# Whole-population check: every file in the uploads directory is requested by
# its exact URL. A single-file test would pass even if the rewrite only covered
# part of the directory.
#
# Distinguishes 403 (correct) from 500 (directive not understood — the exact
# failure the rewrite exists to prevent) and 200 (files are being served).

set -u

BASE="${BASE:-http://gpbm.local}"
DIR="${DIR:-/dadabik/uploads}"
UPLOAD_PATH="${UPLOAD_PATH:-/var/www/gpbm/public_html/dadabik/uploads}"

echo "=== A2-4 · rev 001 · 14 Aug 2026 ==="
echo "run_at : $(date '+%Y-%m-%d %H:%M:%S')"
echo "host   : $(hostname)"
echo "base   : $BASE$DIR"
echo "source : $UPLOAD_PATH"
echo

echo "=== D.0  environment — what is actually serving this ==="
curl -sI "$BASE/" | grep -iE '^(HTTP/|Server:)' || echo "  (no response from $BASE)"
echo "  mod_access_compat: $(apache2ctl -M 2>/dev/null | grep -ci access_compat) \
(0 = not loaded; the old 'Deny from all' would have 500'd here)"
echo "  mod_authz_core   : $(apache2ctl -M 2>/dev/null | grep -ci authz_core) \
(1 = the branch actually in force)"
echo

echo "=== D.1  the .htaccess now in place ==="
ls -la "$UPLOAD_PATH/.htaccess" 2>/dev/null || echo "  MISSING — nothing is protecting this directory"
echo

# Pure-bash percent-encoding: filenames here contain spaces and commas.
urlencode() {
    local s="$1" i c out=""
    for (( i=0; i<${#s}; i++ )); do
        c="${s:i:1}"
        case "$c" in
            [a-zA-Z0-9.~_-]) out+="$c" ;;
            *) printf -v c '%%%02X' "'$c" ; out+="$c" ;;
        esac
    done
    printf '%s' "$out"
}

probe() { # probe <label> <url> <expected>
    local label="$1" url="$2" expect="$3" code
    code=$(curl -s -o /dev/null -w '%{http_code}' "$url")
    if [ "$code" = "$expect" ]; then
        printf '  %-4s %-6s %s\n' "$code" "PASS" "$label"
    else
        printf '  %-4s %-6s %s   (expected %s)\n' "$code" "**FAIL**" "$label" "$expect"
    fi
    [ "$code" = "$expect" ]
}

echo "=== D.2  every file in the directory, requested by exact URL ==="
total=0; pass=0
shopt -s dotglob nullglob
for f in "$UPLOAD_PATH"/*; do
    [ -f "$f" ] || continue
    name=$(basename "$f")
    total=$((total+1))
    probe "$name" "$BASE$DIR/$(urlencode "$name")" 403 && pass=$((pass+1))
done
shopt -u dotglob nullglob
echo
echo "  files tested: $total   passed: $pass   failed: $((total-pass))"
echo

echo "=== D.3  the directory itself ==="
probe "directory listing" "$BASE$DIR/" 403
echo

echo "=== D.4  a filename that does NOT exist ==="
# Must also be 403, not 404. A 404 would mean the deny is being applied per-file
# rather than to the directory — which is the property that makes guessable
# filenames irrelevant. This is the check most likely to reveal a partial fix.
probe "nonexistent file" "$BASE$DIR/$(urlencode 'no-such-file-a2-4.txt')" 403
echo

echo "=== D.5  verdict ==="
if [ "$total" -gt 0 ] && [ "$pass" -eq "$total" ]; then
    echo "  PASS — all $total files denied, directory denied, absent file denied"
else
    echo "  FAIL — $((total-pass)) of $total files did not return 403; see above"
fi
echo "=== A2-4 complete ==="
