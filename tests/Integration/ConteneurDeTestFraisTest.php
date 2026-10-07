<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * LE TEST QUI GARDE LES AUTRES TESTS HONNÊTES.
 *
 * Il ne teste rien du droit marocain, et c'est pour cela qu'il est
 * indispensable : il vérifie que la suite de tests VOIT le code qu'elle est
 * censée tester.
 *
 * ─── CE QUI S'EST PASSÉ, LE 7 OCTOBRE 2026 ─────────────────────────────────
 *
 * `.env.test` portait `APP_DEBUG=0`. Un noyau Symfony non débogué ne vérifie
 * pas la fraîcheur de son conteneur compilé : il charge
 * `var/cache/test/…Container.php` tel quel, sans comparer les dates des
 * fichiers de configuration. Conséquence mesurée :
 *
 *   • la garde `eteindre_action_publique` de `config/packages/workflow.yaml`
 *     a été vidée de `and subject.amendeDeuxPourCentPayee()` — c'est-à-dire
 *     que l'amende de 2 % de l'art. 325 al. 1 n'était plus exigée — et le test
 *     qui protège cette règle a répondu « OK (1 test, 3 assertions) » ;
 *
 *   • à l'inverse, le conteneur gardait un service `App\Security\DossierVoter`
 *     d'une disposition de fichiers antérieure. La classe n'existait plus, et
 *     huit tests ont échoué sur « Class "App\Security\DossierVoter" not
 *     found » — un message qui ne nomme ni le cache, ni sa péremption.
 *
 * Autrement dit : les 703 lignes de règles de droit de `workflow.yaml`
 * pouvaient être modifiées sans qu'aucun test ne rougisse. Sur un projet dont
 * la valeur entière est que les gardes légales soient vérifiées, c'est la pire
 * défaillance possible : une suite verte qui ne teste plus rien.
 *
 * `APP_DEBUG=1` corrige la cause. Ce test l'épingle, pour qu'un futur
 * « optimisons la suite de tests » se heurte à une assertion et à cette
 * explication, plutôt qu'à rien.
 */
final class ConteneurDeTestFraisTest extends KernelTestCase
{
    /**
     * ★ Le noyau de test est DÉBOGUÉ, donc son conteneur se recalcule quand la
     * configuration change.
     */
    #[Test]
    public function leNoyauDeTestEstDeboguePourQueLeConteneurSuiveLaConfiguration(): void
    {
        self::bootKernel();

        self::assertTrue(
            self::$kernel->isDebug(),
            "APP_DEBUG=0 en test gèle le conteneur compilé : les gardes de workflow.yaml peuvent "
            ."alors être modifiées sans qu'aucun test ne rougisse. Voir le commentaire de .env.test."
        );

        // Et la conséquence concrète : le noyau connaît ses fichiers de
        // configuration comme des RESSOURCES à surveiller. Sans débogage,
        // cette liste n'est pas même constituée.
        self::assertTrue(
            self::getContainer()->getParameter('kernel.debug'),
            'Le paramètre doit concorder avec le noyau.'
        );
    }

    /**
     * L'horloge du conteneur est bien une horloge FIGÉE, et pas l'horloge
     * système.
     *
     * Substitution déclarée dans le bloc `when@test` de
     * `config/services.yaml`. Si elle sautait, tous les tests de délai
     * continueraient de passer AUJOURD'HUI et commenceraient à échouer à une
     * date imprévisible — le pire mode de panne, parce qu'il ne se manifeste
     * pas au moment du changement.
     */
    #[Test]
    public function lHorlogeDuConteneurEstSubstituee(): void
    {
        self::bootKernel();
        $conteneur = self::getContainer();

        foreach ([
            \Psr\Clock\ClockInterface::class,
            \Symfony\Component\Clock\ClockInterface::class,
        ] as $service) {
            self::assertInstanceOf(
                MockClock::class,
                $conteneur->get($service),
                \sprintf(
                    'Le service « %s » doit être une horloge figée en test. N\'en substituer qu\'un '
                        .'laisserait une horloge réelle dans la moitié des services : le pire des cas, '
                        .'celui qui passe la plupart du temps.',
                    $service
                )
            );
        }
    }

    /**
     * Les deux machines à états sont bien câblées par le conteneur.
     *
     * `GrapheDuDossierTest` lit `workflow.yaml` comme un fichier texte : il
     * vérifie que les NOMS concordent, pas que le bundle sache construire les
     * machines. Un `marking_store` mal configuré ou un `supports` visant une
     * classe absente passerait sa vérification et casserait au démarrage.
     */
    #[Test]
    public function lesDeuxMachinesSontCableesParLeConteneur(): void
    {
        self::bootKernel();
        $conteneur = self::getContainer();

        foreach (['state_machine.dossier_penal', 'state_machine.interdiction_bancaire'] as $service) {
            self::assertInstanceOf(WorkflowInterface::class, $conteneur->get($service));
        }
    }
}
