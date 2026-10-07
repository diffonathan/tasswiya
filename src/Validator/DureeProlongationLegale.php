<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Contrainte personnalisée : la durée d'une prorogation doit être « égale ou
 * supérieure » au délai initial (art. 325 al. 8).
 *
 * POURQUOI UNE CONTRAINTE SUR LA CLASSE et non un `#[Assert\GreaterThan]` sur
 * la propriété : le plancher n'est pas une constante. C'est le délai initial
 * DU DOSSIER auquel la prorogation se rattache, lequel est persisté par
 * dossier pour qu'un changement de loi n'altère pas les dossiers ouverts.
 * La contrainte doit donc voir les deux objets ensemble.
 *
 * ET POURQUOI IL N'Y A AUCUN PLAFOND. L'article 325 al. 8 dit « لمدة مماثلة
 * أو أكثر » — pour une durée égale ou supérieure — sans plafond de durée et
 * sans limite de nombre. La circulaire du parquet évoque « 30 jours
 * supplémentaires » et la presse « 60 jours maximum » ; mais une circulaire
 * ne peut pas réduire ce que la loi ouvre, et le plafond de 60 jours n'est
 * écrit nulle part. Une durée de 90 jours est donc VALIDE : elle déclenche
 * une mention, pas un refus (voir
 * {@see \App\Entity\Prolongation::mentionSiAuDelaDeLaPratiqueDuParquet()}).
 *
 * Ajouter un plafond ici serait coder une règle inexistante, ce qui est la
 * faute que ce projet s'interdit.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class DureeProlongationLegale extends Constraint
{
    public string $messageDureeInsuffisante =
        'La durée de prorogation ({{ duree }} jours) est inférieure au délai initial '
        .'({{ plancher }} jours). L\'article 325 al. 8 impose une durée « égale ou supérieure » : '
        .'c\'est un plancher, et il n\'existe aucun plafond légal.';

    public string $messageDossierAbsent =
        'Une prorogation sans dossier rattaché n\'a pas de délai initial, donc pas de plancher '
        .'vérifiable.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
