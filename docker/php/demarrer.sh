#!/bin/sh
# Demarrage en production.
#
# L'ORDRE EST DELIBERE : on ouvre le port AVANT de migrer.
#
# L'ordre naturel serait l'inverse — on ne repond pas avant que le schema soit
# a jour. Mais sur le depot voisin, cet ordre a coute un deploiement :
# l'hebergeur teste le port 16 secondes apres avoir lance le conteneur et
# n'attend qu'une seconde. Migrer contre une base distante qui se reveille
# depasse ce delai, la plateforme tue le conteneur EN PLEIN TRAVAIL et jette
# ses journaux. On ne voit alors ni erreur ni cause — le pire des echecs.
#
# On ouvre donc le port tout de suite. Si les migrations echouent, on le dit et
# on s'arrete, mais le conteneur aura vecu assez longtemps pour que ses
# journaux soient conserves.
set -e

erreur() {
    echo ""
    echo "----------------------------------------------------------------"
    echo "  CONFIGURATION INCOMPLETE — l'application ne peut pas demarrer"
    echo "----------------------------------------------------------------"
    echo ""
    echo "  $1"
    echo ""
    exit 1
}

[ -n "${APP_SECRET}" ] || erreur "APP_SECRET est vide. Produisez une chaine aleatoire de 32 octets."

# DEUX facons de decrire la base, et c'est delibere.
#
# DATABASE_URL tient tout dans une variable — pratique, et c'est ce que les
# hebergeurs proposent a copier. Mais une adresse est ANALYSEE : si le mot de
# passe contient @ : / ? # % ou &, l'analyseur coupe au premier et PHP envoie
# un mot de passe tronque. Le serveur repond « mot de passe refuse » alors que
# la valeur collee est la bonne — un echec qui accuse la mauvaise chose. Les
# variables separees ne passent par aucun analyseur, et il faut que cette
# sortie de secours existe AVANT d'en avoir besoin.
if [ -z "${DATABASE_URL}" ]; then
    if [ -n "${DB_HOST}" ]; then
        [ -n "${DB_DATABASE}" ] || erreur "DB_HOST est renseigne mais pas DB_DATABASE."
        [ -n "${DB_USERNAME}" ] || erreur "DB_HOST est renseigne mais pas DB_USERNAME."
        [ -n "${DB_PASSWORD}" ] || erreur "DB_HOST est renseigne mais pas DB_PASSWORD."

        # L'encodage est fait ICI, une seule fois, par PHP : c'est ce qui rend
        # la sortie de secours reellement sure pour un mot de passe a
        # caracteres reserves.
        UTILISATEUR_ENCODE=$(php -r 'echo rawurlencode(getenv("DB_USERNAME"));')
        MOTDEPASSE_ENCODE=$(php -r 'echo rawurlencode(getenv("DB_PASSWORD"));')

        # Sans consigne, le pilote PostgreSQL se contente de « prefer » : il
        # tente le chiffrement et s'en passe si le serveur ne le propose pas.
        # Un mot de passe peut donc partir en clair sans que rien ne le
        # signale. Sur une base jointe par l'internet, ce n'est pas acceptable
        # par defaut.
        DATABASE_URL="postgresql://${UTILISATEUR_ENCODE}:${MOTDEPASSE_ENCODE}@${DB_HOST}:${DB_PORT:-5432}/${DB_DATABASE}?serverVersion=16&charset=utf8&sslmode=${DB_SSLMODE:-require}"
        export DATABASE_URL
        echo "-> base decrite par DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD"
    else
        erreur "Aucune base de donnees n'est configuree.
  Deux facons, au choix :
  - DATABASE_URL : l'adresse complete
  - DB_HOST + DB_PORT + DB_DATABASE + DB_USERNAME + DB_PASSWORD
    A preferer si le mot de passe contient @ : / ? # % ou & — ces
    caracteres cassent l'analyse d'une adresse."
    fi
else
    echo "-> base decrite par DATABASE_URL"
fi

echo "-> prechauffage du cache"
php bin/console cache:warmup --no-interaction

php -S "0.0.0.0:${PORT}" -t public /usr/local/share/tasswiya/routeur.php &
PID_SERVEUR=$!

attente=0
while [ "${attente}" -lt 40 ]; do
    if php -r 'exit(@fsockopen("127.0.0.1", (int) getenv("PORT"), $e, $s, 1) ? 0 : 1);' 2>/dev/null; then
        break
    fi
    attente=$((attente + 1))
    sleep 0.25
done

if [ "${attente}" -ge 40 ]; then
    echo "!! pas en ecoute sur le port ${PORT} apres 10 s"
    exit 1
fi

echo "-> en ecoute sur le port ${PORT} (le controle de sante peut passer)"

echo "-> migrations"
set +e
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
code_migration=$?
set -e

if [ "${code_migration}" -ne 0 ]; then
    kill "${PID_SERVEUR}" 2>/dev/null || true
    echo "!! les migrations ont echoue — le message exact est au-dessus"
    exit 1
fi

echo "-> pret"

# Ce script est le processus n°1 du conteneur : tant qu'il attend, le conteneur
# vit ; des que le serveur s'arrete, il s'arrete aussi et l'hebergeur relance.
wait "${PID_SERVEUR}"
