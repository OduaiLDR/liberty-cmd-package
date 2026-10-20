#!/usr/bin/env bash
# One-shot completion check for the already-started read-only safety capture.
# No Azure API, SQL connection, restore or apply operation is performed here.
set -eu
umask 0077

capture_pid=1357115
capture_script=/home/ubuntu/sms-recovery-snapshot-20261019.php
verifier=/home/ubuntu/sms-recovery-snapshot-v2-20261019.php
directory=/home/ubuntu/sms-recovery-current-20261019T2050Z

# Bounded wait; no signal is sent to the capture. A reused PID must not prolong this.
deadline=$(( $(date +%s) + 3600 ))
while [ -r "/proc/$capture_pid/cmdline" ] && tr '\0' '\n' < "/proc/$capture_pid/cmdline" | grep -Fxq "$capture_script"; do
    if [ "$(date +%s)" -ge "$deadline" ]; then
        echo 'Capture did not finish within the verification window. No correction was made.'
        exit 1
    fi
    sleep 10
done

if [ ! -f "$directory/manifest.json" ]; then
    echo 'Capture stopped without a completed manifest. Evidence is incomplete; do not use it.'
    exit 1
fi

if [ -e "$directory/verification.json" ] || [ -e "$directory/verification.json.partial" ]; then
    echo 'Refusing to overwrite a verification report.'
    exit 1
fi

set -C
php "$verifier" --verify="$directory" > "$directory/verification.json.partial"
ln "$directory/verification.json.partial" "$directory/verification.json"
echo 'Current safety capture passed strict gzip, hash, required-file and row-count checks. No production correction was made.'
