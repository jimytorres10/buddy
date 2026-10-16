#!/usr/bin/env bash
# Déploie le code local sur le serveur (nouvelle release, migrations, bascule sans coupure).
# Depuis Git Bash, à la racine du projet :
#   ./deploy/deploy.sh azureuser@IP
# Avec une clé .pem téléchargée depuis Azure :
#   SSH_KEY=~/Downloads/ma-cle.pem ./deploy/deploy.sh azureuser@IP
set -euo pipefail

TARGET="${1:?Usage: deploy.sh <utilisateur@hote>}"
RELEASE="$(date +%Y%m%d%H%M%S)"
ARCHIVE="$(mktemp -t buddy-XXXXXX).tar.gz"
SSH_OPTS=(-o StrictHostKeyChecking=accept-new)
[ -n "${SSH_KEY:-}" ] && SSH_OPTS+=(-i "$SSH_KEY")
cd "$(dirname "$0")/.."

echo "==> Archive des fichiers suivis (hors vendor, var, .env.local…)"
git ls-files -z --cached --others --exclude-standard | tar --null -T - -czf "$ARCHIVE"
scp -q "${SSH_OPTS[@]}" "$ARCHIVE" "$TARGET:/tmp/buddy-$RELEASE.tar.gz"
rm -f "$ARCHIVE"

echo "==> Installation de la release $RELEASE"
ssh "${SSH_OPTS[@]}" "$TARGET" "bash -s -- $RELEASE" <<'REMOTE'
set -euo pipefail
RELEASE="$1"
APP=/var/www/buddy
DIR="$APP/releases/$RELEASE"

mkdir -p "$DIR/var"
tar -xzf "/tmp/buddy-$RELEASE.tar.gz" -C "$DIR"
rm -f "/tmp/buddy-$RELEASE.tar.gz"
ln -s "$APP/shared/.env.local" "$DIR/.env.local"

# var/ accessible en écriture à PHP-FPM (www-data) et à l'utilisateur de déploiement
sudo setfacl -R -m u:www-data:rwX -m u:"$USER":rwX "$DIR/var"
sudo setfacl -dR -m u:www-data:rwX -m u:"$USER":rwX "$DIR/var"

cd "$DIR"
export APP_ENV=prod
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress
composer dump-env prod
php bin/console asset-map:compile
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
php bin/console cache:clear
php bin/console cache:warmup

ln -sfn "$DIR" "$APP/current.tmp" && mv -T "$APP/current.tmp" "$APP/current"
sudo systemctl reload php8.4-fpm

# Garde les 5 dernières releases
ls -1dt "$APP"/releases/* | tail -n +6 | xargs -r rm -rf
echo "Release $RELEASE en ligne."
REMOTE
