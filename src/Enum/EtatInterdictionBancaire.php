<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Les états de la SECONDE machine à états, celle de l'interdiction bancaire
 * d'émettre des chèques.
 *
 * Pourquoi une seconde machine plutôt que des places de plus dans
 * `dossier_penal` : l'interdiction bancaire court en parallèle de la
 * procédure pénale et ne dépend pas d'elle. Un dossier peut être pénalement
 * éteint et le compte encore interdit, ou l'inverse. Les mélanger dans une
 * machine à état unique obligerait à un produit cartésien d'états, c'est-à-dire
 * à perdre la lisibilité même pour laquelle on a choisi `state_machine`.
 *
 * Deux horloges la pilotent (LOI.md § 2) :
 *  - H5, cinq ans à compter de l'incident de paiement (art. 312 et 313
 *    nouveaux, « خمس سنوات »), contre DIX ANS dans l'ancien code. La division
 *    par deux est lue sur deux textes primaires ;
 *  - H4, deux ans à compter de l'expiration du délai de présentation, qui est
 *    la FENÊTRE pour régulariser (art. 313 nouveau). Cette fenêtre est
 *    nouvelle : l'ancien article posait les mêmes conditions sans aucun délai.
 */
enum EtatInterdictionBancaire: string
{
    /** Interdiction en cours. H5 court, H4 court si elle n'est pas déjà fermée. */
    case ACTIVE = 'active';

    /**
     * Régularisée : paiement du chèque ou constitution d'une provision
     * suffisante et disponible DANS la fenêtre de deux ans, ET paiement de la
     * pénalité de l'art. 314.
     *
     * Le texte dit que la régularisation « lève l'interdiction et purge tous
     * ses effets » (تطهير جميع الآثار). D'où l'irréversibilité : cet état ne
     * se quitte pas, sous réserve de l'interdiction JUDICIAIRE de l'art. 317,
     * qui est une autre interdiction et n'est pas modélisée ici.
     */
    case PURGEE = 'purgee';

    /**
     * Les cinq ans sont écoulés sans régularisation. L'interdiction tombe par
     * épuisement du délai, mais les effets ne sont PAS purgés : c'est ce qui
     * distingue cet état de PURGEE, et c'est pour cela qu'on ne les confond
     * pas en un seul « plus interdit ».
     */
    case EXPIREE = 'expiree';

    public function estTerminal(): bool
    {
        return self::ACTIVE !== $this;
    }
}
