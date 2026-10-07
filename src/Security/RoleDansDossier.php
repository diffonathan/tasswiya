<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Le rôle d'un utilisateur DANS UN DOSSIER DONNÉ.
 *
 * Distinct des rôles Symfony (`ROLE_USER`, `ROLE_ADMIN`), et la distinction
 * est le cœur de la décision d'autorisation de ce projet : être avocat n'est
 * pas une propriété de la personne, c'est une propriété de son rapport à un
 * dossier. Le même utilisateur est créancier dans un dossier, débiteur dans
 * un autre, et rien du tout dans le troisième.
 *
 * C'est pour cela que l'autorisation passe par un voter sur l'objet et non
 * par une hiérarchie de rôles : une hiérarchie ne peut pas exprimer
 * « créancier de CE dossier ».
 */
enum RoleDansDossier: string
{
    /** Le bénéficiaire du chèque. Celui dont l'art. 325 al. 8 exige l'accord. */
    case BENEFICIAIRE = 'beneficiaire';

    /**
     * Le tireur. C'est lui qui est sous contrôle judiciaire pendant les
     * trente jours (art. 325 al. 7), et c'est lui qui a le plus besoin de
     * voir l'échéance — et la mesure de contrôle qui l'accompagne.
     */
    case TIREUR = 'tireur';

    /**
     * L'avocat, mandaté sur le dossier. Voit tout du dossier, y compris la
     * carte des certitudes, parce que c'est lui qui est en mesure de trancher
     * ce que ce logiciel laisse ouvert.
     */
    case AVOCAT = 'avocat';

    /**
     * Le greffe, qui saisit les décisions du parquet et les quittances.
     *
     * Rôle d'enregistrement : il écrit des faits, il ne décide pas des
     * transitions. La décision du parquet lui-même n'est pas modélisée comme
     * un utilisateur de ce logiciel — le parquet n'est pas censé s'en servir.
     */
    case GREFFE = 'greffe';

    /** Aucun rapport avec ce dossier. Ne voit rien, pas même son existence. */
    case AUCUN = 'aucun';
}
