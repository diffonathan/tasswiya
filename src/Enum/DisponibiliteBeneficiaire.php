<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * La disponibilité du bénéficiaire pour donner l'accord que l'article 325
 * al. 8 exige avant toute prorogation du délai.
 *
 * ANGLE MORT ASSUMÉ. La loi 71.24 ne dit rien du bénéficiaire injoignable,
 * décédé, pluriel, ou qui refuse abusivement la prorogation (LOI.md § 4.15).
 * Elle fait de son accord une condition sans dire comment l'obtenir quand
 * personne ne répond.
 *
 * Choix d'implémentation retenu (LOI.md § 6, H4) : le produit BLOQUE la
 * prorogation ET LE DIT, au lieu de la refuser silencieusement ou de la
 * présumer accordée. Le degré affiché est CHOIX_PRODUIT, jamais « loi 71-24 ».
 */
enum DisponibiliteBeneficiaire: string
{
    /** Bénéficiaire identifié, joignable, unique : son accord peut être recueilli. */
    case DISPONIBLE = 'disponible';

    /** Convoqué sans réponse. Le texte ne prévoit ni présomption ni délai de carence. */
    case INJOIGNABLE = 'injoignable';

    /**
     * Bénéficiaire décédé. Qui donne l'accord : les héritiers, l'un d'eux,
     * tous ? Le texte est muet, et ce logiciel n'invente pas la réponse.
     */
    case DECEDE = 'decede';

    /**
     * Bénéficiaires pluriels — chèque endossé, co-titulaires. L'accord de
     * l'un vaut-il pour tous ? Muet également.
     */
    case PLURIEL = 'pluriel';

    /**
     * Vrai si un accord opposable peut être recueilli de cette personne.
     *
     * Les quatre cas sauf DISPONIBLE répondent faux, non parce que la loi
     * l'interdit, mais parce qu'elle ne dit pas comment faire : refuser en
     * expliquant est la seule conduite qui n'invente rien.
     */
    public function permetDeRecueillirUnAccord(): bool
    {
        return self::DISPONIBLE === $this;
    }

    /** Ce que l'écran doit afficher pour expliquer le blocage, sans le travestir en règle. */
    public function explicationDuBlocage(): ?string
    {
        return match ($this) {
            self::DISPONIBLE => null,
            self::INJOIGNABLE => 'Bénéficiaire injoignable. L\'article 325 al. 8 exige son accord pour proroger le délai, '
                .'mais la loi 71.24 ne prévoit ni présomption d\'accord ni procédure en cas de silence. '
                .'Blocage par ce logiciel, faute de règle : ce n\'est pas une interdiction légale.',
            self::DECEDE => 'Bénéficiaire décédé. L\'article 325 al. 8 exige son accord ; la loi 71.24 ne dit pas '
                .'qui le donne à sa place. Blocage par ce logiciel, faute de règle.',
            self::PLURIEL => 'Bénéficiaires pluriels. L\'article 325 al. 8 parle du bénéficiaire au singulier et ne dit '
                .'pas si l\'accord de l\'un vaut pour tous. Blocage par ce logiciel, faute de règle.',
        };
    }
}
