#!/bin/sh
# Point d'entrée du conteneur de développement.
#
# `vendor/` vit dans un volume Docker et non sur le poste (voir
# docker-compose.yml). Un volume neuf est VIDE : sans ce script, le premier
# `docker compose up` démarrerait un conteneur qui échoue sur
# « vendor/autoload_runtime.php: No such file ». On installe donc ici, une
# seule fois, et les démarrages suivants ne coûtent rien.
set -e

if [ ! -f /app/vendor/autoload_runtime.php ]; then
    echo "→ vendor/ absent du volume : composer install (une seule fois)"
    composer install --no-interaction --prefer-dist
fi

# `var/` est également dans un volume. Il n'est pas versionné et n'existe donc
# pas au premier démarrage ; Symfony échouerait sur un cache non inscriptible.
mkdir -p /app/var/cache /app/var/log

# Attendre PostgreSQL ne sert à rien ici : `depends_on: service_healthy` l'a
# déjà fait, et le refaire en PHP masquerait une vraie panne de base derrière
# une attente.

exec "$@"
