<?php

declare(strict_types=1);

namespace App\Domaine\Urgence;

use App\Domaine\Horloge\Horloge;
use App\Entity\Dossier;

/**
 * Ce qui presse sur un dossier, et laquelle des cinq horloges presse.
 *
 * ═══ POURQUOI « LAQUELLE » EST AUSSI IMPORTANT QUE « COMBIEN » ════════════
 *
 * Un tableau qui afficherait « 3 jours » sans dire de quelle horloge il
 * parle serait faux la moitié du temps. Trois jours avant l'expiration du
 * délai de présentation au paiement (art. 268) et trois jours avant
 * l'expiration du délai de régularisation pénale (art. 325 al. 6) ne
 * demandent ni la même action, ni au même acteur, ni avec le même enjeu.
 *
 * Cet objet porte donc l'horloge elle-même, et l'écran affiche son point de
 * départ nommé à côté du nombre de jours.
 *
 * ═══ LES TROIS BANDES, ET POURQUOI « PASSÉE » N'EST PAS « PERDU » ═════════
 *
 *  - **PRESSE** : au moins une horloge court encore. Triée par le nombre de
 *    jours restants le plus faible : c'est la seule qui soit un ordre
 *    d'urgence. Trier par date de création classerait un dossier de l'an
 *    dernier où plus rien ne court avant un dossier d'avant-hier où il reste
 *    deux jours.
 *  - **PASSEE** : toutes les horloges du dossier sont échues. Ce n'est pas
 *    « perdu » : l'expiration du délai de l'art. 325 al. 6 fait tomber
 *    l'obstacle procédural à la poursuite, elle n'éteint aucun droit, et le
 *    paiement plus l'amende de 2 % éteignent encore l'action publique. La
 *    bande dit donc ce qui a changé, pas ce qui serait fini.
 *  - **CLOSE** : l'état pénal est terminal. Et c'est la seule condition —
 *    volontairement, parce qu'une justification familiale retenue n'arrête
 *    PAS les cinq ans de l'interdiction bancaire. La ligne continue donc
 *    d'afficher l'horloge bancaire qui court, sous une bande qui dit « clos
 *    au pénal » et non « clos ». Le pénal est clos, la banque ne l'est pas,
 *    et c'est exactement ce qu'un classement par « une » date limite aurait
 *    été incapable d'exprimer.
 */
final readonly class UrgenceDuDossier
{
    public const string BANDE_PRESSE = 'presse';
    public const string BANDE_PASSEE = 'passee';
    public const string BANDE_CLOSE = 'close';

    public function __construct(
        public Dossier $dossier,
        public string $bande,
        /** L'horloge qui décide du rang, ou `null` si aucune ne court. */
        public ?Horloge $horlogePressante,
        /**
         * Jours restants sur cette horloge. Négatif si elle est échue,
         * `null` si aucune horloge n'existe.
         *
         * Négatif et non zéro quand c'est passé : zéro voudrait dire « elle
         * expire aujourd'hui », ce qui est faux et grave pour quelqu'un qui
         * compte sur ce chiffre.
         */
        public ?int $joursRestants,
        /** Toutes les horloges du dossier, pour le détail au survol. */
        public array $toutesLesHorloges,
        public bool $etatTerminalAuPenal,
        public bool $sousControleJudiciaire,
    ) {
    }

    /** Le rang de la bande, pour le tri. */
    public function rangDeLaBande(): int
    {
        return match ($this->bande) {
            self::BANDE_PRESSE => 0,
            self::BANDE_PASSEE => 1,
            default => 2,
        };
    }

    /**
     * Vrai si l'échéance est à sept jours ou moins : le seuil au-delà duquel
     * l'écran cesse de distinguer.
     *
     * Sept jours et non « trois » ou « cinq » : c'est la semaine, l'unité
     * dans laquelle un cabinet organise sa semaine. Ce seuil est un choix
     * d'affichage et aucune règle de droit n'en dépend — il ne pilote aucun
     * calcul, seulement l'ordre de lecture.
     */
    public function imminente(): bool
    {
        return self::BANDE_PRESSE === $this->bande
            && null !== $this->joursRestants
            && $this->joursRestants <= 7;
    }

    /** Le ton de la pastille : perdu, en cours, réglé. */
    public function ton(): string
    {
        return match ($this->bande) {
            self::BANDE_PRESSE => $this->imminente() ? 'perdu' : 'en-cours',
            self::BANDE_PASSEE => 'perdu',
            default => 'regle',
        };
    }
}
