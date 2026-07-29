#!/bin/bash
# 💬 Naš chat — pokretanje na Macu.
# Dvoklik u Finderu, ili u Terminalu: ./start-mac.command
cd "$(dirname "$0")"

if ! command -v php >/dev/null 2>&1; then
    echo "PHP nije instaliran."
    echo "1) Instaliraj Homebrew:  https://brew.sh"
    echo "2) Zatim u Terminalu:    brew install php"
    exit 1
fi

IP=$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo "IP-tvog-Maca")

echo "────────────────────────────────────────────────────"
echo "  💬 Naš chat je upaljen!"
echo ""
echo "  Na ovom Macu:  http://localhost:8080"
echo "  S mobitela:    http://$IP:8080   (ista Wi-Fi mreža)"
echo ""
echo "  Prvi put te vodi na setup za kreiranje računa."
echo "  Zaustavljanje: Ctrl+C (ili zatvori ovaj prozor)"
echo "────────────────────────────────────────────────────"

# caffeinate -i: Mac ne ide u sleep dok server radi (poklopac mora ostati otvoren)
exec caffeinate -i php -S 0.0.0.0:8080 router.php
