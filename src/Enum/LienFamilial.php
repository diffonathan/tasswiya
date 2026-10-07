<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Le lien de famille entre le tireur et le bénéficiaire, au seul sens de la
 * cause de justification de l'article 325 al. 4 et 5.
 *
 * Les cas sont limitatifs, et c'est le point que la presse et les notes de
 * cabinet effacent : le texte vise les ÉPOUX, les ASCENDANTS et les
 * DESCENDANTS AU PREMIER DEGRÉ. Un frère, un oncle, un petit-fils ne sont
 * pas dans le texte. Ajouter un cas ici serait étendre une dépénalisation.
 */
enum LienFamilial: string
{
    case AUCUN = 'aucun';

    /** Époux, mariage en cours. */
    case EPOUX = 'epoux';

    /**
     * Ex-époux. La cause de justification joue encore PENDANT LES QUATRE
     * ANNÉES suivant la dissolution du lien conjugal — règle que, d'après
     * le dépouillement de LOI.md, aucune source secondaire ne mentionne.
     *
     * Conséquence sur le modèle : ce cas exige une date de dissolution, sans
     * laquelle la fenêtre de quatre ans est incalculable. La garde refuse
     * donc plutôt que de supposer.
     */
    case EX_EPOUX = 'ex_epoux';

    /** Ascendant au premier degré : père ou mère. */
    case ASCENDANT_PREMIER_DEGRE = 'ascendant_premier_degre';

    /** Descendant au premier degré : fils ou fille. */
    case DESCENDANT_PREMIER_DEGRE = 'descendant_premier_degre';

    /**
     * Vrai si le lien est, en lui-même, dans le champ de l'article 325 al. 4.
     *
     * Pour EX_EPOUX, répondre vrai ici ne suffit pas : il reste à vérifier la
     * fenêtre de quatre ans, qui dépend d'une date et donc d'une horloge.
     * C'est pour cela que la garde est en deux morceaux.
     */
    public function estDansLeChampArticle325(): bool
    {
        return self::AUCUN !== $this;
    }

    /** Vrai si la fenêtre de quatre ans de l'art. 325 al. 5 doit être vérifiée. */
    public function exigeLaFenetreDeQuatreAns(): bool
    {
        return self::EX_EPOUX === $this;
    }
}
