<?php

declare(strict_types=1);

namespace App\Domaine\Horloge;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/**
 * L'horloge de la démonstration : le temps réel, plus un décalage poussé à la
 * main depuis l'écran `/demonstration`.
 *
 * ⚠ CECI N'EST PAS UN OUTIL DE PRODUCTION, et c'est la seule classe du projet
 * dont il faut le dire deux fois. Elle existe pour qu'on puisse FILMER une
 * règle de droit en train de s'appliquer : on pousse de trente et un jours, le
 * dossier bascule tout seul en « délai expiré », et personne n'a cliqué sur
 * « expirer ».
 *
 * ─── CE QU'ELLE PROUVE, ET POURQUOI ELLE N'EST PAS UNE TRICHERIE ───────────
 *
 * Elle ne touche AUCUNE donnée. Aucune date du dossier n'est antidatée, aucun
 * état n'est forcé. Le dossier ne vieillit pas : c'est le présent qui avance.
 * La différence est tout le sujet — antidater les données ferait MENTIR le
 * dossier, tandis que déplacer le présent fait VIEILLIR une situation vraie.
 *
 * Et la bascule qui s'ensuit est obtenue par le même code qu'en production :
 * {@see \App\Workflow\ExpirateurDeDelais} demande à la machine à états si la
 * transition `expirer_delai` est possible, et la garde temporelle de
 * {@see \App\Workflow\EcouteurDeGardesTemporelles} répond en lisant CETTE
 * horloge. La démonstration ne prend donc aucun raccourci que la production
 * n'aurait pas : avancer de vingt-neuf jours ne fait rien basculer.
 *
 * ─── POURQUOI ELLE REMPLACE `ClockInterface` DANS TOUT LE CONTENEUR ────────
 *
 * Parce que si elle ne valait que pour l'écran de démonstration, la liste des
 * dossiers, le détail du dossier et la commande console continueraient à lire
 * le temps réel, et l'écran dirait « délai expiré » pendant que le tableau
 * d'à côté afficherait « il reste 29 jours ». Un produit dont tout le métier
 * est une affaire de délais ne supporte pas deux horloges.
 *
 * Elle est donc aliasée sur `Psr\Clock\ClockInterface` dans
 * `config/services.yaml` — sauf en test, où le bloc `when@test` du même
 * fichier impose la `MockClock`. L'ordre compte : le dernier alias gagne, et
 * la suite de tests doit rester maîtresse de son temps.
 *
 * ─── CE QUI EMPÊCHE DE LA PRENDRE POUR UN OUTIL ────────────────────────────
 *
 * 1. {@see decalageEnJours()} est publique, et chaque écran l'affiche dès
 *    qu'elle n'est pas nulle. Un décalage silencieux serait un piège.
 * 2. Le décalage ne recule pas et ne se choisit pas : on avance, ou l'on
 *    remet à zéro. Il n'y a pas de « se placer au 14 mars », qui permettrait
 *    de fabriquer un dossier pour une date choisie.
 * 3. L'écran de démonstration le dit en toutes lettres, et le bandeau du
 *    gabarit de base le répète sur toutes les pages.
 */
final readonly class HorlogeDeDemonstration implements ClockInterface
{
    private ClockInterface $horlogeReelle;

    public function __construct(
        private DecalageDeDemonstration $decalage,
        ?ClockInterface $horlogeReelle = null,
    ) {
        // `Clock::get()` et non `new \DateTimeImmutable()` : le fuseau de
        // l'application est posé par le composant Clock, et le reprendre à la
        // main ici ferait de cette classe un second endroit qui décide du
        // fuseau. L'argument reste injectable pour les tests unitaires.
        $this->horlogeReelle = $horlogeReelle ?? Clock::get();
    }

    public function now(): \DateTimeImmutable
    {
        $jours = $this->decalage->enJours();
        $maintenant = $this->horlogeReelle->now();

        if (0 === $jours) {
            return $maintenant;
        }

        // `modify('+N days')` et non une addition de secondes : un décalage de
        // jours doit rester un décalage de jours à travers un changement
        // d'heure légale, sinon une démonstration lancée fin octobre dériverait
        // d'une heure et une échéance calculée à minuit changerait de jour.
        return $maintenant->modify(\sprintf('+%d days', $jours));
    }

    /**
     * Le décalage courant, en jours. Zéro quand la démonstration n'a rien
     * poussé.
     *
     * Exposé exprès : tout écran qui affiche une date doit pouvoir dire si
     * cette date vient du calendrier ou de la démonstration.
     */
    public function decalageEnJours(): int
    {
        return $this->decalage->enJours();
    }

    /** Vrai si le temps affiché n'est pas le temps réel. */
    public function temporiseePourLaDemonstration(): bool
    {
        return 0 !== $this->decalage->enJours();
    }

    /** L'instant réel, pour que l'écran puisse montrer l'écart. */
    public function instantReel(): \DateTimeImmutable
    {
        return $this->horlogeReelle->now();
    }
}
