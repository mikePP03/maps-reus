#!/usr/bin/env bash
# Despliegue del backend en Hostinger (hosting compartido / Cloud con SSH).
# Uso, desde el servidor:  bash ~/domains/backend.jumechi-soft.com/maps-reus/backend/deploy/deploy.sh
# Es seguro repetirlo: cada vez actualiza el código y las cachés.
set -euo pipefail

# El repo es la carpeta dos niveles por encima de este script (backend/deploy/deploy.sh).
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
APP_DIR="$REPO_DIR/backend"
PHP="${PHP:-php}"

cd "$REPO_DIR"
git pull --ff-only

cd "$APP_DIR"
composer install --no-dev --optimize-autoloader --no-interaction

if [ ! -f .env ]; then
  cp deploy/hostinger.env.example .env
  "$PHP" artisan key:generate --force
  echo "Creado .env: revisa APP_URL en $APP_DIR/.env"
fi

if [ ! -f database/database.sqlite ]; then
  echo "Falta database/database.sqlite: súbelo antes (ver deploy/HOSTINGER.md)." >&2
  exit 1
fi

"$PHP" artisan migrate --force
"$PHP" artisan optimize:clear
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

echo "Listo. Prueba: curl -s \$(grep ^APP_URL .env | cut -d= -f2)/api/v1/plana/feed"
