<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Enum\EtatDossier;
use App\Enum\EtatInterdictionBancaire;
use App\Enum\TransitionDossier;
use App\Enum\TransitionInterdictionBancaire;
use App\Workflow\CatalogueDesBlocages;
use App\Workflow\EcouteurDeGardesTemporelles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Le gardien de la cohérence entre `config/packages/workflow.yaml` et le code.
 *
 * POURQUOI CE TEST EXISTE. Le graphe est décrit deux fois : une fois en YAML,
 * que le composant Workflow lit, et une fois en énumérations PHP, dont le
 * domaine se sert. Deux descriptions de la même chose divergent toujours, et
 * ici la divergence serait silencieuse jusqu'au moment où l'on filme : un
 * `apply()` sur une transition dont le nom a changé lève une exception, et un
 * état en base qu'aucun cas d'énumération ne reconnaît casse le dossier.
 *
 * Ce test rend donc la divergence impossible. Il lit le YAML comme un fichier
 * de données et compare, sans démarrer le noyau Symfony.
 */
final class GrapheDuDossierTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $configuration = null;

    #[Test]
    public function lesPlacesDuYamlEtLEnumerationDesEtatsCoincident(): void
    {
        $placesDuYaml = self::machine('dossier_penal')['places'];
        $casDeLEnumeration = array_map(
            static fn (EtatDossier $etat): string => $etat->value,
            EtatDossier::cases()
        );

        sort($placesDuYaml);
        sort($casDeLEnumeration);

        self::assertSame(
            $casDeLEnumeration,
            $placesDuYaml,
            'Une place du YAML sans cas dans EtatDossier produirait en base un état '
            .'que le domaine ne saurait pas relire.'
        );
    }

    #[Test]
    public function lesTransitionsDuYamlEtLEnumerationCoincident(): void
    {
        $transitionsDuYaml = array_keys(self::machine('dossier_penal')['transitions']);
        $casDeLEnumeration = array_map(
            static fn (TransitionDossier $t): string => $t->value,
            TransitionDossier::cases()
        );

        sort($transitionsDuYaml);
        sort($casDeLEnumeration);

        self::assertSame($casDeLEnumeration, $transitionsDuYaml);
    }

    #[Test]
    public function laSecondeMachineEstAussiSynchronisee(): void
    {
        $machine = self::machine('interdiction_bancaire');

        $places = $machine['places'];
        $transitions = array_keys($machine['transitions']);
        $placesAttendues = array_map(
            static fn (EtatInterdictionBancaire $e): string => $e->value,
            EtatInterdictionBancaire::cases()
        );
        $transitionsAttendues = array_map(
            static fn (TransitionInterdictionBancaire $t): string => $t->value,
            TransitionInterdictionBancaire::cases()
        );

        // Les deux côtés sont triés : ce test compare des ENSEMBLES, pas des
        // ordres. L'ordre des places dans le YAML est fait pour se lire, celui
        // de l'énumération pour se raisonner, et il n'y a aucune raison de les
        // contraindre à coïncider.
        sort($places);
        sort($transitions);
        sort($placesAttendues);
        sort($transitionsAttendues);

        self::assertSame($placesAttendues, $places);
        self::assertSame($transitionsAttendues, $transitions);
    }

    #[Test]
    public function lesDeuxMachinesSontDesStateMachine(): void
    {
        // Le choix se défend en entretien, mais il doit aussi tenir dans le
        // fichier : un `type: workflow` autoriserait plusieurs jetons, donc un
        // dossier à la fois en « délai en cours » et en « poursuite engagée »,
        // c'est-à-dire un état que l'article 325 al. 6 exclut.
        self::assertSame('state_machine', self::machine('dossier_penal')['type']);
        self::assertSame('state_machine', self::machine('interdiction_bancaire')['type']);
    }

    #[Test]
    public function lesTransitionsTemporellesNOntAucuneGardeIsGranted(): void
    {
        // ★ LE PIÈGE QUE CE TEST VERROUILLE.
        //
        // Une garde qui appelle `is_granted()` exige un jeton de sécurité :
        // elle lève une exception hors requête, donc en ligne de commande et
        // dans le Scheduler. Les transitions que le Scheduler pousse ne
        // doivent donc JAMAIS en porter.
        //
        // Sans ce test, la régression serait invisible en développement — où
        // tout passe par une requête — et n'apparaîtrait qu'en production, à
        // la première passe planifiée.
        foreach (self::machinesAVerifier() as $nomDeMachine => $machine) {
            foreach ($machine['transitions'] as $nomDeTransition => $transition) {
                if (!$this->estDeclencheeParLeTemps($nomDeMachine, $nomDeTransition)) {
                    continue;
                }

                self::assertStringNotContainsString(
                    'is_granted',
                    (string) ($transition['guard'] ?? ''),
                    \sprintf(
                        'La transition « %s » de la machine « %s » est poussée par le Scheduler : '
                        .'une garde is_granted() y lèverait une exception faute de jeton.',
                        $nomDeTransition,
                        $nomDeMachine,
                    )
                );
            }
        }
    }

    #[Test]
    public function chaqueTransitionTemporellementGardeeExisteDansLeYaml(): void
    {
        // L'écouteur s'abonne à des événements nommés d'après les transitions.
        // Un nom qui ne correspond à rien ne provoque aucune erreur : la garde
        // ne s'exécute simplement jamais, et la transition devient libre. Ce
        // test rend cette défaillance silencieuse impossible.
        $transitionsExistantes = array_merge(
            array_keys(self::machine('dossier_penal')['transitions']),
            array_keys(self::machine('interdiction_bancaire')['transitions']),
        );

        foreach (EcouteurDeGardesTemporelles::transitionsGardeesTemporellement() as $transition) {
            self::assertContains(
                $transition,
                $transitionsExistantes,
                'Une garde temporelle visant une transition inexistante ne s\'exécuterait jamais, '
                .'et la transition serait franchissable sans condition.'
            );
        }
    }

    #[Test]
    public function chaqueCodeDeBlocageAUneRegleSourcee(): void
    {
        // Le degré de certitude doit voyager avec la règle jusqu'à l'écran.
        // Un code de blocage sans règle au catalogue afficherait un refus sans
        // source, ce que la contrainte n° 1 du projet interdit.
        $catalogue = CatalogueDesBlocages::catalogue();
        self::assertNotEmpty($catalogue);

        foreach ($catalogue as $code => $regle) {
            self::assertNotSame('', $regle->enonce, "Le code « $code » a un énoncé vide.");
            self::assertNotSame('', $regle->article, "Le code « $code » n'a pas d'article.");
            self::assertNotSame(
                '',
                $regle->attribution(),
                "Le code « $code » n'a pas d'attribution affichable."
            );
        }
    }

    #[Test]
    public function aucuneRegleNonEtablieNEstAttribueeALaLoi(): void
    {
        // ★ L'ASSERTION QUI PROTÈGE LE PROJET DE SA PIRE FAUTE.
        //
        // Il n'existe aucune version française officielle de la loi 71.24.
        // Écrire « loi n° 71.24, BO n° 7478 » sous une règle qui vient de la
        // circulaire du parquet, de la doctrine ou d'un choix de ce logiciel
        // serait une fausse citation — et c'est exactement ce que le brief du
        // projet faisait avec « déjà prolongé une fois, loi 71-24 ».
        foreach (CatalogueDesBlocages::catalogue() as $code => $regle) {
            if ('etabli' === $regle->degre->value) {
                continue;
            }

            self::assertStringNotContainsString(
                'loi n° 71.24, BO',
                $regle->attribution(),
                "La règle « $code » n'est pas établie : son attribution ne doit pas être la loi."
            );
        }
    }

    #[Test]
    public function laGardeDuQuotaEstUnParametreProduitEtNonUneRegleDeDroit(): void
    {
        // La règle « prolongeable une seule fois » n'existe pas dans le texte.
        // Cette garde reste — c'est le moment filmé — mais son degré doit
        // rester CHOIX_PRODUIT, pour toujours. Voir LOI.md § 4.1.
        $regle = CatalogueDesBlocages::regle(CatalogueDesBlocages::QUOTA_PROLONGATIONS_EPUISE);

        self::assertSame('choix_produit', $regle->degre->value);
        self::assertStringContainsString('LOI.md § 4.1', (string) $regle->renvoiLoiMd);
    }

    // ═══════════════════ Lecture du YAML ═══════════════════

    private function estDeclencheeParLeTemps(string $nomDeMachine, string $nomDeTransition): bool
    {
        return match ($nomDeMachine) {
            'dossier_penal' => TransitionDossier::from($nomDeTransition)->estDeclencheeParLeTemps(),
            'interdiction_bancaire' => TransitionInterdictionBancaire::from($nomDeTransition)->estDeclencheeParLeTemps(),
            default => false,
        };
    }

    /** @return array<string, array<string, mixed>> */
    private static function machinesAVerifier(): array
    {
        return [
            'dossier_penal' => self::machine('dossier_penal'),
            'interdiction_bancaire' => self::machine('interdiction_bancaire'),
        ];
    }

    /** @return array<string, mixed> */
    private static function machine(string $nom): array
    {
        self::$configuration ??= Yaml::parseFile(
            \dirname(__DIR__, 2).'/config/packages/workflow.yaml'
        );

        return self::$configuration['framework']['workflows'][$nom]
            ?? throw new \RuntimeException("La machine « $nom » est absente de workflow.yaml.");
    }
}
