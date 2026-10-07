# LOI.md — le dossier des règles de Tasswiya

> **Ceci n'est pas un conseil juridique.** Ce document est une note de travail
> de développeur. Il sert à écrire du code, pas à décider d'un dossier réel.
> Pour une situation réelle, il faut un avocat.

> **La seule version officielle du texte est en arabe.** Il n'existe à ce jour
> aucune traduction française officielle de la loi n° 71.24 (voir § 3.C6).
> Tout énoncé français de ce document est une **traduction de travail**, faite
> ici, non opposable. Chaque règle porte donc son arabe source quand le mot
> compte.

---

## 0. Ce que ce document est, et comment le lire

La machine à états de Tasswiya est la traduction en code de ce fichier. Qui le
lit dans six mois doit pouvoir répondre à deux questions sur n'importe quelle
ligne du programme : **d'où vient cette règle** et **à quel point est-elle
sûre**.

### Échelle de certitude

| Degré | Ce que ça veut dire |
|---|---|
| **établi** | Lu sur le texte officiel (BO n° 7478, arabe), ou lu sur l'édition officielle du Code de commerce pour un article que la loi 71.24 ne touche pas. Ou bien : plusieurs sources indépendantes et concordantes, dont au moins une officielle. |
| **probable** | Sources concordantes mais secondaires, ou texte officiel lu à travers une reconstitution explicite (fragment élidé par le BO recollé sur le code antérieur). |
| **incertain** | Sources divergentes, source unique, ou pièce non trouvée. **Doit être signalé à l'écran, pas seulement ici.** |

### La règle d'affichage qui découle de cette échelle

- Une règle **établie** s'affiche normalement, avec son article en légende.
- Une règle **probable** s'affiche avec la mention de la source secondaire et
  non de la loi (« instruction du parquet », « doctrine », jamais « loi 71-24 »).
- Une règle **incertaine** ne pilote aucun calcul silencieusement : elle
  déclenche une **alerte** (« ce point n'est pas tranché, vérifiez ») plutôt
  qu'une conclusion. **En cas de doute, le produit alerte, il ne laisse pas
  passer.**

### Une remarque de méthode qui sert partout

L'**article premier** de la loi 71.24 énumère limitativement les articles
modifiés : 240, 242, 295, 306, 310, 311, 312, 313, 314, 317, 318, 319, 320.
L'article deuxième abroge-remplace 316 et 325. L'article troisième crée
231-1 à 231-4. L'article quatrième abroge 328.

**Conséquence : tout autre article du Code de commerce (268, 270, 271, 307,
309, 315, 321, 322, 323, 324, 326…) est inchangé.** Le lire dans l'édition
officielle pré-réforme donne donc le droit en vigueur, et ces règles sont
**établies**, pas « pré-réforme donc douteuses ». Réserve : inchangé *par la
loi 71.24* ; le dépouillement des BO 7479 à 7548 n'a trouvé aucune autre
modification du Code de commerce touchant le chèque (§ 3.C7).

---

## 1. Les pièces, et leur rang

Les fichiers sont dans `docs/sources/` de ce dépôt, pour que les commandes de
ce document soient rejouables.

| Rang | Pièce | Fichier local | Ce qu'elle apporte |
|---|---|---|---|
| **1** | **Bulletin officiel n° 7478**, édition générale **arabe**, 9 chaabane 1447 (29 janvier 2026), 115ᵉ année. Dahir n° 1.26.03 du 2 chaabane 1447 (22 janvier 2026) + texte intégral de la loi n° 71.24, **pages 838 à 842**. | `BO_7478_ar.pdf`, extrait `loi_71-24_extrait_BO7478.txt` | Le texte. Source primaire unique de toutes les citations arabes de ce document. |
| **1 bis** | **Code de commerce (loi 15-95) consolidé pré-réforme**, édition officielle de la Présidence du ministère public. | `code_commerce_avant_reforme_PMP.pdf/.txt` | (a) l'état antérieur, pour mesurer ce que la réforme change ; (b) les **articles non modifiés**, qui sont donc le droit en vigueur ; (c) les **fragments que le BO élide** et qu'on recolle. |
| **2** | **Circulaire de la Présidence du ministère public** sur la mise en œuvre de la loi 71.24 — apparemment **n° 2/2026 du 3 février 2026**. Copie **tierce**, scan + OCR. | `circulaire_parquet_2-2026_OCR.txt` | La doctrine d'application du parquet : prolongation « +30 jours », renvoi à l'art. 161 CPP, régime transitoire, mécanique du classement. **Ce n'est pas la loi.** |
| **3** | **Observatoire national de la criminalité** (ministère de la Justice), page française. | — | Confirme indépendamment le périmètre (13/2/4/1) et, surtout, la prolongation « équivalente ou plus ». **Contient une erreur** (§ 4.7). |
| **4** | Études de praticiens **signées** : Saïd Bouttouil (Hespress, 23/02/2026, reprise par Le12) ; Me Laila Touhami Kadiri (fnh.ma) ; Me Abdelhaq Bolgot (Challenge, repris par Le360 — décrit le **projet**, pas le texte publié) ; Cabinet Aghnaj & Associés. | — | Les trous du texte, la pratique de terrain, le volet bancaire. |
| **5** | Presse marocaine (Médias24, LesEco, 2M, SNRT, Maroc Hebdo, Le Nouvelliste, L'ODJ, madar21…). | — | Chiffres du phénomène. **Source de la plupart des erreurs arbitrées au § 4.** La plupart de ces articles paraphrasent la même dépêche : ils comptent pour **une** source. |
| **6** | Pages de « cabinets » sans signature ni citation d'article (avocatmarocain.com, avocat-jawhari.com, quactus.fr, alamalkanoun, concoursmarocofficiel…). | — | **À ne jamais citer dans le produit.** Deux chiffres franchement faux y ont été relevés (§ 4.4, § 4.9). |

### Les pièces qu'on n'a pas

- **Le PDF officiel de la circulaire du parquet.** Seule une copie tierce
  OCRisée. Son numéro et sa date restent **probables**.
- **Les circulaires de Bank Al-Maghrib** annoncées par les articles 240, 242,
  231-1 et 231-4. Aucune trouvée. **« Je n'en ai trouvé aucune » n'est pas
  « il n'en existe aucune ».**
- **Une traduction française officielle.** Elle n'existe pas (§ 3.C6).
- **Toute jurisprudence.** Aucune décision publiée sur le nouvel article 325.

### Piège d'extraction à connaître avant de lancer un grep

L'extraction texte du BO 7478 rend certaines ligatures autrement :
`ثلاثين` sort en **`ثالثين`**, et `لا` sort en **`ال`**. Chercher la bonne
graphie renvoie zéro à tort.

```sh
grep -c "ثلاثين" docs/sources/loi_71-24_extrait_BO7478.txt   # 0  (faux négatif)
grep -c "ثالثين" docs/sources/loi_71-24_extrait_BO7478.txt   # 1  (la vraie occurrence)
```

---

## 2. Il y a cinq horloges, pas une

C'est la première chose que le modèle de données doit encaisser. Un champ
unique `dateLimiteRegularisation` dans l'entité `Dossier` ferait **mentir** le
produit.

| # | Horloge | Point de départ | Durée | Effet si elle expire | Article | Degré |
|---|---|---|---|---|---|---|
| H1 | **Présentation au paiement** | date d'émission **portée sur le chèque** | 20 j (émis et payable au Maroc) / 60 j (émis à l'étranger) | les recours cambiaires tombent ; le tiré doit **quand même** payer (art. 271) | 268 (non modifié) | établi |
| H2 | **Pénalité bancaire** | date de l'**injonction bancaire** (الإنذار), que la banque envoie dans les 2 jours de l'incident | **3 mois** | la pénalité de l'art. 314 **et** l'amende de 6 % de l'art. 307 al. 3 deviennent dues | 314 (nouveau) | établi |
| H3 | **Régularisation pénale** | date de l'**إعذار** (mise en demeure-interrogatoire du parquet) | **30 jours** | l'obstacle procédural à la poursuite tombe | 325 al. 6 (nouveau) | établi |
| H4 | **Faculté d'émettre** | **expiration du délai de présentation** | **2 ans** | la régularisation bancaire n'est plus possible par cette voie | 313 (nouveau) | établi |
| H5 | **Interdiction bancaire** | date de l'**incident de paiement** | **5 ans** | fin de l'interdiction | 312 et 313 (nouveaux) | établi |

Et une sixième, judiciaire, distincte : l'**interdiction prononcée par le
tribunal** (art. 317), de 1 à 5 ans dans le texte antérieur, dont la durée est
élidée au BO (§ 3.B17) — **à ne pas confondre avec H5**.

**La chronologie réelle**, qui est l'ossature de la machine à états :

```
émission ─H1(20/60 j)→ présentation ─→ incident de paiement (عارض الأداء)
   ├─ 2 j → injonction bancaire (art. 313) ─H2(3 mois)→ pénalité art. 314 due
   ├─ H5(5 ans) interdiction d'émettre
   ├─ H4(2 ans depuis l'expiration de H1) fenêtre de régularisation bancaire
   └─ plainte → إعذار-interrogatoire par OPJ + contrôle judiciaire
                     └─H3(30 j)→ [prolongation possible] → poursuite possible
```

**Le « +31 jours » de la démonstration doit compter depuis l'إعذار**, pas
depuis le rejet du chèque. L'écran doit montrer l'إعذار comme l'événement
déclencheur, sinon la démonstration est juste et le modèle est faux.

---

## 3. Les règles

### A. Le circuit bancaire

**A1 — Délai de présentation : 20 jours / 60 jours.** Un chèque émis et
payable au Maroc doit être présenté dans les 20 jours ; 60 jours s'il est émis
à l'étranger et payable au Maroc. Le décompte part de la **date d'émission
portée sur le chèque**, pas de la remise réelle.
*Source :* art. 268, non modifié par la loi 71.24, lu dans l'édition
officielle du Code. — **établi**

**A2 — Le chèque présenté tard doit quand même être payé.** Le tiré doit payer
même après l'expiration du délai de présentation. « Délai de présentation
expiré » n'est donc **pas un état terminal** : il ne fait tomber que les
recours cambiaires.
*Source :* art. 270 et 271, non modifiés. — **établi**

**A3 — Certificat de refus de paiement.** Toute banque qui refuse le paiement
d'un chèque tiré sur ses caisses doit remettre au porteur ou à son mandataire
un certificat de refus de paiement, dont les mentions sont fixées par Bank
Al-Maghrib (circulaire n° 5/G/97 du 18/09/1997).
*Source :* art. 309, non modifié. — **établi** pour l'obligation ;
**incertain** pour le contenu exact du certificat (circulaire 5/G/97 non lue :
flux PDF non extractible).

**A4 — Injonction bancaire sous deux jours, chèque par chèque.** La banque
tirée doit, **pour chaque chèque séparément**, par tout moyen prouvant l'envoi,
**dans un délai de deux jours à compter de la date de l'incident**, enjoindre
au titulaire de restituer contre récépissé les formules en sa possession et de
ne plus émettre, pendant cinq ans, de chèques autres que [formules de retrait
ou chèques certifiés — fragment élidé].
*Source :* art. 313 nouveau, BO p. 840 : « *يجب على … وفاء شيك لعدم توفر أو
كفاية المؤونة أن تأمر صاحب الحساب بالنسبة لكل شيك على حدة، بكل وسيلة تثبت
توجيه الأمر، داخل أجل يومين ابتداء من تاريخ العارض* ». Le délai de deux jours
et le « chèque par chèque » sont **nouveaux** : l'ancien art. 313 ne les
contenait pas. — **établi**

**A5 — Un seul ordre pour tous les chèques du même jour.** Si plusieurs chèques
sans provision ou à provision insuffisante sont présentés au paiement **le même
jour**, la banque doit adresser **une seule injonction** couvrant tous ces
chèques.
*Source :* art. 313 nouveau, verbatim : « *إذا تم تقديم عدة شيكات للوفاء تكون
مؤونتها منعدمة أو غير كافية في نفس اليوم، يتعين على المؤسسات البنكية توجيه أمر
واحد يخص جميع الشيكات التي تم تقديمها* ». Règle **nouvelle**. — **établi**

**A6 — Interdiction bancaire d'émettre : cinq ans, contre dix auparavant.**
Cinq ans à compter de la date de l'incident de paiement enregistré au nom du
titulaire pour défaut de provision suffisante, sauf régularisation selon
l'art. 313.
*Source :* art. 312 et 313 nouveaux (« *خمس سنوات* », lu deux fois) contre les
anciens art. 312 et 313 (« *عشر سنوات* », lu dans l'édition officielle). La
division par deux est donc **établie sur deux textes primaires**, et non plus
sur une source secondaire. — **établi**

```sh
grep -c "خمس سنوات"  docs/sources/loi_71-24_extrait_BO7478.txt            # 3
grep -c "عشر سنوات"  docs/sources/code_commerce_avant_reforme_PMP.txt     # 4
```

**A7 — Pénalité bancaire de régularisation : 0,5 % / 1 % / 1,5 %, plancher
500 DH, plafond 50 000 DH.** Barème selon le rang de l'injonction de
l'art. 313 : 1ʳᵉ injonction 0,5 %, 2ᵉ 1 %, 3ᵉ **et suivantes** 1,5 %. Si le
montant de la provision est inférieur à la valeur du chèque au jour de sa
présentation, **la pénalité ne peut porter que sur le montant du manquant**
(مبلغ الخصاص). Plancher **500 DH**, plafond **50 000 DH**.
*Source :* art. 314 nouveau, BO p. 840 : pourcentages, « *لا يمكن أن تشمل إلا
مبلغ الخصاص* », « *يحدد الحد الأدنى … في 500 درهم والأقصى في 50.000 درهم* ».
Les assiettes de chaque pourcentage sont **élidées** par le BO et recollées
sur l'ancien art. 314 (« *من مبلغ الشيك أو الشيكات غير المؤداة موضوع الإنذار
الأول المنصوص عليه في المادة 313* ») : le sens est certain, le libellé exact
est **probable**. — **établi** pour les chiffres, **probable** pour le libellé
des assiettes.

**A8 — La fenêtre de trois mois exonère deux amendes, pas une.** La pénalité
de l'art. 314 **et** l'amende prévue au **3ᵉ alinéa de l'article 307** ne sont
pas dues si le titulaire du compte régularise ou constitue la provision du
chèque impayé **dans les trois mois à compter de la date de l'injonction
bancaire** (الإنذار).
*Source :* art. 314 nouveau, dernier alinéa, verbatim : « *لا تفرض الغرامة …
وكذا الغرامة المنصوص عليها في الفقرة الثالثة من المادة 307 … إذا بادر صاحب
الحساب إلى تسوية أو توفير مؤونة الشيك غير المؤدى داخل أجل ثلاثة أشهر ابتداء من
تاريخ الإنذار* ». **Et l'article 307 al. 3, que la loi 71.24 ne modifie pas,
dit ce qu'il exonère** : « *يعاقب بنفس الغرامة الساحب الذي أغفل أو لم يقم
بتوفير المؤونة لأداء الشيك حين تقديمه* », la « même amende » étant celle de
l'art. 307 al. 1 = **6 % du montant du chèque, minimum 100 DH** ; et l'al. 4
limite cette amende à la différence quand la provision est partielle.
— **établi** (les deux textes lus).

**A9 — Recouvrement de la faculté d'émettre : deux ans et deux conditions.**
Le titulaire recouvre la faculté d'émettre, sous réserve de l'art. 317, s'il
établit : (1) qu'il a payé le montant du chèque ou constitué une provision
suffisante et disponible sur son compte **dans un délai de deux ans à compter
de la date d'expiration du délai de présentation au paiement** ; (2) qu'il a
payé la pénalité (الذعيرة) de l'art. 314. **La régularisation lève
l'interdiction et purge tous ses effets.**
*Source :* art. 313 nouveau, BO p. 840 : « *خلال مدة سنتين ابتداء من تاريخ
انتهاء أجل التقديم للوفاء* » et « *تؤدي التسوية إلى رفع المنع … وتطهير جميع
الآثار المترتبة عليه* ». La fenêtre de deux ans est **nouvelle** : l'ancien
art. 313 posait les deux mêmes conditions **sans aucun délai**. — **établi**
(la « purge des effets » est l'état qu'une transition `regularisationBancaire`
doit produire).

**A10 — Consultation obligatoire avant délivrance d'un chéquier, et chèques
barrés par défaut.** Avant de délivrer des formules à un client, toute banque
doit consulter le service de centralisation des incidents de paiement sur
chèques de l'art. 160 de la loi n° 103.12. Les banques délivrent par défaut
des formules **barrées** ou portant « non endossable sauf au profit d'un
établissement bancaire ». Si le client demande **expressément** des formules
ordinaires, la banque doit y répondre **dans un délai maximal de 15 jours**.
*Source :* art. 310 nouveau, BO p. 839-840. — **établi**

**A11 — Restitution des formules.** La banque peut, par décision motivée,
réclamer la restitution des formules déjà délivrées ; en cas de clôture du
compte, elle doit enjoindre la restitution de toutes les formules détenues par
le titulaire **et ses mandataires**.
*Source :* art. 311 nouveau. — **établi**

**A12 — Déclaration des incidents à Bank Al-Maghrib : le délai n'est pas dans
la loi.** Les banques doivent déclarer tout incident de paiement à BAM « dans
un délai fixé par Bank Al-Maghrib », sous peine des amendes de l'art. 319.
*Source :* art. 322, non modifié par la loi 71.24. Le délai est donc
**réglementaire** et la circulaire BAM qui le fixe n'a pas été trouvée.
— **établi** pour le renvoi, **incertain** pour le délai lui-même.
→ Tasswiya **ne doit afficher aucun délai de déclaration**.

**A13 — Gel électronique du montant : à deux consentements.** À la demande du
porteur **ou** du bénéficiaire **et sur ordre du tireur**, le montant du chèque
peut être gelé par voie électronique à distance ; le régime du chèque certifié
s'y applique ; les modalités sont fixées par une **دورية** du Wali de Bank
Al-Maghrib.
*Source :* art. 242 nouveau : « *يمكن بطلب من حامل الشيك أو المستفيد وبأمر من
الساحب، تجميد مبلغ الشيك بطريقة إلكترونية عن بعد* ». — **établi** pour la
règle. **Ce n'est pas une faculté unilatérale du bénéficiaire** : un bouton
« geler le montant » côté créancier modéliserait une règle inexistante. Et la
circulaire d'application n'a pas été trouvée : **inopérant à notre
connaissance** — formulation à employer, et non « inapplicable ».

**A14 — Validité formelle du chèque.** Tout chèque non conforme aux formules
délivrées par la banque, ou auquel manque une mention obligatoire, est
**invalide comme chèque** mais « *قد يعتبر سندا عاديا لإثبات الدين* » (peut
valoir titre ordinaire de preuve de la dette) si les conditions de ce titre
sont réunies. Les modèles de formules sont fixés par **منشور** du Wali de BAM
(non trouvé).
*Source :* art. 240 nouveau. — **établi**

**A15 — Paiement entre commerçants : chèque barré ou virement au-delà de
10 000 DH.** Sanction : amende d'au moins **6 %** de la somme payée, solidaire
entre créancier et débiteur.
*Source :* art. 306 nouveau pour le seuil (« *عشرة آلاف درهم* ») ; la sanction
est **élidée** au BO (« *يعاقب على عدم …* » puis « le reste sans changement »)
et recollée sur l'ancien art. 306 al. 2 et 3. — **établi** pour le seuil,
**probable** pour la sanction.
⚠️ **Le seuil de 10 000 DH n'est pas un apport de la réforme** : le code
antérieur le portait déjà. Le présenter comme une nouveauté de 2026 serait
faux.

**A16 — Prescription cambiaire : allongée du double.** Actions du porteur
contre les endosseurs et le tireur : **1 an** à compter de l'expiration du
délai de présentation. Actions des divers obligés les uns contre les autres :
**1 an**. Action du porteur contre le tiré : **2 ans** à compter de
l'expiration du délai de présentation.
*Source :* art. 295 nouveau (durées lues verbatim) contre l'ancien art. 295
(**6 mois / 6 mois / 1 an**, lu dans l'édition officielle). L'allongement est
donc **établi** sur deux textes primaires — et aucune source secondaire ne le
mentionne.
L'identification précise de chaque action et la réserve finale
(« *غير أنه في حالة …* ») sont **élidées** par le BO ; la réserve antérieure,
qui est vraisemblablement reprise à l'identique, maintient le droit d'agir
contre le tireur qui n'a pas fourni la provision.
— **établi** pour les durées, **probable** pour l'imputation des actions.

### B. Le circuit pénal

> **Le nouvel article 325 a onze alinéas.** Ils sont listés dans leur ordre
> réel ci-dessous, parce que le texte lui-même s'y réfère par numéro
> (l'alinéa 8 renvoie à « *الفقرة السادسة أعلاه* » = l'alinéa 6, celui des
> 30 jours — recoupement interne qui valide cette numérotation).

**B1 — L'إعذار est une condition préalable obligatoire de la poursuite.**
« *يجب أن يسبق المتابعة إعذار ساحب الشيك* » : les poursuites **doivent** être
précédées d'une mise en demeure du tireur.
*Source :* art. 325 al. 6. — **établi**
→ Dans la machine à états, c'est une **garde sur la transition vers la
poursuite**, pas une étape informative.

**B2 — Trente jours, à compter de la date de l'إعذار.** « *… بأن يقوم بتسوية
وضعيته خلال أجل ثلاثين (30) يوما من تاريخ هذا الإعذار* ».
*Source :* art. 325 al. 6, verbatim. — **établi**
→ **Point de départ : ni la présentation, ni le rejet, ni la plainte, ni
l'injonction bancaire.** L'إعذار est un acte du parquet exécuté par la police
judiciaire, postérieur à la plainte, donc postérieur de plusieurs semaines ou
mois au rejet bancaire.

**B3 — Forme de l'إعذار : un interrogatoire par un officier de police
judiciaire.** « *ويتم الإعذار المذكور في شكل استجواب، يقوم به أحد ضباط الشرطة
القضائية، وذلك بناء على تعليمات من النيابة العامة* ».
*Source :* art. 325 al. 7. — **établi**
→ **Aucune notification par huissier ni lettre recommandée n'est prévue.** La
preuve de la date de départ est le **procès-verbal d'audition**. Le modèle de
données doit donc porter un événement `EcedarNotifie` dont la pièce
justificative est un PV, pas un accusé de réception postal.

**B4 — Le délai n'est pas un délai de grâce : contrôle judiciaire obligatoire.**
« *مع إخضاع ساحب الشيك المعني، لواحد أو أكثر من تدابير المراقبة القضائية بما
فيها السوار الإلكتروني* » : le tireur est soumis à **une ou plusieurs** mesures
de contrôle judiciaire, **bracelet électronique compris**. La rédaction est à
l'impératif.
*Source :* art. 325 al. 7. — **établi**
La circulaire du parquet précise que la mesure est prise parmi celles de
l'**article 161 du Code de procédure pénale** — et écrit « *لأحد تدابير* »
(l'**une** des mesures) là où la loi écrit « une ou plusieurs ».
— renvoi à l'art. 161 : **probable** (circulaire, copie tierce).
→ Un écran qui affiche « 30 jours pour régulariser » **sans dire que le tireur
est sous contrôle judiciaire** décrit mal la situation de son propre
utilisateur débiteur.

**B5 — Prolongation : décision du parquet + accord du bénéficiaire, durée égale
ou supérieure, aucune limite de nombre.** « *يمكن للنيابة العامة تمديد الأجل،
المنصوص عليه في الفقرة السادسة أعلاه، لمدة مماثلة أو أكثر، بعد موافقة
المستفيد، مع استمرار مفعول تدبير المراقبة القضائية المتخذ في حقه بما فيه
السوار الإلكتروني* ».
*Source :* art. 325 al. 8, verbatim. — **établi**

Quatre choses, toutes dans ce seul alinéa :
1. La **décision appartient au ministère public** (« يمكن للنيابة العامة »).
2. L'**accord du bénéficiaire est une condition**, pas le déclencheur. Le
   parquet peut refuser **même si** le bénéficiaire accepte ; son accord est
   sans effet si le bénéficiaire refuse. Deux conditions **cumulatives et
   asymétriques**.
3. La durée est « **égale ou supérieure** » au délai initial : **plancher
   30 jours, aucun plafond**.
4. **Aucune limite de nombre de prolongations.** Les mots « une seule fois »
   (مرة واحدة) **ne figurent ni dans la loi, ni dans la circulaire** :

```sh
grep -c "مرة واحدة"      docs/sources/loi_71-24_extrait_BO7478.txt       # 0
grep -c "مرة واحدة"      docs/sources/circulaire_parquet_2-2026_OCR.txt  # 0
grep -c "مماثلة أو أكثر" docs/sources/loi_71-24_extrait_BO7478.txt       # 1
grep -c "30 يوما إضافية" docs/sources/circulaire_parquet_2-2026_OCR.txt  # 2 (le fichier contient deux passes d'OCR du même document)
```

5. Le contrôle judiciaire **continue** pendant la prolongation.

→ **C'est la règle la plus importante du projet et celle que le brief avait
fausse.** Arbitrage complet au § 4.1.

**B6 — Extinction de l'action publique : paiement ou désistement, PLUS 2 %.**
« *يترتب عن الأداء أو التنازل عن الشكاية بالنسبة لساحب الشيك الذي أغفل الحفاظ
على المؤونة أو تكوينها قصد الوفاء بالشيك عند تقديمه، عدم تحريك الدعوى العمومية
أو سقوطها حسب الحالة وذلك بعد أدائه غرامة تحدد قيمتها في اثنين (2%) بالمائة من
مبلغ الشيك أو الخصاص* ».
*Source :* art. 325 al. 1, verbatim. — **établi**

Quatre conséquences à encoder :
1. Les conditions sont **cumulatives** : sans l'amende de 2 %, la poursuite
   subsiste.
2. L'assiette est **« du montant du chèque OU du manquant »** (الخصاص) : donc
   le manquant dès qu'il existe une provision partielle. Même logique qu'à
   l'art. 314 (A7).
3. **C'est une amende (غرامة), versée à la caisse du tribunal selon la
   circulaire — pas une indemnité au bénéficiaire.** L'indemnisation du
   bénéficiaire est un objet séparé (B10). Ne pas confondre les deux dans le
   modèle de données.
4. Ce régime est **limité au cas de l'article 316 point 1** (omission de
   maintenir ou constituer la provision) : l'alinéa le dit lui-même en
   désignant « *ساحب الشيك الذي أغفل الحفاظ على المؤونة أو تكوينها* ». Il ne
   couvre **ni** l'opposition irrégulière, **ni** le faux, **ni** le chèque de
   garantie.

**B7 — Après une condamnation définitive.** Si le paiement ou le désistement
intervient après une décision passée en force de chose jugée, il **met fin à
l'exécution de la peine privative de liberté et efface ses effets**, après
paiement de l'amende prononcée au titre de l'art. 316 al. 1 (5 000 à
20 000 DH).
*Source :* art. 325 al. 2. — **établi**
→ **Deux amendes se cumulent après condamnation.** Avant condamnation, les 2 %
suffisent. La règle dépend donc de l'état du dossier : **c'est exactement une
transition de machine à états**, et une source d'erreur si on la code comme un
montant unique.
→ Limite relevée par le juge Bouttouil : cet effet ne vaudrait que pour le
délit d'omission de provision, et non pour les autres délits de l'art. 316.
— **probable** (praticien ; cohérent avec le libellé de l'alinéa 1).

**B8 — Réhabilitation judiciaire.** Le condamné peut, en tout état de cause,
demander la réhabilitation judiciaire (رد الاعتبار القضائي) **dès le paiement
des deux amendes** visées aux deux alinéas précédents.
*Source :* art. 325 al. 3. — **établi**

**B9 — Cause de justification familiale, plus étroite qu'on ne le lit
partout.** « *لا جريمة ولا عقوبة* » (ni infraction ni peine) dans les cas du
**point (1) de l'art. 316 uniquement**, lorsqu'il s'agit d'**époux**,
d'**ascendants** ou de **descendants au premier degré** ; sans préjudice du
droit de la partie lésée d'agir au civil. Pour les époux, la cause joue encore
**pendant les quatre années suivant la dissolution du lien conjugal**.
*Source :* art. 325 al. 4 et 5. — **établi**
→ Trois restrictions que presse et notes de cabinet effacent : **premier degré
seulement**, **point 1 seulement**, et la **fenêtre de 4 ans** après le
divorce, que personne ne mentionne. Conséquence opérationnelle (Bouttouil) :
en cas d'ex-conjoint, il faut la date du divorce et le calcul des 4 ans comme
fin de non-recevoir — **donc un champ de date de plus dans le modèle**.

**B10 — Consignation au greffe sans transaction ni désistement.** Si le tireur
dépose la valeur du chèque à la caisse du tribunal **sans** qu'il y ait
transaction (صلح) ni désistement, le bénéficiaire peut demander
l'**indemnisation civile** devant le juge civil.
*Source :* art. 325 al. 9. — **établi**
(Et l'art. 326, non modifié, permet par ailleurs au porteur constitué partie
civile de réclamer devant le juge pénal un montant égal à la valeur du chèque.
— **établi**.)

**B11 — Irrévocabilité de la transaction et du désistement.** « *لا يجوز
الرجوع في الصلح أو التنازل حسب هذه المادة، إلا في الأحوال التي يجيز القانون
الطعن فيه* » : on ne peut pas revenir sur la transaction ou le désistement,
sauf dans les cas où la loi en permet la contestation.
*Source :* art. 325 al. 10. — **établi**
→ **Excellente transition irréversible** pour le graphe d'états : une fois
`desistementEnregistre`, aucune transition de retour.

**B12 — Pas de peines alternatives.** « *لا يحكم بالعقوبات البديلة في الجنح
المنصوص عليها في المادة 316 أعلاه* » : aucune peine alternative (loi 43.22) ne
peut être prononcée pour les délits de l'art. 316. La circulaire du parquet
ordonne même d'appeler des jugements qui en prononceraient.
*Source :* art. 325 al. 11. — **établi**
→ C'est un **durcissement**, au milieu d'une réforme qu'on présente comme un
allègement (§ 4.10).

**B13 — Les peines de l'article 316 nouveau.**

| Fait | Peine | Amende |
|---|---|---|
| (1) tireur qui a **omis de maintenir ou de constituer la provision** en vue du paiement à la présentation ; (2) tireur qui fait **opposition de manière irrégulière** auprès du tiré | **6 mois à 3 ans** | 5 000 à 20 000 DH |
| (1) **contrefaçon ou falsification** d'un chèque ; (2) **acceptation, endossement ou aval en connaissance de cause** d'un chèque faux ; (3) **usage ou tentative d'usage** en connaissance de cause | **1 à 5 ans** | 20 000 à 50 000 DH |
| **chèque de garantie** — voir B14 | **aucune prison** | 2 % de la valeur du chèque |

Plus la **confiscation et destruction** des chèques faux, et la confiscation
par ordonnance judiciaire du matériel ayant servi ou destiné à les produire,
**sauf usage à l'insu du propriétaire** (le texte vise l'absence de
connaissance, non la bonne foi).
*Source :* art. 316 nouveau, intégralement reproduit au BO p. 840-841.
— **établi**

*Pour mémoire, l'ancien art. 316 :* **1 à 5 ans** + amende de **2 000 à
10 000 DH**, « *dont le montant ne peut être inférieur à vingt-cinq (25) pour
cent du montant du chèque ou du manquant* », pour **six cas confondus** (dont
le chèque de garantie). Lu dans l'édition officielle. — **établi**
→ L'éclatement en trois régimes est l'apport majeur, et le **25 % était le
plancher de l'amende pénale**, pas un taux de régularisation (§ 4.2).

**B14 — Chèque de garantie : l'amende frappe celui qui le reçoit, pas le
tireur.** « *يعاقب بغرامة تحدد قيمتها في اثنين (2%) بالمائة من قيمة الشيك كل
شخص قام عن علم بقبول تسلم أو تظهير شيك شرط أن لا يستخلص فورا وأن يحتفظ به على
سبيل الضمان* ». Si cette amende est payée **avant** une décision passée en
force de chose jugée, l'action publique n'est pas mise en mouvement ou s'éteint
selon le cas. Et « en toute hypothèse, l'acceptation d'un chèque à titre de
garantie n'empêche pas d'en réclamer la valeur ».
*Source :* art. 316 nouveau, dernier bloc. — **établi**
→ Deux pièges : (a) le **débiteur de cette amende est le bénéficiaire**, pas le
tireur ; (b) l'assiette est « **de la valeur du chèque** » **sans** « ou du
manquant », contrairement aux 2 % de l'art. 325. Ce sont **deux amendes de 2 %
différentes** (§ 4.3).

**B15 — Article 318 : aggravé.** **3 mois à 2 ans** + amende de **5 000 à
20 000 DH** pour celui qui émet des chèques en violation de l'art. 313 ou de
l'interdiction prononcée contre lui au titre de l'art. 317.
*Source :* art. 318 nouveau. Ancien art. 318 : **1 mois à 2 ans** + **1 000 à
10 000 DH**. Minimum de prison **triplé**, amendes **×5** et **×2**. La
circulaire du parquet l'écrit elle-même (« *رفع العقوبة المقررة في المادة
318* »). — **établi**

**B16 — Article 319 : l'amende contre la banque, doublée.** Amende de **5 000
à 50 000 DH** (fourchette inchangée) contre le tiré dans trois cas renvoyant
aux art. 271, 273, 309, 311, 312, 313 et 317. **Doublée** lorsque, pour un
incident de paiement non régularisé par le titulaire au titre de l'art. 313,
la banque n'établit pas avoir adressé au titulaire, **pour un incident
antérieur**, l'injonction de restituer les formules.
*Source :* art. 319 nouveau ; le libellé des trois cas est **élidé**.
— **établi** pour le montant et la clause de doublement ; **incertain** pour le
détail des trois cas.
→ C'est le seul mécanisme chiffré de type « progression », et il frappe **la
banque**, pas le tireur.

**B17 — Interdiction judiciaire d'émettre (art. 317).** Le tribunal peut
interdire au condamné d'émettre des chèques autres que de retrait ou certifiés,
**et** — ajout de la réforme — lui interdire d'émettre des chèques **en vertu
d'un mandat donné par une personne physique** ; la publication de la décision
peut être ordonnée **aux frais du condamné**.
*Source :* art. 317 nouveau pour les ajouts (lus verbatim) ; **la durée est
élidée** (« *يجوز للمحكمة في …* » puis « le reste sans changement »). L'ancien
art. 317 disait **1 à 5 ans**. — **probable** pour la durée.
→ À **ne pas confondre** avec l'interdiction bancaire de 5 ans (A6).

**B18 — Récidive : il existe une règle, et elle n'est pas où on la cherche.**
L'article **323**, que la loi 71.24 ne modifie pas, dispose que les faits punis
par les **articles 317 et 318** constituent **la même infraction** pour
l'application de la récidive.
*Source :* art. 323, édition officielle, non modifié. — **probable** (non
reproduit au BO 7478 : son maintien résulte de l'absence de l'art. 323 dans la
liste de l'article premier).
→ **La loi 71.24 ne définit aucune récidive du tireur de chèque sans
provision.** Si Tasswiya veut un état « récidive », il doit être construit sur
le **compteur d'injonctions de l'art. 313** (qui pilote le barème 0,5 / 1 /
1,5 %), et nommé comme tel — pas sur une notion pénale que ce texte ne porte
pas.

**B19 — Aucun seuil de « petit chèque ».** Rien dans la loi 71.24 ne module le
délai, la prolongation ou les 2 % selon le montant. Les seuls montants lus
sont : plancher 500 DH et plafond 50 000 DH de la pénalité bancaire (art. 314),
seuil de 10 000 DH de l'art. 306, 6 % et minimum 100 DH de l'art. 307, et les
fourchettes d'amendes pénales. — **établi**
→ Le contrôle judiciaire obligatoire s'applique donc quel que soit le montant,
ce que le juge Bouttouil critique explicitement comme disproportionné.

### C. Périmètre, entrée en vigueur, transitoire

**C1 — Identité du texte.** Loi n° **71.24** modifiant et complétant la loi
n° **15.95** formant Code de commerce, promulguée par le **dahir n° 1.26.03 du
2 chaabane 1447 (22 janvier 2026)**, publiée au **Bulletin officiel n° 7478**,
édition générale **arabe**, 9 chaabane 1447 (**29 janvier 2026**), 115ᵉ année,
**pages 838 à 842**.
*Source :* BO 7478, lu. — **établi**
⚠️ Le même BO publie les lois **70.24** (dahir 1.26.02), 74.24, 54.23 et 52.23,
tous avec des dahirs du 22 janvier 2026. Les numéros voisins sont proches et
les dates identiques.

**C2 — Entrée en vigueur : le 29 janvier 2026, dès la publication.** La loi
71.24 **ne contient aucun article d'entrée en vigueur** : elle s'arrête à
l'« article cinquième ». Contre-exemple instructif dans le **même** bulletin :
la loi 52.23 sur les traducteurs assermentés prévoit explicitement une entrée
en vigueur trois mois après publication. La circulaire du parquet le confirme
(« *دخل حيز التنفيذ من تاريخ نشره في الجريدة الرسمية* »).
— **établi**
→ **Trois dates circulent** : 22 janvier (dahir), 29 janvier (publication =
entrée en vigueur), 3 février (circulaire). La bonne est le **29 janvier
2026**.

**C3 — Périmètre : 13 modifiés, 2 abrogés-remplacés, 4 nouveaux, 1 abrogé.**
Modifiés : 240, 242, 295, 306, 310, 311, 312, 313, 314, 317, 318, 319, 320.
Abrogés et remplacés : **316** et **325**. Nouveaux : **231-1 à 231-4**
(chapitre XIV, « dispositions propres à la lettre de change tirée sur une
banque »). Abrogé : **328** (chèques postaux).
*Source :* BO 7478, article premier à article quatrième, vérifié article par
article ; décompte corroboré au mot par l'Observatoire national de la
criminalité. — **établi**

**C4 — La seule disposition transitoire de la loi ne concerne pas le chèque.**
L'article cinquième exclut les **lettres de change** créées avant l'entrée en
vigueur et tirées sur une banque de l'application du nouvel art. 231-1. **Rien,
dans la loi, sur les poursuites de chèque en cours.**
*Source :* BO 7478, article cinquième. — **établi**

**C5 — Le régime transitoire du chèque vient de la circulaire, pas de la loi.**
Selon la circulaire du parquet : les dispositions **procédurales** s'appliquent
immédiatement, mais **les poursuites engagées avant le 29 janvier 2026 ne sont
pas soumises à la nouvelle formalité de l'إعذار** (« *وتكون المتابعات الجارية
قبل 29 يناير 2026 غير خاضعة للشكليات الجديدة (الإعذار)* ») ; les dispositions
**substantielles** s'appliquent à **toutes** les poursuites en cours au titre
de la **loi plus douce** (القانون الأصلح للمتهم) ; les condamnés dont l'affaire
est pendante bénéficient des garanties nouvelles ; les détenus définitifs
peuvent obtenir la suspension de la peine et une libération immédiate s'ils
paient (ou obtiennent un désistement) et acquittent l'amende prononcée.
— **probable** (circulaire, copie tierce OCRisée).
→ **Si Tasswiya encode « dossier ouvert avant le 29/01/2026 ⇒ pas d'إعذار »,
il encode une instruction administrative et doit l'étiqueter comme telle** :
« instruction de la Présidence du ministère public », jamais « loi 71-24 ».
→ Et une note de cabinet (avocat-jawhari.com) affirme l'inverse (« les
procédures engagées antérieurement demeurent soumises aux anciennes règles ») :
c'est faux pour le fond, la loi plus douce s'appliquant rétroactivement.

**C6 — Il n'existe pas de version française officielle.** Le n° 7478 est
l'édition générale **arabe**. L'édition française (« édition de traduction
officielle ») passe du n° **7474** (15 janvier 2026) au n° **7480**
(5 février 2026) : **il n'y a pas de n° 7478 en français**. Les 17 numéros
français de 2026 disponibles, jusqu'au n° 7540 (3 septembre 2026), ne
contiennent ni « 71.24 », ni « 1.26.03 », ni « 15.95 ».
*Source :* listing du portail SGG + sondage HTTP direct + dépouillement des
PDF. — **établi dans la plage vérifiée**
→ **Conséquence directe pour un produit francophone qui affiche « chaque règle
porte sa source » : écrire « art. 325, BO n° 7478 du 29 janvier 2026 » sous un
libellé français est une fausse citation.** Il faut citer l'arabe et marquer la
traduction comme non officielle, sur chaque écran, à côté de l'avertissement
« ceci n'est pas un conseil juridique ».

**C7 — Aucun rectificatif, aucune modification postérieure.** Aucune mention
de « 71.24 », « 1.26.03 » ni « 15.95 » portant sur le chèque dans les BO
édition générale n° **7479 à 7548** (dernier servi au 7 octobre 2026). Le seul
BO qui cite 15.95 est le n° 7533 (10 août 2026), et c'est une réforme de la loi
103.12 sur les établissements de crédit, sans rapport avec le chèque.
— **établi dans la plage vérifiée** ; la période postérieure au 7548 n'est pas
couverte.

**C8 — La loi ne renvoie à aucun décret : elle renvoie à trois circulaires de
Bank Al-Maghrib.** Un **منشور** du Wali pour les modèles de formules de chèque
(art. 240) ; une **دورية** du Wali pour le gel électronique (art. 242) ; un
**منشور** du Wali pour la forme de la lettre de change tirée sur une banque
(art. 231-1) ; et les modalités et délais de déclaration des incidents sur
lettres de change (art. 231-4).
— **établi** quant à leur prévision ; **aucune n'a été trouvée**.
→ Tant qu'elles ne sont pas publiées, ces pans du dispositif sont en vigueur
sans être opérationnels. **Tasswiya ne peut afficher aucune règle sur ces
points.**

**C9 — Lettre de change tirée sur une banque : hors périmètre, mentionné pour
mémoire.** Articles 231-1 à 231-4 nouveaux : forme fixée par circulaire BAM,
support électronique possible, consultation obligatoire du service des effets
de commerce impayés avant délivrance d'un carnet, interdiction de délivrance
pendant **5 ans** après un incident, déclaration obligatoire à BAM **sous peine
d'une amende de 50 000 à 100 000 DH**.
*Source :* BO 7478 p. 842, articles entièrement nouveaux donc publiés sans
élision. — **établi**
→ **Le périmètre de Tasswiya est gelé sur le chèque seul.** Ces articles sont
ici pour qu'on sache qu'on les a lus et écartés, pas pour être implémentés.

---

## 4. Les contradictions, arbitrées

### 4.1 « Prolongeable une seule fois » — la règle n'existe pas. ÉCARTÉE.

**Ce que disent les sources.** Le brief du projet, cabinetkrari.ma
(« renouvelable une fois »), lodj.ma (« un délai d'un mois, renouvelable une
fois »), avocat-jawhari.com (« 30 jours supplémentaires… total maximum :
60 jours ») et l'essentiel de la presse disent : **30 jours, prolongeables une
seule fois de 30 jours, si le bénéficiaire l'accepte**.

**Ce que dit le texte.** L'art. 325 al. 8 dit que le **ministère public** peut
proroger « *لمدة مماثلة أو أكثر* » — **pour une durée égale ou supérieure** —
**après accord du bénéficiaire**. Sans plafond de durée. Sans limite de nombre.

**Qui je crois, et pourquoi.** Le **Bulletin officiel**, pour trois raisons
qui se cumulent :
1. C'est le texte, lu directement, et les mots « مرة واحدة » n'y sont pas (la
   commande qui le montre est au § B5).
2. L'**Observatoire national de la criminalité**, sur le site du ministère de
   la Justice, écrit indépendamment « pour une durée **équivalente ou plus**
   après accord du bénéficiaire » : une source officielle confirme le BO
   **contre** la presse.
3. La **circulaire du parquet elle-même ne dit pas « une seule fois »** : elle
   écrit « *ويمكن تمديد الأجل المذكور إلى 30 يوما إضافية* » — « le délai peut
   être prolongé de 30 jours supplémentaires ». C'est une doctrine
   d'application, pas une limitation de nombre, et surtout **ce n'est pas la
   loi** : une circulaire ne peut pas réduire ce que la loi ouvre.

**Ce que ça change pour le projet.** L'info-bulle prévue — « déjà prolongé une
fois — **loi 71-24** » — attribuerait à la loi 71-24 une règle que la loi
71-24 ne porte pas. C'est exactement le risque que la contrainte n° 1 veut
éviter (« chaque règle affichée porte sa source »).

**Conduite retenue :**
- La garde reste, parce qu'elle est le moment filmé, **mais elle change de
  nature** : c'est un **paramètre produit**, pas une règle de droit. Nom de la
  garde : `prolongationsAutoriseesEpuisees`, pas
  `prolongationDejaJoueeUneFois`.
- Valeur par défaut `1`, **paramétrable**, documentée comme un choix (§ 6, H3).
- **Libellé de l'info-bulle** (le seul qui soit juste) :
  > « Limite de **ce logiciel** : 1 prolongation. La loi 71-24 (art. 325 al. 8)
  > ne limite **pas** le nombre de prolongations ; elle exige une durée au
  > moins égale au délai initial, une **décision du ministère public** et
  > l'**accord du bénéficiaire**. »
- Et une seconde garde, celle-là **vraiment** légale et qui mérite autant la
  caméra : `accordBeneficiaireNonEnregistre`, sourcée sur l'art. 325 al. 8.
- La condition réellement manquante dans le brief, **la décision du parquet**,
  devient un champ du modèle : une prolongation sans décision du parquet
  enregistrée n'est pas une prolongation.

### 4.2 « 2 % contre 25 % auparavant » — faux tel quel. Et ça casse le barème daté.

**Ce que dit le brief** (et SNRT, Le360, Médias24) : la perception qui éteint
les poursuites est « ramenée à 2 % du montant, contre 25 % auparavant ». Le
plan du projet en tirait une entité `BarèmeRégularisation` datée, 25 % avant le
29/01/2026, 2 % après.

**Ce que disent les deux textes primaires.**
- **Ancien art. 316** : emprisonnement de 1 à 5 ans + amende de 2 000 à
  10 000 DH, « *دون أن تقل قيمتها عن خمسة وعشرين في المائة من مبلغ الشيك أو من
  الخصاص* » — **le 25 % était le PLANCHER DE L'AMENDE PÉNALE prononcée par le
  tribunal après condamnation.**
- **Ancien art. 325** : « *إذا قام ساحب شيك بدون مؤونة بتكوين أو إتمام المؤونة
  خلال أجل عشرين يوما من تاريخ التقديم، جاز تخفيض عقوبة الحبس أو إسقاطها* » —
  si le tireur constituait la provision **dans les 20 jours de la
  présentation**, le tribunal **pouvait** réduire ou écarter la **peine de
  prison**. Rien de plus.

**Donc, avant le 29 janvier 2026 : il n'existait AUCUN mécanisme d'extinction
de l'action publique contre paiement d'un pourcentage.** Le 25 % et les 2 % ne
sont pas deux valeurs d'une même grandeur : ce sont **deux objets juridiques
différents** (un plancher d'amende après condamnation / une condition
d'extinction avant condamnation). L'ordre de grandeur de l'allègement est réel,
le raccourci journalistique est compréhensible, mais **un barème daté
25 % → 2 % modéliserait une grandeur qui n'a jamais existé**.

— **établi** (ancien art. 316 et ancien art. 325 lus dans l'édition officielle ;
nouveaux art. 316 et 325 lus au BO).

**Conduite retenue :**
- `BarèmeRégularisation` **ne porte pas de taux à 25 %**. Le régime antérieur
  s'exprime comme un **régime**, pas comme un taux : « pas d'extinction ;
  réduction ou dispense possible de la peine de prison si la provision est
  constituée dans les 20 jours de la **présentation** (ancien art. 325) ».
- Noter au passage que l'ancien point de départ était la **présentation** et
  non l'إعذار : **toute source qui parle de 20 jours, ou d'un délai courant du
  rejet ou de la présentation, décrit l'état antérieur.**
- Et surtout : **le régime antérieur ne s'applique pas aux dossiers anciens.**
  La circulaire applique la **loi plus douce** à toutes les poursuites en
  cours (C5). Un `validFrom`/`validTo` indexé sur la date du chèque serait donc
  faux **deux fois**. Le barème daté doit être indexé sur **l'état du dossier**
  (poursuite pendante ou non), pas sur la date d'émission du chèque — et ce
  point est **probable**, pas établi : il repose sur la circulaire. **Il
  s'affiche avec une alerte.**

### 4.3 Les 2 % désignent deux amendes différentes, et le plancher/plafond appartient à une troisième.

| | Assiette | Qui paie | Effet | Plancher / plafond |
|---|---|---|---|---|
| **Art. 325 al. 1** — 2 % | montant du chèque **ou du manquant** (الخصاص) | le **tireur** | avec le paiement ou le désistement : empêche ou éteint l'action publique | **aucun** |
| **Art. 316** — 2 % | **valeur du chèque** (pas de « ou du manquant ») | **celui qui accepte ou endosse** un chèque de garantie | remplace la prison ; payée avant décision définitive, empêche ou éteint l'action publique | **aucun** |
| **Art. 314** — 0,5 / 1 / 1,5 % | montant du ou des chèques impayés, **réduit au manquant** si provision partielle | le **titulaire du compte**, envers la banque | conditionne le recouvrement de la faculté d'émettre | **500 DH / 50 000 DH** |

**avocat-jawhari.com publie « Taux : 2 %, Minimum : 500 dirhams, Plafond :
50 000 dirhams »** : c'est la fusion de deux amendes qui n'ont ni la même
assiette, ni le même débiteur, ni le même effet. **Reprendre ce triplet
mettrait un chiffre faux sur chaque écran.** Ni le texte, ni la circulaire, ni
l'ONC, ni le juge Bouttouil ne mentionnent de plancher ou de plafond sur les
2 %.

→ Dans le modèle : **trois amendes distinctes**, trois assiettes, trois
débiteurs. Jamais un champ `tauxAmende`.

### 4.4 Le plancher de 500 DH : à écarter sur les 2 %, à conserver sur l'art. 314.

Voir 4.3. L'hypothèse la plus vraisemblable est une recopie de travers :
Bouttouil utilise justement « un chèque de 500 dirhams » comme **exemple de
montant dérisoire** pour critiquer le contrôle judiciaire obligatoire.
— « plancher de 500 DH sur les 2 % » : **écarté**.

### 4.5 Le point de départ des 30 jours : ni la présentation, ni le rejet.

Le brief écrit « un chèque revient impayé… l'émetteur a trente jours ». C'est
faux de plusieurs semaines. Le point de départ est la **date de l'إعذار**
(B2-B3), acte du parquet exécuté par un OPJ, postérieur à la plainte.
Explication probable de l'erreur : **l'ancien art. 325 faisait bien courir
20 jours depuis la présentation** (4.2). La confusion est donc une confusion
ancien/nouveau régime, et c'est la plus coûteuse du dossier.
— **arbitré : le texte gagne.** La machine à états démarre H3 sur
`EcedarNotifie`.

### 4.6 L'ancien barème de l'article 314 : 1 % / 10 % / 20 %, et non 5 / 10 / 20.

L'édition officielle du Code publiée par la Présidence du ministère public
imprime **1 %** pour la première injonction. La doctrine courante (fnh.ma,
upsilon-consulting) cite 5 %. Le 1 % casse la progression géométrique, ce qui
rend l'erreur d'impression plausible — mais **je crois l'édition officielle**,
parce que c'est la seule des deux qui soit un texte et non un commentaire.

**Ce que ça change :** rien sur le droit en vigueur (le nouveau barème
0,5 / 1 / 1,5 % est lu verbatim au BO) ; **tout** sur la façon de raconter
l'ampleur de la baisse. **Donc on ne la raconte pas.** Aucune phrase du genre
« les pénalités ont été divisées par dix » ne doit figurer dans le produit ou
le README : la grandeur de départ n'est pas tranchée.
— **incertain** ; affiché nulle part.

### 4.7 L'ONC dit l'inverse du Bulletin officiel sur les peines.

La page française de l'Observatoire national de la criminalité écrit : « le
**relèvement** du plafond de la peine à trois ans pour le tireur du chèque qui
a omis de maintenir ou de constituer la provision ». Or le plafond passe de
**cinq à trois** ans : c'est un **abaissement**. Même formule pour le faux
(« relèvement du plafond à cinq ans ») alors que les cinq ans existaient déjà.

**Je crois le BO.** C'est une erreur de rédaction de l'ONC, probablement une
traduction maladroite de « تعديل ». **Conséquence pratique : l'ONC est une
excellente source pour le périmètre et pour la prolongation, et une mauvaise
source pour les peines.** Ne jamais le citer sous un chiffre de peine.

### 4.8 Peines de l'article 316 : Bouttouil contre Touhami Kadiri. Le BO tranche.

- Saïd Bouttouil cite le texte : **6 mois à 3 ans** / **1 à 5 ans**.
- Me Laila Touhami Kadiri (fnh.ma) écrit : 6 mois à 2 ans / 1 à 3 ans, et
  conclut que « la peine maximale est désormais de 3 ans ».
- Me Bolgot, sur le **projet**, donnait 6 mois à 2 ans / 1 à 3 ans.

**Lu au BO : 6 mois à 3 ans et 1 à 5 ans** (B13). L'hypothèse la plus simple
est que Touhami Kadiri et Bolgot décrivent la version du **projet** de loi, le
maximum ayant été porté de 2 à 3 ans pendant la navette parlementaire.
— **arbitré sur texte source.**

### 4.9 « 10 ans d'interdiction » et « 1 à 5 ans de prison » sur avocatmarocain.com : de l'ancien régime.

L'interdiction bancaire est de **5 ans** (A6) et la peine pour défaut de
provision de **6 mois à 3 ans** (B13). Le « 10 ans » et le « 1 à 5 ans » sont
exactement les chiffres de l'ancien code. **Site à écarter**, même s'il donne
par ailleurs correctement les taux de l'art. 314.

### 4.10 « Allègement des peines » : faux en trois endroits.

1. Le **1 à 5 ans** n'a pas disparu : il reste pour le faux et la
   falsification, et son amende passe de **2 000–10 000** à
   **20 000–50 000 DH**.
2. L'**article 318 est aggravé** : 1 mois–2 ans + 1 000–10 000 DH devient
   3 mois–2 ans + 5 000–20 000 DH (B15).
3. Les **peines alternatives sont exclues** pour tous les délits de l'art. 316
   (B12) — durcissement qu'aucun article de presse ne relève.

Le récit « fin du tout-carcéral » est donc **vrai pour le défaut de provision
et le chèque de garantie, faux pour le reste**. Si le produit ou le README
raconte la réforme, il la raconte comme ça.

### 4.11 La dépénalisation familiale est trois fois plus étroite qu'on ne le lit.

Voir B9. Premier degré seulement, point 1 de l'art. 316 seulement, et fenêtre
de 4 ans après la dissolution du mariage.
— **arbitré sur texte source** contre presse et notes de cabinet.

### 4.12 La durée de la prolongation : la loi contre la circulaire.

- **Loi** (art. 325 al. 8) + ONC : « durée **égale ou supérieure** » — donc
  ≥ 30 jours, sans plafond, à la discrétion du parquet.
- **Circulaire** du parquet : « **30 jours supplémentaires** », point.
- **Presse** : « 60 jours maximum ».

**Je ne peux pas trancher entre la loi et la circulaire**, parce que les deux
sont des faits : la loi ouvre, le parquet applique plus étroitement. Une
circulaire ne peut pas réduire ce que la loi ouvre, mais c'est elle qui décrit
le comportement observable du système.

**Conduite prudente retenue** — celle qui alerte au lieu de laisser passer :
- le champ `dureeProlongation` accepte **toute durée ≥ au délai initial**,
  conformément à la loi ;
- la **valeur proposée par défaut** est 30 jours, étiquetée « pratique du
  parquet (circulaire du 3 février 2026) » ;
- si l'utilisateur saisit plus de 30 jours, **aucun blocage**, mais une mention
  « au-delà de la pratique décrite par la circulaire ; conforme à l'art. 325
  al. 8 » ;
- et jamais de plafond à 60 jours, qui n'est écrit nulle part.

### 4.13 « Attestation de régularisation » : la pièce n'existe pas sous ce nom.

Les documents qui circulent réellement, d'après les textes lus :

| Pièce | Base |
|---|---|
| Certificat de refus de paiement (modèle BAM) | art. 309 |
| Injonction bancaire de restitution des formules | art. 313 |
| Procès-verbal d'audition valant إعذار | art. 325 al. 6-7 |
| Quittance de paiement de l'amende de 2 % à la caisse du tribunal | art. 325 al. 1 + circulaire |
| Désistement de plainte | art. 325 al. 1 et 10 |
| Récépissé de consignation à la caisse du tribunal | art. 325 al. 9 |

→ **Tasswiya nomme ces pièces-là.** Inventer une « attestation de
régularisation » serait inventer un document.

### 4.14 Le manquant n'est presque jamais chiffré en pratique.

Point de praticien signalé par le juge Bouttouil : la loi distingue l'assiette
« montant du chèque » et l'assiette « manquant », mais « la réalité pratique
montre que l'attestation bancaire se borne toujours à indiquer que la provision
est inexistante ou insuffisante **sans chiffrer le découvert** ».
— **probable** (source unique mais signée et spécialisée).

→ Conséquence produit : le champ « montant du manquant » sera **vide dans la
majorité des cas**. Conduite prudente : **ne rien calculer** et afficher
« assiette indéterminée : le certificat de refus ne chiffre pas le manquant »,
plutôt que de retomber silencieusement sur le montant du chèque — ce qui
surestimerait l'amende.

### 4.15 Ce que le texte ne dit pas du tout, et qu'il ne faut pas combler.

- **Le tireur introuvable ou qui ne défère pas à la convocation.** L'art. 325
  ne l'organise pas. Pratique proposée par le juge Bouttouil : avis de
  recherche, puis à l'interpellation **pas de garde à vue**, audition + إعذار
  de 30 jours + annulation de l'avis de recherche + contrôle judiciaire ; si
  le délai expire sans qu'il se présente, réémission de l'avis de recherche.
  — **probable comme « point flou reconnu »**, pas comme règle de droit.
- **Le bénéficiaire injoignable, décédé, pluriel, ou qui refuse abusivement.**
  Angle mort complet du texte. → choix d'implémentation (§ 6, H4).
- **Les délais francs.** La loi 71.24 ne contient **aucune** clause sur le
  calcul des délais. Par contraste, la loi 52.23, publiée dans le **même**
  bulletin, précise à son art. 150 que tous ses délais sont des **délais
  francs**. Ce silence est une vraie question pour une machine à états : jour
  de l'إعذار compté ou non, échéance tombant un vendredi, un jour férié ou
  pendant le Ramadan. → **choix d'implémentation assumé** (§ 6, H1-H2), et
  **jamais présenté comme une règle de droit**. Le12.ma écrit « 30 jours
  calendaires » en résumant Bouttouil, mais le texte arabe dit seulement
  « *ثلاثين (30) يوما* ».
- **Aucune statistique sur les prolongations.** Le ministre donne le nombre de
  dossiers classés ; personne ne donne le nombre d'إعذار notifiés, ni de
  prolongations demandées, accordées ou refusées. Le mécanisme que le projet
  met en scène **n'a aucune statistique publique derrière lui** — à ne pas
  combler par une estimation.

### 4.16 Le piège de lecture du Bulletin officiel, qui explique la carte des certitudes.

Le BO **ne réimprime pas** les articles modifiés en entier : il reproduit les
fragments modifiés, remplace le reste par des points de suspension, parfois
suivis de « *(الباقي بدون تغيير)* » = « le reste sans changement ». Sont
publiés ainsi : **240, 242, 295, 306, 310, 311, 312, 313, 317, 318, 319, 320**.
Sont publiés **intégralement** : **316** et **325** (abrogés-remplacés) et
**231-1 à 231-4** (nouveaux).

→ **Les règles au cœur de Tasswiya — le délai, la prolongation, les 2 %, les
peines — sont lisibles en entier sur le texte source.** Les règles
périphériques ne le sont pas, et leur libellé exact demande le Code consolidé
au 29 janvier 2026, qui n'a pas été trouvé en version officielle. C'est pour
cela, et pour cela seulement, que des règles périphériques restent
« probable ».

---

## 5. Ce que la machine à états doit en faire

Sept conséquences non négociables, chacune rattachée à sa règle.

1. **Deux dates limites typées, pas une.** `echeanceRegularisationPenale`
   (H3, déclenchée par `EcedarNotifie`) et `echeanceExonerationPenaliteBancaire`
   (H2, déclenchée par `InjonctionBancaireEnvoyee`). Plus `H1`, `H4`, `H5`
   comme fenêtres calculées. Un champ `dateLimiteRegularisation` unique est
   interdit (§ 2).
2. **L'événement déclencheur est typé et porte sa pièce justificative.**
   `EcedarNotifie` exige un PV d'audition ; `InjonctionBancaireEnvoyee` exige
   une preuve d'envoi (art. 313). Pas de date saisie sans son événement.
3. **La garde de prolongation est un paramètre produit**, nommée
   `prolongationsAutoriseesEpuisees`, avec l'info-bulle du § 4.1. La garde
   légale, elle, est `accordBeneficiaireNonEnregistre` — et il en faut une
   troisième, `decisionParquetNonEnregistree` (§ 4.1).
4. **L'extinction exige deux conditions cumulatives** : paiement intégral **ou**
   désistement, **et** paiement des 2 %. Garde :
   `pasDExtinctionSansAmendeDeDeuxPourCent` (B6).
5. **Le montant dû dépend de l'état du dossier, pas d'une constante.** Avant
   condamnation : 2 %. Après condamnation définitive : 2 % **et** l'amende de
   l'art. 316 al. 1 ; et la réhabilitation n'est ouverte qu'après les deux
   (B7-B8). C'est une transition, pas un calcul.
6. **Trois transitions irréversibles**, qui donnent au graphe sa forme :
   `desistementEnregistre` et `transactionEnregistree` (art. 325 al. 10, B11),
   et `regularisationBancaire` qui « purge tous les effets » de l'interdiction
   (art. 313, A9).
7. **Le degré de certitude voyage avec la règle jusqu'à l'écran.** Chaque
   garde porte son article **et** son degré ; une garde `probable` ou
   `incertain` affiche sa source réelle (circulaire, doctrine) et non « loi
   71-24 ». Une règle `incertain` **alerte** au lieu de conclure (§ 0).

---

## 6. Les choix d'implémentation assumés — ce qui n'est PAS du droit

Ces lignes sont des décisions de développeur. Elles doivent être affichées
comme telles dans le produit, dans un encart « hypothèses de calcul », distinct
des règles sourcées.

| # | Choix | Pourquoi, et ce qui serait à vérifier |
|---|---|---|
| **H1** | **Jours calendaires**, et le jour de l'إعذار **n'est pas compté** (échéance = إعذار + 30 jours). | La loi 71.24 est muette (§ 4.15). Le choix d'exclure le *dies a quo* est le plus défavorable au créancier pressé et le plus favorable au débiteur, donc le moins susceptible de faire manquer un droit par excès de zèle. **À reprendre si une jurisprudence ou une circulaire tranche.** |
| **H2** | **Aucun report** si l'échéance tombe un vendredi, un jour férié ou pendant le Ramadan ; mais l'écran **signale** que le 30ᵉ jour est un jour non ouvré. | Rien dans le texte. Reporter serait inventer une règle ; ne rien dire serait laisser passer. Donc : calcul strict + alerte. |
| **H3** | **Une** prolongation par défaut, **paramétrable**, durée saisie ≥ au délai initial, valeur proposée 30 jours. | § 4.1 et § 4.12. Le défaut reflète la pratique du parquet, la loi autorise plus, et le produit ne ment sur aucun des deux. |
| **H4** | Si le bénéficiaire est **injoignable, décédé ou pluriel**, le dossier passe dans un état `accordBeneficiaireIndisponible` qui **bloque la prolongation et le dit**, au lieu de la refuser silencieusement. | Angle mort du texte (§ 4.15). Bloquer + expliquer est la conduite qui alerte. |
| **H5** | **Tireur introuvable** : état `convocationSansEffet`, qui ne fait pas courir les 30 jours et affiche la pratique rapportée par le juge Bouttouil **comme doctrine**, non comme règle. | § 4.15. |
| **H6** | **Manquant non chiffré** : assiette `indeterminee`, aucun montant calculé, message explicite. | § 4.14. Retomber sur le montant du chèque surestimerait l'amende. |
| **H7** | **Aucun montant affiché n'est opposable.** Les calculs de 2 %, de 0,5/1/1,5 % et de 6 % sont des **estimations d'aide à la préparation**, jamais des décomptes. | Contrainte n° 1. L'amende est fixée par le tribunal ou la banque, pas par ce logiciel. |
| **H8** | **Données strictement fictives** : noms manifestement inventés, aucun RIB, aucune CIN, aucun numéro de chèque plausible, aucune banque réelle nommée. | Contrainte n° 3. |

---

## 7. À vérifier au Bulletin officiel — liste courte et ordonnée

Le fichier à ouvrir est `docs/sources/BO_7478_ar.pdf`. **La loi 71.24 occupe
les pages 838 à 842 du bulletin, soit les pages 56 à 60 du PDF.** Pour les
articles non reproduits, la seconde pièce est
`docs/sources/code_commerce_avant_reforme_PMP.pdf`.

Ordonnée par impact sur la machine à états, la plus lourde d'abord.

1. **Article 325, alinéa 8 — page 841.** Relire le paragraphe de la
   prolongation et confirmer deux choses : que les mots « **لمدة مماثلة أو
   أكثر** » y sont, et que « **مرة واحدة** » n'y est pas. **C'est la seule
   vérification qui peut annuler le moment filmé du projet.**
2. **Article 325, alinéa 6 — page 841.** Confirmer que les 30 jours courent
   « **من تاريخ هذا الإعذار** » et non d'un autre événement. C'est le point
   zéro de l'horloge principale.
3. **Article 325, alinéa 1 — page 841.** Confirmer l'assiette des 2 % :
   « **من مبلغ الشيك أو الخصاص** », et vérifier qu'aucun plancher ni plafond
   n'y figure.
4. **Article 314 — page 840.** Lire les fragments **élidés** : l'assiette de
   chacun des trois pourcentages. Le Code consolidé au 29 janvier 2026 est
   nécessaire ; à défaut, confirmer la reconstitution faite ici sur l'ancien
   art. 314 (A7).
5. **Article 313 — page 840.** Deux fragments élidés à combler : quelles
   formules de chèques restent autorisées pendant l'interdiction de cinq ans,
   et le libellé exact du recouvrement de la faculté d'émettre (A9).
6. **Article 317, alinéa 1 — page 840.** La **durée** de l'interdiction
   judiciaire est élidée. Confirmer qu'elle reste de 1 à 5 ans (B17), pour ne
   pas la confondre avec l'interdiction bancaire de cinq ans.
7. **Article 295 — page 839.** Confirmer les durées 1 an / 1 an / 2 ans et
   lire la réserve élidée « **غير أنه في حالة …** » (A16). L'allongement des
   prescriptions n'est signalé par aucune source secondaire : il mérite une
   seconde lecture.
8. **Article 319 — pages 840-841.** Le libellé des trois cas est élidé. Sans
   lui, on connaît le montant et la clause de doublement, pas les obligations
   sanctionnées (B16).
9. **Article 306, alinéa 2 — page 839.** La sanction est élidée ; confirmer le
   minimum de 6 % et la solidarité créancier-débiteur (A15).
10. **La circulaire de la Présidence du ministère public.** Obtenir le PDF
    officiel sur **pmp.ma** pour confirmer le numéro (**2/2026 ?**) et la date
    (**3 février 2026 ?**), aujourd'hui seulement **probables** à partir d'un
    OCR de copie tierce. Tout ce que Tasswiya affiche en s'appuyant sur la
    circulaire — le régime transitoire, les « 30 jours supplémentaires », la
    mécanique du classement — en dépend.
11. **Une traduction française officielle.** Surveiller les éditions
    françaises postérieures au n° 7540 (3 septembre 2026). Tant qu'elle
    n'existe pas, **chaque écran doit porter la mention « traduction de travail
    — seule la version arabe du BO n° 7478 fait foi »**.
12. **Les circulaires de Bank Al-Maghrib** (art. 240 formules, art. 242 gel
    électronique, art. 231-1 et 231-4 lettre de change). Chercher sur
    **bkam.ma** et dans les BO postérieurs. Tant qu'elles manquent, écrire
    « texte d'application non retrouvé », **jamais** « inapplicable ».
13. **La circulaire BAM n° 5/G/97** (mentions du certificat de refus de
    paiement, art. 309). Le PDF a été récupéré mais son flux n'est pas
    extractible. Question directement opératoire : **le certificat doit-il
    chiffrer le manquant ?** (§ 4.14).
14. **Les travaux parlementaires** (Chambre des représentants, Chambre des
    conseillers) : exposé des motifs et rapports de commission. C'est la piste
    la plus directe pour comprendre l'intention derrière « durée égale ou
    supérieure », et accessoirement pour confirmer que le maximum de l'art. 316
    a bien été porté de 2 à 3 ans pendant la navette (§ 4.8).
15. **Les BO postérieurs au n° 7548** (7 octobre 2026), pour un éventuel
    rectificatif ou une modification. Le contrôle fait ici s'arrête là
    (§ 3.C7).
