#!/bin/bash
# h26-config-drift-check.sh -- rev 002 -- 3 Oct 2026 -- role: gate -- authority: derived
# rev 002 (item H41): $dadabik_session_name added to the sanctioned list. The two hosts SHOULD use
#   different session names (Alan, 3 Oct 2026); rev 001 failed on that difference.
#
# Proves config_custom.php.local and config_custom.php.server differ in ONLY the four
# ways H26 sanctioned. Any other difference is a defect. Run before every deployment.
# Prints a row whether it passes or fails -- a silent run is a run that did not happen.

INC="${1:-/var/www/gpbm/public_html/dadabik/include}"
LOCAL="$INC/config_custom.php.local"
SERVER="$INC/config_custom.php.server"

ALLOWED='^\$(host|db_name|user|pass|secret_key|files_url|images_url|debug_mode|dadabik_session_name)$'

for f in "$LOCAL" "$SERVER"; do
    [ -r "$f" ] || { echo "FAIL: cannot read $f"; exit 2; }
done

extract () {  # setting=value, comments and blank lines stripped, sorted
    grep -oE "^\\\$[A-Za-z_]+(\[[^]]+\])*[[:space:]]*=[[:space:]]*[^;]+;" "$1" \
      | sed -E 's/[[:space:]]*=[[:space:]]*/=/; s/;$//' | sort
}

extract "$LOCAL"  > /tmp/h26-local.kv
extract "$SERVER" > /tmp/h26-server.kv

echo "settings in local:  $(wc -l < /tmp/h26-local.kv)"
echo "settings in server: $(wc -l < /tmp/h26-server.kv)"
echo

# 1. same set of setting NAMES
comm -3 <(cut -d= -f1 /tmp/h26-local.kv | sort -u) \
        <(cut -d= -f1 /tmp/h26-server.kv | sort -u) > /tmp/h26-names.diff
if [ -s /tmp/h26-names.diff ]; then
    echo "FAIL: the two files do not define the same settings:"
    cat /tmp/h26-names.diff
    RC=1
else
    echo "PASS: both files define the same set of settings"
    RC=0
fi

# 2. values differ only where sanctioned
UNEXPECTED=0
while IFS= read -r name; do
    if ! [[ "$name" =~ $ALLOWED ]]; then
        echo "FAIL: unsanctioned difference in $name"
        grep "^$(printf '%s' "$name" | sed 's/[][\\.*^$]/\\&/g')=" /tmp/h26-local.kv  | sed 's/^/    local:  /'
        grep "^$(printf '%s' "$name" | sed 's/[][\\.*^$]/\\&/g')=" /tmp/h26-server.kv | sed 's/^/    server: /'
        UNEXPECTED=1
    fi
done < <(comm -3 /tmp/h26-local.kv /tmp/h26-server.kv | sed 's/^\t//' | cut -d= -f1 | sort -u)

if [ "$UNEXPECTED" -eq 0 ]; then
    echo "PASS: all differences are within the sanctioned list"
else
    RC=1
fi

# 3. the one that must be right in production
grep -qE '^\$debug_mode[[:space:]]*=[[:space:]]*0;' "$SERVER" \
  && echo "PASS: \$debug_mode = 0 in server config" \
  || { echo "FAIL: \$debug_mode is not 0 in server config"; RC=1; }

echo
[ "$RC" -eq 0 ] && echo "RESULT: PASS" || echo "RESULT: FAIL"
exit $RC
