<?php

declare(strict_types=1);

namespace App\Enum;

/** Les noms de transitions de la machine `interdiction_bancaire`. */
enum TransitionInterdictionBancaire: string
{
    /** Art. 313 nouveau : lève l'interdiction et purge tous ses effets. Irréversible. */
    case REGULARISER = 'regulariser';

    /** Art. 312 : les cinq ans sont écoulés. Déclenchée par le seul passage du temps. */
    case EXPIRER = 'expirer';

    public function estDeclencheeParLeTemps(): bool
    {
        return self::EXPIRER === $this;
    }
}
