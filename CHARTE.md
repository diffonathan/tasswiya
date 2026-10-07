# CHARTE.md — la charte graphique de Tasswiya

> **Ceci n'est pas un conseil juridique**, et ce document n'est pas du droit.
> Les règles de droit du projet sont dans [`LOI.md`](LOI.md). Ce fichier-ci ne
> décide que de ce qui se voit.

Ce document s'adresse à qui reprendra le projet. Il dit **quels sont les
jetons**, **ce que chaque couleur a le droit de signifier**, **ce qu'on ne
fait jamais**, et **pourquoi** — parce qu'une charte dont on a perdu les
raisons se fait contourner à la première contrainte.

| Où | Quoi |
|---|---|
| [`public/charte/tasswiya.css`](public/charte/tasswiya.css) | les jetons et les utilitaires, servis tels quels |
| [`public/charte/planche.html`](public/charte/planche.html) | la planche de contrôle — `http://localhost:8001/charte/planche.html` |
| [`brand/`](brand/) | les quatre fichiers de marque, et leur [README](brand/README.md) |
| [`brand/mesures/charte.php`](brand/mesures/charte.php) | la commande qui imprime tous les nombres de ce document |

**Tous les nombres de ce fichier sortent d'une commande**, conformément à la
règle du projet. Celle-ci :

```sh
docker compose exec php php brand/mesures/charte.php
```

Elle se termine par un verdict et un **code de sortie** : un jeton de texte
sous le seuil WCAG AA, ou un SVG de marque malformé, la font échouer. La
charte est vérifiable, pas seulement racontée.

---

## 1. La palette : 17 — Indigo `#635bff`

Prise au catalogue maison [`../charte/CHARTE-24-PALETTES.md`](../charte/CHARTE-24-PALETTES.md),
dont le principe est repris tel quel : **une seule couleur pilote tout**, et
tout le reste se dérive d'elle par une recette.

Le choix a été **mesuré**, et il mérite d'être expliqué parce qu'il n'est pas
le choix évident.

### Le raisonnement, dans l'ordre

**D'abord les rôles, ensuite l'accent.** Trois couleurs du produit ne sont pas
négociables : l'or porte les montants, le vert ce qui est réglé, le rouge ce
qui est perdu. Ce sont des rôles de la charte maison — « ce qu'un thème ne
réécrit jamais » —, pas des choix de ce projet. Elles occupent donc le cercle
chromatique **avant** que l'accent n'arrive, et c'est à lui de se placer
autour d'elles.

Posées sur le cercle, elles laissent trois arcs vides (§ 2 des mesures) :

| Arc | Largeur |
|---|---|
| rouge → or | 29,2° |
| or → vert | 115,2° |
| **vert → rouge** | **215,6°** |

Le plus grand fait 215,6°, et son milieu tombe à **260,1°**. C'est la teinte
où l'accent ne vient s'entasser contre aucun des trois signaux de sens.

**Et la palette la plus proche de cet idéal est le violet profond `#7c3aed`,
à 2,1° — déjà prise par Mizan.** La position mathématiquement parfaite était
occupée. Tout le reste de l'arbitrage consiste à s'en éloigner le moins
possible sans ressembler à Mizan.

### L'arbitrage, à deux critères qui tirent en sens inverse

Trois palettes libres tombent encore dans l'arc (à moins de 30° de l'idéal) :

| Palette libre | Écart à l'idéal | Écart à la série |
|---|---|---|
| 20 lavande | 4,9° | **7,0°** |
| 18 violet-royal | 10,7° | **8,6°** |
| **17 indigo** | 17,1° | **19,2°** |

Lavande et violet-royal sont plus proches de l'idéal, mais à 7,0° et 8,6° de
Mizan : deux écrans de la série se confondraient de mémoire. **L'indigo
s'éloigne de la série plus du double**, pour 6 à 12° d'idéal perdus.

C'est le meilleur compromis disponible, pas un choix confortable : **aucune
palette libre n'est à plus de 19,2° d'une palette déjà attribuée.** L'indigo
est le moins mauvais, et c'est ce qu'il faut écrire.

### Pourquoi pas magenta ni fuchsia

Ce sont les seules palettes vraiment **loin** de la série. Elles sont écartées
quand même, et la mesure qui tranche n'est pas leur écart au rouge pris seul,
mais le **deuxième plus petit écart du quatuor** {accent, or, vert, rouge}. Le
premier, or ↔ rouge, est subi et identique partout ; le deuxième est celui que
le choix de l'accent décide (§ 4 des mesures) :

| Candidate | Écart à l'idéal | 2ᵉ écart minimal |
|---|---|---|
| **17 indigo** | 17,1° | **90,7°** |
| 21 magenta | 70,3° | 37,5° |
| 22 fuchsia | 77,3° | 30,5° |
| 24 bordeaux | 87,7° | 29,2° |

Dès que l'accent passe du côté chaud du cercle, il s'entasse avec l'or et le
rouge. Et le rouge de ce produit est le signal le plus important de tout
l'écran : c'est celui que la démonstration fait apparaître au seul passage du
temps. **Une couleur pilote voisine de la couleur d'alarme aurait brouillé le
seul signal qui compte.**

### La dérivation

Jamais à l'œil. La recette est celle de la charte maison, et § 5 des mesures
imprime chaque valeur :

| Jeton | Valeur | Dérivation |
|---|---|---|
| `--indigo` | `#635bff` | la couleur pilote |
| `--indigo-sourd` | `#514bd1` | `assombrir(accent, 0.18)` |
| `--indigo-encre` | `#4540b3` (papier) / `#a9a5ff` (nuit) | `assombrir(0.30)` / `eclaircir(0.45)` |
| `--grad-indigo` | `-15deg, #a9a5ff → #4d47c7` | `eclaircir(0.45)` → `assombrir(0.22)` |

---

## 2. Les cinq rôles

Une couleur qui change de sens d'un écran à l'autre fait perdre le lecteur.
Les rôles sont donc **nommés par le sens** dans le CSS, jamais par la couleur :
`<span class="montant">` se relit et se vérifie, `<span class="or">` ne se
vérifie pas.

| Rôle | Jeton | Ce qu'il porte, et **rien d'autre** |
|---|---|---|
| **Indigo — l'action** | `--indigo`, `--indigo-encre` | navigation, liens, boutons, focus, onglets |
| **Or — les montants** | `--or`, `--or-encre` | valeur du chèque, manquant, amendes, pénalités |
| **Vert — ce qui est réglé** | `--vert-encre` | régularisé, action publique éteinte, peine effacée, interdiction purgée |
| **Rouge — ce qui est perdu** | `--rouge-encre` | délai expiré, poursuite engagée, condamnation |
| **Le degré de certitude** | *aucune couleur* | voir § 3 |

### Le point faible connu, et sa parade

L'or est à **29,2° du rouge** : c'est l'écart le plus faible de toute la
charte (§ 6 des mesures). Il est **subi** — les deux teintes sont des rôles
maison, pas des choix de ce projet.

La parade n'est donc pas chromatique, elle est **typographique** : l'or ne se
pose que sur des **nombres**, et tout nombre est en police mono avec
`tabular-nums`. La forme sépare ce que la teinte ne sépare pas assez. C'est
pour cela qu'un titre en or, ou un « point important » mis en or, casserait
réellement quelque chose et pas seulement une convention.

---

## 3. Le cinquième rôle — le degré de certitude

**C'est la pièce originale de cette charte**, et elle n'existe dans aucun
autre projet de la série.

### Le problème

Tasswiya affiche du droit reconstitué depuis un Bulletin officiel arabe dont
des fragments sont élidés. Quatre degrés cohabitent à l'écran
(`App\Enum\DegreCertitude`) : **ETABLI**, **PROBABLE**, **INCERTAIN**,
**CHOIX_PRODUIT**. Un utilisateur doit voir **d'un coup d'œil** ce qui vient
du texte officiel et ce que l'application a décidé toute seule. Afficher un
choix de développeur avec l'autorité d'une loi est la faute que tout ce
projet cherche à ne pas commettre.

### La décision : le degré de certitude n'a AUCUNE couleur

Les quatre rôles occupent déjà le cercle, et leur écart le plus faible est de
29,2° : une cinquième teinte s'y entasserait. Surtout, INCERTAIN devrait
**alerter**, donc tirer vers le rouge — qui appartient à « délai expiré ». Un
rouge qui voudrait dire deux choses ne dit plus rien.

Le degré est donc porté par **le trait et la texture**. Ce n'est pas un
renoncement : c'est ce qui lui permet de ne jamais entrer en collision avec
les quatre autres rôles, et de **survivre au noir et blanc par construction**
plutôt que par chance.

### Les quatre canaux, par ordre d'importance

| Degré | 1. Filet de gauche | 2. Trame de fond | 3. Retrait | 4. Étiquette |
|---|---|---|---|---|
| **ETABLI** | plein | aucune | aucun | « établi » |
| **PROBABLE** | **double** | aucune | aucun | « probable » |
| **INCERTAIN** | tireté | hachures obliques | aucun | « incertain — à vérifier » |
| **CHOIX_PRODUIT** | pointillé | grille de points | 1,5 rem | « choix de ce logiciel » |

**Pourquoi le filet est le canal principal, et pas la trame.** Un
`border-style` est la **seule propriété de cette liste qu'un navigateur
imprime toujours**, même quand il jette les fonds, les ombres et les dégradés.
Le signal qui doit survivre au noir et blanc est donc posé sur la seule
propriété qui ne disparaît pas. La trame est le canal d'appoint, pas
l'inverse — et c'est une décision technique autant que graphique.

**Pourquoi la rampe se lit sans légende.** Elle va du continu au discontinu,
dans l'ordre : un trait entier dit « c'est écrit », un trait brisé dit « c'est
reconstitué ». Personne n'a besoin qu'on le lui explique. Le double trait de
PROBABLE dit deux sources concordantes là où l'établi n'en avait besoin que
d'une, et il reste continu — on est encore du côté du su.

**Pourquoi CHOIX_PRODUIT est décalé.** Il n'est pas une règle de droit : il ne
doit pas être aligné avec le droit. Le retrait rend l'encart « hypothèses de
calcul » de [`LOI.md`](LOI.md) § 6 lisible même quand une hypothèse se trouve
isolée au milieu de règles sourcées.

**Pourquoi l'étiquette existe quand même.** Pas pour tenir le signal, mais
parce qu'un lecteur d'écran ne voit ni filet ni trame — et parce que
`RegleAppliquee::attribution()` a de toute façon une phrase exacte à écrire
sous chaque règle, qui ne dit **jamais** « loi 71-24 » sous une règle qui n'en
vient pas.

### Un piège qui est devenu un jeton

`border-left-style: double` **doit faire au moins 5 px**. À 4 px le navigateur
retombe sur un trait plein, et PROBABLE devient indistinguable d'ETABLI. La
largeur de ce filet n'est donc pas décorative, elle est fonctionnelle. C'est
écrit dans le CSS à l'endroit où quelqu'un serait tenté de la réduire.

---

## 4. Le substrat : papier par défaut, nuit comme thème entier

**C'est le seul écart de cette charte au catalogue maison**, qui dit « nuit
uniquement, aucun thème clair ». Il est délibéré et il a trois raisons :

1. **Le cinquième rôle doit survivre au noir et blanc**, c'est-à-dire à
   l'impression. Un dossier de régularisation s'imprime et se glisse dans une
   chemise. Une charte nuit s'imprime en aplat noir ou se fait inverser par le
   navigateur, et le signal qui compte le plus se perd en route.
2. **Le produit n'affiche presque que des tableaux de dates et de nombres en
   police mono.** Du `backdrop-filter: blur()` derrière des chiffres de 13 px
   est exactement le cas où le glassmorphism coûte le plus de lisibilité pour
   le moins d'effet.
3. **Le registre.** Les autres projets de la série sont des terminaux et des
   tableaux de bord. Celui-ci est une pièce de dossier.

Ce qui est **gardé** de la charte maison ne bouge pas : une seule couleur
pilote tout, la recette de dérivation, les quatre rôles, et la courbe
d'animation signature `cubic-bezier(0.22, 1, 0.36, 1)`.

La nuit n'est pas un repli dégradé : **les deux substrats sont mesurés au même
seuil** (§ 7), et chaque jeton de texte passe WCAG AA sur son fond.

| Jeton | Papier `#fbfaf7` | Nuit `#111318` |
|---|---|---|
| `--encre` | 15,75:1 | 14,90:1 |
| `--encre-sourde` | 6,13:1 | 7,27:1 |
| `--indigo-encre` | 7,63:1 | 8,42:1 |
| `--or-encre` | 6,37:1 | 9,43:1 |
| `--vert-encre` | 5,86:1 | 8,46:1 |
| `--rouge-encre` | 6,94:1 | 8,08:1 |

Et pour le seul endroit où l'accent devient un **aplat sous du texte** : blanc
sur `--indigo-sourd` donne **6,41:1**, blanc sur `--indigo` donne 4,70:1. Les
boutons prennent donc `--indigo-sourd` au repos et `--indigo` au survol.

---

## 5. La typographie

Aucune police téléchargée. Trois piles système, et la troisième n'est pas un
luxe.

| Jeton | Pour quoi |
|---|---|
| `--police-texte` | le texte courant |
| `--police-nombre` | **tout** nombre : dates, montants, durées, articles |
| `--police-arabe` | l'arabe verbatim |

**Pourquoi pas DM Sans ni Azeret Mono, que la charte maison demande.** Il n'y
a pas de chaîne npm (contrainte n° 2), donc il faudrait les charger depuis un
hébergeur de polices. Une démonstration doit s'afficher à l'identique hors
réseau ; et une requête sortante vers un tiers, sur un produit qui parle de
dossiers pénaux, est une fuite de données qu'on ne se permet pas. La pile
système est le prix payé, et il est écrit ici pour qu'il soit un choix et pas
un oubli.

**Pourquoi une pile arabe.** Le produit affiche de l'arabe **verbatim** —
`RegleAppliquee::$arabeSource` existe pour ça, parce que la seule version
officielle de la loi n° 71.24 est en arabe. Rendre ce mot avec la pile latine
donnerait des carrés vides, exactement sur le mot dont le produit dit qu'il
fait foi. La classe `.arabe` porte aussi `unicode-bidi: isolate`, et ce n'est
pas décoratif : sans lui, un mot arabe au milieu d'une phrase française
emporte la ponctuation voisine du mauvais côté, et une citation d'article se
met à mentir sur ses propres bornes.

---

## 6. Le responsive, mesuré

La contrainte dit « éprouvé à 375 px, pas supposé ». Le contrôle vit **dans la
planche** (`§ 6` de `planche.html`) : il parcourt tous les éléments, signale
ceux qui dépassent le bord, et se remesure au redimensionnement. Pas de Chrome
headless (contrainte n° 4), et personne n'a à se souvenir de le lancer.

Relevé à 375 px : **document 375 px, aucun débordement**, zone à défilement
assumé 469 px dans 343 px.

Deux défauts ont été trouvés par cette mesure, et pas à l'œil :

- **`overflow-wrap: anywhere` écrasait les tableaux.** `anywhere` entre dans
  le calcul de la largeur minimale du tableau, ce qui autorise le navigateur à
  comprimer toutes les colonnes jusqu'à une lettre par ligne : « régularisation »
  devenait « régular / isation ». Remplacé par **`break-word`**, qui ne casse
  que ce qui déborde vraiment.
- **Les pastilles d'état se coupaient.** « en cours » devenait quatre lignes
  d'une syllabe dans un ovale haut de quatre lignes. `.jalon` porte désormais
  `white-space: nowrap` : un état se lit d'un coup d'œil ou ne sert à rien.

Un tableau qui ne peut pas rétrécir se met dans `.defilant` : **on fait
défiler la zone, jamais la page**.

---

## 7. Ce qu'on ne fait JAMAIS

1. **Jamais d'or sur autre chose qu'un montant.** Ni un titre, ni une mise en
   valeur, ni le mot arabe du logotype. L'or est à 29,2° du rouge et ne tient
   que par sa discipline d'emploi. C'est la règle la plus facile à enfreindre
   sans y penser.
2. **Jamais d'indigo sur un élément non cliquable.** Un seul indigo décoratif
   rend le signal « on peut cliquer ici » faux pour toute la page.
3. **Jamais de couleur pour porter un degré de certitude.** Filet, trame,
   retrait, mot. Le cinquième rôle est achromatique, et c'est ce qui le rend
   robuste.
4. **Jamais « la loi limite à une prolongation ».** Le quota est un
   **CHOIX_PRODUIT** ; l'article 325 nouveau dit « لمدة مماثلة أو أكثر » —
   durée égale ou supérieure — sans plafonner le nombre. L'écran doit le dire
   **là où il bloque**, avec le traitement visuel du degré CHOIX_PRODUIT.
   ([`LOI.md`](LOI.md) § 4.1)
5. **Jamais retirer le `:focus-visible`.** L'écran se parcourt au clavier, et
   une démonstration filmée au clavier est précisément ce que ce projet veut
   pouvoir faire.
6. **Jamais de chiffre du dossier dans une feuille de style.** Une charte qui
   porterait un délai ou un montant casserait la séparation que
   `config/packages/tasswiya.yaml` tient depuis le début.
7. **Jamais de nom de jeton CSS dans un commentaire SVG.** Un commentaire XML
   ne peut pas contenir deux tirets consécutifs : le fichier devient malformé,
   le navigateur s'arrête à la première erreur, et **rien ne le signale à la
   page qui l'inclut**. Le logotype complet s'est affiché vide pendant une
   passe entière pour cette raison. Le contrôle est désormais dans § 9 des
   mesures.
8. **Jamais `prefers-reduced-motion` ignoré**, et jamais une distinction qui
   ne survivrait pas à `forced-colors: active`. En contraste forcé les trames
   sont retirées plutôt que laissées à moitié mortes — le filet, lui, survit,
   et c'est pour cela qu'il est le canal principal.
9. **Jamais d'avertissement juridique absent d'un écran.** Il est dans
   `base.html.twig`, pas dans les pages : une mention qu'il faut penser à
   passer page par page finit par manquer sur une page, et c'est exactement
   celle qu'un lecteur prendra pour un conseil juridique.

---

## 8. Reprendre le projet

```sh
cd tasswiya && docker compose up -d

# Le produit
http://localhost:8001

# La planche de contrôle de la charte
http://localhost:8001/charte/planche.html

# Tous les nombres de ce document, et le verdict
docker compose exec php php brand/mesures/charte.php
```

Si vous changez un jeton de couleur, **relancez la commande** : elle échoue si
un contraste tombe sous le seuil ou si un SVG de marque devient malformé.
Si vous changez une largeur, **ouvrez la planche à 375 px** : elle se mesure
toute seule.
