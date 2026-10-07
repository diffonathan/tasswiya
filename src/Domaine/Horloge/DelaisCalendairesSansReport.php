<?php

declare(strict_types=1);

namespace App\Domaine\Horloge;

use App\Enum\DegreCertitude;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * L'implémentation retenue par défaut : jours calendaires, dies a quo exclu,
 * aucun report sur jour non ouvré.
 *
 * Les trois décisions, et leur motif (LOI.md § 6, H1 et H2) :
 *
 *  1. JOURS CALENDAIRES. Le texte arabe dit « ثلاثين (30) يوما » sans plus.
 *     Le12.ma écrit « 30 jours calendaires » en résumant le juge Bouttouil,
 *     mais c'est un résumé de presse, pas le texte.
 *
 *  2. LE JOUR DE L'ÉCÉDAR N'EST PAS COMPTÉ : échéance = écédar + 30 jours.
 *     C'est le choix le plus favorable au débiteur, donc le moins susceptible
 *     de faire manquer un droit par excès de zèle. À reprendre si une
 *     jurisprudence ou une circulaire tranche l'inverse.
 *
 *  3. AUCUN REPORT si l'échéance tombe un vendredi, un jour férié ou pendant
 *     le Ramadan. Reporter serait inventer une règle que le texte ne porte
 *     pas ; ne rien dire serait laisser passer une difficulté réelle. Donc :
 *     calcul strict, et l'écran SIGNALE que le trentième jour est un jour
 *     non ouvré — voir {@see Horloge::tombeUnJourProbablementNonOuvre()}.
 *
 * L'attribut #[AsAlias] fait de cette classe l'implémentation que l'autowiring
 * injecte partout où l'on demande {@see ReglesDeDelaiInterface}. C'est le
 * point précis qu'un test substitue.
 */
#[AsAlias(id: ReglesDeDelaiInterface::class)]
final class DelaisCalendairesSansReport implements ReglesDeDelaiInterface
{
    public function echeance(\DateTimeImmutable $depart, int $jours): \DateTimeImmutable
    {
        if ($jours < 0) {
            throw new \InvalidArgumentException('Un délai ne peut pas être négatif.');
        }

        // Normalisé à minuit : une échéance légale est un jour, pas un instant.
        // Garder l'heure de l'écédar ferait expirer le délai à 14 h 32 le
        // trentième jour, ce que le texte ne dit nulle part.
        return $depart->modify('midnight')->modify(\sprintf('+%d days', $jours));
    }

    public function libelleAffiche(): string
    {
        return 'Jours calendaires, jour de départ non compté, aucun report si l\'échéance tombe '
            .'un jour non ouvré. La loi 71.24 ne règle pas le calcul des délais : c\'est une '
            .'hypothèse de ce logiciel, pas une règle de droit.';
    }

    public function degre(): DegreCertitude
    {
        return DegreCertitude::CHOIX_PRODUIT;
    }
}
