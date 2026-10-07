<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EtatInterdictionBancaire;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * L'interdiction bancaire d'émettre des chèques, et sa propre machine à états.
 *
 * POURQUOI UNE SECONDE MACHINE, et c'est le choix de modélisation à défendre
 * en premier : l'interdiction bancaire court en PARALLÈLE de la procédure
 * pénale, et sans dépendre d'elle. Un dossier peut être pénalement éteint et
 * le compte encore interdit ; l'interdiction peut être purgée et la poursuite
 * continuer. Les mettre dans la même machine imposerait un produit cartésien
 * d'états — « délai expiré et interdit », « délai expiré et purgé » — qui
 * ferait perdre la lisibilité même pour laquelle on a choisi `state_machine`.
 *
 * Deux machines séparées, deux agrégats séparés, deux verrous optimistes
 * séparés : c'est aussi ce qui permet au greffe et à la banque d'écrire sans
 * se marcher dessus.
 *
 * ⚠ À NE PAS CONFONDRE avec l'interdiction JUDICIAIRE de l'article 317, que le
 * tribunal prononce et dont la durée est élidée au Bulletin officiel — lue
 * dans l'ancien article, elle serait de 1 à 5 ans, degré PROBABLE. Ce sont
 * deux interdictions distinctes, et le fait qu'elles durent toutes deux cinq
 * ans au maximum invite précisément à les confondre. Celle-ci n'est pas
 * modélisée ici.
 */
#[ORM\Entity]
#[ORM\Table(name: 'interdiction_bancaire')]
class InterdictionBancaire
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Verrou optimiste, pour la même raison que sur le dossier : la banque
     * enregistre la pénalité, le titulaire déclare sa régularisation, et le
     * Scheduler pousse l'expiration des cinq ans.
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    /**
     * Marquage de la machine `interdiction_bancaire`.
     *
     * Chaîne et non énumération, pour la même raison que sur le dossier : le
     * `MethodMarkingStore` de Symfony travaille sur des chaînes, parce que
     * les places d'un graphe sont des chaînes dans la configuration.
     */
    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $etat = 'active';

    /**
     * Départ de l'horloge H5 : la date de l'incident de paiement.
     *
     * Cinq ans (art. 312 et 313 nouveaux, « خمس سنوات »), contre DIX ANS dans
     * l'ancien code (« عشر سنوات »). La division par deux est lue sur deux
     * textes primaires, et non rapportée par une source secondaire :
     *
     *     grep -c "خمس سنوات" docs/sources/loi_71-24_extrait_BO7478.txt        # 3
     *     grep -c "عشر سنوات" docs/sources/code_commerce_avant_reforme_PMP.txt # 4
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateIncidentDePaiement;

    /**
     * Échéance du délai de présentation (H1), qui est le DÉPART de la fenêtre
     * de régularisation H4 de deux ans.
     *
     * Le départ d'une horloge est ici l'échéance d'une autre : c'est l'exemple
     * le plus net de ce qu'un champ `dateLimiteRegularisation` unique aurait
     * rendu inexprimable (art. 313 nouveau, « ابتداء من تاريخ انتهاء أجل
     * التقديم للوفاء »).
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $echeanceDuDelaiDePresentation;

    /**
     * Rang de l'injonction de l'art. 313, qui pilote le barème de la pénalité
     * de l'art. 314 : 0,5 % pour la première, 1 % pour la deuxième, 1,5 % pour
     * la troisième ET LES SUIVANTES.
     *
     * C'est sur ce compteur, et sur rien d'autre, qu'un éventuel état de
     * « récidive » devrait se construire. La loi 71.24 ne définit AUCUNE
     * récidive du tireur de chèque sans provision : l'article 323, qu'elle ne
     * modifie pas, traite de la récidive pour les articles 317 et 318, qui
     * sont d'autres infractions. Appeler cela « récidive » dans l'interface
     * importerait une notion pénale que ce texte ne porte pas.
     */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $rangDeLInjonction = 1;

    /** Première condition de l'art. 313 : paiement du chèque ou provision suffisante et disponible. */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $paiementOuProvisionConstituee = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $datePaiementOuProvision = null;

    /** Seconde condition de l'art. 313 : paiement de la pénalité de l'art. 314. */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $penaliteArticle314Payee = false;

    public function __construct(
        \DateTimeImmutable $dateIncidentDePaiement,
        \DateTimeImmutable $echeanceDuDelaiDePresentation,
        int $rangDeLInjonction = 1,
    ) {
        $this->id = Uuid::v7();
        $this->dateIncidentDePaiement = $dateIncidentDePaiement->modify('midnight');
        $this->echeanceDuDelaiDePresentation = $echeanceDuDelaiDePresentation->modify('midnight');
        $this->rangDeLInjonction = max(1, $rangDeLInjonction);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function version(): int
    {
        return $this->version;
    }

    /** L'état, typé. C'est par là que passe tout le domaine. */
    public function etat(): EtatInterdictionBancaire
    {
        return EtatInterdictionBancaire::from($this->etat);
    }

    /** @internal Réservé au `MethodMarkingStore`. Le domaine lit {@see etat()}. */
    public function getEtat(): string
    {
        return $this->etat;
    }

    /** @internal Réservé au `MethodMarkingStore`. Passer par `apply()`, jamais par ici. */
    public function setEtat(string $etat): void
    {
        $this->etat = EtatInterdictionBancaire::from($etat)->value;
    }

    public function dateIncidentDePaiement(): \DateTimeImmutable
    {
        return $this->dateIncidentDePaiement;
    }

    public function echeanceDuDelaiDePresentation(): \DateTimeImmutable
    {
        return $this->echeanceDuDelaiDePresentation;
    }

    public function rangDeLInjonction(): int
    {
        return $this->rangDeLInjonction;
    }

    public function datePaiementOuProvision(): ?\DateTimeImmutable
    {
        return $this->datePaiementOuProvision;
    }

    public function enregistrerPaiementOuProvision(\DateTimeImmutable $date): void
    {
        $this->paiementOuProvisionConstituee = true;
        $this->datePaiementOuProvision = $date->modify('midnight');
    }

    public function enregistrerPenaliteArticle314(): void
    {
        $this->penaliteArticle314Payee = true;
    }

    // ───────── Prédicats appelés par les gardes du YAML ─────────

    /** Les deux conditions de l'art. 313 sont réunies. Cumulatives. */
    public function deuxConditionsDeRegularisationReunies(): bool
    {
        return $this->paiementOuProvisionConstituee && $this->penaliteArticle314Payee;
    }

    public function paiementOuProvisionConstituee(): bool
    {
        return $this->paiementOuProvisionConstituee;
    }

    public function penaliteArticle314Payee(): bool
    {
        return $this->penaliteArticle314Payee;
    }

    /**
     * Le taux de la pénalité de l'art. 314 applicable, en points de base
     * (50 = 0,5 %), pour éviter tout flottant sur un pourcentage légal.
     *
     * Barème selon le rang de l'injonction : 0,5 % / 1 % / 1,5 % pour la
     * troisième et les suivantes. Plancher 500 DH, plafond 50 000 DH — qui
     * appartiennent à CETTE pénalité et non à l'amende de 2 % de
     * l'art. 325 al. 1, laquelle n'a ni plancher ni plafond (LOI.md § 4.4).
     *
     * ⚠ Aucune phrase du produit ne doit comparer ce barème à l'ancien. L'ancien
     * article 314 imprime 1 % / 10 % / 20 % dans l'édition officielle du Code,
     * tandis que la doctrine courante cite 5 % / 10 % / 20 % ; le point n'est
     * pas tranché (LOI.md § 4.6). Écrire « les pénalités ont été divisées par
     * dix » reposerait sur un chiffre contesté.
     */
    public function tauxPenaliteArticle314EnPointsDeBase(): int
    {
        return match (true) {
            1 === $this->rangDeLInjonction => 50,
            2 === $this->rangDeLInjonction => 100,
            default => 150,
        };
    }

    /** Plancher de la pénalité de l'art. 314, en centimes : 500 DH. */
    public const int PLANCHER_PENALITE_314_EN_CENTIMES = 50_000;

    /** Plafond de la pénalité de l'art. 314, en centimes : 50 000 DH. */
    public const int PLAFOND_PENALITE_314_EN_CENTIMES = 5_000_000;
}
