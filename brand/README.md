# Marque — Tasswiya

| Fichier | Usage |
|---|---|
| `logo.svg` | le symbole seul. **Aucune police requise** : trois rectangles, rendu identique partout. Plancher 16 px, confort à partir de 24 px. |
| `favicon.svg` | le même dessin, **épaissi pour l'onglet**. C'est lui qu'on met en `rel="icon"`. |
| `logo-complet.svg` | symbole + nom latin + le mot arabe, pour un en-tête web. |
| `logo-mono.svg` | une seule couleur, héritée du texte environnant (`currentColor`). Tampon, gravure, fond coloré. **Plancher 32 px**, voir plus bas. |
| `mesures/charte.php` | la commande qui imprime les nombres de ce fichier **et** vérifie que ces quatre SVG sont bien formés. |

Les fichiers de ce dossier sont la **source**. Ils sont servis au web depuis
`public/marque/`, où ils sont recopiés — c'est un `cp`, pas une
transformation, parce qu'il n'y a pas de chaîne de construction dans ce
projet :

```sh
cp brand/*.svg public/marque/
docker compose exec php php brand/mesures/charte.php   # vérifie les quatre
```

Les couleurs, les rôles et les raisons sont dans [`../CHARTE.md`](../CHARTE.md).

| Rôle | Valeur |
|---|---|
| Indigo (marque, action) | `#635bff` — dégradé `#a9a5ff` → `#4d47c7` |
| Or (montants) | `#f9a825` |

---

## Le symbole

**Tasswiya** (تسوية) veut dire « régularisation », « règlement ». Le mot vient
de la racine **س-و-ي**, qui veut dire *rendre égal*, *mettre à niveau*. C'est
cette racine-là que le symbole dessine, et pas l'idée vague de « justice ».

**Deux lignes qui finissent au même bord droit.** Celle du haut est entière :
c'est le niveau à atteindre. Celle du bas est interrompue — et ce qui la
complète, à droite, est **une pièce d'or**.

L'or est le rôle « valeur » de la charte maison ; dans ce produit il ne porte
que des montants. **Ici, la pièce d'or EST le montant.** Elle est déjà posée à
sa place, au bord du niveau. Ce qui sépare encore les deux, c'est le vide de
six unités : **le délai**.

Le symbole dit donc exactement ce que fait le produit — le montant est
identifié, le niveau est connu, et il reste un intervalle de temps à franchir
avant que les deux se rejoignent. Et l'égalité des bords droits est le nom du
produit.

Cela se lit sans légende, ce qui est la seule exigence qui compte.

## Ce qui a été écarté

**La balance.** Mizan l'a prise, et c'est son nom même. La reprendre ici
aurait fait deux projets de la même série avec le même symbole.

**L'horloge, le sablier, le compte à rebours.** Ils disent « délai », ce qui
est juste, mais ils le disent comme le dirait n'importe quel minuteur. Rien
n'y évoque une somme à payer, et surtout rien n'y évoque le mot *tasswiya*.

**Le chèque, ou sa silhouette.** Il dit l'objet du litige, pas ce que le
produit en fait. Un logiciel de suivi de délais qui montre un chèque promet
un carnet de chèques.

**La pièce d'or posée à gauche de la ligne.** Elle se lisait alors comme une
barre de progression — « voilà ce qui est déjà fait » —, ce qui inverse le
sens. Elle est à droite, après le vide, parce que **c'est le vide qui est le
sujet**.

## La lisibilité, mesurée

La leçon est reprise de Mizan telle quelle : **c'est le vide entre les formes
qui décide de la lisibilité, pas leur épaisseur.** Ici, le vide porte en plus
le sens — c'est le manque. S'il se soude, le symbole dit « une ligne et
demie » au lieu de « il manque un intervalle ».

Les nombres sont imprimés par `mesures/charte.php` § 8 ; le plancher maison
est de 3 unités sur un `viewBox` de 64.

| Grandeur | Unités | @16 px | @24 px | @200 px |
|---|---|---|---|---|
| épaisseur d'une barre | 7 | 1,75 px | 2,62 px | 21,9 px |
| vide vertical entre les barres | 10 | 2,50 px | 3,75 px | 31,2 px |
| **vide horizontal avant la pièce d'or** | **6** | **1,50 px** | **2,25 px** | **18,8 px** |
| longueur de la pièce d'or | 13 | 3,25 px | 4,88 px | 40,6 px |
| marge au cadre | 12 | 3,00 px | 4,50 px | 37,5 px |

## Pourquoi un `favicon.svg` séparé

`logo.svg` **tient** à 16 px — son vide critique y fait 1,50 px — mais sans
marge. Un onglet est rendu avec de l'antialiasing, parfois sur fond clair,
parfois sur fond sombre, et 1,50 px de vide suffisent juste à ne pas se
souder. « Juste suffisant » n'est pas une marge.

`favicon.svg` épaissit donc la matière de 7 à 9 unités et le vide critique de
6 à 7 unités. Le dessin est le même ; seules les proportions sont recalculées
pour la taille à laquelle il sera réellement vu.

## Le monochrome a sa propre géométrie

`logo-mono.svg` **n'est pas** la version couleur dépouillée. Sans le champ
indigo ni la pièce d'or, tout ce qui portait la différence disparaît, et il
faut la reconstruire autrement :

1. **le champ devient un contour évidé**, sinon le symbole perd sa silhouette
   de pastille et flotte dans la page ;
2. **la pièce d'or devient une pièce creuse** — la distinction passe de la
   couleur à la forme. C'est la seule façon de garder le sens quand la couleur
   n'est plus disponible ; et une pièce creuse dit même mieux ce qu'elle doit
   dire : un montant encore dû ;
3. **tout s'épaissit** : 8 unités au lieu de 7, et le vide horizontal passe de
   6 à 7, parce qu'un contour coûte deux parois là où un aplat n'en coûtait
   aucune.

**Conséquence mesurée, et c'est une limite à dire :** la paroi de la pièce
creuse fait 2,5 unités, soit **0,63 px à 16 px**. Elle disparaît. Le plancher
de cette version est donc **32 px**, pas 16. Pour un onglet de navigateur,
c'est `favicon.svg` qu'il faut, jamais celui-ci.

## Un piège qui a coûté une passe

**Un commentaire XML ne peut pas contenir deux tirets consécutifs.** Citer le
nom d'un jeton CSS tel quel dans un commentaire de SVG rend le fichier
malformé — et le navigateur s'arrête à la première erreur **sans rien dire à
la page qui l'inclut**. `logo-complet.svg` s'est affiché avec son symbole et
sans son nom, en silence.

Deux conséquences, toutes deux en place :

- les noms de jetons se citent **sans leur préfixe** dans tout ce dossier ;
- `mesures/charte.php` § 9 **parse les quatre SVG** et fait échouer la
  commande si l'un d'eux est malformé. Il vérifie aussi que l'or n'apparaît
  qu'**une fois** par fichier : une seconde occurrence voudrait dire que l'or
  a servi à décorer, et une faute de charte dans le logo se propage partout.
  `logo-mono.svg` en a zéro, et c'est voulu.

## À voir en vrai

`http://localhost:8001/charte/planche.html` § 1 affiche les quatre fichiers
aux tailles où ils seront réellement vus : 16, 24, 32, 64, 128 px.
