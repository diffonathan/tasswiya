# Mettre Tasswiya en ligne, gratuitement

Tout est prêt dans le dépôt. Il reste **deux comptes à créer**, et un compte ne
se crée pas à votre place. Compter un quart d'heure.

> **Tasswiya n'est pas un conseil juridique.** L'avertissement est sur chacun
> des cinq écrans, et il doit l'être aussi partout où ce lien est présenté :
> fiche du portfolio, dépôt GitHub, publication LinkedIn. Un lien qui circule
> sans son avertissement est un lien qui a perdu la moitié de son sens.

> Ce document décrit une **démonstration**, pas une exploitation. Les écarts
> avec ce qu'on ferait pour de vrai sont signalés au fur et à mesure — les
> taire donnerait une fausse idée de ce qui a été pensé.

---

## Pourquoi deux hébergeurs et pas un

| | Quoi | Pourquoi celui-là |
|---|---|---|
| **Render** | l'application | seul hébergeur gratuit qui exécute une image Docker avec PHP, sans carte bancaire |
| **Neon** | la base PostgreSQL | la base gratuite de Render **expire au bout de 30 jours**. Celle de Neon est permanente. Une démonstration qui meurt au bout d'un mois est pire qu'une absence de démonstration : le lien est dans votre CV |

Conséquence agréable de la séparation : les **512 Mo** de l'offre gratuite sont
pour **PHP seul**. En développement, `docker-compose.yml` les partage avec
PostgreSQL et borde la base à 256 Mo pour s'en assurer ; en ligne, la base est
ailleurs et tout le plafond revient à l'application.

---

## 1. La base de données — neon.tech

1. Créer un compte (connexion par GitHub possible).
2. **New Project** → région **Europe (Frankfurt)**, la plus proche du Maroc
   parmi les régions gratuites, et la même que Render.
3. Copier la chaîne de connexion proposée. Elle ressemble à
   `postgresql://…@ep-…-pooler.eu-central-1.aws.neon.tech/neondb?sslmode=require`.

> **Prendre la version `pooler`** si les deux sont proposées. Render endort le
> service et le réveille, ce qui rouvre des connexions à chaque fois. Sans
> mutualisation, la limite de connexions de l'offre gratuite se remplit, et
> l'erreur que PostgreSQL renvoie alors ne désigne pas la cause.

**Rien à réécrire dans le préfixe.** `postgresql://` est ce que Neon donne et
ce que Doctrine attend. C'est une différence avec le dépôt voisin Factura, qui
tourne sous Laravel et exige `pgsql://` : le piège existe là-bas, pas ici.

**Facultatif, et ça fait gagner un aller-retour :** ajouter
`&serverVersion=<la version majeure que Neon affiche>` à la fin de la chaîne.
Sans elle, Doctrine interroge le serveur au premier appel de chaque processus
pour découvrir sa version — une requête, une fois, et plus rien ensuite. Mieux
vaut l'omettre que l'inventer : une version déclarée fausse est pire qu'une
version inconnue.

---

## 2. L'application — render.com

1. Créer un compte, connecter GitHub.
2. **New → Blueprint**, choisir le dépôt. Render lit `render.yaml` et prépare
   seul le service, la région, le contrôle de santé et les variables.
3. Il ne demandera **qu'une seule valeur** :

   | Variable | Valeur |
   |---|---|
   | `DATABASE_URL` | la chaîne Neon de l'étape 1, telle quelle |

   `APP_SECRET` n'est pas dans cette liste, et c'est volontaire : `render.yaml`
   la déclare en `generateValue`, donc **Render la tire lui-même** et la garde.
   Personne ne la copie, elle ne transite par aucun presse-papiers, elle
   n'apparaît dans aucun fichier — alors qu'une valeur saisie à la main doit
   bien être produite quelque part, et c'est ce « quelque part » qui fuit.
   Il ne reste donc à saisir que ce qui ne peut pas se deviner.

   Tout le reste — `APP_ENV`, `APP_DEBUG`, `MESSENGER_TRANSPORT_DSN`,
   `PHP_CLI_SERVER_WORKERS`, `PORT` — est posé dans le `Dockerfile`. Chaque
   variable à recopier dans l'interface d'un hébergeur est une occasion de se
   tromper, et une faute de frappe ne se voit qu'à l'exécution.

4. **Apply.**

> **Si le mot de passe de la base contient `@ : / ? # % &`** : ne pas utiliser
> `DATABASE_URL`. Une adresse est *analysée*, et l'analyseur coupe au premier
> de ces caractères ; PHP envoie alors un mot de passe tronqué et le serveur
> répond « mot de passe refusé » alors que la valeur collée est la bonne — un
> échec qui accuse la mauvaise chose. Poser à la place `DB_HOST`,
> `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` : `docker/php/demarrer.sh` les
> encode lui-même, une seule fois, et impose `sslmode=require`. La sortie de
> secours existe **avant** qu'on en ait besoin, et c'est tout l'intérêt.
>
> **Éprouvée sur l'image de production**, avec le pire mot de passe qu'on
> puisse écrire — `p@ss:w/rd?x#y%z&1`, qui porte les sept caractères réservés,
> `%` compris. L'image démarre, migre, sème, et `/dossiers/CH-2026-0221` rend
> 200.
>
> Elle ne le faisait pas toujours, et la cause mérite d'être écrite parce
> qu'elle n'était pas dans ce script. `config/packages/doctrine.yaml` lisait
> `%env(resolve:DATABASE_URL)%` : `resolve:` développe les `%parametre%`
> trouvés **dans** la valeur. Or encoder un mot de passe en fabrique — `a@b:c`
> devient `a%40b%3Ac`, Symfony y lit `%40b%` et s'arrête sur « You have
> requested a non-existent parameter "40b" ». La sortie de secours tenait donc
> tant que le mot de passe n'avait qu'**un** caractère réservé et cassait à
> partir de **deux** : exactement les cas pour lesquels elle existe, et avec un
> message qui ne désigne ni la base, ni le mot de passe, ni l'encodage. Le
> `resolve:` a été retiré — aucune adresse de ce dépôt n'a de paramètre à
> développer.

---

## 3. Ce que le démarrage fait, et dans quel ordre

L'ordre est écrit dans `docker/php/demarrer.sh`, et chaque étape s'annonce dans
les journaux (**Logs**, sur la page du service) :

```
-> base decrite par DATABASE_URL
-> prechauffage du cache
-> en ecoute sur le port 8080 (le controle de sante peut passer)
-> migrations
-> jeu de demonstration (charge seulement si la base ne contient aucun dossier)
-> rattrapage des delais echus
-> pret
```

**Le port s'ouvre AVANT les migrations, et c'est délibéré.** L'ordre naturel
serait l'inverse — on ne répond pas avant que le schéma soit à jour. Mais sur
un déploiement voisin, cet ordre a coûté une mise en ligne : l'hébergeur teste
le port seize secondes après avoir lancé le conteneur et n'attend qu'une
seconde. Migrer contre une base distante qui se réveille dépasse ce délai ; la
plateforme tue le conteneur **en plein travail** et jette ses journaux. On ne
voit alors ni erreur ni cause — le pire des échecs.

`render.yaml` pose donc `healthCheckPath: /`, et ce choix dépend d'une
propriété précise de cet écran : `/` interroge la base mais **attrape** l'échec
et rend quand même 200 en affichant « injoignable » (voir
`src/Controller/AccueilController.php`). Il mesure « le service écoute-t-il ? »
et non « toutes ses dépendances sont-elles prêtes ? ». Une adresse qui exigerait
la base déclarerait le service mort pendant le travail qui le rend vivant.

Et l'échec reste signalé par la bonne voie : **si les migrations échouent**,
`demarrer` tue le serveur et sort en erreur. Le conteneur meurt, Render le
voit, et les journaux ont eu le temps d'être conservés.

**Le jeu de démonstration se sème tout seul si la base est vide, et ne touche à
rien sinon.** La commande, lancée à la main, *vide* la base avant de la
remplir ; au démarrage elle reçoit `--seulement-si-vide`. Sans cette garde, la
démonstration se réinitialiserait à chaque réveil du conteneur — c'est-à-dire à
chaque visite après quinze minutes de calme — et effacerait sous les yeux d'un
visiteur ce qu'il vient d'y faire. Le piège a déjà été payé sur le dépôt
voisin. « Vide » se mesure sur les **dossiers** et non sur les tables : les
migrations qui viennent de passer ont créé toutes les tables, donc une base
neuve est pleine de tables et vide de dossiers.

**Un semis qui échoue n'arrête pas le service**, alors qu'une migration qui
échoue l'arrête. La différence est voulue : une migration manquée laisse un
schéma qui ne correspond pas aux entités, donc des pages en 500, et servir cela
est pire que ne rien servir. Un semis manqué laisse une base correcte et vide :
les écrans répondent, la liste est vide, et cela se répare sans redéployer.

**Le rattrapage des délais échus remplace une tâche périodique.** Dans une
exploitation, `tasswiya:faire-expirer-les-delais` serait une tâche planifiée :
un délai échoit à une date, pas à une visite. L'offre gratuite ne donne ni
second processus ni tâche planifiée, donc la seule horloge récurrente
disponible est le réveil du conteneur, et le script s'en sert. Sans cela, la
démonstration vieillirait mal : trente jours après la mise en ligne, le dossier
pivot afficherait un compteur négatif à côté d'un état « écédar notifié »
toujours ouvert, parce que personne n'aurait demandé à la machine à états si la
garde temporelle était tombée.

### Mesuré sur l'image, le 7 octobre 2026

Contre un PostgreSQL 16 voisin, sur ce poste :

```bash
docker build -t tasswiya-prod .
docker run -d --name essai --network tasswiya_default -p 8098:8080 \
  --memory 512m \
  -e APP_SECRET=une_valeur_de_32_octets_au_moins \
  -e DATABASE_URL="postgresql://tasswiya:tasswiya@postgres:5432/essai?serverVersion=16&charset=utf8" \
  tasswiya-prod
docker logs essai | grep -E '^-> |finished in'
docker stats --no-stream --format '{{.MemUsage}}' essai   # AUCUNE requête servie
curl -s -o /dev/null localhost:8099/                      # une seule visite
docker stats --no-stream --format '{{.MemUsage}}' essai   # un ouvrier a travaillé
docker image ls tasswiya-prod --format '{{.Size}}'
```

**Les deux `docker stats` ne donnent pas le même nombre, et l'écart n'est pas du
bruit** : le serveur intégré préfork quatre ouvriers qui ne chargent le noyau
Symfony qu'à leur **première** requête. Un conteneur qui n'a encore rien servi
pèse la moitié de celui qui a répondu une fois — et sur l'hébergeur, le contrôle
de santé le fait répondre tout de suite. C'est donc la seconde valeur qui décrit
le service en ligne. Une recette qui n'indiquerait pas quand mesurer donnerait
un nombre irreproductible.

| Grandeur | Valeur |
|---|---|
| mémoire, aucune requête servie | 11,97 Mio sur 512 |
| mémoire après une visite de `/` | 27,10 Mio sur 512 |
| mémoire après les cinq écrans | 39,47 Mio sur 512 |
| mémoire après 200 requêtes, 20 en parallèle | 39,78 Mio sur 512 — et `OOMKilled=false` |
| taille de l'image | 236 Mo |
| le port répond 200 | 1,49 s après `docker run` |
| séquence complète jusqu'à `-> pret` | 2,31 s |
| migrations | 80,5 ms, 26 requêtes SQL |
| rattrapage juste après le semis | 0 dossier basculé sur 4 examinés |
| construction complète, `--no-cache` | 103 s |

> Mesuré le 7 octobre 2026 sur l'image `tasswiya-prod`, bridée à 512 Mo, contre
> le PostgreSQL 16 du `docker-compose`. Les durées varient avec la machine ;
> les **ordres de grandeur** sont ce qui se transporte, pas les centièmes.
>
> **Le plafond n'est pas tenu par `memory_limit`.** `128M` multiplié par les
> cinq processus du serveur intégré ferait 640 Mo, soit plus que les 512
> disponibles : si cinq requêtes saturaient chacune sa limite PHP, le conteneur
> serait tué avant que PHP ne proteste. Ce qui tient le plafond, c'est que les
> pages de ce produit coûtent quelques mégaoctets — mesuré ci-dessus à 39,78 Mio
> sous 200 requêtes. `memory_limit` est un garde-fou par requête, pas la preuve
> du plafond.

Ces durées bornent ce que coûte **l'image**. Chez Neon il faut y ajouter le
réveil de la base, qui n'est pas de ce côté-ci et n'a pas été mesuré.

Le préchauffage du cache Symfony est fait **à la construction** de l'image, pas
au démarrage (`RUN … cache:warmup` dans le `Dockerfile`). Le conteneur gratuit
s'endort après quinze minutes et le visiteur suivant attend son réveil : tout
ce que le démarrage fait, il le fait devant lui. Ce travail est donc déplacé
dans la construction, où personne ne regarde.

L'image ne tourne pas en `root` : un utilisateur `tasswiya` est créé, et seul
`var/` lui appartient. `src/`, `templates/`, `migrations/` et `vendor/` restent
en lecture seule pour le processus servi.

---

## 4. Le lien, et où le reporter

Render donne une adresse en `https://tasswiya-XXXX.onrender.com`. À reporter :

- dans `portfolio/src/data/portfolio.ts` **et** `portfolio.en.ts`, champ
  `demoUrl` de la fiche Tasswiya — le bouton « Voir la démo » apparaît alors ;
- dans le dépôt GitHub, champ **Website** ;
- dans la fiche LinkedIn du projet, en média.

Avec, à chaque fois, **deux phrases qui doivent voyager avec le lien** :

1. « Ceci n'est pas un conseil juridique : démonstration technique, données
   fictives. »
2. « Première ouverture un peu lente, le serveur se réveille. »

La seconde n'est pas de la coquetterie. Une page blanche de quarante secondes
passe pour une panne ; annoncée, c'est de la lenteur. C'est toute la différence
entre « c'est lent » et « c'est cassé ».

**Les deux adresses à montrer**, celles qui portent le moment du projet :

```
https://VOTRE-ADRESSE.onrender.com/tutoriel?jours=29
https://VOTRE-ADRESSE.onrender.com/tutoriel?jours=31
```

À 29 jours, les quatre transitions sortantes sont rayées ; à 31, une seule
s'ouvre — et c'est celle que personne ne peut déclencher. Rien n'a été saisi,
aucun bouton pressé : le seul fait nouveau est le passage du temps.

**Ces deux adresses ne dépendent d'aucun état du serveur.** `/tutoriel` porte
son temps dans son adresse : il reconstruit le scénario en mémoire à partir du
nombre de jours reçu, sans écrire une ligne en base (voir le commentaire en
tête de `src/Controller/TutorielController.php`). Deux visiteurs simultanés sur
`?jours=29` et `?jours=31` voient chacun le sien. C'est ce qui rend le moment
du projet partageable dans un CV, et ce qui le met hors d'atteinte de la
question du paragraphe suivant.

---

## 5. L'horloge de démonstration, `var/`, et ce qui se perd au réveil

`/demonstration` range son décalage dans un fichier de `var/`. **`var/` n'est
pas persistant chez Render** : le réveil repart de l'image, donc le décalage
repart à zéro.

**Décision : on le laisse là, et on ne le persiste pas.** Trois raisons, dans
l'ordre de poids.

**1. Le moment qui compte n'en dépend pas.** C'est `/tutoriel` qu'on montre, et
il porte son temps dans son adresse. Le décalage global ne sert que l'écran
`/demonstration`, qui est l'instrument de la bascule *filmée*, pas la
démonstration elle-même.

**2. Persister rendrait les choses pires, pas meilleures.** Le décalage est
**global à l'installation**, pas par visiteur — c'est assumé et documenté dans
`DecalageDeDemonstration`, parce que la page et la commande console doivent
voir le même présent. Sur une adresse publique, un visiteur qui pousse de dix
ans le pousserait **pour tout le monde**, et plus rien ne le remettrait à zéro :
il faudrait une porte d'administration pour un instrument qui n'est pas un
produit. L'oubli au réveil est donc une soupape gratuite : la démonstration
revient d'elle-même au temps réel.

**3. Ce n'est pas un comportement nouveau.** Un réveil fait exactement ce que
fait le bouton « réinitialiser » de l'écran, que l'écran explique déjà en
toutes lettres : l'horloge revient au temps réel, et **les dossiers déjà
basculés y restent** — la machine à états n'a pas de transition de retour depuis
« délai expiré », parce qu'un délai expiré ne se dé-expire pas. Le réveil
déclenche la même chose, par la plateforme au lieu du bouton.

**L'asymétrie, mesurée.** Elle est réelle et il faut la connaître. Sur l'image,
après avoir poussé l'horloge de 31 jours puis recréé le conteneur :

```
etats avant le reveil     delai_expire : 3
le reveil                 docker rm -f essai && docker run -d --name essai … tasswiya-prod
etats apres le reveil     delai_expire : 3
le decalage               ls: var/demonstration: No such file or directory
```

Les trois dossiers restent `delai_expire`, le décalage est à zéro. Sur
`/dossiers/CH-2026-0107`, cela se voit : le bandeau dit **« Délai expiré »** et
le compteur, juste à côté, dit **« 3 jours restants »**. Ce n'est pas un bogue
d'affichage — l'état et le compteur disent deux choses vraies à deux instants
différents — mais c'est illisible pour un visiteur.

**Comment on s'en sort :** on recharge le jeu de démonstration. La procédure
est juste en dessous, et elle existe pour ça.

---

## 6. Remettre la démonstration à zéro

L'offre gratuite de Render **ne donne pas d'accès au terminal** : on ne peut
pas y lancer `bin/console`. Le levier est donc ailleurs, et il tombe tout seul
de la règle « semer si la base est vide » : **on vide la base, et le prochain
démarrage resème.**

1. Chez **Neon**, onglet **SQL Editor**, sur la base du projet :

   ```sql
   TRUNCATE TABLE dossier, cheque, partie, prolongation,
                  evenement_dossier, interdiction_bancaire,
                  messenger_messages CASCADE;
   ```

   `TRUNCATE` et non `DROP` : les tables et les migrations déjà jouées
   restent, donc rien à remigrer. `doctrine_migration_versions` n'est **pas**
   dans la liste, et il ne doit pas y être — l'effacer ferait rejouer la
   migration initiale contre des tables qui existent déjà, et le démarrage
   échouerait sur « relation déjà existante ».

2. Chez **Render**, **Manual Deploy → Restart service**. Les journaux doivent
   dire :

   ```
   -> jeu de demonstration (charge seulement si la base ne contient aucun dossier)
    Base sans aucun dossier : le jeu de démonstration est chargé.
    [OK] 9 dossiers fictifs chargés, tous par franchissement des transitions
         de la machine à états.
   ```

C'est une remise à zéro **volontaire**, en deux gestes dans deux interfaces
différentes. C'est plus long qu'un bouton, et c'est exactement ce qu'on veut :
une remise à zéro accessible d'un clic finit par être cliquée par accident.

---

## 7. Vérifier après coup

```bash
# le service repond, et l'ecran d'etat dit si la base est jointe
curl -s -o /dev/null -w "%{http_code}\n" https://VOTRE-ADRESSE.onrender.com/

# les ecrans, dans l'ordre : tous doivent rendre 200
for u in / /dossiers /demonstration /tutoriel "/tutoriel?jours=31"; do
  printf "%-22s %s\n" "$u" \
    "$(curl -s -o /dev/null -w '%{http_code}' "https://VOTRE-ADRESSE.onrender.com$u")"
done

# la base est vraiment jointe ET semee : le dossier pivot doit repondre 200
curl -s -o /dev/null -w "%{http_code}\n" \
  https://VOTRE-ADRESSE.onrender.com/dossiers/CH-2026-0221
```

Le dernier est celui qui prouve quelque chose. `/` rend 200 même si la base est
injoignable — c'est ce qu'on lui demande, pour que le contrôle de santé passe
pendant les migrations. `/dossiers/CH-2026-0221` ne rend 200 que si la base
répond **et** si le jeu de démonstration y est.

Et l'œil, pas le code : `/tutoriel?jours=29` doit montrer quatre transitions
rayées, `?jours=31` une seule ouverte.

---

## 8. Ce qui tombe en panne si on se trompe

C'est la partie que les recettes omettent, et c'est celle qui sert. Chaque
ligne est une faute possible et sa **conséquence visible** — la conséquence est
rarement là où l'on cherche.

### Les cinq fautes qui coûtent cher

**Choisir la base PostgreSQL de Render au lieu de Neon.** Tout marche. Pendant
trente jours. Puis la base expire, et le lien de votre CV rend une page qui dit
que la base est injoignable. Personne ne vous le signalera ; vous l'apprendrez
en cliquant sur votre propre lien, peut-être des mois plus tard. **C'est la
seule faute de cette liste qui ne se voit pas le jour où on la commet.**

**Mettre `DATABASE_URL` dans `render.yaml` « pour aller plus vite ».** Le
fichier est versionné dans un dépôt public. Le mot de passe de la base part
dans l'historique git, et l'en retirer demande de **réécrire l'historique**,
pas de faire un commit de correction. La variable est marquée `sync: false`
exactement pour que ce raccourci ne soit pas tentant.

**Régénérer `APP_SECRET` après coup.** Render propose de la retirer ou de la
régénérer ; elle est en `generateValue` pour qu'on n'y touche plus. La
régénérer invalide toutes les sessions en cours, donc les jetons CSRF : un
visiteur en train de pousser l'horloge reçoit « Jeton de formulaire invalide »
et ne comprend pas pourquoi.

**Retirer `--seulement-si-vide` de `demarrer.sh`.** La démonstration se vide et
se resème à **chaque réveil du conteneur**, c'est-à-dire à chaque visite après
quinze minutes de calme. Un visiteur qui parcourt un dossier voit la liste se
réinitialiser sous ses yeux. Rien dans les journaux ne ressemble à une erreur :
le semis s'annonce et réussit, à chaque fois.

**Mettre un contrôle de santé qui exige la base** — par exemple `/dossiers` au
lieu de `/`. Pendant les migrations, l'adresse rend 500 ; Render conclut que le
service est mort et tue le conteneur **en pleine migration**, puis jette ses
journaux. Le déploiement boucle sans qu'aucun message ne nomme la cause. C'est
l'échec qui a déjà coûté une mise en ligne sur un dépôt voisin, et c'est pour
cela que `demarrer` ouvre le port avant de migrer.

### Table des symptômes

| Ce qu'on voit | Cause la plus fréquente |
|---|---|
| `CONFIGURATION INCOMPLETE — APP_SECRET est vide` | `render.yaml` n'a pas été importé en Blueprint ; la variable a été ajoutée à la main et laissée vide |
| `Aucune base de donnees n'est configuree` | `DATABASE_URL` non enregistrée dans le tableau de bord — Render ne l'invente pas |
| `/` répond 200 et dit **« injoignable »** | la chaîne Neon est fausse, ou la base a expiré (base Render au lieu de Neon) |
| « mot de passe refusé » alors que la valeur collée est la bonne | caractère réservé (`@ : / ? # % &`) dans le mot de passe : passer aux variables `DB_*` |
| le déploiement boucle, journaux vides | contrôle de santé qui exige la base, ou port non ouvert avant les migrations |
| `relation « dossier » existe déjà` | `doctrine_migration_versions` a été vidé avec les autres tables |
| liste des dossiers vide au premier jour | le semis a échoué ; son message est dans les journaux, et le service est resté en ligne exprès pour qu'on puisse le lire |
| la démonstration se vide toute seule | `--seulement-si-vide` retiré du script de démarrage |
| « Délai expiré » à côté de « 3 jours restants » | un visiteur a poussé l'horloge, puis le conteneur s'est rendormi : voir § 5, et recharger le jeu (§ 6) |
| « Jeton de formulaire invalide » sur `/demonstration` | `APP_SECRET` régénérée pendant la session du visiteur |
| limite de connexions atteinte chez Neon | chaîne non `pooler` : chaque réveil rouvre des connexions |
| compteurs négatifs sur des dossiers non expirés | le conteneur n'a pas redémarré depuis longtemps ; le rattrapage se fait au démarrage, pas en continu |

Les journaux sont dans **Logs**, sur la page du service. `demarrer` y écrit
chaque étape et nomme la cause probable quand il s'arrête. Quand quelque chose
ne va pas, c'est la première chose à lire — avant de changer un réglage.
