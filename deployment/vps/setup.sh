#!/usr/bin/env bash
# Run as root on Debian 12 after installing nginx, PHP 8.2, MariaDB and Composer.
set -Eeuo pipefail
test "$(id -u)" = 0
base=/var/www/back
if ! id deploy >/dev/null 2>&1; then
    adduser --disabled-password --gecos 'Salon deployment' deploy
fi
usermod -aG www-data deploy
install -d -o deploy -g www-data -m 2750 "$base" "$base/releases" "$base/incoming" "$base/shared"
install -d -o deploy -g www-data -m 2770 "$base/shared/storage" \
    "$base/shared/storage/app" "$base/shared/storage/app/private" \
    "$base/shared/storage/framework" "$base/shared/storage/framework/cache" \
    "$base/shared/storage/framework/cache/data" "$base/shared/storage/framework/views" \
    "$base/shared/storage/framework/sessions" "$base/shared/storage/logs"
install -d -o deploy -g deploy -m 700 /home/deploy/.ssh
printf 'restrict ' > /home/deploy/.ssh/authorized_keys
cat /root/salon-deploy-key.pub >> /home/deploy/.ssh/authorized_keys
chown deploy:deploy /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys
install -o root -g root -m 755 /root/salon-vps/deploy.sh /usr/local/bin/salon-deploy
install -o root -g root -m 750 /root/salon-vps/backup.sh /usr/local/sbin/salon-backup
printf '%s\n' 'deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.2-fpm, /usr/local/sbin/salon-backup' > /etc/sudoers.d/salon-deploy
chmod 440 /etc/sudoers.d/salon-deploy
visudo -cf /etc/sudoers.d/salon-deploy

python3 - <<'PY'
import base64, os, secrets, subprocess
from pathlib import Path
p = Path('/var/www/back/shared/.env')
if not p.exists():
    password = secrets.token_hex(32)
    sql = "CREATE DATABASE IF NOT EXISTS salon_danse CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
    sql += f"CREATE USER IF NOT EXISTS 'salon_app'@'127.0.0.1' IDENTIFIED BY '{password}';\n"
    sql += "GRANT SELECT, INSERT, UPDATE, DELETE ON salon_danse.* TO 'salon_app'@'127.0.0.1';\n"
    subprocess.run(['mariadb'], input=sql, text=True, check=True)
    p.write_text('\n'.join([
        'APP_NAME="Salon de la Danse"', 'APP_ENV=production',
        'APP_KEY=base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
        'APP_DEBUG=false', 'APP_URL=https://vps123924.serveur-vps.net',
        'APP_LOCALE=fr', 'APP_FALLBACK_LOCALE=en',
        'LOG_CHANNEL=daily', 'LOG_LEVEL=warning', 'LOG_DAILY_DAYS=14',
        'DB_CONNECTION=mariadb', 'DB_HOST=127.0.0.1', 'DB_PORT=3306',
        'DB_DATABASE=salon_danse', 'DB_USERNAME=salon_app', 'DB_PASSWORD=' + password,
        'SESSION_DRIVER=array', 'CACHE_STORE=file', 'QUEUE_CONNECTION=sync',
        'FILESYSTEM_DISK=local', 'MAIL_MAILER=log',
        'FRONTEND_URL=http://localhost:5173', ''
    ]))
    os.chmod(p, 0o640)
PY
chown deploy:www-data "$base/shared/.env"

cat > /etc/php/8.2/fpm/conf.d/99-salon.ini <<'EOF'
expose_php=Off
display_errors=Off
log_errors=On
upload_max_filesize=3M
post_max_size=4M
memory_limit=256M
opcache.enable=1
EOF
install -d -m 755 /var/www/acme
cat > /etc/nginx/sites-available/salon <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name vps123924.serveur-vps.net;
    root /var/www/back/current/public;
    index index.php;
    client_max_body_size 4m;
    location ^~ /.well-known/acme-challenge/ { root /var/www/acme; }
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_param HTTP_PROXY "";
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
    location ~ \.php$ { return 404; }
    location ~ /\.(?!well-known).* { deny all; }
}
EOF
ln -sfn /etc/nginx/sites-available/salon /etc/nginx/sites-enabled/salon
nginx -t
systemctl reload nginx
systemctl reload php8.2-fpm
systemctl enable nginx php8.2-fpm mariadb
certbot --nginx --non-interactive --agree-tos --register-unsafely-without-email \
    --redirect -d vps123924.serveur-vps.net

cat > /etc/systemd/system/salon-backup.service <<'EOF'
[Unit]
Description=Backup Salon database and private storage
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/salon-backup
EOF
cat > /etc/systemd/system/salon-backup.timer <<'EOF'
[Unit]
Description=Daily Salon backup
[Timer]
OnCalendar=*-*-* 03:00:00
Persistent=true
[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now salon-backup.timer
echo 'VPS setup complete'
