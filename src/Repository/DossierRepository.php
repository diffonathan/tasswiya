<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dossier;
use App\Enum\EtatDossier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Le repository des dossiers.
 *
 * DATA MAPPER, ET ON LE VOIT ICI PLUS QU'AILLEURS : l'entité {@see Dossier}
 * ne contient aucune de ces requêtes, et ne connaît même pas cette classe.
 * En ActiveRecord, `Dossier::whereEtat(...)->get()` serait une méthode de
 * l'entité elle-même, et le domaine dépendrait de la base. Ici, la
 * séparation permet d'instancier un dossier dans un test unitaire et de
 * faire courir toute la logique de délais sans démarrer PostgreSQL.
 *
 * @extends ServiceEntityRepository<Dossier>
 */
class DossierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registre)
    {
        parent::__construct($registre, Dossier::class);
    }

    /**
     * Les dossiers dont le délai pénal PEUT avoir expiré.
     *
     * DQL écrit à la main, et avec une précaution qui est tout le sujet : la
     * requête ne décide PAS de l'expiration. Elle présélectionne les dossiers
     * dans un état où un délai court, et l'expiration elle-même est tranchée
     * par la garde temporelle, qui seule connaît l'horloge injectée et les
     * règles de calcul des délais.
     *
     * Pourquoi ne pas filtrer sur la date en SQL, ce qui serait plus efficace :
     * parce que l'échéance n'est PAS persistée. Elle se recalcule depuis la
     * date de l'écédar, la durée initiale et la somme des prorogations
     * accordées, selon des règles de calcul substituables. Une comparaison
     * SQL sur une échéance stockée figerait les dossiers anciens sous les
     * hypothèses de calcul du jour où ils ont été écrits — et une correction
     * de l'hypothèse H1 ne repasserait jamais sur eux.
     *
     * Le filtre sur la date de l'écédar est seulement une borne de sécurité :
     * aucun délai ne peut courir avant son point de départ.
     *
     * @return list<Dossier>
     */
    public function dossiersDontLeDelaiPenalPeutAvoirExpire(): array
    {
        $dql = <<<'DQL'
            SELECT d, p
            FROM App\Entity\Dossier d
            LEFT JOIN d.prolongations p
            WHERE d.etat IN (:etatsOuLeDelaiCourt)
              AND d.dateEcedar IS NOT NULL
            ORDER BY d.dateEcedar ASC
            DQL;

        return $this->getEntityManager()
            ->createQuery($dql)
            // Les valeurs et non les cas : la colonne `etat` est le marquage de
            // la machine à états, donc une chaîne (voir Dossier::$etat).
            ->setParameter('etatsOuLeDelaiCourt', [
                EtatDossier::ECEDAR_NOTIFIE->value,
                EtatDossier::DELAI_PROLONGE->value,
            ])
            ->getResult();
    }

    /**
     * Les dossiers d'un participant, par son adresse de courriel.
     *
     * Volontairement limitée aux parties au dossier : l'avocat et le greffe
     * ne passent pas par ici, parce que leur périmètre ne se déduit pas d'un
     * lien de partie. C'est le voter qui décide ce que chacun voit, et cette
     * requête n'est qu'un raccourci de liste pour les deux rôles dont le
     * rattachement est une donnée du dossier.
     *
     * @return list<Dossier>
     */
    public function dossiersDuParticipant(string $courriel): array
    {
        $dql = <<<'DQL'
            SELECT d, c, t, b
            FROM App\Entity\Dossier d
            JOIN d.cheque c
            JOIN d.tireur t
            JOIN d.beneficiaire b
            WHERE t.courrielDeContact = :courriel
               OR b.courrielDeContact = :courriel
            ORDER BY d.reference ASC
            DQL;

        return $this->getEntityManager()
            ->createQuery($dql)
            ->setParameter('courriel', $courriel)
            ->getResult();
    }

    /**
     * Un dossier avec tout ce dont les gardes ont besoin, en une requête.
     *
     * Les gardes de `proroger_delai` interrogent la prorogation en attente et
     * le journal des pièces. Sans ces jointures, le chargement paresseux
     * déclencherait une requête par collection à chaque évaluation de garde,
     * et le graphe d'états se paierait en allers-retours vers la base.
     */
    public function dossierCompletParReference(string $reference): ?Dossier
    {
        $dql = <<<'DQL'
            SELECT d, c, t, b, p, e, i
            FROM App\Entity\Dossier d
            JOIN d.cheque c
            JOIN d.tireur t
            JOIN d.beneficiaire b
            LEFT JOIN d.prolongations p
            LEFT JOIN d.evenements e
            LEFT JOIN d.interdictionBancaire i
            WHERE d.reference = :reference
            DQL;

        return $this->getEntityManager()
            ->createQuery($dql)
            ->setParameter('reference', $reference)
            ->getOneOrNullResult();
    }

    /**
     * Compte les dossiers par état.
     *
     * Existe pour la discipline des chiffres du projet : toute proportion
     * publiée doit dire sur combien de cas elle porte, et aucun nombre ne
     * s'écrit sans la commande qui l'imprime. Cette méthode est cette
     * commande, côté données.
     *
     * @return array<string, int> l'état en clé, le nombre en valeur
     */
    public function compterParEtat(): array
    {
        $dql = <<<'DQL'
            SELECT d.etat AS etat, COUNT(d.id) AS nombre
            FROM App\Entity\Dossier d
            GROUP BY d.etat
            DQL;

        $lignes = $this->getEntityManager()->createQuery($dql)->getScalarResult();

        $comptes = [];
        foreach ($lignes as $ligne) {
            // `EtatDossier::from()` plutôt qu'un transtypage : un état en base
            // qui ne correspond à aucune place du graphe doit faire échouer le
            // compte, pas apparaître dans un tableau de bord comme une
            // catégorie inconnue.
            $comptes[EtatDossier::from((string) $ligne['etat'])->value] = (int) $ligne['nombre'];
        }

        return $comptes;
    }
}
