#!/bin/bash
# Weekly renewal of the Tailscale HTTPS certificate for the chat (valid 90 days).
# The HTTPS proxy watches the tls/ folder and reloads the new cert automatically.
TS=$(command -v tailscale)
[ -z "$TS" ] && TS="/Applications/Tailscale.app/Contents/MacOS/Tailscale"
HOST=$("$TS" status --json | /usr/bin/python3 -c 'import json,sys; print(json.load(sys.stdin)["Self"]["DNSName"].rstrip("."))')
[ -z "$HOST" ] && exit 1
cd "$(dirname "$0")/tls" || exit 1
"$TS" cert "$HOST" && echo "$(date): cert renewed for $HOST" >> /tmp/chat-cert-renew.log
