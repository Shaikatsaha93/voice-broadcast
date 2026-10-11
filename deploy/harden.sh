#!/usr/bin/env bash
# Lock the deployed application down so that only the web user (www-data) and root can read the code,
# the .env secrets, logs and uploads. Run as root after every deploy (rsync / git pull resets permissions):
#   sudo bash deploy/harden.sh [/var/www/broadcast]
set -euo pipefail
APP="${1:-/var/www/broadcast}"
[ "$(id -u)" -eq 0 ] || { echo "Run as root"; exit 1; }
[ -f "$APP/artisan" ] || { echo "No Laravel app in $APP"; exit 1; }

# 1) ownership + permissions: nobody else (no other login user) can list or read anything
chown -R www-data:www-data "$APP"
find "$APP" -type d -exec chmod 750 {} +
find "$APP" -type f -exec chmod 640 {} +
chmod 600 "$APP/.env"
chmod 750 "$APP/artisan"

# 2) secrets files outside the app
[ -f /root/broadcast-credentials.txt ] && chmod 600 /root/broadcast-credentials.txt || true

# 3) nginx: hide the version, never serve dot files / backups / sqlite / logs, run only public/index.php
rm -f /etc/nginx/conf.d/hardening.conf
sed -i '/server_tokens/d' /etc/nginx/nginx.conf
sed -i '/^http {/a\    server_tokens off;' /etc/nginx/nginx.conf
SITE=/etc/nginx/sites-available/broadcast
if [ -f "$SITE" ]; then
  cat > "$SITE" <<'N'
server {
  listen 80 default_server;
  server_name _;
  root /var/www/broadcast/public;
  index index.php;
  client_max_body_size 60M;


  location / { try_files $uri $uri/ /index.php?$query_string; }
  location = /index.php { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.5-fpm.sock; }
  location ~ \.php$ { return 404; }                                   # no other script is ever executed
  location ~ /\.(?!well-known) { deny all; }                          # .env, .git, ...
  location ~* \.(sqlite|sql|bak|old|orig|log|ini|conf|md|json|lock|dist|stub|blade\.php)$ { deny all; }
}
N
fi
nginx -t
(nginx -s reload 2>/dev/null || systemctl reload nginx)

# 4) PHP: do not leak the version, never print errors to visitors
for d in /etc/php/*/fpm/conf.d; do
  printf 'expose_php=Off\ndisplay_errors=Off\ndisplay_startup_errors=Off\n' > "$d/99-hardening.ini"
done
for s in /etc/init.d/php*-fpm; do [ -x "$s" ] && "$s" reload >/dev/null 2>&1 || true; done

echo "Hardened $APP"
