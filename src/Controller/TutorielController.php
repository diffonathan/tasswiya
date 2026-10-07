<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domaine\Certitude\RegleAppliquee;
use App\Domaine\Horloge\CalculateurDHorloges;
use App\Domaine\Horloge\DelaisCalendairesSansReport;
use App\Domaine\Horloge\DelaisFrancs;
use App\Domaine\Horloge\Horloge;
use App\Entity\Cheque;
use App\Entity\Dossier;
use App\Entity\Partie;
use App\Entity\Prolongation;
use App\Enum\CasArticle316;
use App\Enum\DegreCertitude;
use App\Enum\DisponibiliteBeneficiaire;
use App\Enum\LieuEmission;
use App\Enum\TransitionDossier;
use App\Security\ResolveurDeRoleParCourriel;
use App\Workflow\CatalogueDesBlocages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\TransitionBlocker;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Le tutoriel interactif : ce que ce projet a résolu, et ce qu'il n'a pas
 * résolu.
 *
 * ─── LA RÈGLE D'OR, REPRISE DU TUTORIEL DE MIZAN ───────────────────────────
 *
 * AUCUNE SORTIE N'EST MAQUETTÉE. Pas une valeur de cet écran n'est écrite à
 * la main dans le gabarit : les échéances sortent de
 * {@see CalculateurDHorloges}, les refus de
 * `WorkflowInterface::buildTransitionBlockerList()`, les articles et les
 * degrés de {@see CatalogueDesBlocages}, le graphe de
 * `WorkflowInterface::getDefinition()`, et l'extrait de configuration est LU
 * SUR LE DISQUE dans `config/packages/workflow.yaml`. Si une règle change, le
 * tutoriel change ; s'il se trompe, c'est que le moteur se trompe.
 *
 * C'est aussi la discipline des chiffres du projet : aucun nombre publié sans
 * la commande qui l'imprime. Ici, la commande est la page.
 *
 * ─── CE QUE CE CONTRÔLEUR NE FAIT PAS ──────────────────────────────────────
 *
 *  1. IL N'ÉCRIT RIEN. Aucun `persist`, aucun `flush`, aucune requête : les
 *     dossiers de démonstration sont construits en mémoire à chaque requête
 *     et jetés à la fin. On ne voudrait pas qu'un visiteur du tutoriel laisse
 *     des dossiers derrière lui, et une démonstration qui dépend de l'état de
 *     la base ne se rejoue pas deux fois à l'identique.
 *
 *  2. IL NE CALCULE AUCUNE RÈGLE LUI-MÊME. Il ne demande jamais « le délai
 *     est-il expiré ? » en comparant deux dates : il demande à la machine à
 *     états si la transition est franchissable, et il affiche la réponse.
 *     Deux endroits qui savent quand un délai expire finiraient par
 *     diverger.
 *
 *  3. IL N'INVENTE AUCUN CHIFFRE DE DROIT. Les durées viennent du dossier
 *     (`dureeDelaiInitialEnJours`), les pourcentages ne sont pas affichés du
 *     tout, et chaque renvoi pointe une section de `LOI.md` plutôt que de
 *     recopier une valeur qui dériverait.
 *
 * ─── POURQUOI CET ÉCRAN NE POUSSE PAS L'HORLOGE DU PRODUIT ────────────────
 *
 * Le projet a une horloge de démonstration — `App\Domaine\Horloge\HorlogeDeDemonstration`,
 * aliasée sur `Psr\Clock\ClockInterface` dans tout le conteneur — dont le
 * décalage est rangé dans un fichier de `var/`, donc GLOBAL à l'installation
 * et partagé avec la console. C'est le bon outil pour filmer une règle qui
 * s'applique à un dossier persisté, et c'est l'écran de démonstration qui s'en
 * sert.
 *
 * Ce tutoriel n'y touche pas. Une page de documentation qui, en étant
 * simplement ouverte, déplacerait le temps de toute l'installation serait un
 * piège : la liste des dossiers d'à côté se mettrait à mentir, et la commande
 * console avec elle. Un GET ne modifie pas un état partagé.
 *
 * Le curseur de cet écran déplace donc l'AUTRE BORNE du même intervalle. Il ne
 * change pas quand on est, il change QUAND L'ÉCÉDAR A ÉTÉ NOTIFIÉ : les
 * dossiers de démonstration sont construits à chaque requête, et la date de
 * leur écédar est posée à N jours derrière l'instant que
 * `Psr\Clock\ClockInterface` rapporte au moteur — quel que soit cet instant, y
 * compris si l'écran de démonstration a poussé le décalage global, auquel cas
 * les deux écrans restent cohérents sans se parler.
 *
 * Ce que la démonstration prouve est intact, parce que c'est la MÊME garde qui
 * répond : `App\Workflow\EcouteurDeGardesTemporelles` compare l'échéance à
 * l'instant qu'elle lit sur l'horloge injectée, et à vingt-neuf jours elle
 * refuse quand à trente et un elle laisse passer. Personne ne clique sur
 * « expirer », et aucune date n'est forcée dans un dossier existant : la seule
 * chose que le visiteur choisit est l'âge de l'écédar, qui est le point de
 * départ que l'article 325 al. 6 désigne.
 *
 * ─── LA SEULE MANIPULATION D'ÉTAT, ASSUMÉE ET RENDUE ──────────────────────
 *
 * LE JETON DE SÉCURITÉ. Deux gardes de `workflow.yaml` portent un
 * `is_granted()`, parce que l'article 325 al. 8 fait de l'accord du
 * bénéficiaire une CONDITION DE DROIT et non une case à cocher : le droit est
 * donc porté par `App\Security\Voter\DossierVoter`. Un visiteur anonyme ne
 * peut ni notifier un écédar (c'est le greffe) ni accorder une prorogation
 * (c'est le bénéficiaire). Pour amener le dossier de démonstration jusqu'à
 * l'écédar en passant par de VRAIES transitions, le tutoriel prend donc
 * l'identité d'un utilisateur fictif — adresse en `.invalid`, domaine réservé
 * qui ne peut désigner personne — le temps de l'appel, et la rend dans un
 * `finally`. Il n'existe aucun utilisateur réel dans ce projet
 * (`security.yaml`, `memory: users: []`), et rien n'est lu ni écrit en base.
 *
 * Et cette contrainte devient une leçon : le § 2 du tutoriel montre la MÊME
 * transition jugée sous trois identités, ce qui rend visible que
 * l'autorisation porte ici une règle de droit.
 */
final class TutorielController extends AbstractController
{
    /**
     * Les crans proposés au visiteur, en jours écoulés depuis l'écédar.
     *
     * 29, 30 et 31 ne sont pas décoratifs : ils encadrent le seul fait qui
     * ouvre la transition filmée. Et 30 est le piège — l'échéance tombe ce
     * jour-là, et `Horloge::estDepassee()` exige un dépassement STRICT : le
     * délai court donc encore le trentième jour.
     */
    private const array CRANS = [0, 29, 30, 31, 45];

    public function __construct(
        /*
         * `dossierPenalStateMachine` : l'alias d'autowiring que le bundle
         * enregistre pour une machine nommée `dossier_penal` de
         * `type: state_machine`. Le nom brut du YAML ne correspond à rien
         * dans le conteneur.
         */
        #[Target('dossierPenalStateMachine')]
        private readonly WorkflowInterface $machineDuDossier,
        private readonly CalculateurDHorloges $horloges,
        private readonly TokenStorageInterface $stockageDeJeton,
    ) {
    }

    #[Route('/tutoriel', name: 'tutoriel', methods: ['GET'])]
    public function __invoke(Request $requete): Response
    {
        /*
         * Lu en chaîne puis filtré, et non par `getInt()` : depuis Symfony 7,
         * `getInt()` lève une `BadRequestException` sur une valeur non
         * numérique, et un `?jours=abc` rendrait 400. Une page de
         * documentation ne doit pas répondre par une erreur à un lien
         * recopié de travers ; elle repart du premier cran et se montre.
         *
         * Le plafond n'est pas de la seule prudence d'entrée : au-delà de dix
         * ans, les horloges H4 et H5 sont toutes les deux échues et la
         * démonstration ne montre plus rien.
         */
        $saisie = filter_var($requete->query->get('jours', '0'), \FILTER_VALIDATE_INT);
        $jours = max(0, min(3650, false === $saisie ? 0 : $saisie));

        /*
         * AUCUNE MANIPULATION DE L'HORLOGE ICI. Le décalage de démonstration
         * du produit est global à l'installation et partagé avec la console :
         * l'écrire depuis une page de documentation ferait mentir les écrans
         * de consultation d'à côté. C'est l'âge de l'écédar que ce curseur
         * déplace, et rien d'autre.
         */
        return $this->render('tutoriel.html.twig', $this->construireLaVue($jours));
    }

    /**
     * @return array<string, mixed>
     */
    private function construireLaVue(int $jours): array
    {
        $calendrier = $this->calendrierDuScenario($jours);

        // ── Les cinq dossiers de démonstration, tous fictifs ───────────────
        $avecEcedar = $this->dossierAvecEcedar('TUT-A', $calendrier);
        $sansEcedar = $this->dossierSansEcedar('TUT-B', $calendrier);
        $quotaEpuise = $this->dossierAuQuotaEpuise('TUT-C', $calendrier);
        $beneficiaireDecede = $this->dossierDontLeBeneficiaireEstDecede('TUT-D', $calendrier);
        $pretPourEcedar = $this->dossierPretPourEcedar('TUT-E', $calendrier);

        return [
            'jours' => $jours,
            'crans' => self::CRANS,
            /*
             * L'instant que LE MOTEUR lit, et non `new DateTimeImmutable()` :
             * si l'écran de démonstration a poussé le décalage global, c'est
             * cette date-là qui compte, et le tutoriel doit afficher celle sur
             * laquelle les gardes se prononcent. Normalisée à minuit, parce
             * qu'une échéance légale est un jour et non un instant.
             */
            'aujourdHui' => $this->horloges->aujourdHui(),
            'calendrier' => $calendrier,

            'moment' => $this->leMomentQuiDoitSeVoir($avecEcedar),
            'horloges' => $this->pourquoiCinqHorloges($avecEcedar, $sansEcedar),
            'refus' => $this->ceQueLaMachineRefuse($quotaEpuise, $pretPourEcedar),
            'certitude' => $this->ceQuiVientDuTexteEtCeQuiVientDeNous(),
            'incertitudes' => $this->ceQueLeProjetNeSaitPasFaire($avecEcedar, $beneficiaireDecede),

            /*
             * Les deux comptes, et pas un seul : le composant éclate une
             * transition partant de PLUSIEURS places en un objet `Transition`
             * par place de départ. Afficher le seul total ferait chercher en
             * vain vingt-neuf noms dans un fichier qui en porte quatorze.
             */
            'graphe' => [
                'places' => \count($this->machineDuDossier->getDefinition()->getPlaces()),
                'transitions' => \count($this->machineDuDossier->getDefinition()->getTransitions()),
                'transitionsNommees' => \count(array_unique(array_map(
                    static fn (Transition $t): string => $t->getName(),
                    $this->machineDuDossier->getDefinition()->getTransitions(),
                ))),
            ],
            'etiquettesDeDegre' => $this->etiquettesDeDegre(),
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  LE MOMENT QUI DOIT SE VOIR
    // ═════════════════════════════════════════════════════════════════════

    /**
     * On pousse l'horloge, et le dossier bascule tout seul.
     *
     * Ce que cette méthode rend permet à l'écran de montrer l'enchaînement
     * complet : l'état avant, le verdict du moteur sur `expirer_delai`, la
     * règle du refus quand il refuse, l'état après, et le graphe des
     * transitions ouvertes depuis la place courante — celles qui se barrent
     * comprises.
     *
     * Personne ne clique. La transition est franchie par
     * `WorkflowInterface::apply()` appelé sans aucune condition propre au
     * tutoriel : si la garde temporelle la refuse, elle n'est pas franchie,
     * et c'est tout.
     *
     * @return array<string, mixed>
     */
    private function leMomentQuiDoitSeVoir(Dossier $dossier): array
    {
        $h3 = $this->horloges->h3($dossier);
        \assert($h3 instanceof Horloge);

        $etatAvant = $dossier->etat();
        $grapheAvant = $this->grapheDepuisLaPlaceCourante($dossier);

        $blocages = $this->blocages($dossier, TransitionDossier::EXPIRER_DELAI);
        $franchie = [] === $blocages;

        if ($franchie) {
            // Le même appel que celui de `ExpirateurDeDelais`, que le
            // Scheduler déclenche en production. La démonstration ne prend
            // aucun raccourci que la production n'aurait pas — elle ne
            // persiste simplement rien.
            $this->machineDuDossier->apply($dossier, TransitionDossier::EXPIRER_DELAI->value);
        }

        return [
            'etatAvant' => $etatAvant,
            'etatApres' => $dossier->etat(),
            'franchie' => $franchie,
            'blocages' => $blocages,
            'grapheAvant' => $grapheAvant,
            'grapheApres' => $this->grapheDepuisLaPlaceCourante($dossier),
            'h3' => $h3,
            'joursRestants' => $h3->joursRestants($this->horloges->aujourdHui()),
            'echeanceDepassee' => $h3->estDepassee($this->horloges->aujourdHui()),
            'dureeEffective' => $this->horloges->dureeEffectiveDuDelaiPenalEnJours($dossier),
            'controleJudiciaire' => $dossier->mesuresDeControleJudiciaire(),
            'etatImpliqueControle' => $etatAvant->impliqueUnControleJudiciaire(),
            'etatApresImpliqueControle' => $dossier->etat()->impliqueUnControleJudiciaire(),
            'commandeDeProduction' => 'bin/console tasswiya:faire-expirer-les-delais',
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  § 1 — POURQUOI CINQ HORLOGES ET PAS UNE
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Deux dossiers rigoureusement identiques, dont l'un a reçu son écédar.
     *
     * Le premier a trente jours qui courent. Le second n'a RIEN qui court —
     * pas une échéance lointaine, pas d'échéance du tout — parce que sans
     * écédar il n'y a pas de point de départ. C'est contre-intuitif, et c'est
     * exactement ce que la presse rate (`LOI.md` § 4.5).
     *
     * @return array<string, mixed>
     */
    private function pourquoiCinqHorloges(Dossier $avecEcedar, Dossier $sansEcedar): array
    {
        return [
            'avecEcedar' => $this->colonneDHorloges($avecEcedar),
            'sansEcedar' => $this->colonneDHorloges($sansEcedar),
            // Ce que le dossier a en commun : l'écédar est la SEULE
            // différence, et l'écran doit pouvoir le prouver plutôt que
            // l'affirmer.
            'communs' => [
                'dateEmission' => $avecEcedar->cheque()->dateEmissionPorteeSurLeCheque(),
                'memeDateEmission' => $avecEcedar->cheque()->dateEmissionPorteeSurLeCheque()
                    == $sansEcedar->cheque()->dateEmissionPorteeSurLeCheque(),
                'memeDateIncident' => $avecEcedar->dateIncidentDePaiement()
                    == $sansEcedar->dateIncidentDePaiement(),
                'memeDateInjonction' => $avecEcedar->dateInjonctionBancaire()
                    == $sansEcedar->dateInjonctionBancaire(),
            ],
            /*
             * L'écart entre le rejet du chèque et l'écédar, en jours, calculé
             * et non annoncé. C'est l'erreur de toute la presse : elle fait
             * courir les trente jours depuis le rejet. Le scénario l'exprime
             * par un écart de plusieurs semaines, et la page le mesure.
             */
            'ecartRejetEcedar' => (int) $avecEcedar->dateIncidentDePaiement()
                ?->diff($avecEcedar->dateEcedar())->format('%a'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function colonneDHorloges(Dossier $dossier): array
    {
        $aujourdHui = $this->horloges->aujourdHui();
        $lignes = [];

        foreach ($this->horloges->toutesLesHorloges($dossier) as $code => $horloge) {
            $lignes[$code] = [
                'horloge' => $horloge,
                'joursRestants' => $horloge->joursRestants($aujourdHui),
                'depassee' => $horloge->estDepassee($aujourdHui),
                'jourNonOuvre' => $horloge->tombeUnJourProbablementNonOuvre(),
            ];
        }

        return [
            'reference' => $dossier->reference(),
            'etat' => $dossier->etat(),
            'dateEcedar' => $dossier->dateEcedar(),
            'lignes' => $lignes,
            // Les deux prédicats de la démonstration, rendus tels quels :
            // `null` et non zéro quand aucun délai ne court, parce que zéro
            // voudrait dire « il expire aujourd'hui ».
            'joursRestantsSurLeDelaiPenal' => $this->horloges->joursRestantsSurLeDelaiPenal($dossier),
            'delaiPenalExpire' => $this->horloges->delaiPenalExpire($dossier),
            'etatFaitCourirLeDelai' => $dossier->etat()->faitCourirLeDelaiPenal(),
            // Et le verdict du moteur sur `expirer_delai` : sur le dossier
            // sans écédar, le blocage n'est même pas temporel, c'est le
            // MARQUAGE qui l'interdit. La transition ne part pas de sa place.
            'blocagesExpirer' => $this->blocages($dossier, TransitionDossier::EXPIRER_DELAI),
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  § 2 — CE QUE LA MACHINE À ÉTATS REFUSE
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Une transition interdite, le refus, son article et son degré.
     *
     * POURQUOI UNE DÉCOMPOSITION DE L'EXPRESSION. Le composant Workflow
     * évalue la garde du YAML comme UNE expression et rend donc UN blocage
     * pour toute la conjonction, dont le seul paramètre utile est
     * l'expression elle-même. Un écran qui s'arrêterait là dirait « refusé »
     * sans dire par quoi.
     *
     * Le tutoriel reprend donc l'expression TELLE QUE LE MOTEUR LA REND —
     * elle n'est pas recopiée du YAML, elle sort du `TransitionBlocker` — et
     * évalue chaque membre un par un, par les mêmes appels que le moteur :
     * la méthode du sujet pour un `subject.xxx()`, le vote de sécurité pour
     * un `is_granted()`. Ce qui s'affiche est donc la vraie valeur de chaque
     * condition, pas une reconstitution.
     *
     * @return array<string, mixed>
     */
    private function ceQueLaMachineRefuse(Dossier $quotaEpuise, Dossier $pretPourEcedar): array
    {
        $cheminDuYaml = 'config/packages/workflow.yaml';

        return [
            'prorogation' => $this->sousTroisIdentites($quotaEpuise, TransitionDossier::PROROGER_DELAI),
            /*
             * Et une seconde transition, sur un dossier CHOISI POUR QUE LA
             * SEULE CONDITION QUI DIFFÈRE SOIT L'AUTORISATION : le
             * procès-verbal valant écédar et les mesures de contrôle
             * judiciaire sont enregistrés, mais la transition n'a pas encore
             * été appliquée. Les deux membres « état du sujet » de la garde
             * sont donc vrais pour tout le monde, et seul le vote de sécurité
             * sépare le greffe des autres. Prendre le dossier déjà écédarisé
             * aurait donné un refus par le MARQUAGE, qui ne montre rien de
             * l'autorisation.
             */
            'ecedar' => $this->sousTroisIdentites($pretPourEcedar, TransitionDossier::NOTIFIER_ECEDAR),
            'yaml' => [
                'chemin' => $cheminDuYaml,
                'proroger' => $this->extraitDuYaml($cheminDuYaml, 'proroger_delai'),
                'expirer' => $this->extraitDuYaml($cheminDuYaml, 'expirer_delai'),
            ],
            'commandes' => [
                'bin/console workflow:dump dossier_penal',
                'bin/console debug:config framework workflows',
            ],
        ];
    }

    /**
     * La même transition, jugée sous trois identités.
     *
     * C'est la démonstration que le `is_granted()` du YAML porte une RÈGLE DE
     * DROIT et non une politique d'interface : l'accord du bénéficiaire est
     * une condition de l'article 325 al. 8, et c'est le bénéficiaire — pas le
     * greffe, pas le tireur — qui peut le donner.
     *
     * @return array<string, mixed>
     */
    private function sousTroisIdentites(Dossier $dossier, TransitionDossier $transition): array
    {
        $identites = [
            'anonyme' => null,
            'greffe' => [null, [ResolveurDeRoleParCourriel::ROLE_SYMFONY_GREFFE]],
            'beneficiaire' => [$dossier->beneficiaire()->courrielDeContact(), []],
        ];

        $verdicts = [];
        foreach ($identites as $nom => $identite) {
            $verdicts[$nom] = null === $identite
                ? $this->verdict($dossier, $transition)
                : $this->sousLIdentiteDe(
                    $identite[0] ?? 'inconnu@exemple.invalid',
                    $identite[1],
                    fn (): array => $this->verdict($dossier, $transition),
                );
        }

        return [
            'transition' => $transition->value,
            'etat' => $dossier->etat(),
            'reference' => $dossier->reference(),
            'verdicts' => $verdicts,
            'irreversible' => $transition->estIrreversible(),
            'declencheeParLeTemps' => $transition->estDeclencheeParLeTemps(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verdict(Dossier $dossier, TransitionDossier $transition): array
    {
        $blocages = $this->blocages($dossier, $transition);
        $conjonctions = [];

        foreach ($blocages as $blocage) {
            if (null !== $blocage['expression']) {
                $conjonctions = $this->decomposerLaConjonction($blocage['expression'], $dossier);
            }
        }

        /*
         * Les membres faux, extraits. Pourquoi : le gabarit a besoin de DIRE
         * laquelle des conditions bloque, et une phrase écrite à la main dans
         * le gabarit (« la seule condition fausse est le quota ») redeviendrait
         * fausse au premier changement de garde ou de cran d'horloge. La prose
         * de l'écran lit donc cette liste.
         */
        $faux = [];
        foreach ($conjonctions as $membre) {
            if (false === $membre['valeur']) {
                $faux[] = $membre['source'];
            }
        }

        return [
            'franchissable' => [] === $blocages,
            'blocages' => $blocages,
            'conjonctions' => $conjonctions,
            'membresFaux' => $faux,
        ];
    }

    /**
     * Évalue chaque membre d'une conjonction, par les mêmes appels que le
     * moteur.
     *
     * Le découpage sur « and » est sûr ici parce que les gardes de ce projet
     * sont des conjonctions plates, sans parenthèses ni « or » : il n'y a
     * donc pas d'ambiguïté d'associativité à trancher. Un membre que ce
     * petit évaluateur ne sait pas lire est rendu avec la valeur `null` et
     * affiché « non évalué » — mieux qu'un « faux » inventé.
     *
     * @return list<array{source: string, valeur: bool|null, nature: string, nie: bool}>
     */
    private function decomposerLaConjonction(string $expression, Dossier $dossier): array
    {
        $plat = trim((string) preg_replace('/\s+/', ' ', $expression));
        $membres = [];

        foreach (explode(' and ', $plat) as $membre) {
            $membre = trim($membre);
            $source = $membre;
            $nie = str_starts_with($membre, 'not ');
            if ($nie) {
                $membre = trim(substr($membre, 4));
            }

            $valeur = null;
            $nature = 'non évalué';

            if (1 === preg_match('/^subject\.([A-Za-z]+)\(\)$/', $membre, $trouve)) {
                $methode = $trouve[1];
                if (method_exists($dossier, $methode)) {
                    $valeur = (bool) $dossier->{$methode}();
                    $nature = 'état du sujet';
                }
            } elseif (1 === preg_match('/^is_granted\(\'([A-Z_]+)\',\s*subject\)$/', $membre, $trouve)) {
                $valeur = $this->isGranted($trouve[1], $dossier);
                $nature = 'vote de sécurité';
            }

            $membres[] = [
                'source' => $source,
                'valeur' => null === $valeur ? null : ($nie ? !$valeur : $valeur),
                'nature' => $nature,
                'nie' => $nie,
            ];
        }

        return $membres;
    }

    // ═════════════════════════════════════════════════════════════════════
    //  § 3 — CE QUI VIENT DU TEXTE ET CE QUI VIENT DE NOUS
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Le passage le plus important du tutoriel.
     *
     * Les deux règles sont tirées du catalogue réel, pas réécrites : l'une
     * est ÉTABLIE et vient du Bulletin officiel, l'autre est un CHOIX_PRODUIT
     * et vient de nous. Elles s'affichent avec leur traitement visuel — filet
     * plein contre filet pointillé, trame, retrait — et avec l'attribution
     * que chacune a le DROIT de porter.
     *
     * Le quota de prorogations est le cœur de l'affaire : la loi 71.24 ne
     * limite PAS le nombre de prorogations, et l'écran doit le dire LÀ OÙ IL
     * BLOQUE (`LOI.md` § 4.1).
     *
     * @return array<string, mixed>
     */
    private function ceQuiVientDuTexteEtCeQuiVientDeNous(): array
    {
        $catalogue = CatalogueDesBlocages::catalogue();

        // Le compte par degré, sur le catalogue réel. Reproductible :
        //   grep -c "DegreCertitude::ETABLI" src/Workflow/CatalogueDesBlocages.php
        $parDegre = [];
        foreach (DegreCertitude::cases() as $degre) {
            $parDegre[$degre->value] = 0;
        }
        foreach ($catalogue as $regle) {
            ++$parDegre[$regle->degre->value];
        }

        return [
            'etabli' => CatalogueDesBlocages::regle(CatalogueDesBlocages::DELAI_PENAL_NON_EXPIRE),
            'choixProduit' => CatalogueDesBlocages::regle(CatalogueDesBlocages::QUOTA_PROLONGATIONS_EPUISE),
            // La garde vraiment légale, à côté du paramètre produit : elle
            // mérite autant la caméra, et plus, puisqu'elle est dans le texte.
            'legaleVoisine' => CatalogueDesBlocages::regle(CatalogueDesBlocages::ACCORD_BENEFICIAIRE_NON_ENREGISTRE),
            'decisionParquet' => CatalogueDesBlocages::regle(CatalogueDesBlocages::DECISION_PARQUET_NON_ENREGISTREE),
            'parDegre' => $parDegre,
            'total' => \count($catalogue),
            'commande' => 'grep -c "DegreCertitude::ETABLI" src/Workflow/CatalogueDesBlocages.php',
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  § 4 — CE QUE LE PROJET NE SAIT PAS FAIRE
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Les points non tranchés, pris sur le catalogue et sur le domaine.
     *
     * Un tutoriel qui ne dirait que les forces se lirait comme une brochure.
     * Et ce n'est pas de la modestie de façade : `LOI.md` § 0 impose qu'une
     * règle INCERTAINE alerte au lieu de conclure, et c'est cette conduite
     * que la section montre en acte.
     *
     * @return array<string, mixed>
     */
    private function ceQueLeProjetNeSaitPasFaire(Dossier $avecEcedar, Dossier $beneficiaireDecede): array
    {
        $incertaines = [];
        foreach (CatalogueDesBlocages::catalogue() as $code => $regle) {
            if (DegreCertitude::INCERTAIN === $regle->degre) {
                $incertaines[$code] = $regle;
            }
        }

        $h3 = $this->horloges->h3($avecEcedar);
        \assert($h3 instanceof Horloge);

        // La nature des jours : la même échéance calculée par les DEUX
        // implémentations de `ReglesDeDelaiInterface`. L'écart est d'un jour,
        // et il n'est pas tranché par le texte. On ne l'annonce pas : on le
        // calcule devant le lecteur.
        $calendaire = new DelaisCalendairesSansReport();
        $franc = new DelaisFrancs();
        $depart = $h3->depart;
        $duree = $h3->joursOuMois;

        return [
            'regles' => $incertaines,
            'natureDesJours' => [
                'depart' => $depart,
                'duree' => $duree,
                'calendaire' => [
                    'echeance' => $calendaire->echeance($depart, $duree),
                    'libelle' => $calendaire->libelleAffiche(),
                    'degre' => $calendaire->degre(),
                    'parDefaut' => true,
                ],
                'franc' => [
                    'echeance' => $franc->echeance($depart, $duree),
                    'libelle' => $franc->libelleAffiche(),
                    'degre' => $franc->degre(),
                    'parDefaut' => false,
                ],
                'ecartEnJours' => (int) $calendaire->echeance($depart, $duree)
                    ->diff($franc->echeance($depart, $duree))->format('%a'),
            ],
            'jourNonOuvre' => [
                'concerne' => $h3->tombeUnJourProbablementNonOuvre(),
                'echeance' => $h3->echeance,
                // Aucun report : reporter serait inventer une règle que le
                // texte ne porte pas. L'écran signale, il ne décale pas.
                'hypothese' => $h3->hypotheseDeCalcul,
            ],
            'beneficiaireIndisponible' => [
                'reference' => $beneficiaireDecede->reference(),
                'disponibilite' => $beneficiaireDecede->disponibiliteBeneficiaire(),
                'explication' => $beneficiaireDecede->disponibiliteBeneficiaire()->explicationDuBlocage(),
                'verdict' => $this->sousLIdentiteDe(
                    (string) $beneficiaireDecede->beneficiaire()->courrielDeContact(),
                    [],
                    fn (): array => $this->verdict($beneficiaireDecede, TransitionDossier::PROROGER_DELAI),
                ),
                'regle' => CatalogueDesBlocages::regle(CatalogueDesBlocages::BENEFICIAIRE_INDISPONIBLE),
            ],
            // L'assiette d'amende : le certificat de refus ne chiffre presque
            // jamais le manquant, et le produit refuse de retomber sur le
            // montant du chèque — ce qui surestimerait l'amende.
            'assiette' => [
                'determinee' => $avecEcedar->cheque()->assietteEstDeterminee(),
                'centimes' => $avecEcedar->cheque()->assietteEnCentimes(
                    $avecEcedar->casArticle316()->assietteReductibleAuManquant()
                ),
                'manquant' => $avecEcedar->cheque()->manquantEnCentimes(),
                'montantDuCheque' => $avecEcedar->cheque()->montantEnCentimes(),
                'regle' => CatalogueDesBlocages::regle(CatalogueDesBlocages::CERTIFICAT_DE_REFUS_MANQUANT),
            ],
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  LES OUTILS
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Les blocages que le moteur oppose à une transition, aplatis pour
     * l'écran — avec la règle du catalogue quand le code en désigne une.
     *
     * Trois sortes de blocages cohabitent, et l'écran ne doit pas les
     * confondre :
     *  - un code du {@see CatalogueDesBlocages}, posé par
     *    `EcouteurDeGardesTemporelles` : il porte son article et son degré ;
     *  - `BLOCKED_BY_EXPRESSION_GUARD_LISTENER`, posé par le composant pour
     *    la garde du YAML : son seul paramètre utile est l'expression ;
     *  - `BLOCKED_BY_MARKING` : le sujet n'est pas dans la place de départ.
     *    Ce n'est pas une garde, c'est la FORME DU GRAPHE qui refuse.
     *
     * @return list<array<string, mixed>>
     */
    private function blocages(Dossier $dossier, TransitionDossier $transition): array
    {
        $aplatis = [];

        foreach ($this->machineDuDossier->buildTransitionBlockerList($dossier, $transition->value) as $blocage) {
            $parametres = $blocage->getParameters();

            $aplatis[] = [
                'code' => $blocage->getCode(),
                'message' => $blocage->getMessage(),
                'expression' => \is_string($parametres['expression'] ?? null) ? $parametres['expression'] : null,
                'parLeMarquage' => TransitionBlocker::BLOCKED_BY_MARKING === $blocage->getCode(),
                'parLExpression' => TransitionBlocker::BLOCKED_BY_EXPRESSION_GUARD_LISTENER === $blocage->getCode(),
                'regle' => $this->regleDuCatalogueSiElleExiste($blocage->getCode()),
            ];
        }

        return $aplatis;
    }

    /**
     * La règle du catalogue, ou `null` pour les codes internes au composant.
     *
     * Le catalogue lève une exception sur un code inconnu, et c'est voulu :
     * un blocage sans règle afficherait un refus sans source. Les trois codes
     * du composant ne sont pas des codes métier, ils n'ont donc rien au
     * catalogue, et c'est ici qu'on fait la différence plutôt que d'affaiblir
     * la garde du catalogue.
     */
    private function regleDuCatalogueSiElleExiste(string $code): ?RegleAppliquee
    {
        return \array_key_exists($code, CatalogueDesBlocages::catalogue())
            ? CatalogueDesBlocages::regle($code)
            : null;
    }

    /**
     * Toutes les transitions qui partent de la place courante, avec leur
     * verdict : c'est le « graphe affiché à côté », où la transition se barre.
     *
     * Les transitions sont lues sur la DÉFINITION de la machine et non
     * listées à la main : si `workflow.yaml` en gagne une, elle apparaît ici
     * sans qu'on touche au tutoriel.
     *
     * @return list<array<string, mixed>>
     */
    private function grapheDepuisLaPlaceCourante(Dossier $dossier): array
    {
        $place = $dossier->etat()->value;
        $lignes = [];

        foreach ($this->machineDuDossier->getDefinition()->getTransitions() as $transition) {
            \assert($transition instanceof Transition);
            if (!\in_array($place, $transition->getFroms(), true)) {
                continue;
            }

            $nom = TransitionDossier::from($transition->getName());
            $blocages = $this->blocages($dossier, $nom);

            $lignes[] = [
                'nom' => $transition->getName(),
                'vers' => $transition->getTos(),
                'ouverte' => [] === $blocages,
                'blocages' => $blocages,
                'declencheeParLeTemps' => $nom->estDeclencheeParLeTemps(),
                'irreversible' => $nom->estIrreversible(),
            ];
        }

        return $lignes;
    }

    /**
     * Exécute un appel sous une identité fictive, et rend le jeton précédent.
     *
     * Le `finally` n'est pas une précaution de style : le serveur de
     * développement sert la requête suivante dans le même processus, et un
     * jeton laissé en place ferait voir à l'écran suivant un utilisateur qui
     * n'existe pas.
     *
     * @param list<string> $roles
     */
    private function sousLIdentiteDe(string $courriel, array $roles, callable $appel): mixed
    {
        $precedent = $this->stockageDeJeton->getToken();

        try {
            $this->stockageDeJeton->setToken(new PreAuthenticatedToken(
                new InMemoryUser($courriel, null, $roles),
                'principal',
                $roles,
            ));

            return $appel();
        } finally {
            $this->stockageDeJeton->setToken($precedent);
        }
    }

    /**
     * L'extrait de `workflow.yaml` qui définit une transition, LU SUR LE
     * DISQUE.
     *
     * Lu et non recopié : un extrait recopié dans un gabarit se désynchronise
     * de la configuration qu'il prétend montrer, et c'est précisément ce que
     * le lecteur vient vérifier.
     *
     * POURQUOI LES COMMENTAIRES SONT RETIRÉS, MAIS LEURS NUMÉROS RENDUS. Ce
     * fichier porte plusieurs dizaines de lignes de commentaire autour de
     * chaque transition — c'est elles qui portent l'arbitrage, ses sources et
     * les commandes qui le vérifient, et c'est la pièce qu'un recruteur
     * Symfony lira en premier. Les reproduire ici noierait la définition qu'on
     * veut montrer ; les effacer en silence laisserait croire qu'elles
     * n'existent pas. L'écran rend donc la définition nue, et DIT à quelles
     * lignes du fichier le raisonnement se lit — y compris le bloc qui
     * PRÉCÈDE la transition, qui est là où il se trouve réellement.
     *
     * @return array{texte: string, premiereLigne: int, derniereLigne: int,
     *                commentairePremiereLigne: int, commentaireDerniereLigne: int,
     *                lignesDeCommentaire: int}
     */
    private function extraitDuYaml(string $cheminRelatif, string $nomDeTransition): array
    {
        $vide = [
            'texte' => '', 'premiereLigne' => 0, 'derniereLigne' => 0,
            'commentairePremiereLigne' => 0, 'commentaireDerniereLigne' => 0,
            'lignesDeCommentaire' => 0,
        ];

        $chemin = $this->getParameter('kernel.project_dir').'/'.$cheminRelatif;
        \assert(\is_string($chemin));

        $lignes = @file($chemin, \FILE_IGNORE_NEW_LINES);
        if (false === $lignes) {
            // Pas d'exception : le tutoriel doit rester lisible même si le
            // fichier a bougé. L'absence se voit à l'écran.
            return $vide;
        }

        $debut = null;
        $indentation = 0;
        foreach ($lignes as $index => $ligne) {
            if (1 === preg_match('/^(\s+)'.preg_quote($nomDeTransition, '/').':\s*$/', $ligne, $trouve)) {
                $debut = $index;
                $indentation = \strlen($trouve[1]);
                break;
            }
        }

        if (null === $debut) {
            return $vide;
        }

        // ── Le bloc de commentaire qui PRÉCÈDE la transition ──────────────
        //
        // On remonte tant que les lignes sont des commentaires. Une ligne
        // vide arrête la remontée : dans ce fichier, c'est elle qui sépare le
        // commentaire d'une transition de celui de la précédente, et sans cet
        // arrêt on annoncerait au lecteur les commentaires du voisin.
        $hautDuCommentaire = $debut;
        for ($index = $debut - 1; $index >= 0; --$index) {
            if (!str_starts_with(trim($lignes[$index]), '#')) {
                break;
            }
            $hautDuCommentaire = $index;
        }

        // ── Le corps de la transition ─────────────────────────────────────
        $fin = $debut;
        $retenues = [];
        $commentaires = $debut - $hautDuCommentaire;

        for ($index = $debut; $index < \count($lignes); ++$index) {
            $ligne = $lignes[$index];
            $nue = trim($ligne);

            // Une ligne non vide moins indentée que la transition : on est
            // sorti du bloc.
            if ($index > $debut && '' !== $nue && \strlen($ligne) - \strlen(ltrim($ligne)) <= $indentation) {
                break;
            }

            $fin = $index;

            if (str_starts_with($nue, '#')) {
                ++$commentaires;

                continue;
            }
            if ('' === $nue) {
                continue;
            }

            $retenues[] = substr($ligne, $indentation);
        }

        return [
            'texte' => implode("\n", $retenues),
            'premiereLigne' => $debut + 1,
            'derniereLigne' => $fin + 1,
            'commentairePremiereLigne' => $hautDuCommentaire + 1,
            'commentaireDerniereLigne' => $fin + 1,
            'lignesDeCommentaire' => $commentaires,
        ];
    }

    /**
     * Les étiquettes des quatre degrés, en un seul endroit.
     *
     * En PHP et non dans le gabarit : le gabarit pourrait alors en inventer
     * une cinquième, ou traduire « CHOIX_PRODUIT » en quelque chose qui
     * ressemblerait à du droit.
     *
     * @return array<string, string>
     */
    private function etiquettesDeDegre(): array
    {
        return [
            DegreCertitude::ETABLI->value => 'établi',
            DegreCertitude::PROBABLE->value => 'probable',
            DegreCertitude::INCERTAIN->value => 'incertain',
            DegreCertitude::CHOIX_PRODUIT->value => 'choix de ce logiciel',
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  LES DOSSIERS DE DÉMONSTRATION — STRICTEMENT FICTIFS
    //
    //  `LOI.md` § 6 H8 : noms manifestement inventés, aucun RIB, aucune CIN,
    //  aucun numéro de chèque plausible, aucune banque réelle nommée. Les
    //  adresses sont en `.invalid`, domaine réservé par la RFC 2606 qui ne
    //  peut désigner personne.
    //
    //  Aucun `persist`, aucun `flush`, aucune requête : ces dossiers vivent le
    //  temps d'une requête HTTP et sont jetés. Le tutoriel ne laisse rien
    //  derrière lui, et une démonstration qui dépendrait de l'état de la base
    //  ne se rejouerait pas deux fois à l'identique.
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Le calendrier du scénario, calé sur l'ÉCÉDAR et non sur des dates
     * écrites en dur.
     *
     * POURQUOI RELATIF, ET RELATIF À L'ÉCÉDAR. Des dates en dur obligeraient à
     * pousser l'horloge pour que la démonstration vieillisse, et l'horloge de
     * ce produit est globale à l'installation (voir l'en-tête de cette
     * classe). Le curseur déplace donc l'âge de l'écédar — qui est précisément
     * le point de départ que l'article 325 al. 6 désigne, et le seul.
     *
     * Les écarts entre les étapes, eux, sont fixes, et ils ne sont pas
     * arbitraires : ils reproduisent la chronologie du dossier de règles
     * (`LOI.md` § 2). Sept semaines entre le rejet du chèque et l'écédar —
     * c'est l'écart que la presse efface en faisant courir les trente jours
     * depuis le rejet —, deux jours entre l'incident et l'injonction bancaire
     * (art. 313), et la plainte entre les deux.
     *
     * @return array{ecedar: \DateTimeImmutable, plainte: \DateTimeImmutable,
     *                injonction: \DateTimeImmutable, incident: \DateTimeImmutable,
     *                emission: \DateTimeImmutable}
     */
    private function calendrierDuScenario(int $jours): array
    {
        // L'instant que le MOTEUR lit, normalisé à minuit : une échéance
        // légale est un jour et non un instant.
        $ecedar = $this->horloges->aujourdHui()->modify(\sprintf('-%d days', $jours));

        return [
            'ecedar' => $ecedar,
            'plainte' => $ecedar->modify('-27 days'),
            'injonction' => $ecedar->modify('-47 days'),
            'incident' => $ecedar->modify('-49 days'),
            'emission' => $ecedar->modify('-56 days'),
        ];
    }

    /**
     * Le dossier au stade de l'écédar, amené là par de VRAIES transitions.
     *
     * Chaque étape passe par `WorkflowInterface::apply()`, donc par les
     * gardes : le certificat de refus pour `constater_impaye`, le
     * procès-verbal et le contrôle judiciaire pour `notifier_ecedar`. Poser
     * l'état directement avec `setEtat()` contournerait tout le droit encodé
     * dans ce projet — l'entité le dit elle-même.
     *
     * @param array<string, \DateTimeImmutable> $calendrier
     */
    private function dossierAvecEcedar(string $reference, array $calendrier): Dossier
    {
        $dossier = $this->dossierSansEcedar($reference, $calendrier);

        $dossier->enregistrerEcedar(
            $calendrier['ecedar'],
            'PV-AUDITION-FICTIF-001',
            // Art. 325 al. 7, rédigé à l'impératif : « une ou plusieurs »
            // mesures, bracelet électronique compris. Les trente jours ne sont
            // pas un délai de grâce, et l'écran ne doit pas les présenter
            // comme tels.
            ['interdiction de quitter le territoire', 'bracelet électronique'],
        );

        // `notifier_ecedar` exige le rôle du greffe : la décision du parquet
        // entre dans ce logiciel comme une pièce, pas comme un acte.
        $this->sousLIdentiteDe(
            'greffe@exemple.invalid',
            [ResolveurDeRoleParCourriel::ROLE_SYMFONY_GREFFE],
            fn (): bool => $this->appliquer($dossier, TransitionDossier::NOTIFIER_ECEDAR),
        );

        return $dossier;
    }

    /**
     * Le dossier jumeau, qui n'a PAS reçu son écédar.
     *
     * Rigoureusement les mêmes dates de chèque, d'incident, de certificat,
     * d'injonction bancaire et de plainte — elles sortent du même calendrier.
     * Aucune horloge H3 : pas une échéance lointaine, pas d'échéance du tout.
     *
     * @param array<string, \DateTimeImmutable> $calendrier
     */
    private function dossierSansEcedar(string $reference, array $calendrier): Dossier
    {
        $dossier = new Dossier(
            $reference,
            new Cheque('CHEQUE-FICTIF-'.$reference, 4_850_000, $calendrier['emission'], LieuEmission::MAROC),
            new Partie('Tireur fictif', 'tireur@exemple.invalid'),
            new Partie('Bénéficiaire fictive', 'beneficiaire@exemple.invalid'),
        );

        $dossier->declarerCasArticle316(CasArticle316::OMISSION_DE_PROVISION);

        $dossier->enregistrerIncidentDePaiement(
            $calendrier['incident'],
            $calendrier['incident'],
            'CERT-REFUS-FICTIF-001',
        );
        $this->appliquer($dossier, TransitionDossier::CONSTATER_IMPAYE);

        // L'injonction bancaire fait partir H2, et elle seule. Deux jours
        // après l'incident (art. 313) : ce logiciel n'audite pas ce délai,
        // dont la sanction frappe la banque et non le tireur.
        $dossier->enregistrerInjonctionBancaire($calendrier['injonction'], 'PREUVE-ENVOI-FICTIVE-001');

        $dossier->enregistrerPlainte($calendrier['plainte']);
        $this->appliquer($dossier, TransitionDossier::DEPOSER_PLAINTE);

        return $dossier;
    }

    /**
     * Le dossier prêt à être écédarisé, mais pas encore écédarisé.
     *
     * Il existe pour une raison de démonstration et non de scénario : sur lui,
     * les deux conditions « état du sujet » de la garde de `notifier_ecedar`
     * sont vraies — le procès-verbal d'audition et les mesures de contrôle
     * judiciaire sont au dossier — et la SEULE condition qui sépare les
     * identités est le vote de sécurité. C'est ce qui rend visible que
     * l'autorisation porte ici une règle, et non une politique d'interface.
     *
     * @param array<string, \DateTimeImmutable> $calendrier
     */
    private function dossierPretPourEcedar(string $reference, array $calendrier): Dossier
    {
        $dossier = $this->dossierSansEcedar($reference, $calendrier);

        $dossier->enregistrerEcedar(
            $calendrier['ecedar'],
            'PV-AUDITION-FICTIF-003',
            ['interdiction de quitter le territoire'],
        );

        // Et on n'applique PAS la transition : c'est le moteur qui va dire,
        // identité par identité, s'il l'aurait acceptée.

        return $dossier;
    }

    /**
     * Le dossier dont le quota de prorogations de CE LOGICIEL est épuisé.
     *
     * La première prorogation est posée comme accordée dans le jeu de données,
     * et il faut le dire : la transition `proroger_delai` appartient au
     * bénéficiaire, et on ne va pas se donner son accord pour fabriquer une
     * donnée de départ. Ce que le moteur juge, c'est la SECONDE demande —
     * celle qui porte une décision du parquet, un accord du bénéficiaire et
     * une durée suffisante, et qui ne se heurte qu'au quota.
     *
     * @param array<string, \DateTimeImmutable> $calendrier
     */
    private function dossierAuQuotaEpuise(string $reference, array $calendrier): Dossier
    {
        $dossier = $this->dossierAvecEcedar($reference, $calendrier);
        $ecedar = $calendrier['ecedar'];

        $premiere = new Prolongation($ecedar->modify('+8 days'), Prolongation::DUREE_PROPOSEE_PAR_DEFAUT_EN_JOURS);
        $dossier->ajouterProlongation($premiere);
        $premiere->enregistrerDecisionDuParquet($ecedar->modify('+9 days'), 'DECISION-PARQUET-FICTIVE-001');
        $premiere->enregistrerAccordDuBeneficiaire($ecedar->modify('+9 days'), 'ACCORD-FICTIF-001');
        $premiere->marquerAccordee($ecedar->modify('+10 days'));

        // La seconde demande est COMPLÈTE au regard de la loi : décision du
        // parquet, accord du bénéficiaire, durée au moins égale au délai
        // initial. Tout ce qui lui manque est l'autorisation de ce logiciel.
        $seconde = new Prolongation($ecedar->modify('+18 days'), Prolongation::DUREE_PROPOSEE_PAR_DEFAUT_EN_JOURS);
        $dossier->ajouterProlongation($seconde);
        $seconde->enregistrerDecisionDuParquet($ecedar->modify('+19 days'), 'DECISION-PARQUET-FICTIVE-002');
        $seconde->enregistrerAccordDuBeneficiaire($ecedar->modify('+19 days'), 'ACCORD-FICTIF-002');

        return $dossier;
    }

    /**
     * Le dossier dont le bénéficiaire est décédé : l'angle mort du texte.
     *
     * Quota porté à deux, décision du parquet et accord enregistrés — l'accord
     * ayant été donné avant le décès. Il ne reste donc qu'UNE SEULE condition
     * fausse, et elle n'est pas dans la loi : l'article 325 al. 8 exige
     * l'accord du bénéficiaire sans dire qui le donne à sa place. Degré
     * INCERTAIN, donc le produit alerte au lieu de conclure
     * (`LOI.md` § 4.15 et § 6 H4).
     *
     * Le quota est porté à deux exprès : avec le quota par défaut, deux
     * membres de la conjonction seraient faux et la démonstration perdrait sa
     * netteté.
     *
     * @param array<string, \DateTimeImmutable> $calendrier
     */
    private function dossierDontLeBeneficiaireEstDecede(string $reference, array $calendrier): Dossier
    {
        $ecedar = $calendrier['ecedar'];

        $dossier = new Dossier(
            $reference,
            new Cheque('CHEQUE-FICTIF-'.$reference, 4_850_000, $calendrier['emission'], LieuEmission::MAROC),
            new Partie('Tireur fictif', 'tireur@exemple.invalid'),
            new Partie('Bénéficiaire fictive', 'beneficiaire@exemple.invalid'),
            quotaProlongationsDeCeLogiciel: 2,
        );

        $dossier->declarerCasArticle316(CasArticle316::OMISSION_DE_PROVISION);
        $dossier->enregistrerIncidentDePaiement(
            $calendrier['incident'],
            $calendrier['incident'],
            'CERT-REFUS-FICTIF-002',
        );
        $this->appliquer($dossier, TransitionDossier::CONSTATER_IMPAYE);
        $dossier->enregistrerInjonctionBancaire($calendrier['injonction'], 'PREUVE-ENVOI-FICTIVE-002');
        $dossier->enregistrerPlainte($calendrier['plainte']);
        $this->appliquer($dossier, TransitionDossier::DEPOSER_PLAINTE);
        $dossier->enregistrerEcedar($ecedar, 'PV-AUDITION-FICTIF-002', ['bracelet électronique']);
        $this->sousLIdentiteDe(
            'greffe@exemple.invalid',
            [ResolveurDeRoleParCourriel::ROLE_SYMFONY_GREFFE],
            fn (): bool => $this->appliquer($dossier, TransitionDossier::NOTIFIER_ECEDAR),
        );

        $demande = new Prolongation($ecedar->modify('+8 days'), Prolongation::DUREE_PROPOSEE_PAR_DEFAUT_EN_JOURS);
        $dossier->ajouterProlongation($demande);
        $demande->enregistrerDecisionDuParquet($ecedar->modify('+9 days'), 'DECISION-PARQUET-FICTIVE-003');
        $demande->enregistrerAccordDuBeneficiaire($ecedar->modify('+9 days'), 'ACCORD-FICTIF-003');

        $dossier->declarerDisponibiliteBeneficiaire(DisponibiliteBeneficiaire::DECEDE);

        return $dossier;
    }

    /**
     * Franchit une transition si le moteur l'autorise, et dit si elle l'a été.
     *
     * Aucune exception avalée en silence : si une garde refuse une étape du
     * jeu de données, l'écran le montrera, parce que le dossier n'aura pas
     * avancé et que son état est affiché. Mieux vaut un tutoriel qui dit
     * « bloqué ici » qu'un tutoriel qui plante.
     */
    private function appliquer(Dossier $dossier, TransitionDossier $transition): bool
    {
        if (!$this->machineDuDossier->can($dossier, $transition->value)) {
            return false;
        }

        $this->machineDuDossier->apply($dossier, $transition->value);

        return true;
    }
}
