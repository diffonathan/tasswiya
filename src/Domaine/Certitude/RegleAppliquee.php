<?php

declare(strict_types=1);

namespace App\Domaine\Certitude;

use App\Enum\DegreCertitude;

/**
 * Une règle telle qu'elle s'affiche : son énoncé, son article, son degré, et
 * l'attribution qu'on a le DROIT d'écrire sous elle.
 *
 * C'est la pièce qui fait voyager le degré de certitude du code jusqu'à
 * l'écran (LOI.md § 5 point 7). Sans elle, le degré resterait un commentaire
 * de développeur et le produit afficherait une instruction du parquet avec
 * l'autorité d'une loi.
 *
 * La règle d'attribution est stricte, et elle vient d'un fait vérifié : il
 * n'existe AUCUNE version française officielle de la loi 71.24. La série
 * française du Bulletin officiel passe du n° 7474 au n° 7480, et aucun des
 * numéros français de 2026 jusqu'au n° 7540 ne contient « 71.24 », « 1.26.03 »
 * ni « 15.95 ». Tout énoncé français de ce produit est donc une TRADUCTION DE
 * TRAVAIL non opposable, et chaque écran doit le dire.
 */
final readonly class RegleAppliquee
{
    /**
     * @param string      $enonce        la règle en français — traduction de travail
     * @param string      $article       l'article, tel qu'on a le droit de le citer
     * @param string|null $arabeSource   le verbatim arabe quand le mot compte
     * @param string|null $sourceReelle  pour un degré PROBABLE ou INCERTAIN : la
     *                                   source VRAIE (circulaire, doctrine, édition antérieure du Code), qui
     *                                   remplace « loi 71-24 » dans l'affichage
     * @param string|null $renvoiLoiMd   la section de LOI.md où l'arbitrage est écrit
     */
    public function __construct(
        public string $enonce,
        public string $article,
        public DegreCertitude $degre,
        public ?string $arabeSource = null,
        public ?string $sourceReelle = null,
        public ?string $renvoiLoiMd = null,
    ) {
        if (DegreCertitude::ETABLI !== $degre && null === $sourceReelle) {
            // Garde-fou de conception, pas de validation d'entrée : une règle
            // non établie SANS source réelle s'afficherait fatalement sous
            // l'autorité de la loi, qui est exactement ce qu'on veut empêcher.
            throw new \LogicException(
                'Une règle de degré '.$degre->value.' doit porter sa source réelle, '
                .'sinon elle s\'affichera comme si elle venait de la loi 71.24.'
            );
        }
    }

    /**
     * Ce que l'écran écrit sous la règle.
     *
     * Jamais « loi 71-24 » pour une règle PROBABLE ou INCERTAINE : ce serait
     * une fausse citation, et c'est la faute que la contrainte n° 1 du projet
     * cherche à éviter.
     */
    public function attribution(): string
    {
        return match ($this->degre) {
            DegreCertitude::ETABLI => $this->article
                .' — loi n° 71.24, BO n° 7478 du 29 janvier 2026 (version arabe seule officielle ; '
                .'traduction de travail, non opposable)',
            DegreCertitude::PROBABLE, DegreCertitude::INCERTAIN => $this->article.' — '.$this->sourceReelle,
            DegreCertitude::CHOIX_PRODUIT => 'Hypothèse de ce logiciel — '.$this->sourceReelle,
        };
    }

    /**
     * Vrai si l'écran doit afficher une alerte « ce point n'est pas tranché,
     * vérifiez » au lieu d'une conclusion.
     *
     * En cas de doute, le produit alerte ; il ne laisse pas passer.
     */
    public function exigeUneAlerte(): bool
    {
        return $this->degre->exigeUneAlerte();
    }
}
