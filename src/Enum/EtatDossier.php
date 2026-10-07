<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Les états du dossier pénal, c'est-à-dire sa POSITION DANS LA PROCÉDURE.
 *
 * Choix de modélisation, à défendre tel quel : ce sont les places d'une
 * `state_machine` et non d'un `workflow`, parce qu'un dossier occupe une
 * position procédurale et une seule. Il ne peut pas être à la fois « délai en
 * cours » et « poursuite engagée ».
 *
 * Ce que ces états NE modélisent PAS, et c'est volontaire :
 *
 *  - le circuit bancaire. LOI.md § 2 recense cinq horloges, dont trois
 *    bancaires (H2 pénalité, H4 faculté d'émettre, H5 interdiction) qui
 *    courent EN PARALLÈLE du circuit pénal. Les faire entrer ici obligerait
 *    à un produit d'états, et le dossier cesserait d'avoir un état lisible.
 *    Elles sont donc des ÉVÉNEMENTS qui démarrent des horloges, plus une
 *    seconde machine à états minuscule pour l'interdiction d'émettre
 *    (voir {@see EtatInterdictionBancaire}) ;
 *
 *  - l'indisponibilité du bénéficiaire. LOI.md § 6 H4 l'appelle un « état »,
 *    mais elle est ORTHOGONALE à la position procédurale : un bénéficiaire
 *    décédé ne déplace pas le dossier, il empêche une transition. C'est donc
 *    une propriété du dossier lue par une garde, qui bloque ET l'explique.
 */
enum EtatDossier: string
{
    /**
     * Chèque émis, pas encore présenté ou pas encore rejeté.
     * Horloge H1 en cours (20 j au Maroc, 60 j depuis l'étranger, art. 268).
     */
    case CHEQUE_EMIS = 'cheque_emis';

    /**
     * Incident de paiement constaté, certificat de refus remis au porteur
     * (art. 309, non modifié par la loi 71.24).
     */
    case IMPAYE_CONSTATE = 'impaye_constate';

    /** Plainte déposée. Le parquet est saisi ; l'écédar n'est pas encore notifié. */
    case PLAINTE_DEPOSEE = 'plainte_deposee';

    /**
     * Tireur introuvable, ou qui ne défère pas à la convocation.
     *
     * L'article 325 n'organise PAS ce cas (LOI.md § 4.15). Cet état existe
     * pour une raison précise : dans cette position, l'horloge H3 des
     * 30 jours NE COURT PAS, puisqu'elle part de la date de l'écédar et
     * qu'aucun écédar n'a pu être notifié. Modéliser l'absence du tireur
     * comme un simple drapeau laisserait un délai courir contre un homme
     * qu'on n'a pas vu.
     *
     * Degré : CHOIX_PRODUIT (LOI.md § 6, H5). La pratique rapportée — avis
     * de recherche, pas de garde à vue à l'interpellation, audition valant
     * écédar — est de la doctrine de praticien (juge Saïd Bouttouil), à
     * afficher comme telle et jamais comme la loi.
     */
    case CONVOCATION_SANS_EFFET = 'convocation_sans_effet';

    /**
     * Écédar notifié sous forme d'interrogatoire par un officier de police
     * judiciaire sur instruction du parquet (art. 325 al. 7).
     *
     * C'EST ICI QUE PART L'HORLOGE H3 DES 30 JOURS, et nulle part ailleurs :
     * ni à la présentation, ni au rejet, ni à la plainte, ni à l'injonction
     * bancaire (art. 325 al. 6, verbatim « من تاريخ هذا الإعذار »).
     *
     * Et ce n'est pas un délai de grâce : le tireur est simultanément soumis
     * à une ou plusieurs mesures de contrôle judiciaire, bracelet
     * électronique compris (art. 325 al. 7, rédigé à l'impératif).
     */
    case ECEDAR_NOTIFIE = 'ecedar_notifie';

    /**
     * Délai prorogé par décision du ministère public après accord du
     * bénéficiaire (art. 325 al. 8). Le contrôle judiciaire CONTINUE.
     *
     * État distinct de ECEDAR_NOTIFIE, et pas un simple compteur, pour que
     * l'écran puisse dire laquelle des deux échéances il affiche.
     */
    case DELAI_PROLONGE = 'delai_prolonge';

    /**
     * Le délai de régularisation est expiré : l'obstacle procédural à la
     * poursuite est tombé.
     *
     * C'EST L'ÉTAT QUE LA DÉMONSTRATION ATTEINT AU SEUL PASSAGE DU TEMPS.
     * Aucune action humaine ne mène ici, seule l'horloge le fait.
     */
    case DELAI_EXPIRE = 'delai_expire';

    /** Action publique mise en mouvement ; l'écédar préalable est acquis (art. 325 al. 6). */
    case POURSUITE_ENGAGEE = 'poursuite_engagee';

    /** Décision passée en force de chose jugée (art. 325 al. 2). */
    case CONDAMNATION_DEFINITIVE = 'condamnation_definitive';

    /**
     * Transaction ou désistement de plainte acté.
     *
     * État intermédiaire et NON terminal, et c'est tout son intérêt :
     * l'art. 325 al. 1 exige deux conditions CUMULATIVES, le paiement ou le
     * désistement ET le versement de l'amende de 2 %. Un dossier désisté
     * dont l'amende n'est pas payée reste donc ici, et la garde le dit.
     *
     * Position irréversible : on ne peut revenir ni sur la transaction ni
     * sur le désistement (art. 325 al. 10, « لا يجوز الرجوع في الصلح أو
     * التنازل »), sauf dans les cas où la loi en permet la contestation —
     * ce que ce logiciel ne tranche pas et n'ouvre donc pas.
     */
    case DESISTEMENT_OU_TRANSACTION_ACTE = 'desistement_ou_transaction_acte';

    /** L'action publique n'est pas mise en mouvement, ou s'éteint (art. 325 al. 1). Terminal. */
    case ACTION_PUBLIQUE_ETEINTE = 'action_publique_eteinte';

    /**
     * Après condamnation définitive : le paiement ou le désistement met fin
     * à l'exécution de la peine privative de liberté et efface ses effets,
     * une fois l'amende de l'art. 316 al. 1 payée (art. 325 al. 2).
     */
    case PEINE_EFFACEE = 'peine_effacee';

    /**
     * Réhabilitation judiciaire ouverte, ce qui suppose le paiement DES DEUX
     * amendes (art. 325 al. 3). Terminal.
     */
    case REHABILITATION_OUVERTE = 'rehabilitation_ouverte';

    /**
     * Cause de justification familiale : « ni infraction ni peine »
     * (لا جريمة ولا عقوبة), art. 325 al. 4 et 5. Terminal au pénal — l'action
     * civile de la partie lésée reste ouverte, et l'écran doit le dire.
     *
     * Trois restrictions que la presse efface et que la garde fait
     * respecter : le POINT 1 de l'art. 316 seulement, le PREMIER DEGRÉ
     * seulement, et pour les époux une fenêtre de QUATRE ANS après la
     * dissolution du mariage.
     */
    case JUSTIFICATION_FAMILIALE_RETENUE = 'justification_familiale_retenue';

    /** Vrai si aucune transition ne part de cet état dans `workflow.yaml`. */
    public function estTerminal(): bool
    {
        return match ($this) {
            self::ACTION_PUBLIQUE_ETEINTE,
            self::REHABILITATION_OUVERTE,
            self::JUSTIFICATION_FAMILIALE_RETENUE => true,
            default => false,
        };
    }

    /**
     * Vrai si l'horloge H3 des 30 jours court dans cet état.
     *
     * Volontairement faux pour CONVOCATION_SANS_EFFET : sans écédar notifié,
     * il n'y a pas de point de départ, donc pas de délai.
     */
    public function faitCourirLeDelaiPenal(): bool
    {
        return match ($this) {
            self::ECEDAR_NOTIFIE, self::DELAI_PROLONGE => true,
            default => false,
        };
    }

    /**
     * Vrai si le tireur est sous contrôle judiciaire dans cet état
     * (art. 325 al. 7, et al. 8 pour la continuation pendant la prolongation).
     *
     * Un écran qui annonce « 30 jours pour régulariser » sans afficher cette
     * mesure décrit mal la situation de son propre utilisateur débiteur.
     */
    public function impliqueUnControleJudiciaire(): bool
    {
        return $this->faitCourirLeDelaiPenal();
    }
}
