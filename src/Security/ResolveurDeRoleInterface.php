<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Dossier;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Détermine le rôle d'un utilisateur dans un dossier.
 *
 * POURQUOI UNE INTERFACE, et c'est la seconde démonstration du conteneur de
 * ce projet : le rattachement d'un utilisateur à un dossier change selon le
 * déploiement. Dans la démonstration, il se lit sur l'adresse de courriel
 * des parties ; dans une installation réelle, il viendrait d'un annuaire de
 * cabinet, d'un mandat enregistré ou d'un identifiant de barreau.
 *
 * L'entité {@see Dossier} ne connaît donc PAS le composant Security, et le
 * voter ne connaît pas la manière dont le rattachement se fait. Entre les
 * deux, cette interface — remplaçable par un alias de conteneur, et la
 * substitution se prouve par un test.
 */
interface ResolveurDeRoleInterface
{
    public function roleDans(Dossier $dossier, ?UserInterface $utilisateur): RoleDansDossier;
}
