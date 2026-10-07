<?php

declare(strict_types=1);

namespace App\Command;

use App\Workflow\ExpirateurDeDelais;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * LA COMMANDE QUI IMPRIME LE CHIFFRE.
 *
 * ─── POURQUOI ELLE EXISTE ───────────────────────────────────────────────────
 *
 * {@see ExpirateurDeDelais} était écrit, documenté, soigné — et SANS AUCUN
 * APPELANT. Le conteneur l'avait donc supprimé à la compilation, ce qui ne se
 * voyait nulle part jusqu'à ce qu'un test tente de le récupérer :
 *
 *   The "App\Workflow\ExpirateurDeDelais" service or alias has been removed
 *   or inlined when the container was compiled.
 *
 * C'est le conteneur qui dit « ce code n'est utilisé par personne », et c'est
 * un diagnostic qu'aucune relecture n'aurait donné. Cette commande est le
 * consommateur que le service attendait.
 *
 * ─── ET POURQUOI UNE COMMANDE PLUTÔT QU'UN SCHEDULER TOUT DE SUITE ──────────
 *
 * Le Scheduler appellera ce même service, et devra l'appeler par ce même
 * chemin. Mais une tâche planifiée ne s'observe pas : on ne peut pas la
 * lancer devant une caméra, et elle ne rend aucun chiffre à l'écran. La
 * commande, elle, fait les deux — et la discipline des chiffres de ce projet
 * exige qu'aucun nombre ne soit publié sans la commande qui l'imprime :
 *
 *   docker compose exec php bin/console tasswiya:faire-expirer-les-delais
 *
 * La tâche planifiée viendra par-dessus, et appellera ce service, pas cette
 * commande : une tâche qui passerait par la console ajouterait un processus et
 * un amorçage complet de Symfony à chaque passe, pour rien.
 *
 * ─── CE QU'ELLE NE FAIT PAS ─────────────────────────────────────────────────
 *
 * Elle ne décide rien. Elle n'ouvre aucune option « forcer », aucune date
 * arbitraire : l'expiration est gardée par l'horloge injectée, et une option
 * de ligne de commande qui la contournerait rendrait possible, depuis un
 * terminal, ce que la machine à états interdit depuis le web. Pour déplacer le
 * temps, on déplace l'horloge — en test par `MockClock`, en démonstration par
 * l'horloge de démonstration — jamais par un drapeau.
 */
#[AsCommand(
    name: 'tasswiya:faire-expirer-les-delais',
    description: 'Fait basculer en « délai expiré » les dossiers dont le délai de l\'art. 325 al. 6 est échu.',
)]
final class FaireExpirerLesDelaisCommande extends Command
{
    public function __construct(
        private readonly ExpirateurDeDelais $expirateur,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $style = new SymfonyStyle($entree, $sortie);

        $compte = $this->expirateur->faireExpirerLesDelaisEchus();

        $style->definitionList(
            ['Dossiers examinés' => (string) $compte['examines']],
            ['Dossiers basculés en « délai expiré »' => (string) $compte['bascules']],
            ['Collisions (laissées pour la passe suivante)' => (string) $compte['collisions']],
        );

        // Les collisions ne sont pas un échec : elles signifient qu'un greffe
        // écrivait pendant la passe, et le dossier est repris au tour
        // suivant. Les taire serait pire — un chiffre publié sur l'activité de
        // cette passe doit pouvoir être recoupé.
        if ($compte['collisions'] > 0) {
            $style->note(
                'Des dossiers ont été modifiés pendant la passe. Ils n\'ont pas été écrasés et '
                .'seront repris au prochain passage.'
            );
        }

        $style->success(\sprintf(
            '%d dossier(s) sur %d examiné(s) ont basculé au seul passage du temps.',
            $compte['bascules'],
            $compte['examines'],
        ));

        return Command::SUCCESS;
    }
}
