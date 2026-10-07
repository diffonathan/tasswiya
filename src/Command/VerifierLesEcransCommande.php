<?php

declare(strict_types=1);

namespace App\Command;

use App\Domaine\Certitude\ReglesDAffichage;
use App\Domaine\Graphe\ExplicateurDuGraphe;
use App\Domaine\Graphe\TransitionExpliquee;
use App\Domaine\Horloge\CalculateurDHorloges;
use App\Domaine\Montant\EstimateurDeMontants;
use App\Domaine\Urgence\ClassementParUrgence;
use App\Entity\Dossier;
use App\Enum\DegreCertitude;
use App\Repository\DossierRepository;
use App\Workflow\CatalogueDesBlocages;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vérifie ce que les écrans affichent, et IMPRIME les nombres.
 *
 * ═══ LA RÈGLE DE LA COMMANDE ══════════════════════════════════════════════
 *
 * Aucun nombre de ce projet n'est publié sans la commande qui l'imprime ou le
 * test qui l'épingle. Cette commande est cette commande, pour les écrans :
 * elle compte les horloges, les transitions barrées, les degrés de certitude
 * et les montants que ce logiciel refuse de chiffrer, et elle rend un verdict
 * avec un code de sortie.
 *
 * ═══ LES QUATRE INVARIANTS QU'ELLE CONTRÔLE ═══════════════════════════════
 *
 * 1. **LA COHÉRENCE DU DIAGNOSTIC.** Une transition impossible a au moins un
 *    motif, une transition possible n'en a aucun. C'est le garde-fou contre la
 *    dérive de deux endroits qui savent la même chose :
 *    `config/packages/workflow.yaml` et
 *    {@see ExplicateurDuGraphe::conditionsDe()}. Une condition ajoutée au YAML
 *    et oubliée dans l'explicateur casse cet invariant au lieu de griser un
 *    bouton en silence.
 *
 * 2. **AUCUNE RÈGLE NON ÉTABLIE SANS SA SOURCE RÉELLE.** `RegleAppliquee`
 *    l'interdit déjà par exception à la construction ; la commande le vérifie
 *    sur l'ensemble des deux catalogues, pour que la vérification ne dépende
 *    pas du chemin d'exécution qui, ce jour-là, construit telle règle.
 *
 * 3. **LE REFUS DE CHIFFRER EST EXPLIQUÉ.** Tout montant non chiffré porte
 *    soit la raison de son indétermination, soit la fourchette que le texte
 *    donne à sa place. Un montant vide et muet est pire qu'un montant faux :
 *    le lecteur le prend pour une panne et va chercher le chiffre ailleurs.
 *
 * 4. **CHAQUE HORLOGE A UN POINT DE DÉPART NOMMÉ.** Un délai affiché sans son
 *    événement de départ est l'erreur que ce projet existe pour ne pas
 *    commettre.
 */
#[AsCommand(
    name: 'tasswiya:verifier-les-ecrans',
    description: 'Compte ce que les écrans affichent et vérifie que le diagnostic du graphe reste cohérent avec la machine à états.',
)]
final class VerifierLesEcransCommande extends Command
{
    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly CalculateurDHorloges $horloges,
        private readonly ExplicateurDuGraphe $explicateur,
        private readonly EstimateurDeMontants $estimateur,
        private readonly ClassementParUrgence $classement,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $style = new SymfonyStyle($entree, $sortie);
        $dossiers = $this->dossiers->findAll();

        if ([] === $dossiers) {
            $style->warning(
                'Aucun dossier en base : rien à vérifier. Chargez le jeu fictif avec '
                .'tasswiya:charger-les-donnees-de-demonstration.'
            );

            return Command::FAILURE;
        }

        $style->title('Ce que les écrans affichent');
        $style->text(\sprintf('Présent de référence : %s.', $this->horloges->aujourdHui()->format('d/m/Y')));

        $defauts = [];

        $comptes = $this->compterParDossier($dossiers, $defauts, $style);
        $this->verifierLesCatalogues($defauts);
        $this->verifierLesPointsDeDepart($dossiers, $defauts);

        $style->section('Totaux');
        $style->definitionList(
            ['Dossiers' => (string) \count($dossiers)],
            ['Horloges qui courent, toutes horloges confondues' => (string) $comptes['horloges']],
            ['Dossiers où le délai pénal (H3) ne court pas' => (string) $comptes['sansDelaiPenal']],
            ['Transitions sortantes examinées' => (string) $comptes['transitions']],
            ['Transitions barrées' => (string) $comptes['barrees']],
            ['Barrées par une RÈGLE' => (string) $comptes['barreesParUneRegle']],
            ['Barrées par une HABILITATION seule' => (string) $comptes['barreesParHabilitationSeule']],
            ['Règles affichées (occurrences)' => (string) array_sum($comptes['degres'])],
            ['… de degré établi' => (string) $comptes['degres'][DegreCertitude::ETABLI->value]],
            ['… de degré probable' => (string) $comptes['degres'][DegreCertitude::PROBABLE->value]],
            ['… de degré incertain' => (string) $comptes['degres'][DegreCertitude::INCERTAIN->value]],
            ['… de degré choix produit' => (string) $comptes['degres'][DegreCertitude::CHOIX_PRODUIT->value]],
            ['Montants proposés' => (string) $comptes['montants']],
            ['… que ce logiciel refuse de chiffrer' => (string) $comptes['montantsIndetermines']],
            ['Règles au catalogue des blocages' => (string) \count(CatalogueDesBlocages::catalogue())],
            ['Règles d\'affichage hors blocage' => (string) \count(ReglesDAffichage::toutes())],
        );

        if ([] !== $defauts) {
            $style->error(\sprintf('%d défaut(s). Les écrans ne doivent pas être publiés en cet état.', \count($defauts)));
            $style->listing($defauts);

            return Command::FAILURE;
        }

        $style->success(
            'Diagnostic cohérent avec la machine à états sur toutes les transitions examinées ; '
            .'toute règle non établie porte sa source réelle ; tout montant non chiffré porte sa '
            .'raison ou sa fourchette ; toute horloge porte son point de départ nommé.'
        );

        return Command::SUCCESS;
    }

    /**
     * @param list<Dossier> $dossiers
     * @param list<string>  $defauts
     *
     * @return array{
     *     horloges: int, sansDelaiPenal: int, transitions: int, barrees: int,
     *     barreesParUneRegle: int, barreesParHabilitationSeule: int,
     *     degres: array<string, int>, montants: int, montantsIndetermines: int,
     * }
     */
    private function compterParDossier(array $dossiers, array &$defauts, SymfonyStyle $style): array
    {
        $comptes = [
            'horloges' => 0,
            'sansDelaiPenal' => 0,
            'transitions' => 0,
            'barrees' => 0,
            'barreesParUneRegle' => 0,
            'barreesParHabilitationSeule' => 0,
            'degres' => [
                DegreCertitude::ETABLI->value => 0,
                DegreCertitude::PROBABLE->value => 0,
                DegreCertitude::INCERTAIN->value => 0,
                DegreCertitude::CHOIX_PRODUIT->value => 0,
            ],
            'montants' => 0,
            'montantsIndetermines' => 0,
        ];

        $lignes = [];

        foreach ($dossiers as $dossier) {
            \assert($dossier instanceof Dossier);

            $horlogesDuDossier = $this->horloges->toutesLesHorloges($dossier);
            $comptes['horloges'] += \count($horlogesDuDossier);
            if (!isset($horlogesDuDossier['H3'])) {
                ++$comptes['sansDelaiPenal'];
            }

            $barreesIci = 0;
            foreach ($this->explicateur->transitionsDepuisLaPlaceCourante($dossier) as $transition) {
                \assert($transition instanceof TransitionExpliquee);
                ++$comptes['transitions'];

                if (!$transition->coherente()) {
                    // LE DÉFAUT QUI COMPTE. Il signifie qu'une condition de
                    // `workflow.yaml` n'est rattachée à aucune règle du
                    // catalogue, et donc qu'un écran grise un bouton sans
                    // pouvoir dire pourquoi.
                    $defauts[] = \sprintf(
                        'Diagnostic incohérent sur %s, transition « %s » : la machine dit %s et '
                        .'l\'explicateur trouve %s motif(s).',
                        $dossier->reference(),
                        $transition->transition->value,
                        $transition->franchissable ? 'FRANCHISSABLE' : 'IMPOSSIBLE',
                        \count($transition->reglesOpposees) + (null === $transition->habilitationManquante ? 0 : 1),
                    );
                }

                if ($transition->barree()) {
                    ++$comptes['barrees'];
                    ++$barreesIci;

                    if ([] !== $transition->reglesOpposees) {
                        ++$comptes['barreesParUneRegle'];
                    } else {
                        ++$comptes['barreesParHabilitationSeule'];
                    }
                }

                foreach ($transition->reglesOpposees as $regle) {
                    ++$comptes['degres'][$regle->degre->value];
                }
            }

            $montantsIndeterminesIci = 0;
            foreach ($this->estimateur->montantsDu($dossier) as $montant) {
                ++$comptes['montants'];
                ++$comptes['degres'][$montant->regle->degre->value];

                if ($montant->chiffre()) {
                    continue;
                }

                ++$comptes['montantsIndetermines'];
                ++$montantsIndeterminesIci;

                if (null === $montant->raisonDeLIndetermination && null === $montant->fourchette) {
                    $defauts[] = \sprintf(
                        'Montant non chiffré et muet sur %s : « %s ».',
                        $dossier->reference(),
                        $montant->libelle,
                    );
                }
            }

            $urgence = $this->classement->urgenceDe($dossier);

            $lignes[] = [
                $dossier->reference(),
                $dossier->etat()->value,
                (string) \count($horlogesDuDossier),
                null === $urgence->horlogePressante
                    ? '—'
                    : \sprintf('%s %+d j', $urgence->horlogePressante->code, $urgence->joursRestants),
                $urgence->bande,
                (string) $barreesIci,
                (string) $montantsIndeterminesIci,
            ];
        }

        $style->section('Par dossier');
        $style->table(
            ['Référence', 'État', 'Horloges', 'Plus pressante', 'Bande', 'Transitions barrées', 'Montants non chiffrés'],
            $lignes,
        );

        return $comptes;
    }

    /**
     * Toute règle non établie doit porter sa source réelle.
     *
     * Le constructeur de `RegleAppliquee` lève déjà sur ce point. La
     * vérification est refaite ici sur l'ensemble des deux catalogues, pour
     * une raison de couverture : une règle qu'aucun écran n'affiche
     * aujourd'hui ne serait jamais construite, et son défaut dormirait
     * jusqu'au jour où un écran la demande.
     *
     * @param list<string> $defauts
     */
    private function verifierLesCatalogues(array &$defauts): void
    {
        foreach ([...array_values(CatalogueDesBlocages::catalogue()), ...array_values(ReglesDAffichage::toutes())] as $regle) {
            if (DegreCertitude::ETABLI === $regle->degre) {
                continue;
            }

            if (null === $regle->sourceReelle) {
                $defauts[] = \sprintf(
                    'Règle de degré %s sans source réelle : « %s ». Elle s\'afficherait sous '
                    .'l\'autorité de la loi n° 71.24.',
                    $regle->degre->value,
                    $regle->article,
                );
            }

            /*
             * Une règle non établie ne doit pas s'attribuer à la loi 71.24 :
             * il n'existe aucune version française officielle de ce texte, et
             * ce serait une fausse citation.
             *
             * Le motif cherché est la FORMULE DE CITATION que
             * `RegleAppliquee::attribution()` réserve au degré établi, et non
             * les mots « loi n° 71.24 » : plusieurs sources réelles les
             * contiennent légitimement, pour dire précisément qu'il ne faut
             * PAS citer la loi sous elles. Chercher les mots aurait signalé
             * en défaut la phrase qui énonce la règle.
             */
            if (str_contains($regle->attribution(), '— loi n° 71.24, BO n° 7478')) {
                $defauts[] = \sprintf(
                    'Règle de degré %s attribuée à la loi n° 71.24 : « %s ».',
                    $regle->degre->value,
                    $regle->article,
                );
            }
        }
    }

    /**
     * Chaque horloge affichée doit porter un point de départ NOMMÉ.
     *
     * Une date de départ sans son événement est l'erreur que ce projet existe
     * pour ne pas commettre : « 15/04/2026 » ne dit rien, « écédar du
     * 15/04/2026 » dit tout.
     *
     * @param list<Dossier> $dossiers
     * @param list<string>  $defauts
     */
    private function verifierLesPointsDeDepart(array $dossiers, array &$defauts): void
    {
        $vues = [];
        foreach ($dossiers as $dossier) {
            foreach (array_keys($this->horloges->toutesLesHorloges($dossier)) as $code) {
                $vues[$code] = true;
            }
        }

        foreach (array_keys($vues) as $code) {
            $nomme = \App\Domaine\Horloge\PresentationDesHorloges::pointDeDepart((string) $code);
            if (str_contains($nomme, 'non documenté')) {
                $defauts[] = \sprintf('Horloge %s affichée sans point de départ nommé.', $code);
            }
        }
    }
}
