<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as ExceptionDbal;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'ecran de verification du squelette.
 *
 * Il ne touche AUCUNE entite du domaine, et c'est un choix d'architecture et
 * non une facilite : cette page doit repondre « oui, la pile tourne » meme
 * pendant que le modele metier est en chantier. Une page d'accueil qui
 * instancie un agregat metier rend 500 a chaque renommage d'une methode, et on
 * ne sait alors plus si c'est Symfony, PostgreSQL ou le domaine qui a lache —
 * exactement l'ambiguite qui coute une demi-heure.
 *
 * Elle lit donc seulement ce que le conteneur sait de lui-meme : la version du
 * noyau, l'horloge injectee, et l'etat de la connexion a la base.
 */
final class AccueilController extends AbstractController
{
    #[Route('/', name: 'accueil', methods: ['GET'])]
    public function __invoke(ClockInterface $horloge, Connection $connexion): Response
    {
        return $this->render('accueil.html.twig', [
            'versionSymfony' => Kernel::VERSION,
            'versionPhp' => \PHP_VERSION,
            'environnement' => $this->getParameter('kernel.environment'),
            // L'heure vient de `ClockInterface` et non de `new
            // DateTimeImmutable()`. Sur un produit dont tout le metier est une
            // affaire de delais, c'est la seule discipline qui rend les regles
            // testables : en test le conteneur substitue une `MockClock` figee
            // (voir le bloc `when@test` de config/services.yaml) sans qu'une
            // ligne de code applicatif change.
            'maintenant' => $horloge->now(),
            'horloge' => $horloge::class,
            'base' => $this->etatDeLaBase($connexion),
        ]);
    }

    /**
     * @return array{joignable: bool, detail: string}
     */
    private function etatDeLaBase(Connection $connexion): array
    {
        try {
            $version = $connexion->fetchOne('SELECT version()');

            return [
                'joignable' => true,
                // Le premier segment seulement : la chaine complete de
                // PostgreSQL enumere le compilateur et l'architecture, ce qui
                // n'apprend rien ici et encombre l'ecran.
                'detail' => implode(' ', \array_slice(explode(' ', (string) $version), 0, 2)),
            ];
        } catch (ExceptionDbal $erreur) {
            // Le message exact et non « erreur de base » : « authentification
            // refusee » et « hote introuvable » ne se corrigent pas au meme
            // endroit, et masquer la difference envoie chercher du cote deja
            // correct.
            return ['joignable' => false, 'detail' => $erreur->getMessage()];
        }
    }
}
