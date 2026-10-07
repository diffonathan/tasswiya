<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Partie>
 */
class PartieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registre)
    {
        parent::__construct($registre, Partie::class);
    }

    /**
     * Retrouve une partie par son adresse de courriel.
     *
     * Le seul pont entre la sécurité et le domaine, et il est volontairement
     * étroit : le résolveur de rôle passe par ici, et nulle part le domaine
     * ne référence l'entité utilisateur.
     */
    public function parCourriel(string $courriel): ?Partie
    {
        $dql = <<<'DQL'
            SELECT p
            FROM App\Entity\Partie p
            WHERE p.courrielDeContact = :courriel
            DQL;

        return $this->getEntityManager()
            ->createQuery($dql)
            ->setParameter('courriel', $courriel)
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }
}
