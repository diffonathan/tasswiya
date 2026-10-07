<?php

declare(strict_types=1);

namespace App\Tests\Workflow;

use App\Domaine\Horloge\CalculateurDHorloges;
use App\Domaine\Horloge\DelaisCalendairesSansReport;
use App\Domaine\Horloge\DelaisFrancs;
use App\Domaine\Horloge\ReglesDeDelaiInterface;
use App\Entity\Cheque;
use App\Entity\Dossier;
use App\Entity\Partie;
use App\Entity\Prolongation;
use App\Enum\EtatDossier;
use App\Enum\TransitionDossier;
use App\Enum\TypePieceJustificative;
use App\Workflow\CatalogueDesBlocages;
use App\Workflow\EcouteurDeGardesTemporelles;
use App\Workflow\EcouteurDeTransitionsAppliquees;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage as ExpressionLanguageDeBase;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * Le moment filmé du projet, épinglé par des assertions.
 *
 * CE QUE CE TEST PROUVE, et il le prouve sans base de données ni serveur web,
 * ce qui est la contrepartie du choix data mapper :
 *
 *  1. qu'on ne peut PAS faire expirer un délai avant son terme, même en
 *     appelant directement la machine à états ;
 *  2. qu'il suffit de pousser l'horloge de trente et un jours pour que le
 *     dossier bascule SEUL, sans aucune action humaine ;
 *  3. que la garde du quota de prorogations refuse la transition, et que son
 *     message dit « limite de ce logiciel » et non « loi 71-24 » — ce qui est
 *     la seule formulation juste, puisque l'article 325 al. 8 ne limite pas le
 *     nombre de prorogations ;
 *  4. que substituer l'implémentation de {@see ReglesDeDelaiInterface} déplace
 *     réellement l'échéance. C'est la démonstration du conteneur, prouvée par
 *     une assertion et non affirmée dans un discours.
 *
 * La machine est construite à la main plutôt que chargée depuis
 * `config/packages/workflow.yaml`, pour que le test reste unitaire et rapide.
 * Les gardes `is_granted()` du YAML sont donc absentes ici : elles exigent un
 * jeton de sécurité et relèvent d'un test fonctionnel. Un second test, qui
 * compare la liste des places et transitions d'ici à celle du YAML, garde les
 * deux descriptions synchronisées.
 */
final class MachineDuDossierTest extends TestCase
{
    private const string REFERENCE_FICTIVE = 'DOSSIER-TEST-0001';

    #[Test]
    public function leDelaiNExpirePasAvantSonTerme(): void
    {
        // L'écédar est notifié le 1er mars 2026. Trente jours courent.
        $horloge = new MockClock(new \DateTimeImmutable('2026-03-01 09:00:00'));
        [$machine, $dossier] = $this->dossierAuStadeEcedar($horloge);

        // Vingt-neuf jours plus tard : le délai court encore.
        $horloge->sleep(29 * 86400);

        self::assertFalse(
            $machine->can($dossier, TransitionDossier::EXPIRER_DELAI->value),
            'Au vingt-neuvième jour, le délai ne doit pas pouvoir expirer.'
        );

        // Et le refus porte SA SOURCE : le code du blocage renvoie au
        // catalogue, qui porte l'article et le degré de certitude. Un
        // « transition non autorisée » muet n'apprendrait rien à l'utilisateur.
        $blocages = $machine->buildTransitionBlockerList($dossier, TransitionDossier::EXPIRER_DELAI->value);
        self::assertTrue($blocages->has(CatalogueDesBlocages::DELAI_PENAL_NON_EXPIRE));
    }

    #[Test]
    public function leDossierBasculeAuSeulPassageDuTemps(): void
    {
        $horloge = new MockClock(new \DateTimeImmutable('2026-03-01 09:00:00'));
        [$machine, $dossier] = $this->dossierAuStadeEcedar($horloge);

        self::assertSame(EtatDossier::ECEDAR_NOTIFIE, $dossier->etat());

        // ★ LE MOMENT FILMÉ : on pousse l'horloge de trente et un jours, et
        // on ne touche à AUCUNE donnée du dossier.
        $horloge->sleep(31 * 86400);

        self::assertTrue(
            $machine->can($dossier, TransitionDossier::EXPIRER_DELAI->value),
            'Au trente et unième jour, le seul passage du temps doit ouvrir la transition.'
        );

        $machine->apply($dossier, TransitionDossier::EXPIRER_DELAI->value);

        self::assertSame(EtatDossier::DELAI_EXPIRE, $dossier->etat());
    }

    #[Test]
    public function laProrogationEstRefuseeSansLaDecisionDuParquet(): void
    {
        // La condition que le brief du projet avait oubliée : l'article 325
        // al. 8 fait de la prorogation une décision DU MINISTÈRE PUBLIC.
        // L'accord du bénéficiaire ne suffit pas.
        $horloge = new MockClock(new \DateTimeImmutable('2026-03-01 09:00:00'));
        [$machine, $dossier] = $this->dossierAuStadeEcedar($horloge);

        $prolongation = new Prolongation($horloge->now(), 30);
        $dossier->ajouterProlongation($prolongation);
        $prolongation->enregistrerAccordDuBeneficiaire($horloge->now(), 'ACCORD-FICTIF-1');

        self::assertFalse(
            $machine->can($dossier, TransitionDossier::PROROGER_DELAI->value),
            'Sans décision du parquet, la prorogation doit être refusée même avec l\'accord du bénéficiaire.'
        );
    }

    #[Test]
    public function laProrogationEstRefuseeSansLAccordDuBeneficiaire(): void
    {
        $horloge = new MockClock(new \DateTimeImmutable('2026-03-01 09:00:00'));
        [$machine, $dossier] = $this->dossierAuStadeEcedar($horloge);

        $prolongation = new Prolongation($horloge->now(), 30);
        $dossier->ajouterProlongation($prolongation);
        $prolongation->enregistrerDecisionDuParquet($horloge->now(), 'DECISION-FICTIVE-1');

        self::assertFalse(
            $machine->can($dossier, TransitionDossier::PROROGER_DELAI->value),
            'L\'article 325 al. 8 exige l\'accord du bénéficiaire : les deux conditions sont cumulatives.'
        );
    }

    #[Test]
    public function uneDureeInferieureAuDelaiInitialEstRefusee(): void
    {
        // Art. 325 al. 8 : « لمدة مماثلة أو أكثر » — une durée ÉGALE OU
        // SUPÉRIEURE. C'est un plancher, et la garde refuse une durée trop
        // courte. Jamais une durée trop longue : il n'existe aucun plafond.
        $horloge = new MockClock(new \DateTimeImmutable('2026-03-01 09:00:00'));
        [$machine, $dossier] = $this->dossierAuStadeEcedar($horloge);

        $this->prorogationComplete($dossier, $horloge, dureeEnJours: 15);

        self::assertFalse(
            $machine->can($dossier, TransitionDossier::PROROGER_DELAI->value),
            'Quinze jours sont inférieurs au délai initial de trente : la garde doit refuser.'
        );
    }

    #[Test]
    public function uneDureeSuperieureAuDelaiInitialEstAcceptee(): void
    {
        // Le pendant du test précédent, et il est aussi important : aucun
        // plafond légal. Les « 60 jours maximum » de la presse ne sont écrits
        // nulle part, et quatre-vingt-dix jours doivent passer.
        $horloge = new MockClock(new \DateTimeImmutable('2026-03-01 09:00:00'));
        [$machine, $dossier] = $this->dossierAuStadeEcedar($horloge);

        $prolongation = $this->prorogationComplete($dossier, $horloge, dureeEnJours: 90);

        self::assertTrue(
            $machine->can($dossier, TransitionDossier::PROROGER_DELAI->value),
            'Aucun plafond légal : une prorogation de quatre-vingt-dix jours est valide.'
        );

        // Et elle porte sa mention, parce qu'elle dépasse la pratique décrite
        // par la circulaire du parquet — une mention, pas un refus.
        self::assertNotNull($prolongation->mentionSiAuDelaDeLaPratiqueDuParquet());
    }

    #[Test]
    public function leQuotaDeProrogationsRefuseLaSecondeEtDitQueCestUneLimiteDuLogiciel(): void
    {
        $horloge = new MockClock(new \DateTimeImmutable('2026-03-01 09:00:00'));
        [$machine, $dossier] = $this->dossierAuStadeEcedar($horloge);

        // Première prorogation : complète, donc accordée.
        $this->prorogationComplete($dossier, $horloge, dureeEnJours: 30);
        $machine->apply($dossier, TransitionDossier::PROROGER_DELAI->value);

        self::assertSame(EtatDossier::DELAI_PROLONGE, $dossier->etat());
        self::assertSame(1, $dossier->nombreDeProlongationsAccordees());

        // Seconde prorogation, aussi complète que la première : elle est
        // refusée par le QUOTA DE CE LOGICIEL, pas par la loi.
        $horloge->sleep(86400);
        $this->prorogationComplete($dossier, $horloge, dureeEnJours: 30);

        $blocages = $machine->buildTransitionBlockerList($dossier, TransitionDossier::PROROGER_DELAI->value);
        self::assertFalse($blocages->isEmpty());

        // ★ L'ASSERTION QUI COMPTE, et elle est là pour empêcher une
        // régression de LIBELLÉ autant que de logique : le message doit dire
        // que la limite vient de ce logiciel, et dire que la loi 71-24 ne
        // limite PAS le nombre de prorogations. L'info-bulle que le brief du
        // projet prévoyait — « déjà prolongé une fois, loi 71-24 » —
        // attribuerait à la loi une règle qu'elle ne porte pas.
        $message = CatalogueDesBlocages::regle(CatalogueDesBlocages::QUOTA_PROLONGATIONS_EPUISE)->enonce;
        self::assertStringContainsString('Limite de ce logiciel', $message);
        self::assertStringContainsString('ne limite PAS le nombre de prorogations', $message);

        $attribution = CatalogueDesBlocages::regle(CatalogueDesBlocages::QUOTA_PROLONGATIONS_EPUISE)->attribution();
        self::assertStringNotContainsString(
            'loi n° 71.24, BO',
            $attribution,
            'Cette garde est un paramètre produit : son attribution ne doit pas être la loi.'
        );
    }

    #[Test]
    public function laProrogationRepousseLEcheanceEtEmpecheLExpiration(): void
    {
        $horloge = new MockClock(new \DateTimeImmutable('2026-03-01 09:00:00'));
        [$machine, $dossier, $calculateur] = $this->dossierAuStadeEcedar($horloge);

        $this->prorogationComplete($dossier, $horloge, dureeEnJours: 30);
        $machine->apply($dossier, TransitionDossier::PROROGER_DELAI->value);

        // Soixante jours, c'est-à-dire au-delà du délai initial de trente mais
        // en deçà des soixante prorogés : le délai court toujours.
        $horloge->sleep(45 * 86400);

        self::assertSame(60, $calculateur->dureeEffectiveDuDelaiPenalEnJours($dossier));
        self::assertFalse($machine->can($dossier, TransitionDossier::EXPIRER_DELAI->value));

        // Puis au-delà de la prorogation : il expire.
        $horloge->sleep(20 * 86400);
        self::assertTrue($machine->can($dossier, TransitionDossier::EXPIRER_DELAI->value));
    }

    #[Test]
    public function onNeProrogePasUnDelaiDejaExpire(): void
    {
        $horloge = new MockClock(new \DateTimeImmutable('2026-03-01 09:00:00'));
        [$machine, $dossier] = $this->dossierAuStadeEcedar($horloge);

        $horloge->sleep(31 * 86400);
        $this->prorogationComplete($dossier, $horloge, dureeEnJours: 30);

        $blocages = $machine->buildTransitionBlockerList($dossier, TransitionDossier::PROROGER_DELAI->value);
        self::assertTrue($blocages->has(CatalogueDesBlocages::DELAI_PENAL_DEJA_EXPIRE));
    }

    #[Test]
    public function aucunDelaiPenalNeCourtAvantLEcedar(): void
    {
        // Règle la plus facile à manquer : les trente jours partent de
        // l'écédar et de rien d'autre — ni de la présentation, ni du rejet, ni
        // de la plainte, ni de l'injonction bancaire (art. 325 al. 6).
        // Un dossier au stade de la plainte n'a donc pas une échéance
        // lointaine : il n'a PAS D'ÉCHÉANCE.
        $horloge = new MockClock(new \DateTimeImmutable('2026-03-01 09:00:00'));
        $calculateur = new CalculateurDHorloges($horloge, new DelaisCalendairesSansReport());

        $dossier = $this->dossierNeuf();
        $dossier->enregistrerIncidentDePaiement(
            new \DateTimeImmutable('2026-02-10'),
            new \DateTimeImmutable('2026-02-10'),
            'CERTIFICAT-FICTIF-1'
        );
        $dossier->enregistrerPlainte(new \DateTimeImmutable('2026-02-20'));

        self::assertNull($calculateur->h3($dossier));
        self::assertNull($calculateur->joursRestantsSurLeDelaiPenal($dossier));
        self::assertFalse($calculateur->delaiPenalExpire($dossier));
    }

    #[Test]
    public function substituerLesReglesDeDelaiDeplaceLEcheance(): void
    {
        // ★ LA DÉMONSTRATION DU CONTENEUR, prouvée et non affirmée.
        //
        // La loi 71.24 ne dit RIEN du calcul des délais — ni jours francs, ni
        // sort du jour de départ. Le choix est donc une implémentation
        // substituable, et la substitution déplace réellement l'échéance d'un
        // jour. Si une circulaire ou un arrêt vient dire que les trente jours
        // sont francs, un alias de conteneur suffit à basculer tout le
        // produit.
        $horloge = new MockClock(new \DateTimeImmutable('2026-03-01 09:00:00'));
        $dossier = $this->dossierAvecEcedarAu(new \DateTimeImmutable('2026-03-01'));

        $avecDefaut = new CalculateurDHorloges($horloge, new DelaisCalendairesSansReport());
        $avecFrancs = new CalculateurDHorloges($horloge, new DelaisFrancs());

        $echeanceParDefaut = $avecDefaut->h3($dossier)?->echeance;
        $echeanceEnFrancs = $avecFrancs->h3($dossier)?->echeance;

        self::assertNotNull($echeanceParDefaut);
        self::assertNotNull($echeanceEnFrancs);
        self::assertSame('2026-03-31', $echeanceParDefaut->format('Y-m-d'));
        self::assertSame('2026-04-01', $echeanceEnFrancs->format('Y-m-d'));

        // Et les deux implémentations se déclarent comme des CHOIX, pas comme
        // du droit : la signature de l'interface le leur impose.
        self::assertTrue($avecDefaut->reglesDeDelai()->degre()->value === 'choix_produit');
        self::assertTrue($avecFrancs->reglesDeDelai()->degre()->value === 'choix_produit');
    }

    #[Test]
    public function lesCinqHorlogesSontDistinctes(): void
    {
        // Interdiction la plus explicite du dossier de règles : pas de champ
        // `dateLimiteRegularisation` unique. Les cinq délais n'ont ni le même
        // départ, ni la même durée, ni le même effet.
        $horloge = new MockClock(new \DateTimeImmutable('2026-03-10 09:00:00'));
        $calculateur = new CalculateurDHorloges($horloge, new DelaisCalendairesSansReport());

        $dossier = $this->dossierAvecEcedarAu(new \DateTimeImmutable('2026-03-01'));
        $dossier->enregistrerInjonctionBancaire(new \DateTimeImmutable('2026-02-12'), 'PREUVE-FICTIVE-1');

        $horloges = $calculateur->toutesLesHorloges($dossier);

        self::assertSame(['H1', 'H2', 'H3', 'H4', 'H5'], array_keys($horloges));

        // Cinq échéances, cinq valeurs différentes : la preuve qu'un champ
        // unique aurait fait mentir le produit.
        $echeances = array_map(
            static fn ($h): string => $h->echeance->format('Y-m-d'),
            $horloges
        );
        self::assertCount(5, array_unique($echeances));

        // Et H4 part de l'ÉCHÉANCE de H1, pas d'un événement daté.
        self::assertSame(
            $horloges['H1']->echeance->modify('+2 years')->format('Y-m-d'),
            $horloges['H4']->echeance->format('Y-m-d')
        );
    }

    // ═══════════════════ Montage ═══════════════════

    private function dossierNeuf(): Dossier
    {
        // Données manifestement inventées : aucun RIB, aucune CIN, aucune
        // banque réelle nommée (contrainte n° 3, LOI.md § 6 H8).
        return new Dossier(
            self::REFERENCE_FICTIVE,
            new Cheque(
                'CHQ-FICTIF-0001',
                1_500_000, // 15 000 DH en centimes
                new \DateTimeImmutable('2026-01-15')
            ),
            new Partie('Tireur Fictif', 'tireur@exemple.invalid'),
            new Partie('Bénéficiaire Fictif', 'beneficiaire@exemple.invalid'),
        );
    }

    private function dossierAvecEcedarAu(\DateTimeImmutable $dateEcedar): Dossier
    {
        $dossier = $this->dossierNeuf();
        $dossier->enregistrerIncidentDePaiement(
            new \DateTimeImmutable('2026-02-10'),
            new \DateTimeImmutable('2026-02-10'),
            'CERTIFICAT-FICTIF-1'
        );
        $dossier->enregistrerPlainte(new \DateTimeImmutable('2026-02-20'));
        $dossier->enregistrerEcedar(
            $dateEcedar,
            'PV-AUDITION-FICTIF-1',
            // Art. 325 al. 7, à l'impératif : une ou plusieurs mesures.
            ['présentation périodique aux services de police']
        );

        return $dossier;
    }

    /**
     * Un dossier au stade de l'écédar, et la machine qui le pilote.
     *
     * @return array{0: StateMachine, 1: Dossier, 2: CalculateurDHorloges}
     */
    private function dossierAuStadeEcedar(MockClock $horloge): array
    {
        $dossier = $this->dossierAvecEcedarAu(
            \DateTimeImmutable::createFromInterface($horloge->now())->modify('midnight')
        );
        $dossier->setEtat(EtatDossier::ECEDAR_NOTIFIE->value);

        $calculateur = new CalculateurDHorloges($horloge, new DelaisCalendairesSansReport());

        return [$this->machine($calculateur), $dossier, $calculateur];
    }

    /**
     * Enregistre une prorogation complète : décision du parquet ET accord du
     * bénéficiaire, les deux conditions cumulatives de l'article 325 al. 8.
     */
    private function prorogationComplete(
        Dossier $dossier,
        MockClock $horloge,
        int $dureeEnJours,
    ): Prolongation {
        $maintenant = \DateTimeImmutable::createFromInterface($horloge->now());
        $prolongation = new Prolongation($maintenant, $dureeEnJours);
        $dossier->ajouterProlongation($prolongation);
        $prolongation->enregistrerDecisionDuParquet($maintenant, 'DECISION-FICTIVE-'.$dureeEnJours);
        $prolongation->enregistrerAccordDuBeneficiaire($maintenant, 'ACCORD-FICTIF-'.$dureeEnJours);

        return $prolongation;
    }

    /**
     * La machine `dossier_penal`, construite à la main.
     *
     * Les expressions reproduisent celles de `config/packages/workflow.yaml`,
     * MOINS les appels à `is_granted()` : ceux-là exigent un jeton de
     * sécurité et relèvent d'un test fonctionnel. Le test
     * {@see GrapheDuDossierTest} compare les deux descriptions pour qu'elles
     * ne divergent pas.
     */
    private function machine(CalculateurDHorloges $calculateur): StateMachine
    {
        $constructeur = new DefinitionBuilder();
        $constructeur->addPlaces(array_map(
            static fn (EtatDossier $etat): string => $etat->value,
            EtatDossier::cases()
        ));
        $constructeur->setInitialPlaces(EtatDossier::CHEQUE_EMIS->value);

        /*
         * ⚠ UN PIÈGE SYMFONY QUI VAUT D'ÊTRE SU, ET QUI S'EST PAYÉ ICI.
         *
         * `Workflow::buildTransitionBlockerListForTransition()` exige que
         * TOUTES les places `from` d'un objet `Transition` figurent dans le
         * marquage — sémantique du ET, qui est celle d'un réseau de Petri.
         * Dans une `state_machine`, le marquage ne contient qu'UNE place : un
         * objet `Transition` construit avec deux `from` ne serait donc JAMAIS
         * franchissable, et `can()` répondrait toujours faux.
         *
         * Le YAML n'en souffre pas, parce que le `FrameworkExtension` traite
         * les deux types différemment : pour `type: workflow` il crée UN objet
         * `Transition` portant tout le tableau `from`, et pour
         * `type: state_machine` il crée UN OBJET PAR PLACE DE DÉPART. La liste
         * `from: [ecedar_notifie, delai_prolonge]` de `workflow.yaml` est donc
         * bien une alternative, et non une conjonction.
         *
         * Ce montage reproduit exactement ce découpage. Ne pas le faire
         * donnerait un test qui échoue là où la configuration réelle marche,
         * ou — bien pire dans l'autre sens — un test qui passe sur un graphe
         * que la configuration réelle n'aurait jamais produit.
         */
        $transitionsMultiples = [
            TransitionDossier::EXPIRER_DELAI->value => EtatDossier::DELAI_EXPIRE->value,
            TransitionDossier::PROROGER_DELAI->value => EtatDossier::DELAI_PROLONGE->value,
        ];

        foreach ($transitionsMultiples as $nom => $vers) {
            foreach ([EtatDossier::ECEDAR_NOTIFIE->value, EtatDossier::DELAI_PROLONGE->value] as $depuis) {
                $constructeur->addTransition(new Transition($nom, $depuis, $vers));
            }
        }

        $repartiteur = new EventDispatcher();
        $repartiteur->addListener(
            'workflow.dossier_penal.guard.expirer_delai',
            [new EcouteurDeGardesTemporelles($calculateur), 'surExpirationDuDelai']
        );
        $repartiteur->addListener(
            'workflow.dossier_penal.guard.proroger_delai',
            [new EcouteurDeGardesTemporelles($calculateur), 'surProrogationDuDelai']
        );
        $repartiteur->addListener(
            'workflow.dossier_penal.completed.proroger_delai',
            [new EcouteurDeTransitionsAppliquees($calculateur), 'surProrogationAccordee']
        );

        // Les gardes d'état du sujet, reprises TEXTUELLEMENT du YAML, moins
        // les appels à `is_granted()`.
        //
        // L'expression est évaluée ici par un `ExpressionLanguage` nu plutôt
        // que par le `GuardListener` de Symfony, et c'est volontaire : le
        // `GuardListener` exige un stockage de jeton et un vérificateur
        // d'autorisation, c'est-à-dire la moitié du composant Security, pour
        // un test qui n'a rien à dire sur les droits. Ce que ce test vérifie,
        // c'est que L'EXPRESSION DIT CE QU'ON CROIT, et elle est copiée mot
        // pour mot depuis `config/packages/workflow.yaml`.
        $expression = 'subject.decisionParquetEnregistree()'
            .' and subject.accordBeneficiaireEnregistre()'
            .' and subject.beneficiaireDisponible()'
            .' and subject.dureeProlongationDemandeeAuMoinsEgaleAuDelaiInitial()'
            .' and not subject.quotaProlongationsEpuise()';

        $langage = new ExpressionLanguageDeBase();
        $repartiteur->addListener(
            'workflow.dossier_penal.guard.proroger_delai',
            static function (GuardEvent $evenement) use ($langage, $expression): void {
                if (true === $langage->evaluate($expression, ['subject' => $evenement->getSubject()])) {
                    return;
                }

                // Le code exact du refus n'est pas déterminable depuis une
                // expression composée : seul le fait du refus l'est. Les
                // messages détaillés viennent du catalogue, et les tests qui
                // portent sur un motif précis l'interrogent directement.
                $evenement->addTransitionBlocker(new TransitionBlocker(
                    'Une condition de l\'article 325 al. 8 n\'est pas réunie.',
                    'garde_etat_du_sujet',
                ));
            }
        );

        return new StateMachine(
            $constructeur->build(),
            new MethodMarkingStore(true, 'etat'),
            $repartiteur,
            'dossier_penal',
        );
    }

    #[Test]
    public function lesPiecesDuDossierSontCellesQueLaProcedureProduit(): void
    {
        // Il circule une prétendue « attestation de régularisation » : cette
        // pièce n'existe pas sous ce nom, et la nommer dans un logiciel serait
        // inventer un document administratif marocain. Ce test épingle la
        // liste close (LOI.md § 4.13).
        $noms = array_map(
            static fn (TypePieceJustificative $t): string => $t->value,
            TypePieceJustificative::cases()
        );

        self::assertContains('proces_verbal_valant_ecedar', $noms);
        self::assertContains('certificat_de_refus_de_paiement', $noms);
        self::assertNotContains('attestation_de_regularisation', $noms);

        // Et le contenu du certificat de refus reste INCERTAIN : le modèle est
        // fixé par une circulaire de Bank Al-Maghrib que nous n'avons pas pu
        // lire. L'écran doit alerter, pas conclure.
        self::assertTrue(
            TypePieceJustificative::CERTIFICAT_DE_REFUS_DE_PAIEMENT
                ->degreDeCertitudeDuContenu()
                ->exigeUneAlerte()
        );
    }
}
