<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lieu d'émission du chèque, qui détermine la durée de l'horloge H1.
 *
 * Art. 268, que la loi 71.24 ne modifie pas : 20 jours pour un chèque émis et
 * payable au Maroc, 60 jours pour un chèque émis à l'étranger et payable au
 * Maroc. Le décompte part de la DATE D'ÉMISSION PORTÉE SUR LE CHÈQUE, pas de
 * la remise réelle au banquier.
 */
enum LieuEmission: string
{
    case MAROC = 'maroc';
    case ETRANGER = 'etranger';

    /** Durée du délai de présentation au paiement, en jours (art. 268). */
    public function joursDePresentation(): int
    {
        return match ($this) {
            self::MAROC => 20,
            self::ETRANGER => 60,
        };
    }
}
