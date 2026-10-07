<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Dossier;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * L'implémentation par défaut : le rôle se lit sur l'adresse de courriel.
 *
 * Suffisante pour une démonstration sur données fictives, et honnête sur sa
 * limite : un vrai mandat d'avocat ne se prouve pas par une adresse de
 * courriel. C'est précisément pourquoi le rattachement passe par une
 * interface remplaçable plutôt que par un `if` dans le voter.
 *
 * L'avocat et le greffe sont reconnus par leurs rôles Symfony, parce que leur
 * périmètre ne se déduit pas du dossier : ils ne sont pas parties. Dans une
 * installation réelle, l'avocat devrait être rattaché dossier par dossier
 * — un avocat n'a pas accès à tous les dossiers d'une juridiction — et c'est
 * l'implémentation qu'il faudra substituer à celle-ci.
 */
#[AsAlias(id: ResolveurDeRoleInterface::class)]
final readonly class ResolveurDeRoleParCourriel implements ResolveurDeRoleInterface
{
    public const string ROLE_SYMFONY_AVOCAT = 'ROLE_AVOCAT';
    public const string ROLE_SYMFONY_GREFFE = 'ROLE_GREFFE';

    public function roleDans(Dossier $dossier, ?UserInterface $utilisateur): RoleDansDossier
    {
        if (null === $utilisateur) {
            return RoleDansDossier::AUCUN;
        }

        $identifiant = $utilisateur->getUserIdentifier();

        // Les parties d'abord : être partie au dossier primera toujours sur un
        // rôle général, parce qu'un avocat qui serait aussi le bénéficiaire
        // doit voir le dossier comme bénéficiaire.
        if ($identifiant === $dossier->beneficiaire()->courrielDeContact()) {
            return RoleDansDossier::BENEFICIAIRE;
        }

        if ($identifiant === $dossier->tireur()->courrielDeContact()) {
            return RoleDansDossier::TIREUR;
        }

        $rolesSymfony = $utilisateur->getRoles();

        if (\in_array(self::ROLE_SYMFONY_AVOCAT, $rolesSymfony, true)) {
            return RoleDansDossier::AVOCAT;
        }

        if (\in_array(self::ROLE_SYMFONY_GREFFE, $rolesSymfony, true)) {
            return RoleDansDossier::GREFFE;
        }

        return RoleDansDossier::AUCUN;
    }
}
