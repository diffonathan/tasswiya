<?php

declare(strict_types=1);

namespace App\Domaine\Horloge;

use App\Enum\DegreCertitude;

/**
 * La manière de compter un délai en jours — et c'est un CHOIX, pas une règle.
 *
 * Pourquoi cette interface existe, et pourquoi elle est le cœur honnête de ce
 * projet : la loi 71.24 ne contient AUCUNE clause sur le calcul des délais.
 * Ni jours francs, ni sort du dies a quo, ni report si le trentième jour
 * tombe un vendredi, un jour férié ou pendant le Ramadan. Le silence est
 * d'autant plus net que la loi 52.23, publiée dans le MÊME Bulletin officiel,
 * précise à son article 150 que tous ses délais sont des délais francs
 * (LOI.md § 4.15).
 *
 * Une machine à états doit pourtant trancher pour produire une date. Le
 * choix est donc rendu REMPLAÇABLE et AFFICHÉ, au lieu d'être enfoui dans un
 * `modify('+30 days')` quelque part.
 *
 * Et c'est aussi la démonstration du conteneur : l'implémentation par défaut
 * est câblée par autowiring, un test la substitue par
 * {@see DelaisFrancs} et la même échéance se déplace d'un jour. La
 * substitution est prouvée par un test, pas affirmée dans un discours.
 */
interface ReglesDeDelaiInterface
{
    /**
     * Calcule l'échéance d'un délai exprimé en jours.
     *
     * @param \DateTimeImmutable $depart l'événement qui fait partir le délai —
     *                                   jamais « maintenant », toujours un fait daté et prouvé par une pièce
     * @param int                $jours  la durée, telle que le texte l'écrit
     */
    public function echeance(\DateTimeImmutable $depart, int $jours): \DateTimeImmutable;

    /**
     * Le libellé à afficher dans l'encart « hypothèses de calcul ».
     *
     * Obligatoire sur l'interface, et non facultatif : une implémentation qui
     * calculerait une date sans pouvoir dire comment elle la calcule
     * reproduirait exactement le défaut qu'on veut éviter.
     */
    public function libelleAffiche(): string;

    /**
     * Toujours CHOIX_PRODUIT. La signature le force, pour qu'aucune
     * implémentation future ne puisse se présenter comme du droit établi.
     */
    public function degre(): DegreCertitude;
}
