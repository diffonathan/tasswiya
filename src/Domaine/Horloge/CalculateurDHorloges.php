<?php

declare(strict_types=1);

namespace App\Domaine\Horloge;

use App\Entity\Dossier;
use App\Entity\InterdictionBancaire;
use App\Entity\Prolongation;
use Psr\Clock\ClockInterface;

/**
 * Le seul endroit du projet qui sait quel jour on est.
 *
 * POURQUOI CETTE CENTRALISATION. Tout le produit est une affaire de dates.
 * Un `new \DateTime()` dispersé dans un contrôleur ou une entité rendrait la
 * machine à états intestable — on ne teste pas un délai de trente jours en
 * attendant trente jours, et encore moins un délai de cinq ans — et surtout
 * irreproductible : deux exécutions du même scénario ne donneraient pas le
 * même résultat.
 *
 * `ClockInterface` est donc injectée, et c'est elle qui permet les deux
 * choses dont ce projet vit :
 *
 *  - en test, `MockClock` fait avancer le temps de trente et un jours en une
 *    instruction, et la transition `expirer_delai` se vérifie en une
 *    milliseconde ;
 *  - en démonstration, la même horloge de démonstration se pousse de
 *    trente et un jours devant la caméra, le dossier bascule tout seul en
 *    « délai expiré », et le spectateur voit une règle de droit s'appliquer
 *    au seul passage du temps. Sans injection de l'horloge, il faudrait
 *    antidater les données — et le dossier mentirait au lieu de vieillir.
 *
 * Le calculateur reçoit AUSSI les règles de calcul des délais
 * ({@see ReglesDeDelaiInterface}), parce que la loi 71.24 ne dit pas comment
 * compter un jour. Deux injections, deux responsabilités distinctes : quel
 * jour on est, et comment on compte les jours.
 */
final readonly class CalculateurDHorloges
{
    public function __construct(
        private ClockInterface $horloge,
        private ReglesDeDelaiInterface $reglesDeDelai,
    ) {
    }

    /** L'instant courant, normalisé à minuit : une échéance légale est un jour. */
    public function aujourdHui(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->horloge->now())->modify('midnight');
    }

    /** H1 — délai de présentation au paiement (art. 268, non modifié). */
    public function h1(Dossier $dossier): Horloge
    {
        $cheque = $dossier->cheque();

        return Horloge::h1Presentation(
            $cheque->dateEmissionPorteeSurLeCheque(),
            $cheque->joursDePresentation(),
            $this->reglesDeDelai,
        );
    }

    /**
     * H2 — fenêtre de trois mois d'exonération de la pénalité bancaire.
     *
     * `null` tant que l'injonction bancaire n'est pas enregistrée : l'horloge
     * n'existe pas avant son point de départ, et retourner une échéance
     * calculée depuis « aujourd'hui » serait fabriquer un délai.
     */
    public function h2(Dossier $dossier): ?Horloge
    {
        $depart = $dossier->dateInjonctionBancaire();

        return null === $depart ? null : Horloge::h2PenaliteBancaire($depart);
    }

    /**
     * H3 — délai de régularisation pénale. L'horloge du projet.
     *
     * `null` tant que l'écédar n'est pas notifié, et c'est la règle la plus
     * importante de cette classe : AUCUN délai de trente jours n'existe avant
     * l'écédar. Un dossier au stade de la plainte, ou dont le tireur est
     * introuvable, n'a pas d'échéance pénale — pas une échéance lointaine,
     * pas d'échéance du tout.
     *
     * La durée tient compte des prorogations effectivement accordées.
     * L'article 325 al. 8 parle de « proroger le délai pour une durée égale
     * ou supérieure » : la durée accordée s'ajoute donc au délai en cours,
     * et les prorogations successives s'accumulent depuis la date de l'écédar.
     */
    public function h3(Dossier $dossier): ?Horloge
    {
        $depart = $dossier->dateEcedar();
        if (null === $depart) {
            return null;
        }

        return Horloge::h3RegularisationPenale(
            $depart,
            $this->dureeEffectiveDuDelaiPenalEnJours($dossier),
            $this->reglesDeDelai,
        );
    }

    /**
     * Durée effective du délai pénal : le délai initial, plus la somme des
     * prorogations accordées.
     */
    public function dureeEffectiveDuDelaiPenalEnJours(Dossier $dossier): int
    {
        $duree = $dossier->dureeDelaiInitialEnJours();

        foreach ($dossier->prolongations() as $prolongation) {
            \assert($prolongation instanceof Prolongation);
            if ($prolongation->estAccordee()) {
                $duree += $prolongation->dureeEnJours();
            }
        }

        return $duree;
    }

    /** H4 — fenêtre de deux ans pour régulariser (art. 313 nouveau). */
    public function h4(Dossier $dossier): Horloge
    {
        return Horloge::h4FaculteDEmettre($this->h1($dossier)->echeance);
    }

    /** H5 — interdiction bancaire de cinq ans (art. 312 et 313 nouveaux). */
    public function h5(Dossier $dossier): ?Horloge
    {
        $depart = $dossier->dateIncidentDePaiement();

        return null === $depart ? null : Horloge::h5InterdictionBancaire($depart);
    }

    /**
     * Toutes les horloges existantes du dossier, dans l'ordre de LOI.md § 2.
     *
     * Les horloges absentes sont omises et non remplies de `null` : un écran
     * qui itère sur ce tableau n'affiche que des délais qui courent
     * réellement.
     *
     * @return array<string, Horloge>
     */
    public function toutesLesHorloges(Dossier $dossier): array
    {
        $horloges = ['H1' => $this->h1($dossier)];

        foreach (['H2' => $this->h2($dossier), 'H3' => $this->h3($dossier), 'H5' => $this->h5($dossier)] as $code => $horloge) {
            if (null !== $horloge) {
                $horloges[$code] = $horloge;
            }
        }

        // H4 n'existe qu'une fois l'incident survenu : avant, la question de
        // recouvrer la faculté d'émettre ne se pose pas.
        if (null !== $dossier->dateIncidentDePaiement()) {
            $horloges['H4'] = $this->h4($dossier);
        }

        ksort($horloges);

        return $horloges;
    }

    /**
     * Le délai pénal est-il expiré ?
     *
     * LE PRÉDICAT DE LA DÉMONSTRATION. Il est faux tant que l'écédar n'est pas
     * notifié : un dossier sans point de départ n'a pas de délai expiré, il
     * n'a pas de délai.
     */
    public function delaiPenalExpire(Dossier $dossier): bool
    {
        return true === $this->h3($dossier)?->estDepassee($this->aujourdHui());
    }

    /**
     * Jours restants sur le délai pénal, ou `null` si aucun délai ne court.
     *
     * `null` et non zéro : zéro voudrait dire « il expire aujourd'hui », ce
     * qui est faux et grave pour un utilisateur qui compte sur ce chiffre.
     */
    public function joursRestantsSurLeDelaiPenal(Dossier $dossier): ?int
    {
        return $this->h3($dossier)?->joursRestants($this->aujourdHui());
    }

    /**
     * La fenêtre de régularisation bancaire de deux ans est-elle encore
     * ouverte ? (art. 313 nouveau, H4)
     *
     * La date examinée est celle du PAIEMENT ou de la constitution de la
     * provision lorsqu'elle est connue, et non « aujourd'hui » : la condition
     * de l'art. 313 est que le paiement soit intervenu dans les deux ans, et
     * non que la saisie soit faite dans les deux ans.
     */
    public function fenetreDeRegularisationBancaireOuverte(
        Dossier $dossier,
        InterdictionBancaire $interdiction,
    ): bool {
        $echeance = Horloge::h4FaculteDEmettre($interdiction->echeanceDuDelaiDePresentation())->echeance;
        $dateExaminee = $interdiction->datePaiementOuProvision() ?? $this->aujourdHui();

        return $dateExaminee <= $echeance;
    }

    /** Les cinq ans de l'interdiction bancaire sont-ils écoulés ? (H5) */
    public function interdictionBancaireExpiree(InterdictionBancaire $interdiction): bool
    {
        return Horloge::h5InterdictionBancaire($interdiction->dateIncidentDePaiement())
            ->estDepassee($this->aujourdHui());
    }

    /**
     * La fenêtre de QUATRE ANS après la dissolution du mariage est-elle
     * encore ouverte ? (art. 325 al. 5)
     *
     * Règle que, d'après le dépouillement de LOI.md, aucune source secondaire
     * ne mentionne : la cause de justification familiale joue encore pendant
     * les quatre années qui suivent le divorce. Sans date de dissolution, on
     * ne suppose rien et on retourne faux — la garde refusera en l'expliquant.
     *
     * La date examinée est celle de l'INFRACTION, c'est-à-dire de l'incident
     * de paiement, et non la date du jour : c'est au moment des faits que la
     * cause de justification joue ou ne joue pas. Comparer à « aujourd'hui »
     * ferait disparaître une justification acquise par le simple écoulement
     * de l'instruction.
     */
    public function fenetreDeQuatreAnsApresDivorceOuverte(Dossier $dossier): bool
    {
        $dissolution = $dossier->dateDissolutionDuMariage();
        if (null === $dissolution) {
            return false;
        }

        $dateDesFaits = $dossier->dateIncidentDePaiement() ?? $this->aujourdHui();

        return $dateDesFaits <= $dissolution->modify('+4 years');
    }

    /** Les règles de calcul en vigueur, à afficher dans l'encart « hypothèses de calcul ». */
    public function reglesDeDelai(): ReglesDeDelaiInterface
    {
        return $this->reglesDeDelai;
    }
}
