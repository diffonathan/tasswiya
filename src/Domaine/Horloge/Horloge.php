<?php

declare(strict_types=1);

namespace App\Domaine\Horloge;

use App\Enum\DegreCertitude;

/**
 * Une horloge : un délai nommé, son point de départ daté, sa durée, son
 * échéance, l'effet de son expiration, son article et son degré.
 *
 * Objet-valeur immuable, calculé et jamais persisté : la base stocke les
 * FAITS DATÉS (date de l'écédar, date de l'injonction) et les échéances se
 * recalculent. Persister une échéance la figerait sous les règles de calcul
 * du jour où elle a été écrite, et une correction de l'hypothèse H1 ne
 * repasserait jamais sur les dossiers anciens.
 *
 * Pourquoi un objet et pas un champ `dateLimiteRegularisation` dans
 * `Dossier` : LOI.md § 2 recense CINQ horloges distinctes, qui n'ont ni le
 * même départ, ni la même durée, ni le même effet. Un champ unique ferait
 * mentir le produit — et c'est l'interdiction la plus explicite du dossier
 * de règles.
 */
final readonly class Horloge
{
    /**
     * @param string $code       H1 à H5, repris de LOI.md § 2
     * @param string $libelle    ce que l'écran affiche
     * @param string $article    la source, telle qu'on a le droit de la citer
     * @param string $effetSiExpire ce que l'expiration change — jamais « le dossier est perdu »
     */
    private function __construct(
        public string $code,
        public string $libelle,
        public \DateTimeImmutable $depart,
        public int $joursOuMois,
        public \DateTimeImmutable $echeance,
        public string $article,
        public DegreCertitude $degre,
        public string $effetSiExpire,
        public string $hypotheseDeCalcul,
    ) {
    }

    /**
     * H1 — délai de présentation au paiement.
     *
     * Part de la DATE D'ÉMISSION PORTÉE SUR LE CHÈQUE, pas de la remise
     * réelle. Et son expiration n'est PAS un état terminal : le tiré doit
     * payer même après (art. 270 et 271, non modifiés). Elle ne fait tomber
     * que les recours cambiaires.
     */
    public static function h1Presentation(
        \DateTimeImmutable $dateEmission,
        int $jours,
        ReglesDeDelaiInterface $regles,
    ): self {
        return new self(
            'H1',
            'Délai de présentation au paiement',
            $dateEmission,
            $jours,
            $regles->echeance($dateEmission, $jours),
            'art. 268 du Code de commerce, non modifié par la loi 71.24',
            DegreCertitude::ETABLI,
            'Les recours cambiaires tombent. Le tiré doit néanmoins payer le chèque présenté tardivement '
                .'(art. 270 et 271) : ce délai expiré ne clôt pas le dossier.',
            $regles->libelleAffiche(),
        );
    }

    /**
     * H2 — fenêtre d'exonération de la pénalité bancaire.
     *
     * Trois mois à compter de l'INJONCTION BANCAIRE, et elle exonère DEUX
     * amendes et non une : la pénalité de l'art. 314 et celle du troisième
     * alinéa de l'art. 307 (6 % du montant du chèque, minimum 100 DH).
     */
    public static function h2PenaliteBancaire(
        \DateTimeImmutable $dateInjonctionBancaire,
    ): self {
        return new self(
            'H2',
            'Fenêtre d\'exonération de la pénalité bancaire',
            $dateInjonctionBancaire,
            3,
            // Trois MOIS : on laisse `DateTimeImmutable` gérer les fins de mois
            // plutôt que d'approximer en 90 jours, ce qui décalerait l'échéance.
            $dateInjonctionBancaire->modify('midnight')->modify('+3 months'),
            'art. 314 nouveau, dernier alinéa (loi 71.24), renvoyant à l\'art. 307 al. 3 non modifié',
            DegreCertitude::ETABLI,
            'La pénalité de l\'art. 314 (0,5 / 1 / 1,5 %) et l\'amende de 6 % de l\'art. 307 al. 3 '
                .'deviennent dues. Montants estimatifs : ils sont fixés par la banque, pas par ce logiciel.',
            'Trois mois de calendrier à compter de l\'injonction bancaire.',
        );
    }

    /**
     * H3 — délai de régularisation pénale. L'horloge du projet.
     *
     * Trente jours À COMPTER DE LA DATE DE L'ÉCÉDAR, verbatim
     * « خلال أجل ثلاثين (30) يوما من تاريخ هذا الإعذار » (art. 325 al. 6).
     * Ni la présentation, ni le rejet, ni la plainte, ni l'injonction
     * bancaire : l'écédar est un acte du parquet exécuté par la police
     * judiciaire, postérieur de plusieurs semaines ou mois au rejet.
     *
     * @param int $jours 30 pour le délai initial ; la durée prorogée pour une
     *                   prorogation, qui doit être « égale ou supérieure »
     *                   au délai initial et n'a aucun plafond légal
     */
    public static function h3RegularisationPenale(
        \DateTimeImmutable $dateEcedar,
        int $jours,
        ReglesDeDelaiInterface $regles,
    ): self {
        return new self(
            'H3',
            'Délai de régularisation pénale',
            $dateEcedar,
            $jours,
            $regles->echeance($dateEcedar, $jours),
            'art. 325 al. 6 nouveau (loi 71.24)',
            DegreCertitude::ETABLI,
            'L\'obstacle procédural à la poursuite tombe : l\'écédar préalable obligatoire ayant été '
                .'accompli, l\'action publique peut être mise en mouvement. Le dossier n\'est pas '
                .'« perdu » : paiement ou désistement plus amende de 2 % l\'éteignent encore (art. 325 al. 1).',
            $regles->libelleAffiche(),
        );
    }

    /**
     * H4 — fenêtre de régularisation bancaire.
     *
     * Deux ans à compter de L'EXPIRATION DU DÉLAI DE PRÉSENTATION, et non de
     * l'incident : le départ de cette horloge est donc l'ÉCHÉANCE d'une autre
     * (H1), ce qu'un champ unique n'aurait jamais pu exprimer. Fenêtre
     * nouvelle : l'ancien art. 313 posait les mêmes conditions sans délai.
     */
    public static function h4FaculteDEmettre(\DateTimeImmutable $echeanceH1): self
    {
        return new self(
            'H4',
            'Fenêtre de régularisation bancaire',
            $echeanceH1,
            24,
            $echeanceH1->modify('midnight')->modify('+2 years'),
            'art. 313 nouveau (loi 71.24)',
            DegreCertitude::ETABLI,
            'La faculté d\'émettre ne peut plus être recouvrée par cette voie. La régularisation dans '
                .'la fenêtre lève l\'interdiction et purge tous ses effets.',
            'Deux ans de calendrier à compter de l\'expiration du délai de présentation.',
        );
    }

    /**
     * H5 — interdiction bancaire d'émettre.
     *
     * Cinq ans à compter de l'incident de paiement (art. 312 et 313 nouveaux,
     * « خمس سنوات »), contre DIX ANS dans l'ancien code (« عشر سنوات »). La
     * division par deux est lue sur deux textes primaires, pas rapportée.
     */
    public static function h5InterdictionBancaire(\DateTimeImmutable $dateIncident): self
    {
        return new self(
            'H5',
            'Interdiction bancaire d\'émettre des chèques',
            $dateIncident,
            60,
            $dateIncident->modify('midnight')->modify('+5 years'),
            'art. 312 et 313 nouveaux (loi 71.24)',
            DegreCertitude::ETABLI,
            'L\'interdiction tombe par épuisement du délai — mais sans la purge des effets que seule '
                .'la régularisation de l\'art. 313 produit. À ne pas confondre avec l\'interdiction '
                .'judiciaire de l\'art. 317, qui est une autre interdiction.',
            'Cinq ans de calendrier à compter de l\'incident de paiement.',
        );
    }

    /**
     * Vrai si l'échéance est dépassée à l'instant fourni.
     *
     * L'instant est un PARAMÈTRE et non `new DateTime()` : c'est ce qui
     * permet de pousser l'horloge de démonstration de trente et un jours et
     * de voir la règle s'appliquer, et c'est ce qui permet de tester un délai
     * autrement qu'en attendant un mois.
     */
    public function estDepassee(\DateTimeImmutable $maintenant): bool
    {
        return $maintenant > $this->echeance;
    }

    /** Jours restants, négatif si l'échéance est passée. */
    public function joursRestants(\DateTimeImmutable $maintenant): int
    {
        $diff = $maintenant->modify('midnight')->diff($this->echeance);

        return (int) $diff->format('%r%a');
    }

    /**
     * Signale que l'échéance tombe un jour probablement non ouvré.
     *
     * « Probablement », et le mot est pesé : on sait tester le vendredi et le
     * samedi, pas le calendrier des fêtes religieuses mobiles ni le Ramadan,
     * dont les dates dépendent de l'observation lunaire. Le produit alerte
     * donc sur ce qu'il sait, et l'hypothèse H2 dit qu'il ne reporte rien.
     *
     * Aucune règle de droit derrière cette méthode : la loi 71.24 ne prévoit
     * aucun report. L'alerte existe pour que l'utilisateur vérifie, pas pour
     * décaler un calcul.
     */
    public function tombeUnJourProbablementNonOuvre(): bool
    {
        // Au Maroc, le week-end administratif est samedi-dimanche et la prière
        // du vendredi écourte l'après-midi des juridictions. On signale les
        // trois, sans trancher ce que le texte ne tranche pas.
        return \in_array((int) $this->echeance->format('N'), [5, 6, 7], true);
    }
}
