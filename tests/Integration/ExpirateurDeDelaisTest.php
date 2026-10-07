<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Cheque;
use App\Entity\Dossier;
use App\Entity\Partie;
use App\Entity\Prolongation;
use App\Enum\EtatDossier;
use App\Repository\DossierRepository;
use App\Workflow\ExpirateurDeDelais;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * LA PASSE D'EXPIRATION, SUR UNE VRAIE BASE.
 *
 * {@see ExpirateurDeDelais} est le service que la démonstration appelle après
 * avoir poussé l'horloge, et celui que le Scheduler appellera en production.
 * Jusqu'ici il n'avait AUCUN appelant et AUCUN test : il était écrit, soigné,
 * documenté — et jamais exécuté. Un service non exécuté n'est pas du code,
 * c'est une intention.
 *
 * CE QUE CE TEST AJOUTE AUX AUTRES, et qui justifie d'allumer PostgreSQL
 * alors que les tests de la machine à états s'en passent :
 *
 *  1. le DQL de {@see DossierRepository::dossiersDontLeDelaiPenalPeutAvoirExpire()}
 *     s'exécute vraiment. Une requête DQL ne se vérifie pas à la lecture :
 *     un nom de champ invalide y lève à l'exécution, et seulement là ;
 *  2. le cycle data mapper complet est parcouru — `persist`, `flush`,
 *     `clear`, rechargement depuis la base. C'est l'argument « Doctrine n'est
 *     pas ActiveRecord » rendu vérifiable : l'entité est construite, oubliée,
 *     puis relue sans jamais savoir qu'une base existe ;
 *  3. le verrou optimiste `#[Version]` est bien en base et s'incrémente. Une
 *     colonne `version` qui ne bouge pas ne protège de rien ;
 *  4. et surtout : la passe ne touche QUE les dossiers échus. Un service qui
 *     ferait expirer un dossier prorogé serait la pire régression possible de
 *     ce produit — il enverrait au pénal quelqu'un à qui le parquet venait
 *     d'accorder un délai.
 */
final class ExpirateurDeDelaisTest extends KernelTestCase
{
    private EntityManagerInterface $gestionnaire;
    private MockClock $horloge;
    private ExpirateurDeDelais $expirateur;

    protected function setUp(): void
    {
        self::bootKernel();
        $conteneur = self::getContainer();

        $gestionnaire = $conteneur->get(EntityManagerInterface::class);
        \assert($gestionnaire instanceof EntityManagerInterface);
        $this->gestionnaire = $gestionnaire;

        $horloge = $conteneur->get(MockClock::class);
        \assert($horloge instanceof MockClock);
        $this->horloge = $horloge;

        $expirateur = $conteneur->get(ExpirateurDeDelais::class);
        \assert($expirateur instanceof ExpirateurDeDelais);
        $this->expirateur = $expirateur;

        $this->viderLesTables();
    }

    protected function tearDown(): void
    {
        $this->viderLesTables();
        parent::tearDown();
    }

    /**
     * ★ LA PASSE FAIT BASCULER L'ÉCHU, ET LUI SEUL.
     *
     * Deux dossiers notifiés le même jour, et une seule différence : l'un a
     * obtenu une prorogation. Trente et un jours plus tard, la passe en fait
     * expirer UN. C'est l'assertion qui compte, parce que l'erreur inverse
     * — faire expirer les deux — ne se verrait sur aucun écran avant qu'un
     * dossier prorogé ne parte au pénal.
     */
    #[Test]
    public function laPasseNeFaitExpirerQueLesDossiersEchus(): void
    {
        $echu = $this->dossierNotifieAujourdHui('DOSSIER-ECHU-0001');
        $proroge = $this->dossierNotifieAujourdHui('DOSSIER-PROROGE-0001');

        // La prorogation porte le délai de 30 à 60 jours : décision du parquet
        // ET accord du bénéficiaire (art. 325 al. 8), les deux cumulatives.
        $maintenant = $this->aujourdHui();
        $prolongation = new Prolongation($maintenant, 30);
        $proroge->ajouterProlongation($prolongation);
        $prolongation->enregistrerDecisionDuParquet($maintenant, 'DECISION-FICTIVE-1');
        $prolongation->enregistrerAccordDuBeneficiaire($maintenant, 'ACCORD-FICTIF-1');
        $prolongation->marquerAccordee($maintenant);
        $proroge->setEtat(EtatDossier::DELAI_PROLONGE->value);

        $this->gestionnaire->flush();
        $this->gestionnaire->clear();

        // ★ On pousse l'horloge de trente et un jours, et on ne touche à aucun
        // dossier.
        $this->horloge->sleep(31 * 86400);

        $compte = $this->expirateur->faireExpirerLesDelaisEchus();

        self::assertSame(0, $compte['collisions']);
        self::assertSame(
            1,
            $compte['bascules'],
            'Un seul dossier doit basculer : celui qui n\'a pas été prorogé.'
        );

        // Et on le relit DEPUIS LA BASE, pas depuis la mémoire : c'est la
        // seule lecture qui prouve que la bascule a été écrite.
        self::assertSame(EtatDossier::DELAI_EXPIRE, $this->relire($echu->reference())->etat());
        self::assertSame(
            EtatDossier::DELAI_PROLONGE,
            $this->relire($proroge->reference())->etat(),
            'Le dossier prorogé doit rester prorogé : faire expirer un délai que le parquet vient '
                .'d\'accorder enverrait au pénal quelqu\'un qui avait obtenu un délai.'
        );
    }

    /**
     * Avant le terme, la passe ne fait rien — et le dit en chiffres.
     *
     * Le compte rendu est vérifié et pas seulement l'état : « examinés 1,
     * basculés 0 » prouve que le DQL a bien RAMENÉ le dossier et que c'est la
     * garde qui l'a écarté. Un DQL qui ne ramènerait rien donnerait
     * « examinés 0 », et le test passerait pour une mauvaise raison — un
     * dossier jamais examiné n'est pas un dossier correctement laissé.
     */
    #[Test]
    public function auVingtNeuviemeJourLaPasseExamineMaisNeBasculeRien(): void
    {
        $this->dossierNotifieAujourdHui('DOSSIER-COURANT-0001');
        $this->gestionnaire->flush();
        $this->gestionnaire->clear();

        $this->horloge->sleep(29 * 86400);

        $compte = $this->expirateur->faireExpirerLesDelaisEchus();

        self::assertSame(1, $compte['examines'], 'Le DQL doit ramener le dossier.');
        self::assertSame(0, $compte['bascules'], 'Mais la garde temporelle doit l\'écarter.');
    }

    /**
     * La passe est IDEMPOTENTE : la relancer ne rebascule rien.
     *
     * En production elle tournera plusieurs fois par jour. Si elle comptait
     * deux fois le même dossier, tout chiffre publié sur son activité serait
     * faux — et la règle de la commande exige que ces chiffres soient justes.
     */
    #[Test]
    public function relancerLaPasseNeRebasculeRien(): void
    {
        $this->dossierNotifieAujourdHui('DOSSIER-IDEMPOTENT-0001');
        $this->gestionnaire->flush();
        $this->gestionnaire->clear();

        $this->horloge->sleep(31 * 86400);

        self::assertSame(1, $this->expirateur->faireExpirerLesDelaisEchus()['bascules']);
        self::assertSame(
            0,
            $this->expirateur->faireExpirerLesDelaisEchus()['bascules'],
            'Le dossier n\'est plus dans une place de départ de `expirer_delai`.'
        );
    }

    /**
     * ★ LE VERROU OPTIMISTE EST RÉEL, et il s'incrémente.
     *
     * `#[Version]` ne sert à rien s'il ne bouge pas : c'est lui qui permet au
     * service de détecter qu'un greffe a enregistré une prorogation pendant la
     * passe, et de laisser le dossier plutôt que d'écraser son écriture.
     */
    #[Test]
    public function leVerrouOptimisteSIncrementeQuandLaPasseEcrit(): void
    {
        $dossier = $this->dossierNotifieAujourdHui('DOSSIER-VERSION-0001');
        $this->gestionnaire->flush();
        $versionInitiale = $dossier->version();
        $this->gestionnaire->clear();

        $this->horloge->sleep(31 * 86400);
        $this->expirateur->faireExpirerLesDelaisEchus();

        self::assertGreaterThan(
            $versionInitiale,
            $this->relire('DOSSIER-VERSION-0001')->version(),
            'Une colonne « version » qui ne bouge pas ne protège d\'aucune collision.'
        );
    }

    /**
     * ★ LE REFUS PORTE SA SOURCE, et pas seulement son énoncé.
     *
     * Le chemin de la démonstration : l'écran doit pouvoir griser le bouton
     * AVEC son info-bulle — l'énoncé, l'article, le degré — et non le griser
     * en silence. C'est la contrainte n° 1 du projet, appliquée au cas le
     * plus simple.
     *
     * Ce test a d'abord rougi, et il avait raison : la méthode rendait
     * `$blocage->getMessage()`, c'est-à-dire l'énoncé seul. L'article, le
     * degré et le renvoi à `LOI.md` étaient calculés par l'écouteur de gardes,
     * rangés dans les paramètres du blocage… et jetés au retour. Un refus
     * sans source est précisément ce que ce produit s'interdit.
     */
    #[Test]
    public function leRefusRenduParLaDemonstrationPorteSaSource(): void
    {
        $dossier = $this->dossierNotifieAujourdHui('DOSSIER-MOTIF-0001');
        $this->gestionnaire->flush();

        $regle = $this->expirateur->faireExpirerLeDelaiDe($dossier);

        self::assertNotNull($regle, 'Avant le terme, le refus doit être motivé.');
        self::assertStringContainsString('trente jours', $regle->enonce);
        self::assertStringContainsString('325', $regle->article, 'Le refus doit porter son article.');
        self::assertStringContainsString(
            'loi n° 71.24',
            $regle->attribution(),
            'Cette règle est ÉTABLIE : elle cite la loi, en disant que seul l\'arabe fait foi.'
        );
        self::assertStringContainsString('non opposable', $regle->attribution());

        // Et après le terme, plus de refus : la bascule a lieu, et elle est
        // écrite en base.
        $this->horloge->sleep(31 * 86400);
        self::assertNull($this->expirateur->faireExpirerLeDelaiDe($dossier));
        self::assertSame(EtatDossier::DELAI_EXPIRE, $this->relire('DOSSIER-MOTIF-0001')->etat());
    }

    /**
     * Le DQL ne ramène pas les dossiers sans écédar.
     *
     * Règle la plus facile à manquer : aucun délai de trente jours n'existe
     * avant l'écédar (art. 325 al. 6). Une requête qui ramènerait les
     * dossiers au stade de la plainte les ferait examiner par une passe qui
     * n'a rien à leur dire — et un jour, par une passe qui croirait avoir
     * quelque chose à leur dire.
     */
    #[Test]
    public function leDqlIgnoreLesDossiersSansEcedar(): void
    {
        $dossier = $this->dossierNeuf('DOSSIER-SANS-ECEDAR-0001');
        $dossier->enregistrerIncidentDePaiement(
            new \DateTimeImmutable('2026-01-20'),
            new \DateTimeImmutable('2026-01-20'),
            'CERTIFICAT-FICTIF-7001'
        );
        $dossier->enregistrerPlainte(new \DateTimeImmutable('2026-01-25'));
        $dossier->setEtat(EtatDossier::PLAINTE_DEPOSEE->value);

        $this->gestionnaire->persist($dossier);
        $this->gestionnaire->flush();
        $this->gestionnaire->clear();

        $this->horloge->sleep(400 * 86400);

        self::assertSame(
            0,
            $this->expirateur->faireExpirerLesDelaisEchus()['examines'],
            'Un dossier sans écédar n\'a pas d\'échéance : même après plus d\'un an, rien n\'expire.'
        );
    }

    // ═══════════════════ Montage ═══════════════════

    private function aujourdHui(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->horloge->now())->modify('midnight');
    }

    /** Données manifestement inventées, domaine `.invalid` (contrainte n° 3). */
    private function dossierNeuf(string $reference): Dossier
    {
        return new Dossier(
            $reference,
            new Cheque('CHQ-FICTIF-'.substr(md5($reference), 0, 6), 1_500_000, new \DateTimeImmutable('2026-01-15')),
            new Partie('Tireur Fictif', 'tireur.'.strtolower($reference).'@exemple.invalid'),
            new Partie('Bénéficiaire Fictif', 'beneficiaire.'.strtolower($reference).'@exemple.invalid'),
        );
    }

    private function dossierNotifieAujourdHui(string $reference): Dossier
    {
        $dossier = $this->dossierNeuf($reference);
        $dossier->enregistrerIncidentDePaiement(
            new \DateTimeImmutable('2026-01-20'),
            new \DateTimeImmutable('2026-01-20'),
            'CERTIFICAT-FICTIF-'.substr(md5($reference), 0, 6)
        );
        $dossier->enregistrerPlainte(new \DateTimeImmutable('2026-01-25'));
        $dossier->enregistrerEcedar(
            $this->aujourdHui(),
            'PV-AUDITION-FICTIF-'.substr(md5($reference), 0, 6),
            ['présentation périodique aux services de police']
        );
        $dossier->setEtat(EtatDossier::ECEDAR_NOTIFIE->value);

        $this->gestionnaire->persist($dossier);

        return $dossier;
    }

    /** Relecture DEPUIS LA BASE : la seule qui prouve qu'une écriture a eu lieu. */
    private function relire(string $reference): Dossier
    {
        $this->gestionnaire->clear();

        $depot = self::getContainer()->get(DossierRepository::class);
        \assert($depot instanceof DossierRepository);

        return $depot->findOneBy(['reference' => $reference])
            ?? throw new \RuntimeException("Dossier « $reference » introuvable en base.");
    }

    /**
     * Base vidée avant et après chaque test.
     *
     * `TRUNCATE … CASCADE` et non un `DELETE` par entité : l'ordre des
     * suppressions dépendrait des clés étrangères, et un test qui échoue à
     * mi-parcours laisserait la base dans un état qui ferait échouer le
     * suivant pour une raison invisible.
     */
    private function viderLesTables(): void
    {
        $this->gestionnaire->clear();
        $this->gestionnaire->getConnection()->executeStatement(
            'TRUNCATE dossier, prolongation, evenement_dossier, cheque, partie, interdiction_bancaire CASCADE'
        );
    }
}
