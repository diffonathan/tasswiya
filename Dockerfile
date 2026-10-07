# Image de PRODUCTION de Tasswiya.
#
# ATTENTION : elle est A LA RACINE, et il faut qu'elle y reste.
#
# Elle serait plus propre dans `docker/php/`, mais un hebergeur qui construit
# depuis un depot cherche un `Dockerfile` a la racine, et s'il n'en trouve pas
# il DEVINE la nature du projet. Sur le depot voisin, Back4App a vu un
# `package.json`, conclu « application Node » et construit une image sans PHP :
# le deploiement est mort sur « node: command not found », et le message ne
# designait pas la cause.
#
# Le Dockerfile de DEVELOPPEMENT est dans `docker/php/`, ou seul
# `docker-compose.yml` va le chercher en le nommant explicitement. Aucune
# confusion possible entre les deux.
#
# Differences avec le developpement, toutes voulues :
#   - le code est COPIE dans l'image, pas monte : une image de production doit
#     tourner sans le depot ;
#   - les dependances sont installees sans celles de developpement, et
#     l'autochargement est optimise ;
#   - opcache ne revalide plus les horodatages ;
#   - `memory_limit` est a 128 Mo : la cible plafonne a 512 Mo pour PHP ET
#     PostgreSQL reunis (contrainte n° 2 du cahier), et un projet de la liste
#     n'a deja pas pu etre mis en ligne pour avoir ignore ce plafond.

# ---------------------------------------------------------------------------
# Etape 1 — les dependances PHP
# ---------------------------------------------------------------------------
FROM composer:2.8 AS dependances

WORKDIR /build

# Les manifestes d'abord : tant qu'ils ne changent pas, Docker reutilise la
# couche d'installation. Copier tout le projet d'emblee reinstallerait les
# dependances a chaque modification d'un controleur.
COPY composer.json composer.lock ./

# `--no-scripts` : les scripts appellent `bin/console`, qui a besoin du code
# applicatif — absent a ce stade.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# ---------------------------------------------------------------------------
# Etape 2 — l'image servie
# ---------------------------------------------------------------------------
FROM php:8.3.26-cli-alpine

# Bibliotheques d'EXECUTION d'abord, en-tetes de compilation dans un groupe
# supprime seul. L'inverse produit une image qui se construit sans erreur et
# dont pdo_pgsql refuse de se charger ; Doctrine repond alors « could not find
# driver », un message qui ne designe pas la cause.
RUN apk add --no-cache libpq icu-libs libzip \
    && apk add --no-cache --virtual .build-deps \
        postgresql-dev icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip opcache \
    && apk del .build-deps

COPY docker/php/php.production.ini /usr/local/etc/php/conf.d/tasswiya.ini
COPY docker/php/routeur.php /usr/local/share/tasswiya/routeur.php

WORKDIR /app

COPY . .
COPY --from=dependances /build/vendor ./vendor

RUN mkdir -p var/cache var/log && chmod -R 775 var

COPY docker/php/demarrer.sh /usr/local/bin/demarrer
RUN chmod +x /usr/local/bin/demarrer

# Tout ce qui n'est PAS secret est pose ici. La raison est pratique : chaque
# variable a saisir a la main dans l'interface d'un hebergeur est une occasion
# de se tromper, et une faute de frappe ne se voit qu'a l'execution. Il ne
# reste a saisir que ce qui ne peut pas vivre dans une image publique :
# APP_SECRET et DATABASE_URL.
#
# PHP_CLI_SERVER_WORKERS : le serveur integre traite UNE requete a la fois.
# Sans ouvriers, le CSS et l'icone attendent chacun leur tour derriere la page.
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    MESSENGER_TRANSPORT_DSN="doctrine://default?auto_setup=1" \
    PHP_CLI_SERVER_WORKERS=4

# L'hebergeur impose le port par une variable et ne le connait pas a l'avance :
# l'ecrire en dur rendrait le service injoignable.
ENV PORT=8080
EXPOSE 8080

CMD ["demarrer"]
