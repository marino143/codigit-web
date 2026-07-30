#!/bin/bash
# 💬 Naš chat — pokretanje na Macu.
# Dvoklik u Finderu, ili u Terminalu: ./start-mac.command
cd "$(dirname "$0")"

if ! command -v php >/dev/null 2>&1; then
    echo "PHP is not installed."
    echo "1) Install Homebrew:  https://brew.sh"
    echo "2) Then in Terminal:  brew install php"
    exit 1
fi

IP=$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo "your-Macs-IP")

echo "────────────────────────────────────────────────────"
echo "  💬 Our Chat is running!"
echo ""
echo "  On this Mac:   http://localhost:8080"
echo "  From phones:   http://$IP:8080   (same Wi-Fi network)"
echo ""
echo "  The first visit takes you to setup to create an account."
echo "  Stop: Ctrl+C (or close this window)"
echo "────────────────────────────────────────────────────"

# caffeinate -i: Mac ne ide u sleep dok server radi (poklopac mora ostati otvoren)
exec caffeinate -i php -S 0.0.0.0:8080 router.php
