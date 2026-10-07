<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Cheque;
use App\Entity\Dossier;
use App\Entity\Partie;
use App\Entity\Prolongation;
use App\Enum\CasArticle316;
use App\Enum\EtatDossier;
use App\Enum\TransitionDossier;
use App\Security\ResolveurDeRoleParCourriel;
use App\Workflow\CatalogueDesBlocages;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * LA MACHINE RÉELLE, celle que `config/packages/workflow.yaml` décrit,
 * instanciée PAR LE CONTENEUR et non remontée à la main.
 *
 * POURQUOI CE TEST EXISTE À CÔTÉ DE {@see \App\Tests\Workflow\MachineDuDossierTest}.
 *
 * Ce dernier reconstruit la machine dans le test, avec un `DefinitionBuilder`
 * et un `ExpressionLanguage` nu. C'est rapide, c'est unitaire, et c'est ce
 * qu'il faut pour épingler la logique d'une garde. Mais cela laisse trois
 * choses INVÉRIFIÉES, et ce sont justement les trois choses qu'un recruteur
 * Symfony sonde :
 *
 *  1. que les expressions de garde du YAML s'ÉVALUENT. Une méthode mal
 *     orthographiée dans `subject.quotaProlongationsEpuise()` ne se voit ni à
 *     la lecture, ni dans un test qui recopie l'expression : elle se voit au
 *     premier appel, en production. Le test qui recopie l'expression recopie
 *     aussi la faute ;
 *  2. que `is_granted()` fonctionne DANS une garde de machine à états. C'est
 *     l'argument central du projet — l'autorisation entre dans le graphe — et
 *     il n'était jusqu'ici appuyé par aucune assertion, puisque le test
 *     unitaire retire précisément les appels à `is_granted()` ;
 *  3. que le découpage `from: [a, b]` d'une `state_machine` est bien une
 *     alternative. Le test unitaire le reproduit à la main en se fondant sur
 *     une lecture du `FrameworkExtension` ; ici c'est le `FrameworkExtension`
 *     lui-même qui construit le graphe, donc la lecture est vérifiée et non
 *     supposée.
 *
 * Et l'horloge est celle du conteneur : le bloc `when@test` de
 * `config/services.yaml` substitue une `MockClock` à `Psr\Clock\ClockInterface`.
 * Pousser cette horloge pousse donc le temps de TOUT le graphe, gardes
 * comprises, sans qu'une ligne de code applicatif le sache. C'est la
 * substitution d'implémentation prouvée par une assertion.
 */
final class MachineReelleDuDossierTest extends KernelTestCase
{
    private const string COURRIEL_TIREUR = 'tireur@exemple.invalid';
    private const string COURRIEL_BENEFICIAIRE = 'beneficiaire@exemple.invalid';

    private WorkflowInterface $machine;
    private MockClock $horloge;

    protected function setUp(): void
    {
        self::bootKernel();

        $conteneur = self::getContainer();

        $machine = $conteneur->get('state_machine.dossier_penal');
        \assert($machine instanceof WorkflowInterface);
        $this->machine = $machine;

        // L'horloge DU CONTENEUR, pas une horloge du test : c'est celle que
        // les gardes reçoivent par injection. En pousser une autre ne
        // prouverait rien.
        $horloge = $conteneur->get(MockClock::class);
        \assert($horloge instanceof MockClock);
        $this->horloge = $horloge;
    }

    /**
     * Le graphe réel est bien celui qu'on croit : quatorze places.
     *
     * Assertion de départ et non de décor : si le `FrameworkExtension` lisait
     * le YAML autrement qu'on ne le croit, tous les tests qui suivent
     * porteraient sur un autre graphe que celui décrit.
     */
    #[Test]
    public function leGrapheDuConteneurEstCeluiDuYaml(): void
    {
        $definition = $this->machine->getDefinition();

        self::assertCount(14, $definition->getPlaces());
        self::assertSame([EtatDossier::CHEQUE_EMIS->value], $definition->getInitialPlaces());

        // ★ Le point que le test unitaire supposait : dans une
        // `state_machine`, `from: [ecedar_notifie, delai_prolonge]` produit
        // DEUX objets `Transition` et non un seul portant deux `from`. Sans
        // ce découpage, `expirer_delai` ne serait jamais franchissable —
        // `can()` répondrait toujours faux, en silence, sans erreur.
        $expirations = array_filter(
            $definition->getTransitions(),
            static fn ($t): bool => TransitionDossier::EXPIRER_DELAI->value === $t->getName(),
        );

        self::assertCount(2, $expirations, 'Une state_machine doit éclater les « from » multiples.');

        foreach ($expirations as $transition) {
            self::assertCount(1, $transition->getFroms(), 'Deux « from » sur un objet seraient une conjonction.');
        }
    }

    // ═══════════════════ ★ LE MOMENT FILMÉ, SUR LA MACHINE RÉELLE ═══════════════════

    /**
     * Au trente et unième jour, le dossier bascule SEUL.
     *
     * Rien n'est touché entre les deux assertions sauf l'horloge. Pas de
     * champ modifié, pas de méthode appelée sur le dossier, aucun clic : le
     * seul passage du temps ouvre la transition.
     */
    #[Test]
    public function auTrenteEtUniemeJourLeDossierExpireSansQuePersonneNAgisse(): void
    {
        $dossier = $this->dossierAuStadeEcedar();

        // Le dossier est enregistré tel quel, et on ne le touche plus.
        $empreinteAvant = $this->empreinte($dossier);

        self::assertFalse(
            $this->machine->can($dossier, TransitionDossier::EXPIRER_DELAI->value),
            'Le jour de l\'écédar, le délai de trente jours commence : il ne peut pas être expiré.'
        );

        $this->horloge->sleep(31 * 86400);

        self::assertTrue(
            $this->machine->can($dossier, TransitionDossier::EXPIRER_DELAI->value),
            'Au trente et unième jour, la garde temporelle doit ouvrir la transition.'
        );

        self::assertSame(
            $empreinteAvant,
            $this->empreinte($dossier),
            'Aucune donnée du dossier ne doit avoir changé : seule l\'horloge a bougé.'
        );

        $this->machine->apply($dossier, TransitionDossier::EXPIRER_DELAI->value);
        self::assertSame(EtatDossier::DELAI_EXPIRE, $dossier->etat());
    }

    /** Au vingt-neuvième jour, non — et le refus porte son code. */
    #[Test]
    public function auVingtNeuviemeJourLeDelaiCourtEncore(): void
    {
        $dossier = $this->dossierAuStadeEcedar();

        $this->horloge->sleep(29 * 86400);

        self::assertFalse($this->machine->can($dossier, TransitionDossier::EXPIRER_DELAI->value));

        $blocages = $this->machine->buildTransitionBlockerList($dossier, TransitionDossier::EXPIRER_DELAI->value);
        self::assertTrue(
            $blocages->has(CatalogueDesBlocages::DELAI_PENAL_NON_EXPIRE),
            'Le refus doit porter le code du catalogue, pour que l\'écran affiche la règle et sa source.'
        );
    }

    /**
     * Une transition interdite LÈVE, elle ne passe pas en silence.
     *
     * Le point que ni `can()` ni `buildTransitionBlockerList()` ne vérifient :
     * ils RÉPONDENT. Ici on appelle `apply()` sur une transition fermée et on
     * exige une exception. Un code appelant qui oublierait de tester `can()`
     * doit se faire arrêter, pas faire avancer le dossier à tort.
     */
    #[Test]
    public function uneTransitionInterditeLeveAuLieuDePasserEnSilence(): void
    {
        $dossier = $this->dossierAuStadeEcedar();

        // Le délai court encore : `expirer_delai` est fermée par la garde
        // temporelle.
        $this->expectException(NotEnabledTransitionException::class);

        $this->machine->apply($dossier, TransitionDossier::EXPIRER_DELAI->value);
    }

    /**
     * Et le dossier n'a pas bougé après l'exception.
     *
     * Une exception levée APRÈS que le marquage a été écrit laisserait un
     * dossier dans un état que la garde refuse. Le test vérifie donc les deux
     * choses : que ça lève, et que ça n'a rien fait.
     */
    #[Test]
    public function apresUneTransitionRefuseeLEtatEstIntact(): void
    {
        $dossier = $this->dossierAuStadeEcedar();

        try {
            $this->machine->apply($dossier, TransitionDossier::EXPIRER_DELAI->value);
            self::fail('La transition aurait dû lever.');
        } catch (NotEnabledTransitionException) {
            // attendu
        }

        self::assertSame(EtatDossier::ECEDAR_NOTIFIE, $dossier->etat());
    }

    /**
     * Une transition qui n'existe pas DEPUIS CETTE PLACE lève aussi.
     *
     * Distinct du cas précédent : là, la garde refusait une transition
     * existante ; ici, la transition `engager_poursuite` n'a pas
     * `ecedar_notifie` dans ses `from`. L'exigence de l'écédar préalable
     * (art. 325 al. 6) est portée par le GRAPHE autant que par les gardes, et
     * c'est le graphe qu'on teste.
     */
    #[Test]
    public function onNePeutPasEngagerLaPoursuiteSansPasserParLExpirationDuDelai(): void
    {
        $dossier = $this->dossierAuStadeEcedar();

        self::assertFalse($this->machine->can($dossier, TransitionDossier::ENGAGER_POURSUITE->value));

        $this->expectException(NotEnabledTransitionException::class);
        $this->machine->apply($dossier, TransitionDossier::ENGAGER_POURSUITE->value);
    }

    // ═══════════════════ LA SECONDE PROROGATION ═══════════════════

    /**
     * La seconde prorogation est REFUSÉE — et la garde du YAML s'évalue
     * réellement, `is_granted()` compris.
     *
     * Trois choses dans un seul scénario, et elles ne se séparent pas : la
     * première prorogation PASSE (donc l'expression entière est satisfaisable,
     * ce qui exclut la faute d'orthographe silencieuse), la seconde ÉCHOUE, et
     * le motif est le quota de ce logiciel.
     */
    #[Test]
    public function laSecondeProrogationEstRefuseeParLeQuotaDuLogiciel(): void
    {
        $dossier = $this->dossierAuStadeEcedar();

        // L'accord est celui du BÉNÉFICIAIRE : art. 325 al. 8. La garde du
        // YAML appelle `is_granted('DOSSIER_ACCORDER_PROLONGATION', subject)`,
        // et le voter n'accorde cet attribut qu'à lui.
        $this->authentifier($this->beneficiaire());

        $this->prorogationComplete($dossier, 30);

        self::assertTrue(
            $this->machine->can($dossier, TransitionDossier::PROROGER_DELAI->value),
            'La première prorogation, complète, doit passer : sinon l\'expression de garde est fautive '
                .'et tous les refus qui suivent seraient des faux positifs.'
        );

        $this->machine->apply($dossier, TransitionDossier::PROROGER_DELAI->value);
        self::assertSame(EtatDossier::DELAI_PROLONGE, $dossier->etat());
        self::assertSame(1, $dossier->nombreDeProlongationsAccordees());

        // ★ LA SECONDE, aussi complète que la première.
        $this->horloge->sleep(86400);
        $this->prorogationComplete($dossier, 30);

        self::assertFalse(
            $this->machine->can($dossier, TransitionDossier::PROROGER_DELAI->value),
            'La seconde prorogation doit être refusée.'
        );

        // Et elle est refusée par le QUOTA, pas par autre chose : si la cause
        // était une autre condition mal réunie, le test passerait pour une
        // mauvaise raison.
        self::assertTrue($dossier->quotaProlongationsEpuise());
        self::assertSame(1, $dossier->quotaProlongationsDeCeLogiciel());
    }

    /**
     * ★ LE LIBELLÉ, et il vaut une assertion à lui seul.
     *
     * La loi 71.24 ne limite NI la durée NI le nombre des prorogations. Le
     * refus ci-dessus est donc un choix de ce logiciel, et l'info-bulle doit
     * le dire. Une info-bulle « déjà prolongé une fois — loi 71-24 »
     * attribuerait à la loi une règle que la loi ne porte pas : c'est une
     * fausse citation, et c'est exactement ce que ce test interdit.
     */
    #[Test]
    public function leRefusDuQuotaNeSeReclameNiDeLaLoiNiDUnePratiqueInventee(): void
    {
        $regle = CatalogueDesBlocages::regle(CatalogueDesBlocages::QUOTA_PROLONGATIONS_EPUISE);

        self::assertStringContainsString('Limite de ce logiciel', $regle->enonce);
        self::assertStringContainsString('ne limite PAS le nombre de prorogations', $regle->enonce);
        self::assertSame('choix_produit', $regle->degre->value);

        // Et le renvoi à LOI.md est présent : l'écran doit pouvoir envoyer
        // l'utilisateur vers le dossier de règles plutôt que de conclure.
        self::assertNotSame('', $regle->renvoiLoiMd);
    }

    /**
     * Quatre-vingt-dix jours PASSENT : aucun plafond légal.
     *
     * Le pendant indispensable du test du quota. Si le logiciel refusait une
     * durée supérieure au délai initial, il inventerait un plafond de
     * soixante jours que le texte ne porte pas — et la presse l'a inventé
     * avant nous.
     */
    #[Test]
    public function uneProrogationDeQuatreVingtDixJoursPasse(): void
    {
        $dossier = $this->dossierAuStadeEcedar();
        $this->authentifier($this->beneficiaire());

        $this->prorogationComplete($dossier, 90);

        self::assertTrue(
            $this->machine->can($dossier, TransitionDossier::PROROGER_DELAI->value),
            'Art. 325 al. 8 : « لمدة مماثلة أو أكثر » — une durée égale OU SUPÉRIEURE, sans plafond.'
        );
    }

    // ═══════════════════ `is_granted()` DANS LA GARDE ═══════════════════

    /**
     * ★ L'AUTORISATION EST DANS LE GRAPHE, et elle s'y vérifie.
     *
     * Le scénario le plus parlant du projet : un dossier dont toutes les
     * conditions de fait sont réunies — décision du parquet, accord du
     * bénéficiaire, durée suffisante, quota intact, délai non expiré — et qui
     * ne proroge PAS, parce que c'est LE TIREUR qui est connecté.
     *
     * La règle n'est pas une règle d'interface : l'art. 325 al. 8 exige
     * « بعد موافقة المستفيد », l'accord du bénéficiaire. Le débiteur ne peut
     * pas y suppléer, et il ne peut pas non plus la forger en appelant la
     * machine à états directement — ce que ce test fait, et qui échoue.
     */
    #[Test]
    public function leTireurNePeutPasFranchirUneTransitionQuiExigeLAccordDeSonCreancier(): void
    {
        $dossier = $this->dossierAuStadeEcedar();
        $this->prorogationComplete($dossier, 30);

        // D'abord le bénéficiaire : tout est réuni, la transition est ouverte.
        $this->authentifier($this->beneficiaire());
        self::assertTrue(
            $this->machine->can($dossier, TransitionDossier::PROROGER_DELAI->value),
            'Témoin : avec le bénéficiaire connecté, la transition est ouverte.'
        );

        // ★ Puis le tireur, et RIEN D'AUTRE NE CHANGE.
        $this->authentifier($this->tireur());
        self::assertFalse(
            $this->machine->can($dossier, TransitionDossier::PROROGER_DELAI->value),
            'Seul le bénéficiaire peut accorder la prorogation (art. 325 al. 8).'
        );

        // Et la preuve que c'est bien le voter qui a parlé, et non une
        // condition de fait qui se serait défaite entre les deux appels.
        $verificateur = self::getContainer()->get('security.authorization_checker');
        \assert($verificateur instanceof AuthorizationCheckerInterface);
        self::assertFalse($verificateur->isGranted('DOSSIER_ACCORDER_PROLONGATION', $dossier));
    }

    /**
     * Un tiers ne voit RIEN, pas même l'existence du dossier.
     *
     * `DOSSIER_VOIR` refusé pour qui n'est pas partie : la différence entre
     * « interdit » et « introuvable » révélerait qu'un dossier existe pour un
     * chèque donné.
     */
    #[Test]
    public function unTiersNAucunDroitSurLeDossier(): void
    {
        $dossier = $this->dossierAuStadeEcedar();
        $this->authentifier(new InMemoryUser('tiers@exemple.invalid', null, ['ROLE_USER']));

        $verificateur = self::getContainer()->get('security.authorization_checker');
        \assert($verificateur instanceof AuthorizationCheckerInterface);

        self::assertFalse($verificateur->isGranted('DOSSIER_VOIR', $dossier));
        self::assertFalse($verificateur->isGranted('DOSSIER_ACCORDER_PROLONGATION', $dossier));
        self::assertFalse($this->machine->can($dossier, TransitionDossier::PROROGER_DELAI->value));
    }

    /**
     * Le greffe notifie l'écédar ; le tireur ne le notifie pas lui-même.
     *
     * Seconde garde `is_granted()` du graphe, sur une autre transition et un
     * autre rôle : une seule vérification pourrait réussir par accident.
     */
    #[Test]
    public function seulLeGreffeNotifieLEcedar(): void
    {
        $dossier = $this->dossierAuStadePlainte();

        $this->authentifier($this->tireur());
        self::assertFalse(
            $this->machine->can($dossier, TransitionDossier::NOTIFIER_ECEDAR->value),
            'L\'écédar est un acte du parquet exécuté par la police judiciaire : le tireur ne le pose pas.'
        );

        $this->authentifier($this->greffe());
        self::assertTrue(
            $this->machine->can($dossier, TransitionDossier::NOTIFIER_ECEDAR->value),
            'Le greffe consigne l\'écédar et les mesures de contrôle judiciaire (art. 325 al. 7).'
        );
    }

    // ═══════════════════ L'EXTINCTION, ET SES DEUX CONDITIONS ═══════════════════

    /**
     * Le désistement SEUL n'éteint pas l'action publique.
     *
     * La presse résume l'art. 325 al. 1 en « payer ou se désister suffit ».
     * Non : il y faut AUSSI l'amende de 2 %, versée à la caisse du tribunal.
     * Les deux conditions sont cumulatives, et ce test est la seule chose qui
     * empêche le produit de reprendre le raccourci de la presse.
     */
    #[Test]
    public function leDesistementSeulNEteintPasLActionPublique(): void
    {
        $dossier = $this->dossierAuStadeEcedar();
        $this->authentifier($this->beneficiaire());

        $this->machine->apply($dossier, TransitionDossier::ACTER_DESISTEMENT->value);
        self::assertSame(EtatDossier::DESISTEMENT_OU_TRANSACTION_ACTE, $dossier->etat());

        self::assertFalse(
            $this->machine->can($dossier, TransitionDossier::ETEINDRE_ACTION_PUBLIQUE->value),
            'Sans l\'amende de 2 % de l\'art. 325 al. 1, la poursuite subsiste.'
        );

        // L'amende payée, et alors seulement, l'extinction s'ouvre.
        $dossier->enregistrerAmendeDeuxPourCent(
            $this->aujourdHui(),
            'QUITTANCE-FICTIVE-2PCT-1'
        );

        self::assertTrue(
            $this->machine->can($dossier, TransitionDossier::ETEINDRE_ACTION_PUBLIQUE->value),
            'Désistement ET amende de 2 % : les deux conditions réunies ouvrent l\'extinction.'
        );
    }

    /**
     * L'état « désistement acté » n'est pas terminal, et c'est tout son
     * intérêt : un dossier désisté dont les 2 % ne sont pas payés y reste.
     */
    #[Test]
    public function leDesistementEstIrreversible(): void
    {
        $dossier = $this->dossierAuStadeEcedar();
        $this->authentifier($this->beneficiaire());
        $this->machine->apply($dossier, TransitionDossier::ACTER_DESISTEMENT->value);

        // Art. 325 al. 10 : on ne revient ni sur la transaction ni sur le
        // désistement. Aucune transition ne repart vers un état antérieur.
        foreach ([
            TransitionDossier::PROROGER_DELAI,
            TransitionDossier::EXPIRER_DELAI,
            TransitionDossier::NOTIFIER_ECEDAR,
            TransitionDossier::ACTER_DESISTEMENT,
        ] as $transition) {
            self::assertFalse(
                $this->machine->can($dossier, $transition->value),
                \sprintf('« %s » ne doit pas être franchissable depuis un désistement acté.', $transition->value)
            );
        }
    }

    // ═══════════════════ Montage ═══════════════════

    private function aujourdHui(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->horloge->now())->modify('midnight');
    }

    /**
     * Données MANIFESTEMENT inventées : domaine `.invalid` réservé par la
     * RFC 2606, aucune banque réelle nommée, aucun numéro plausible
     * (contrainte n° 3).
     */
    private function dossierNeuf(): Dossier
    {
        return new Dossier(
            'DOSSIER-INTEGRATION-0001',
            new Cheque('CHQ-FICTIF-9001', 1_500_000, new \DateTimeImmutable('2026-01-15')),
            new Partie('Tireur Fictif', self::COURRIEL_TIREUR),
            new Partie('Bénéficiaire Fictif', self::COURRIEL_BENEFICIAIRE),
        );
    }

    private function dossierAuStadePlainte(): Dossier
    {
        $dossier = $this->dossierNeuf();
        $dossier->enregistrerIncidentDePaiement(
            new \DateTimeImmutable('2026-01-20'),
            new \DateTimeImmutable('2026-01-20'),
            'CERTIFICAT-FICTIF-9001'
        );
        $dossier->enregistrerPlainte(new \DateTimeImmutable('2026-01-25'));
        $dossier->setEtat(EtatDossier::PLAINTE_DEPOSEE->value);

        // L'écédar est PRÉPARÉ mais la place n'est pas atteinte : c'est
        // `notifier_ecedar` qui l'y mènera, et sa garde exige le
        // procès-verbal et le contrôle judiciaire.
        $dossier->enregistrerEcedar(
            $this->aujourdHui(),
            'PV-AUDITION-FICTIF-9001',
            ['présentation périodique aux services de police']
        );

        return $dossier;
    }

    /** Un dossier à la place `ecedar_notifie`, l'écédar daté d'aujourd'hui. */
    private function dossierAuStadeEcedar(): Dossier
    {
        $dossier = $this->dossierAuStadePlainte();
        $dossier->setEtat(EtatDossier::ECEDAR_NOTIFIE->value);

        return $dossier;
    }

    /** Décision du parquet ET accord du bénéficiaire : art. 325 al. 8. */
    private function prorogationComplete(Dossier $dossier, int $dureeEnJours): Prolongation
    {
        $maintenant = $this->aujourdHui();
        $prolongation = new Prolongation($maintenant, $dureeEnJours);
        $dossier->ajouterProlongation($prolongation);
        $prolongation->enregistrerDecisionDuParquet($maintenant, 'DECISION-FICTIVE-'.$dureeEnJours);
        $prolongation->enregistrerAccordDuBeneficiaire($maintenant, 'ACCORD-FICTIF-'.$dureeEnJours);

        return $prolongation;
    }

    private function tireur(): UserInterface
    {
        return new InMemoryUser(self::COURRIEL_TIREUR, null, ['ROLE_USER']);
    }

    private function beneficiaire(): UserInterface
    {
        return new InMemoryUser(self::COURRIEL_BENEFICIAIRE, null, ['ROLE_USER']);
    }

    private function greffe(): UserInterface
    {
        return new InMemoryUser('greffe@exemple.invalid', null, [
            'ROLE_USER',
            ResolveurDeRoleParCourriel::ROLE_SYMFONY_GREFFE,
        ]);
    }

    /**
     * Pose un jeton dans le stockage du conteneur.
     *
     * C'est ce jeton que le `GuardListener` du composant Workflow lit pour
     * évaluer `is_granted()` dans une garde du YAML : sans lui, la garde
     * lèverait au lieu de refuser — et c'est la raison documentée pour
     * laquelle `expirer_delai`, poussée hors requête par le Scheduler, n'a
     * aucune garde `is_granted()`.
     */
    private function authentifier(UserInterface $utilisateur): void
    {
        $stockage = self::getContainer()->get('security.token_storage');
        \assert($stockage instanceof \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface);

        $jeton = new UsernamePasswordToken($utilisateur, 'principal', $utilisateur->getRoles());
        \assert($jeton instanceof TokenInterface);

        $stockage->setToken($jeton);
    }

    /**
     * Une empreinte des champs du dossier qui pourraient influer sur
     * l'expiration, pour prouver qu'AUCUN n'a changé entre deux assertions.
     *
     * Sans elle, « le dossier a basculé au seul passage du temps » reposerait
     * sur la lecture du test et non sur une assertion.
     */
    private function empreinte(Dossier $dossier): string
    {
        return json_encode([
            'etat' => $dossier->etat()->value,
            'date_ecedar' => $dossier->dateEcedar()?->format('Y-m-d'),
            'duree_initiale' => $dossier->dureeDelaiInitialEnJours(),
            'prolongations_accordees' => $dossier->nombreDeProlongationsAccordees(),
            'quota' => $dossier->quotaProlongationsDeCeLogiciel(),
            'cas' => $dossier->casArticle316()->value,
        ], \JSON_THROW_ON_ERROR);
    }

    /** Épingle le cas pénal par défaut, dont dépendent plusieurs gardes. */
    #[Test]
    public function leCasPenalParDefautEstLOmissionDeProvision(): void
    {
        // L'extinction de l'art. 325 al. 1 et la cause de justification
        // familiale de l'al. 4 sont limitées au POINT 1 de l'art. 316. Si le
        // cas par défaut changeait, les tests d'extinction ci-dessus
        // passeraient ou échoueraient pour une raison invisible.
        self::assertSame(
            CasArticle316::OMISSION_DE_PROVISION,
            $this->dossierNeuf()->casArticle316()
        );
    }
}
