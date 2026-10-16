#!/usr/bin/env bash
# Prépare une VM Ubuntu 24.04 vierge pour Buddy : Nginx, PHP 8.4-FPM, MySQL 8, Composer, HTTPS.
# À lancer une seule fois depuis le poste local :
#   ssh azureuser@IP 'sudo bash -s -- buddy.mondomaine.fr moi@exemple.fr' < deploy/provision.sh
set -euo pipefail

DOMAIN="${1:?Usage: provision.sh <domaine> <email>}"
EMAIL="${2:?Usage: provision.sh <domaine> <email>}"
DEPLOY_USER="${SUDO_USER:-azureuser}"
APP_DIR=/var/www/buddy
export DEBIAN_FRONTEND=noninteractive

echo "==> Swap (les petites VM n'ont que 1 Go de RAM)"
if [ ! -f /swapfile ]; then
    fallocate -l 1G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

echo "==> Paquets"
apt-get update -q
apt-get install -yq software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update -q
apt-get install -yq nginx mysql-server acl unzip git certbot python3-certbot-nginx ufw \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-intl php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-opcache

if ! command -v composer >/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

echo "==> PHP (production)"
cat > /etc/php/8.4/fpm/conf.d/90-buddy.ini <<'INI'
memory_limit = 256M
expose_php = Off
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
realpath_cache_size = 4096K
realpath_cache_ttl = 600
INI
sed -i 's/^;\?catch_workers_output.*/catch_workers_output = yes/' /etc/php/8.4/fpm/pool.d/www.conf

echo "==> Base MySQL"
if [ ! -f "$APP_DIR/shared/.env.local" ]; then
    DB_PASS="$(openssl rand -hex 24)"
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS buddy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'buddy'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER 'buddy'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON buddy.* TO 'buddy'@'localhost';
FLUSH PRIVILEGES;
SQL
    MYSQL_VERSION="$(mysql -N -e 'SELECT VERSION()' | cut -d- -f1)"

    mkdir -p "$APP_DIR/releases" "$APP_DIR/shared"
    cat > "$APP_DIR/shared/.env.local" <<ENV
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$(openssl rand -hex 32)
DEFAULT_URI=https://${DOMAIN}
DATABASE_URL="mysql://buddy:${DB_PASS}@127.0.0.1:3306/buddy?serverVersion=${MYSQL_VERSION}&charset=utf8mb4"
ENV
    chmod 640 "$APP_DIR/shared/.env.local"
fi
chown -R "$DEPLOY_USER":www-data "$APP_DIR"

echo "==> Nginx"
cat > /etc/nginx/sites-available/buddy <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${APP_DIR}/current/public;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    location /assets/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files \$uri =404;
    }

    gzip on;
    gzip_types text/css application/javascript image/svg+xml application/json;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
}
NGINX
ln -sf /etc/nginx/sites-available/buddy /etc/nginx/sites-enabled/buddy
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx
systemctl restart php8.4-fpm

echo "==> Pare-feu"
ufw allow OpenSSH >/dev/null
ufw allow 'Nginx Full' >/dev/null
ufw --force enable >/dev/null

echo "==> HTTPS (Let's Encrypt)"
if certbot --nginx --non-interactive --agree-tos --redirect -m "$EMAIL" -d "$DOMAIN"; then
    echo "HTTPS actif pour $DOMAIN"
else
    echo "!! Certificat non obtenu : vérifie que le DNS de $DOMAIN pointe vers cette VM, puis relance :"
    echo "   sudo certbot --nginx --redirect -m $EMAIL -d $DOMAIN"
fi

echo "==> Serveur prêt. Lance maintenant deploy/deploy.sh depuis ton poste."
