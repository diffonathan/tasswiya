<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Prolongation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Prolongation>
 */
class ProlongationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registre)
    {
        parent::__construct($registre, Prolongation::class);
    }

    /**
     * Les prorogations saisies mais non encore appliquées, avec le détail de
     * ce qui leur manque.
     *
     * Sert l'écran du greffe : une prorogation peut attendre l'accord du
     * bénéficiaire, ou la décision du parquet, ou les deux, et ce ne sont pas
     * les mêmes personnes qu'il faut relancer. Les deux conditions de
     * l'article 325 al. 8 sont cumulatives et asymétriques, donc la liste des
     * prorogations en attente est inutile si elle ne dit pas laquelle manque.
     *
     * @return list<Prolongation>
     */
    public function prorogationsEnAttente(): array
    {
        $dql = <<<'DQL'
            SELECT p, d
            FROM App\Entity\Prolongation p
            JOIN p.dossier d
            WHERE p.accordeeLe IS NULL
            ORDER BY p.demandeeLe ASC
            DQL;

        return $this->getEntityManager()->createQuery($dql)->getResult();
    }

    /**
     * Distribution du nombre de prorogations accordées par dossier.
     *
     * Existe parce que le projet met en scène un mécanisme sur lequel il
     * n'existe AUCUNE statistique publique : ni nombre d'écédars notifiés, ni
     * prorogations demandées, accordées ou refusées. Cette méthode mesure ce
     * que CE LOGICIEL contient, sur des données fictives, et un chiffre qu'elle
     * imprime doit toujours être présenté comme tel — jamais comme une
     * donnée sur la pratique marocaine.
     *
     * @return array<int, int> le nombre de prorogations en clé, le nombre de dossiers en valeur
     */
    public function distributionDuNombreDeProrogations(): array
    {
        $dql = <<<'DQL'
            SELECT COUNT(p.id) AS nombreDeProrogations, d.id AS dossier
            FROM App\Entity\Prolongation p
            JOIN p.dossier d
            WHERE p.accordeeLe IS NOT NULL
            GROUP BY d.id
            DQL;

        $distribution = [];
        foreach ($this->getEntityManager()->createQuery($dql)->getScalarResult() as $ligne) {
            $nombre = (int) $ligne['nombreDeProrogations'];
            $distribution[$nombre] = ($distribution[$nombre] ?? 0) + 1;
        }

        ksort($distribution);

        return $distribution;
    }
}
