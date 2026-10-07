# Image PHP de DÉVELOPPEMENT de Tasswiya.
#
# Pourquoi une image et pas le PHP du poste : il n'y a ni PHP, ni Composer, ni
# CLI Symfony sur cette machine, et le binaire PHP officiel n'est pas signé —
# Smart App Control (Windows 11) le refuse, et le désactiver ne se défait pas
# sans réinstaller Windows. Docker règle la question et rapproche le
# développement de la production.
#
# Version ÉPINGLÉE : ce projet publiera des mesures de durée et de mémoire, et
# une pile qui bouge sous les mesures les invalide.
FROM php:8.3.26-cli-alpine

# Deux familles de paquets, et c'est tout l'enjeu de ce fichier :
#
#   - les bibliothèques d'EXÉCUTION (libpq, icu-libs, libzip), que les
#     extensions chargent à chaque démarrage de PHP. Elles restent ;
#   - les en-têtes de COMPILATION (-dev), qui ne servent qu'à construire les
#     extensions. Elles partent, regroupées sous un nom virtuel.
#
# Supprimer « postgresql-dev » sans avoir installé « libpq » produit une image
# qui se construit SANS ERREUR et dont pdo_pgsql refuse de se charger. Doctrine
# répond alors « could not find driver » — un message qui ne désigne pas la
# cause. Le piège a déjà été payé une fois sur le dépôt voisin.
RUN apk add --no-cache libpq icu-libs libzip \
    && apk add --no-cache --virtual .build-deps \
        postgresql-dev icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip opcache \
    && apk del .build-deps

COPY docker/php/php.ini /usr/local/etc/php/conf.d/tasswiya.ini

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Le bit d'exécution est posé ICI et non hérité du poste : Git sous Windows ne
# conserve pas les permissions, et un script copié sans +x fait échouer le
# démarrage sur « permission denied ».
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/php/routeur.php /usr/local/share/tasswiya/routeur.php
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /app

# 0.0.0.0 et non 127.0.0.1 : sans cela le serveur n'écouterait que la boucle
# locale DU CONTENEUR et resterait injoignable depuis Windows.
#
# Le routeur est indispensable. `php -S -t public` sert les fichiers du dossier
# et rend 404 sur « / » : index.php de Symfony n'est pas un script de routage,
# il ne sait pas rendre « false » pour laisser passer un fichier statique.
EXPOSE 8000
ENTRYPOINT ["entrypoint"]
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "/usr/local/share/tasswiya/routeur.php"]
