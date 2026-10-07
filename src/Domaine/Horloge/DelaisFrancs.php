<?php

declare(strict_types=1);

namespace App\Domaine\Horloge;

use App\Enum\DegreCertitude;

/**
 * L'autre lecture possible : délais francs, c'est-à-dire que ni le jour de
 * départ ni le jour d'échéance ne comptent dans le délai, lequel expire donc
 * un jour plus tard.
 *
 * Cette classe n'est pas une curiosité : elle existe parce que la loi 52.23,
 * publiée dans le MÊME Bulletin officiel n° 7478 que la loi 71.24, précise à
 * son article 150 que ses délais sont des délais francs. Le législateur
 * marocain sait donc écrire cette clause, et ne l'a pas écrite ici. Si une
 * circulaire ou un arrêt vient dire que les trente jours de l'article 325
 * sont francs, c'est cette implémentation qui devient la bonne, et un alias
 * de conteneur suffit à basculer tout le produit.
 *
 * Volontairement SANS #[AsAlias] : c'est l'implémentation par défaut
 * {@see DelaisCalendairesSansReport} qui porte l'alias. Celle-ci se câble à
 * la main, ce qu'un test fait pour prouver que la substitution déplace
 * réellement l'échéance.
 */
final class DelaisFrancs implements ReglesDeDelaiInterface
{
    public function echeance(\DateTimeImmutable $depart, int $jours): \DateTimeImmutable
    {
        if ($jours < 0) {
            throw new \InvalidArgumentException('Un délai ne peut pas être négatif.');
        }

        // Le jour d'échéance ne compte pas non plus : d'où le jour de plus.
        return $depart->modify('midnight')->modify(\sprintf('+%d days', $jours + 1));
    }

    public function libelleAffiche(): string
    {
        return 'Délais francs : ni le jour de départ ni le jour d\'échéance ne sont comptés. '
            .'Lecture alignée sur l\'article 150 de la loi 52.23, publiée au même Bulletin '
            .'officiel n° 7478 — mais la loi 71.24 ne contient pas cette clause.';
    }

    public function degre(): DegreCertitude
    {
        return DegreCertitude::CHOIX_PRODUIT;
    }
}
