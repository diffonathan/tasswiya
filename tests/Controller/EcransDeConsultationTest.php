<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domaine\Graphe\ExplicateurDuGraphe;
use App\Domaine\Graphe\TransitionExpliquee;
use App\Entity\Cheque;
use App\Entity\Dossier;
use App\Entity\InterdictionBancaire;
use App\Entity\Partie;
use App\Enum\DegreCertitude;
use App\Enum\EtatDossier;
use App\Workflow\CatalogueDesBlocages;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * CE QUE LES TROIS ÉCRANS NE DOIVENT JAMAIS SE PERMETTRE.
 *
 * ═══ POURQUOI DES TESTS D'ÉCRAN, ET PAS SEULEMENT DE DOMAINE ══════════════
 *
 * Parce que les quatre fautes que ce projet cherche à éviter sont des fautes
 * D'AFFICHAGE, pas de calcul. Le domaine peut être parfaitement juste pendant
 * qu'un gabarit attribue à la loi une limite qu'elle ne pose pas, ou affiche
 * un délai sans nommer son point de départ. Aucun test de `CalculateurDHorloges`
 * ne verrait passer l'une ou l'autre.
 *
 * Les quatre, telles qu'elles sont épinglées ici :
 *
 *  1. **« La loi limite à une prolongation. »** Elle ne le fait pas
 *     (art. 325 al. 8, « لمدة مماثلة أو أكثر »). Le quota est un
 *     CHOIX_PRODUIT, et l'écran doit le dire LÀ OÙ IL BLOQUE.
 *  2. **Un délai affiché sans son point de départ.** Les trente jours partent
 *     de l'écédar et de rien d'autre ; l'écart avec le rejet du chèque se
 *     compte en semaines.
 *  3. **Retomber sur le montant du chèque quand le manquant n'est pas
 *     chiffré.** Cela surestimerait l'amende, d'autant plus que la provision
 *     était proche d'être suffisante.
 *  4. **Un avertissement juridique absent d'un écran.** Il est dans le
 *     gabarit de base précisément pour qu'aucune page ne puisse l'oublier ;
 *     le test vérifie que c'est encore vrai sur les trois.
 */
final class EcransDeConsultationTest extends WebTestCase
{
    private KernelBrowser $navigateur;
    private EntityManagerInterface $gestionnaire;
    private MockClock $horloge;

    protected function setUp(): void
    {
        $this->navigateur = self::createClient();
        $conteneur = self::getContainer();

        $gestionnaire = $conteneur->get(EntityManagerInterface::class);
        \assert($gestionnaire instanceof EntityManagerInterface);
        $this->gestionnaire = $gestionnaire;

        $horloge = $conteneur->get(MockClock::class);
        \assert($horloge instanceof MockClock);
        $this->horloge = $horloge;

        $this->viderLesTables();
    }

    protected function tearDown(): void
    {
        $this->viderLesTables();
        parent::tearDown();
    }

    /**
     * ★ FAUTE N° 1 — le quota de prorogations n'est PAS une règle de droit.
     *
     * L'écran du dossier doit afficher la limite de ce logiciel avec le degré
     * CHOIX_PRODUIT et sa classe de présentation, et ne jamais écrire que la
     * loi plafonne le nombre de prorogations.
     */
    #[Test]
    public function lEcranDuDossierNAttribueJamaisLeQuotaDeProrogationsALaLoi(): void
    {
        $this->dossierSousEcedar('ECRAN-QUOTA-0001');
        $this->gestionnaire->flush();

        $contenu = $this->html('/dossiers/ECRAN-QUOTA-0001');

        self::assertStringContainsString(
            'regle--choix-produit',
            $contenu,
            'Le quota doit s\'afficher avec le traitement visuel du degré CHOIX_PRODUIT : filet '
                .'pointillé, grille de points, retrait.'
        );
        self::assertStringContainsString('ne limite PAS le nombre de prorogations', $contenu);
        self::assertStringContainsString('Hypothèse de ce logiciel', $contenu);

        // La formulation interdite, sous toutes les tournures qu'on pourrait
        // écrire par distraction.
        foreach ([
            'la loi limite à une',
            'la loi n\'autorise qu\'une',
            'une seule prolongation autorisée par la loi',
            'renouvelable une seule fois',
        ] as $interdite) {
            self::assertStringNotContainsStringIgnoringCase($interdite, $contenu);
        }
    }

    /**
     * ★ FAUTE N° 2 — un délai sans son point de départ nommé.
     *
     * L'écran doit écrire l'ÉVÉNEMENT et non seulement la date, et il doit
     * nier explicitement les trois points de départ que l'on lit partout
     * ailleurs : la présentation, le rejet, la plainte.
     */
    #[Test]
    public function lEcranDuDossierNommeLePointDeDepartDeChaqueHorloge(): void
    {
        $this->dossierSousEcedar('ECRAN-DEPART-0001');
        $this->gestionnaire->flush();

        $contenu = $this->html('/dossiers/ECRAN-DEPART-0001');

        // Les cinq horloges de LOI.md § 2, chacune avec son code.
        foreach (['H1', 'H2', 'H3', 'H4', 'H5'] as $code) {
            self::assertStringContainsString('>'.$code.'<', $contenu, 'L\'horloge '.$code.' doit figurer.');
        }

        // Et les deux points de départ qu'il ne faut surtout pas confondre.
        self::assertStringContainsString('Écédar', $contenu);
        self::assertStringContainsString('Injonction bancaire', $contenu);
        self::assertStringContainsString('Incident de paiement', $contenu);
        self::assertStringContainsString(
            'NI la présentation, NI le rejet, NI la plainte',
            $contenu,
            'L\'écran doit nier explicitement les points de départ que la presse donne.'
        );

        // Le point de départ de H4 est l'ÉCHÉANCE d'une autre horloge : c'est
        // l'argument le plus concret contre un champ « date limite » unique.
        /*
         * Le motif cherché n'a PAS d'apostrophe, et c'est délibéré : Twig
         * échappe `'` en `&#039;`, si bien qu'une assertion écrite avec
         * l'apostrophe échoue sur une page parfaitement correcte. Toutes les
         * assertions de ce fichier portent donc sur des fragments sans
         * apostrophe.
         */
        self::assertStringContainsString('ÉCHÉANCE DE H1', $contenu);
    }

    /**
     * ★ FAUTE N° 3 — retomber sur le montant du chèque.
     *
     * Le manquant n'est pas chiffré : l'amende de 2 % ne doit afficher AUCUN
     * montant, et surtout pas 2 % du montant du chèque.
     */
    #[Test]
    public function lEcranRefuseDeChiffrerQuandLAssietteEstIndeterminee(): void
    {
        // Chèque de 10 000,00 DH, manquant non chiffré. 2 % du chèque feraient
        // 200,00 DH : c'est précisément ce nombre qui ne doit pas apparaître.
        $this->dossierSousEcedar('ECRAN-ASSIETTE-0001', montantEnCentimes: 1_000_000);
        $this->gestionnaire->flush();

        $contenu = $this->html('/dossiers/ECRAN-ASSIETTE-0001');

        self::assertStringContainsString('assiette indéterminée', $contenu);
        self::assertStringContainsString('montant--indetermine', $contenu);
        self::assertStringContainsString(
            'ne retombe PAS sur le montant du chèque',
            $contenu,
            'Le refus de chiffrer doit être EXPLIQUÉ : un montant vide et muet se lit comme une panne.'
        );
        self::assertStringNotContainsString(
            '200,00 DH',
            $contenu,
            '2 % du montant du chèque ne doit jamais tenir lieu de 2 % du manquant.'
        );
    }

    /**
     * ★ FAUTE N° 4 — un écran sans avertissement.
     *
     * Il est dans `base.html.twig` et non dans les pages, pour qu'aucune page
     * ne puisse l'oublier. Le test le vérifie sur les trois écrans, parce que
     * c'est la seule manière de savoir que le gabarit de base est bien celui
     * dont ils héritent tous.
     */
    #[Test]
    public function lAvertissementEtLaMentionDeTraductionFigurentSurLesTroisEcrans(): void
    {
        $this->dossierSousEcedar('ECRAN-AVERT-0001');
        $this->gestionnaire->flush();

        foreach (['/dossiers', '/dossiers/ECRAN-AVERT-0001', '/demonstration'] as $chemin) {
            $contenu = $this->html($chemin);

            self::assertStringContainsString(
                'Ceci n\'est pas un conseil juridique.',
                $contenu,
                'Avertissement absent de '.$chemin.'.'
            );
            self::assertStringContainsString(
                'Seule la version arabe fait foi',
                $contenu,
                'Mention de traduction absente de '.$chemin.' : il n\'existe aucune version française '
                    .'officielle de la loi n° 71.24, et l\'omettre rendrait toute citation fausse.'
            );
        }
    }

    /**
     * ★ LE MOMENT FILMÉ, ÉPINGLÉ.
     *
     * Trente jours : le délai est atteint, et l'écran dit toujours « délai en
     * cours ». Un jour de plus : le dossier est en « délai expiré » SANS
     * qu'aucune requête ne l'ait demandé — seule la passe appelée par l'écran
     * de démonstration est passée, et c'est la garde temporelle qui a décidé.
     *
     * L'assertion qui compte est la PREMIÈRE : si trente jours suffisaient, la
     * démonstration serait une mise en scène.
     */
    #[Test]
    public function auTrentiemeJourRienNeBasculeEtAuTrenteEtUniemeLeDossierExpireSeul(): void
    {
        $this->dossierSousEcedar('ECRAN-MOMENT-0001');
        $this->gestionnaire->flush();
        $this->gestionnaire->clear();

        $expirateur = self::getContainer()->get(\App\Workflow\ExpirateurDeDelais::class);
        \assert($expirateur instanceof \App\Workflow\ExpirateurDeDelais);

        // ─── Trente jours : l'échéance est ATTEINTE, pas dépassée.
        $this->horloge->sleep(30 * 86400);
        self::assertSame(
            0,
            $expirateur->faireExpirerLesDelaisEchus()['bascules'],
            'À l\'échéance exacte, rien ne bascule : la garde teste « maintenant STRICTEMENT après '
                .'l\'échéance ». C\'est ce qui rend le couple de boutons de la démonstration une '
                .'preuve et non une mise en scène.'
        );
        self::assertStringContainsString('Écédar notifié — délai en cours', $this->html('/dossiers/ECRAN-MOMENT-0001'));

        // ─── Un jour de plus, et personne n'a cliqué sur « expirer ».
        $this->horloge->sleep(86400);
        self::assertSame(1, $expirateur->faireExpirerLesDelaisEchus()['bascules']);

        $contenu = $this->html('/dossiers/ECRAN-MOMENT-0001');
        self::assertStringContainsString('Délai expiré', $contenu);
        self::assertStringContainsString(
            'éteignent encore',
            $contenu,
            'Un délai expiré n\'est pas un dossier perdu, et l\'écran doit le dire : le paiement plus '
                .'l\'amende de 2 % éteignent encore l\'action publique (art. 325 al. 1).'
        );
    }

    /**
     * ★ L'INVARIANT DU DIAGNOSTIC, sur toutes les transitions de tous les
     * états atteignables du jeu de test.
     *
     * Une transition impossible a au moins un motif, une transition possible
     * n'en a aucun. C'est le seul garde-fou contre la dérive entre
     * `config/packages/workflow.yaml` et {@see ExplicateurDuGraphe}, qui
     * savent tous deux quelles conditions pèsent sur une transition.
     *
     * Sans lui, une condition ajoutée au YAML et oubliée dans l'explicateur
     * produirait exactement ce que ce projet s'interdit : un bouton grisé en
     * silence.
     */
    #[Test]
    public function leDiagnosticNeDivergeJamaisDeLaMachineAEtats(): void
    {
        $this->dossierSousEcedar('ECRAN-INVARIANT-0001');
        $this->gestionnaire->flush();

        $explicateur = self::getContainer()->get(ExplicateurDuGraphe::class);
        \assert($explicateur instanceof ExplicateurDuGraphe);

        $depot = $this->gestionnaire->getRepository(Dossier::class);

        // Toutes les places du graphe, et non la seule place du jeu de
        // données : une divergence sur une place rarement atteinte est
        // précisément celle que personne ne verrait.
        foreach (ExplicateurDuGraphe::placesDansLOrdreDeLaProcedure() as $place) {
            $dossier = $depot->findOneBy(['reference' => 'ECRAN-INVARIANT-0001']);
            \assert($dossier instanceof Dossier);
            $dossier->setEtat($place->value);

            $examinees = 0;
            foreach ($explicateur->transitionsDepuisLaPlaceCourante($dossier) as $transition) {
                \assert($transition instanceof TransitionExpliquee);
                ++$examinees;

                self::assertTrue(
                    $transition->coherente(),
                    \sprintf(
                        'Depuis « %s », la transition « %s » : la machine dit %s et l\'explicateur '
                        .'trouve %d motif(s). Une condition de workflow.yaml n\'est pas rattachée à '
                        .'une règle du catalogue.',
                        $place->value,
                        $transition->transition->value,
                        $transition->franchissable ? 'FRANCHISSABLE' : 'IMPOSSIBLE',
                        \count($transition->reglesOpposees) + (null === $transition->habilitationManquante ? 0 : 1),
                    )
                );
            }

            if ($place->estTerminal()) {
                self::assertSame(0, $examinees, 'Un état terminal n\'a aucune transition sortante.');
            }
        }
    }

    /**
     * La règle du quota, telle que le catalogue la porte, ne cite PAS la loi
     * dans son attribution.
     *
     * Vérifié au catalogue et pas seulement à l'écran : une règle de degré
     * CHOIX_PRODUIT dont l'attribution dirait « loi n° 71.24 » serait fausse
     * partout où elle s'afficherait, y compris sur des écrans qui n'existent
     * pas encore.
     */
    #[Test]
    public function laRegleDuQuotaPorteSonDegreEtSaSourceReelle(): void
    {
        $regle = CatalogueDesBlocages::regle(CatalogueDesBlocages::QUOTA_PROLONGATIONS_EPUISE);

        self::assertSame(DegreCertitude::CHOIX_PRODUIT, $regle->degre);
        self::assertStringStartsWith('Hypothèse de ce logiciel', $regle->attribution());
        self::assertStringNotContainsString('BO n° 7478', $regle->attribution());
        self::assertSame('لمدة مماثلة أو أكثر، بعد موافقة المستفيد', $regle->arabeSource);
    }

    // ═══════════════════ Montage ═══════════════════

    private function html(string $chemin): string
    {
        $this->navigateur->request('GET', $chemin);
        self::assertResponseIsSuccessful('L\'écran '.$chemin.' doit répondre.');

        return (string) $this->navigateur->getResponse()->getContent();
    }

    private function aujourdHui(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->horloge->now())->modify('midnight');
    }

    /**
     * Un dossier sous écédar DU JOUR, avec ses cinq horloges qui courent et
     * son manquant NON chiffré — le cas courant de `LOI.md` § 4.14.
     *
     * Données manifestement inventées, domaine `.invalid` réservé par la
     * RFC 2606 : aucune donnée personnelle réelle, nulle part.
     */
    private function dossierSousEcedar(string $reference, int $montantEnCentimes = 4_200_000): Dossier
    {
        $emission = $this->aujourdHui()->modify('-60 days');
        $incident = $this->aujourdHui()->modify('-45 days');

        $dossier = new Dossier(
            $reference,
            new Cheque('CHQ-FICTIF-'.substr(md5($reference), 0, 6), $montantEnCentimes, $emission),
            new Partie('Sté FICTIVE A', 'tireur.'.strtolower($reference).'@exemple.invalid'),
            new Partie('Sté FICTIVE B', 'beneficiaire.'.strtolower($reference).'@exemple.invalid'),
        );

        $dossier->enregistrerIncidentDePaiement($incident, $incident, 'CERT-FICTIF-'.substr(md5($reference), 0, 6));
        $dossier->enregistrerInjonctionBancaire($incident->modify('+2 days'), 'PREUVE-FICTIVE');
        $dossier->enregistrerPlainte($this->aujourdHui()->modify('-10 days'));
        $dossier->enregistrerEcedar(
            $this->aujourdHui(),
            'PV-AUDITION-FICTIF-'.substr(md5($reference), 0, 6),
            ['Port du bracelet électronique de surveillance'],
        );
        $dossier->setEtat(EtatDossier::ECEDAR_NOTIFIE->value);

        // L'interdiction bancaire, pour que H4 et H5 existent aussi : le test
        // du point de départ nommé porte sur les CINQ horloges.
        $interdiction = new InterdictionBancaire($incident, $emission->modify('+20 days'));
        $dossier->attacherInterdictionBancaire($interdiction);

        $this->gestionnaire->persist($dossier);
        $this->gestionnaire->persist($interdiction);

        return $dossier;
    }

    private function viderLesTables(): void
    {
        $this->gestionnaire->clear();
        $this->gestionnaire->getConnection()->executeStatement(
            'TRUNCATE dossier, prolongation, evenement_dossier, cheque, partie, interdiction_bancaire CASCADE'
        );
    }
}
