<?php

declare(strict_types=1);

namespace App\Domaine\Montant;

use App\Domaine\Certitude\RegleAppliquee;

/**
 * Un montant tel que l'écran l'affiche : une estimation, son assiette, sa
 * règle — ou le refus explicite de le chiffrer.
 *
 * ═══ LE CŒUR DE CET OBJET EST `$centimes === null` ═════════════════════════
 *
 * `null` n'est pas l'absence de valeur par oubli : c'est une DÉCISION. Le
 * certificat de refus de paiement, en pratique, « se borne toujours à
 * indiquer que la provision est inexistante ou insuffisante sans chiffrer le
 * découvert » (`LOI.md` § 4.14). Or l'assiette de l'amende de l'art. 325
 * al. 1 est « du montant du chèque OU DU MANQUANT ». Quand le manquant n'est
 * pas chiffré, retomber sur le montant du chèque SURESTIMERAIT l'amende — et
 * d'autant plus que la provision était presque suffisante.
 *
 * Le domaine rend donc `null` exprès ({@see \App\Entity\Cheque::assietteEnCentimes()}),
 * et cette classe le porte jusqu'à l'écran avec la phrase qui l'explique. La
 * charte a même un jeton pour ça : `.montant--indetermine` garde la place et
 * la police du montant, sans l'or — parce qu'il n'y a pas de valeur.
 *
 * ═══ POURQUOI DES CENTIMES ENTIERS ════════════════════════════════════════
 *
 * Aucun flottant sur un montant légal. 2 % de 1 234,56 DH en flottant donne
 * 24,691200000000002, et un écran qui affiche ça a perdu la confiance de son
 * lecteur avant d'avoir dit quoi que ce soit.
 *
 * ═══ ET AUCUN DE CES MONTANTS N'EST OPPOSABLE ═════════════════════════════
 *
 * `LOI.md` § 6, H7 : les calculs de 2 %, de 0,5/1/1,5 % et de 6 % sont des
 * estimations d'aide à la préparation. L'amende est fixée par le tribunal ou
 * par la banque, pas par ce logiciel. Chaque montant porte donc sa règle, et
 * l'écran répète l'avertissement à côté du tableau.
 */
final readonly class MontantEstime
{
    /**
     * @param int|null $centimes    `null` quand ce logiciel REFUSE de chiffrer
     * @param string   $assiette    sur quoi le pourcentage porte, en clair
     * @param string|null $raisonDeLIndetermination obligatoire quand `$centimes`
     *                              est `null` : un montant absent sans explication serait lu
     *                              comme un bogue, et l'utilisateur chercherait le chiffre ailleurs
     * @param string|null $fourchette pour une amende que le texte donne en
     *                              fourchette (art. 316 al. 1 : 5 000 à 20 000 DH) et qu'aucun
     *                              calcul ne peut remplacer : c'est le tribunal qui la fixe
     */
    public function __construct(
        public string $libelle,
        public ?int $centimes,
        public string $assiette,
        public RegleAppliquee $regle,
        public ?string $raisonDeLIndetermination = null,
        public ?string $fourchette = null,
        public ?string $mentionDePlancherOuPlafond = null,
    ) {
        if (null === $centimes && null === $raisonDeLIndetermination && null === $fourchette) {
            // Garde-fou de conception. Un montant vide et muet est pire qu'un
            // montant faux : le lecteur le prend pour une panne et va chercher
            // le chiffre dans une source qui, elle, sera fausse.
            throw new \LogicException(
                'Un montant non chiffré doit porter la raison pour laquelle il ne l\'est pas, '
                .'ou la fourchette que le texte donne à sa place.'
            );
        }
    }

    public function chiffre(): bool
    {
        return null !== $this->centimes;
    }

    /** Le montant en dirhams, deux décimales, séparateur de milliers fine insécable. */
    public function enDirhams(): ?string
    {
        if (null === $this->centimes) {
            return null;
        }

        // `number_format` avec une espace fine insécable U+202F : un montant
        // ne se coupe pas en fin de ligne entre ses milliers et ses unités.
        return number_format($this->centimes / 100, 2, ',', "\u{202f}").' DH';
    }
}
