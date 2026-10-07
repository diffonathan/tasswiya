<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Le degré de certitude d'une règle, au sens de l'échelle de `LOI.md` § 0.
 *
 * Pourquoi cette énumération existe : parce que Tasswiya affiche du droit
 * étranger à son auteur, reconstitué à partir d'un Bulletin officiel arabe
 * dont certains fragments sont élidés. Afficher une règle PROBABLE avec la
 * même assurance qu'une règle ÉTABLIE serait mentir sur ce qu'on sait.
 *
 * Conséquence imposée par `LOI.md` § 0 et § 5 point 7 : le degré voyage avec
 * la règle jusqu'à l'écran, et une règle INCERTAINE alerte au lieu de
 * conclure.
 */
enum DegreCertitude: string
{
    /** Lu sur le texte officiel, ou sur un article que la loi 71.24 ne touche pas. */
    case ETABLI = 'etabli';

    /**
     * Sources secondaires concordantes, ou fragment élidé au BO recollé sur
     * le code antérieur. S'affiche en citant sa source réelle — « instruction
     * du ministère public », « doctrine » — et JAMAIS « loi 71-24 ».
     */
    case PROBABLE = 'probable';

    /**
     * Sources divergentes, source unique, ou pièce non trouvée.
     * Ne pilote aucun calcul silencieusement : déclenche une alerte.
     */
    case INCERTAIN = 'incertain';

    /**
     * Choix de développeur assumé (`LOI.md` § 6), qui n'est PAS du droit.
     * À afficher dans l'encart « hypothèses de calcul », séparé des règles
     * sourcées, pour qu'aucun utilisateur ne le prenne pour une règle.
     */
    case CHOIX_PRODUIT = 'choix_produit';

    /** Vrai si la règle doit déclencher une alerte plutôt qu'une conclusion. */
    public function exigeUneAlerte(): bool
    {
        return self::INCERTAIN === $this;
    }

    /**
     * Ce qu'on est autorisé à écrire comme source sous un libellé français.
     *
     * Il n'existe aucune version française officielle de la loi 71.24
     * (`LOI.md` § 3.C6) : écrire « loi 71-24 » sous une règle qui vient de la
     * circulaire du parquet ou de la doctrine serait une fausse citation.
     */
    public function attributionAutorisee(): string
    {
        return match ($this) {
            self::ETABLI => 'loi n° 71.24 — BO n° 7478 du 29 janvier 2026 (version arabe seule officielle ; traduction de travail)',
            self::PROBABLE => 'source secondaire — voir le libellé de la règle',
            self::INCERTAIN => 'point non tranché — à vérifier',
            self::CHOIX_PRODUIT => 'choix d\'implémentation de ce logiciel, pas une règle de droit',
        };
    }
}
