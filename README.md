# Tasswiya — suivi des délais de régularisation des chèques impayés (Maroc)

> **Ceci n'est pas un conseil juridique.** Tasswiya est une démonstration
> technique. Les données qu'il manipule sont fictives et aucun montant affiché
> n'est opposable. Pour une situation réelle, il faut un avocat.

## Le problème

Un chèque revient impayé, et plusieurs délais se mettent à courir en même
temps : la présentation au paiement, la pénalité bancaire, la régularisation
pénale, la faculté d'émettre, l'interdiction bancaire. Créanciers, débiteurs et
avocats les comptent sur un carnet, et une date ratée renvoie tout le monde au
pénal que la réforme de 2026 voulait justement éviter.

Tasswiya tient ces horloges à jour, dit laquelle court, et refuse une action
quand une règle de droit s'y oppose — en nommant la règle et son article.

## Ce que la loi n° 71.24 change

La loi n° 71.24 modifiant le Code de commerce (loi 15-95) a été publiée au
**Bulletin officiel n° 7478 du 29 janvier 2026** (édition générale, arabe,
pages 838 à 842) et s'applique depuis cette date : le texte ne comporte aucune
clause d'entrée en vigueur différée pour le chèque.

Elle remplace la réponse pénale automatique par une **procédure de
régularisation chronométrée** :

- une mise en demeure (**إعذار**), qui prend la forme d'un **interrogatoire par
  un officier de police judiciaire** sur instruction du parquet, doit
  **précéder** toute poursuite (art. 325 al. 6 et 7 nouveaux) ;
- à compter de la date de cet إعذار — et non de la présentation, du rejet, de
  la plainte ni de l'injonction bancaire — l'émetteur a **trente jours** pour
  régulariser (art. 325 al. 6) ;
- le délai peut être **prolongé** sur **décision du ministère public** ET avec
  l'**accord du bénéficiaire**, pour une durée « égale ou supérieure » au délai
  initial (art. 325 al. 8) ;
- pendant tout ce temps, un **contrôle judiciaire est obligatoire** : les
  trente jours ne sont pas un délai de grâce (art. 325 al. 7 et 8).

> **Seule la version arabe fait foi.** Il n'existe aucune traduction française
> officielle de la loi n° 71.24 : la série française des bulletins passe du
> n° 7474 au n° 7480. Tout énoncé français de ce dépôt est une traduction de
> travail, non opposable.

Le détail des règles, leurs sources, les contradictions arbitrées et le **degré
de certitude** de chaque point sont dans [`LOI.md`](LOI.md). Les pièces
primaires sont dans `docs/sources/`, pour que les commandes de ce document
soient rejouables.

### Ce que ce dépôt n'affirme pas

Trois points du dossier sont **incertains** et doivent le rester à l'écran :
la nature des jours et le traitement des bornes (la loi est muette), l'ancien
barème de l'article 314 (l'édition officielle et la doctrine courante
divergent), et le sort du dossier quand le bénéficiaire est injoignable,
décédé, pluriel ou refuse abusivement la prolongation (angle mort complet du
texte). En cas de doute, le produit **alerte** ; il ne conclut pas.

## Comment lancer

Il n'y a **ni PHP, ni Composer, ni CLI Symfony** sur le poste de développement :
tout passe par Docker.

```sh
docker compose up -d
```

Le premier démarrage installe les dépendances dans le conteneur (une à deux
minutes, selon le réseau) ; les suivants sont immédiats. Suivre l'avancement :

```sh
docker compose logs -f php
```

Puis l'application répond sur **<http://localhost:8001>**.

Toutes les commandes se lancent **dans** le conteneur :

```sh
docker compose exec php bin/console about
docker compose exec php bin/console lint:container
docker compose exec php bin/console doctrine:migrations:migrate
docker compose exec php bin/console workflow:dump dossier_penal
docker compose exec php vendor/bin/phpunit
```

La base de développement est aussi joignable depuis le poste, sur
`127.0.0.1:5434` (utilisateur, mot de passe et base : `tasswiya`).

## La pile, et pourquoi ces versions

| Composant | Version | Épinglée dans |
|---|---|---|
| PHP | 8.3.26 | `docker/php/Dockerfile`, `Dockerfile` |
| Symfony | 7.2.9 | `composer.json` (tous les composants en `7.2.*`) |
| PostgreSQL | 16.11 | `docker-compose.yml` |

Les versions sont épinglées à la révision près, et les composants Symfony le
sont **un par un**. Sans cela, `symfony/framework-bundle: 7.2.*` laisse flotter
`symfony/http-kernel` et compagnie vers la dernière branche disponible : le
premier essai a servi une page en 7.4.20 alors que `composer.json` demandait
7.2. Ce projet publiera des mesures, et une pile qui bouge sous les mesures les
invalide.

À savoir : Symfony 7.2 est **hors maintenance** (`bin/console about` l'imprime
en clair). C'est la version demandée par le cahier des charges du projet ; une
mise à niveau vers une branche supportée est un choix à faire explicitement,
pas à subir par flottement de contrainte.

Pour vérifier qu'aucun composant n'a dérivé :

```sh
docker compose exec php composer show \
  | grep -E "^symfony/" \
  | awk '$2 ~ /^7\./ && $2 !~ /^7\.2\./ {print $1" "$2}'
```

Une sortie vide signifie que tout est en 7.2.

## Les pièges déjà payés, et pourquoi le dépôt est fait ainsi

Ce sont des erreurs commises une fois, ici ou sur un dépôt voisin de la même
machine. Les commentaires du code les répètent sur place ; cette liste sert à
ne pas les redécouvrir.

- **`vendor/` n'est pas monté depuis le poste, il vit dans un volume Docker.**
  Chaque requête ouvre plus de mille fichiers de `vendor/` et le pont de
  fichiers Windows → Linux les paie un par un. Sur le dépôt voisin, l'amorçage
  seul mettait 4 s contre 0,23 s une fois `vendor/` dans un volume.
  **Contrepartie assumée :** `vendor/` n'existe pas sur le poste, donc
  l'éditeur n'y trouve pas les sources de Symfony pour l'autocomplétion.
  `var/` est dans un volume pour la même raison, en pire : le conteneur de
  services s'écrit en milliers de fichiers.
- **`opcache.enable_cli = 1` est indispensable.** `php -S` est le serveur
  intégré, lancé par le SAPI **CLI**, où opcache est désactivé par défaut. Sans
  cette ligne, tout `docker/php/php.ini` se lit correctement dans `phpinfo()`
  et n'a aucun effet.
- **Un routeur est nécessaire au serveur intégré.** `php -S -t public` rend 404
  sur `/`, et passer `public/index.php` en script de routage lui fait répondre
  aussi aux requêtes de CSS et d'images, parce qu'il ne retourne jamais
  `false`. D'où `docker/php/routeur.php`, qui vit dans l'image et n'est donc
  jamais joignable par le web.
- **L'argument de la fabrique de `public/index.php` doit s'appeler
  `$context`.** `SymfonyRuntime` le résout **par son nom**, pas par son type :
  renommé en français, il rend un 500 « supports only arguments
  "array $context" » qui n'a l'air d'avoir aucun rapport avec un nom de
  variable.
- **`libpq` avant de supprimer `postgresql-dev`.** L'inverse produit une image
  qui se construit sans erreur et dont `pdo_pgsql` refuse de se charger ;
  Doctrine répond alors « could not find driver », un message qui ne désigne
  pas la cause.
- **Le `Dockerfile` de production est à la racine, et il doit y rester.** Un
  hébergeur qui construit depuis un dépôt cherche un `Dockerfile` à la racine ;
  s'il n'en trouve pas, il devine la nature du projet. Le Dockerfile de
  développement est dans `docker/php/`, où seul `docker-compose.yml` va le
  chercher en le nommant explicitement.
- **Aucune variable de base de données dans `docker-compose.yml`.** Une
  variable d'environnement du conteneur écrase tout : le `.env`, le `.env.test`
  et jusqu'aux valeurs de `phpunit.xml.dist`. Sur le dépôt voisin, la suite de
  tests a tourné une fois sur la base de **développement** à cause de cela, et
  l'a vidée.

## Les contraintes du projet

1. **Ce n'est pas un conseil juridique**, et l'avertissement figure sur chaque
   écran — il est dans `templates/base.html.twig` et non dans chaque page,
   parce qu'une mention qu'il faut penser à passer finit par manquer.
   Chaque règle affichée porte sa source et son degré de certitude.
2. **512 Mo de mémoire au déploiement.** Ni Redis, ni Elasticsearch, ni Chrome
   headless : la file d'attente passe par le transport Doctrine, et le PDF se
   rendra avec une bibliothèque PHP pure. L'image de production limite PHP à
   128 Mo (`docker/php/php.production.ini`) et PostgreSQL à 256 Mo en
   développement. Le conteneur de développement est volontairement plus large
   (768 Mo) : le pic n'est pas la requête, c'est `composer install`, et un
   conteneur bridé se fait tuer pendant l'installation avec pour seul message
   « exit code 137 ».
3. **Données fictives uniquement.** Aucune donnée personnelle réelle, jamais :
   pas de RIB, pas de CIN, pas de numéro de chèque plausible, aucune banque
   réelle nommée.
4. **La discipline des chiffres.** Aucun nombre publié sans la commande qui
   l'imprime ou le test qui l'épingle ; toute proportion dit sur combien de cas
   elle porte ; une même grandeur a un seul document source, les autres y
   renvoient. Les chiffres du phénomène (volumétries, dossiers classés) ne sont
   connus que par reprises de presse : **ils n'entrent pas dans ce fichier**.

## Où est quoi

```
docker-compose.yml          développement : PostgreSQL + PHP
Dockerfile                  production (à la racine, voir plus haut)
docker/php/Dockerfile       développement
docker/php/php.ini          opcache + cache de chemins, pour le montage Windows
docker/php/routeur.php      routeur du serveur intégré
docker/php/entrypoint.sh    installe les dépendances au premier démarrage
docker/php/demarrer.sh      production : ouvre le port AVANT de migrer
config/                     noyau, bundles, services, paquets
config/packages/workflow.yaml   les machines a etats et leurs gardes
src/                        le code applicatif
templates/                  gabarits, avertissement compris
migrations/                 migrations versionnées
tests/                      PHPUnit
LOI.md                      le dossier des règles, avec sources et certitudes
docs/sources/               les pièces primaires (BO 7478, Code pré-réforme…)
```

## Licence

MIT.
