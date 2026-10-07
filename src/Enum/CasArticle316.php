<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Les faits incriminés par l'article 316 nouveau, éclatés en trois régimes.
 *
 * Pourquoi cette énumération pilote des gardes et n'est pas décorative :
 * l'article 325 al. 1 (extinction par paiement ou désistement + 2 %) et
 * l'article 325 al. 4 (cause de justification familiale) sont tous deux
 * limités au POINT 1 de l'article 316, celui de l'omission de provision.
 * Appliquer l'extinction à une opposition irrégulière ou à un faux serait
 * étendre un régime de faveur que le texte restreint nommément.
 *
 * L'ancien article 316 confondait six cas sous une seule peine de 1 à 5 ans ;
 * l'éclatement en trois régimes est l'apport majeur de la réforme.
 */
enum CasArticle316: string
{
    /**
     * Point 1 : tireur qui a omis de maintenir ou de constituer la provision
     * en vue du paiement à la présentation.
     * Peine : 6 mois à 3 ans + 5 000 à 20 000 DH.
     *
     * SEUL cas ouvrant l'extinction de l'art. 325 al. 1 et la cause de
     * justification familiale de l'art. 325 al. 4.
     */
    case OMISSION_DE_PROVISION = 'omission_de_provision';

    /**
     * Point 2 : tireur qui fait opposition de manière irrégulière auprès du
     * tiré. Même peine que le point 1, mais régime d'extinction fermé.
     */
    case OPPOSITION_IRREGULIERE = 'opposition_irreguliere';

    /** Contrefaçon ou falsification d'un chèque. 1 à 5 ans + 20 000 à 50 000 DH. */
    case FAUX_OU_FALSIFICATION = 'faux_ou_falsification';

    /** Acceptation, endossement ou aval en connaissance de cause d'un chèque faux. */
    case ACCEPTATION_DE_FAUX = 'acceptation_de_faux';

    /** Usage ou tentative d'usage en connaissance de cause d'un chèque faux. */
    case USAGE_DE_FAUX = 'usage_de_faux';

    /**
     * Chèque de garantie : celui qui accepte sciemment de recevoir ou
     * d'endosser un chèque à condition de ne pas l'encaisser aussitôt et de
     * le conserver à titre de garantie.
     *
     * DEUX PIÈGES, et ce sont des pièges de modèle de données :
     *  a) le débiteur de l'amende est CELUI QUI REÇOIT — donc le bénéficiaire,
     *     pas le tireur. Un écran qui porte ce montant au débit du tireur
     *     inverse la loi ;
     *  b) l'assiette est « la valeur du chèque », SANS « ou du manquant »,
     *     contrairement aux 2 % de l'art. 325 al. 1. Ce sont deux amendes de
     *     2 % différentes (LOI.md § 4.3).
     * Aucune peine privative de liberté dans ce cas.
     */
    case CHEQUE_DE_GARANTIE = 'cheque_de_garantie';

    /**
     * Vrai si ce cas ouvre l'extinction de l'action publique par paiement ou
     * désistement plus amende de 2 % (art. 325 al. 1).
     */
    public function ouvreExtinctionArticle325(): bool
    {
        return self::OMISSION_DE_PROVISION === $this;
    }

    /**
     * Vrai si ce cas ouvre la cause de justification familiale
     * (art. 325 al. 4 : « dans les cas du point (1) de l'article 316 »).
     */
    public function ouvreJustificationFamiliale(): bool
    {
        return self::OMISSION_DE_PROVISION === $this;
    }

    /**
     * Vrai si le débiteur de l'amende est le bénéficiaire et non le tireur.
     * Voir le piège (a) du cas CHEQUE_DE_GARANTIE.
     */
    public function amendeDueParLeBeneficiaire(): bool
    {
        return self::CHEQUE_DE_GARANTIE === $this;
    }

    /**
     * Vrai si l'assiette de l'amende peut se réduire au manquant.
     *
     * L'art. 325 al. 1 dit « du montant du chèque OU du manquant » ;
     * l'amende du chèque de garantie dit « de la valeur du chèque » tout
     * court. La différence est dans le texte, pas dans l'interprétation.
     */
    public function assietteReductibleAuManquant(): bool
    {
        return self::CHEQUE_DE_GARANTIE !== $this;
    }
}
