#!/bin/bash
# Installs the DMZ Steam processor as a systemd service so it starts on boot
# and restarts by itself if it crashes or the broker is down.
# Run WITHOUT sudo (it asks for sudo when it needs it):
#   bash ~/SteamTrophies/DMZ/install-service.sh
set -e

SERVICE="steamProcessor"
DIR="$(cd "$(dirname "$0")" && pwd)"     # the DMZ folder this script lives in
RUN_AS="${SUDO_USER:-$(whoami)}"         # the normal user, not root
PHP="$(command -v php)"

if [ "$RUN_AS" = "root" ]; then
    echo "Run this as your normal user, not root:  bash $DIR/install-service.sh"
    exit 1
fi
if [ -z "$PHP" ]; then
    echo "php not found. Install it first: sudo apt install php-cli"
    exit 1
fi
if [ ! -f "$DIR/steamProcessor.php" ] || [ ! -f "$DIR/steamConfig.php" ]; then
    echo "steamProcessor.php or steamConfig.php is missing in $DIR"
    exit 1
fi

# stop any copy that was started by hand, so only the service runs
pkill -f "php.*steamProcessor.php" || true

sudo tee /etc/systemd/system/$SERVICE.service > /dev/null <<UNIT
[Unit]
Description=SteamTrophies DMZ Steam processor
After=network-online.target tailscaled.service
Wants=network-online.target
StartLimitIntervalSec=0

[Service]
Type=simple
User=$RUN_AS
WorkingDirectory=$DIR
ExecStart=$PHP $DIR/steamProcessor.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable --now $SERVICE
sleep 2
systemctl --no-pager status $SERVICE | head -12
echo
echo "Logs:     journalctl -u $SERVICE -f"
echo "Restart:  sudo systemctl restart $SERVICE"
echo "Stop:     sudo systemctl stop $SERVICE"
