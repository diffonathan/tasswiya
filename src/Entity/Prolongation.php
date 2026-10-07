<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TypePieceJustificative;
use App\Repository\ProlongationRepository;
use App\Validator\DureeProlongationLegale;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Une prorogation du délai de régularisation pénale (art. 325 al. 8).
 *
 * Entité et non simple compteur sur le dossier, pour trois raisons tirées du
 * texte lui-même :
 *
 *  1. La prorogation a DEUX CONDITIONS CUMULATIVES ET ASYMÉTRIQUES — la
 *     décision du ministère public et l'accord du bénéficiaire — qui
 *     arrivent à des moments différents et par des pièces différentes.
 *     Un entier `nombreDeProlongations` ne pourrait pas porter l'état
 *     intermédiaire « accord donné, décision attendue ».
 *
 *  2. Elle a une DURÉE PROPRE, « égale ou supérieure » au délai initial,
 *     sans plafond. Toutes les prorogations d'un dossier n'ont donc pas la
 *     même durée, et l'échéance courante dépend de la somme des durées
 *     effectivement accordées.
 *
 *  3. Le nombre de prorogations n'est pas limité par la loi. Le quota est un
 *     paramètre de ce logiciel. Garder la trace de chaque prorogation,
 *     accordée ou refusée, est ce qui permet d'afficher honnêtement « limite
 *     de ce logiciel atteinte » au lieu de « la loi l'interdit ».
 */
#[ORM\Entity(repositoryClass: ProlongationRepository::class)]
#[ORM\Table(name: 'prolongation')]
#[DureeProlongationLegale]
class Prolongation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Dossier::class, inversedBy: 'prolongations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Dossier $dossier = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $demandeeLe;

    /**
     * Durée demandée, en jours.
     *
     * Validée par {@see DureeProlongationLegale} : plancher au délai initial
     * (art. 325 al. 8, « لمدة مماثلة أو أكثر »), AUCUN plafond. La valeur
     * proposée par défaut est 30 jours, qui est la pratique décrite par la
     * circulaire du parquet — étiquetée comme telle, pas comme la loi.
     *
     * Au-delà de 30 jours : aucun blocage, mais une mention. Voir
     * {@see mentionSiAuDelaDeLaPratiqueDuParquet()}.
     */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $dureeEnJours;

    /**
     * La décision du ministère public (art. 325 al. 8 : « يمكن للنيابة العامة
     * تمديد الأجل »).
     *
     * C'est LA condition que les résumés de presse omettent, en présentant la
     * prorogation comme un accord entre créancier et débiteur. Elle
     * n'appartient à aucun des deux.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateDecisionDuParquet = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $referenceDecisionDuParquetFictive = null;

    /** L'accord du bénéficiaire (art. 325 al. 8 : « بعد موافقة المستفيد »). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateAccordDuBeneficiaire = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $referenceAccordDuBeneficiaireFictive = null;

    /**
     * Date à laquelle la prorogation a été appliquée à la machine à états.
     *
     * Distincte de la date de décision du parquet : une décision peut être
     * saisie puis appliquée plus tard. Tant que ce champ est nul, la
     * prorogation est « en attente » et c'est elle que les gardes examinent.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $accordeeLe = null;

    public function __construct(
        \DateTimeImmutable $demandeeLe,
        int $dureeEnJours,
    ) {
        $this->id = Uuid::v7();
        $this->demandeeLe = $demandeeLe->modify('midnight');
        $this->dureeEnJours = $dureeEnJours;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function dossier(): ?Dossier
    {
        return $this->dossier;
    }

    /** @internal Appelé par {@see Dossier::ajouterProlongation()} pour tenir les deux côtés. */
    public function rattacherA(Dossier $dossier): void
    {
        $this->dossier = $dossier;
    }

    public function demandeeLe(): \DateTimeImmutable
    {
        return $this->demandeeLe;
    }

    public function dureeEnJours(): int
    {
        return $this->dureeEnJours;
    }

    public function enregistrerDecisionDuParquet(
        \DateTimeImmutable $date,
        string $referenceFictive,
    ): void {
        $this->dateDecisionDuParquet = $date->modify('midnight');
        $this->referenceDecisionDuParquetFictive = $referenceFictive;
        $this->dossier?->ajouterEvenement(
            TypePieceJustificative::DECISION_PARQUET_DE_PROROGATION,
            $date,
            $referenceFictive,
        );
    }

    public function enregistrerAccordDuBeneficiaire(
        \DateTimeImmutable $date,
        string $referenceFictive,
    ): void {
        $this->dateAccordDuBeneficiaire = $date->modify('midnight');
        $this->referenceAccordDuBeneficiaireFictive = $referenceFictive;
        $this->dossier?->ajouterEvenement(
            TypePieceJustificative::ACCORD_DU_BENEFICIAIRE,
            $date,
            $referenceFictive,
        );
    }

    public function decisionDuParquetEnregistree(): bool
    {
        return null !== $this->dateDecisionDuParquet;
    }

    public function accordDuBeneficiaireEnregistre(): bool
    {
        return null !== $this->dateAccordDuBeneficiaire;
    }

    public function estAccordee(): bool
    {
        return null !== $this->accordeeLe;
    }

    public function accordeeLe(): ?\DateTimeImmutable
    {
        return $this->accordeeLe;
    }

    /**
     * Marque la prorogation comme appliquée.
     *
     * Appelé par l'écouteur de la transition `proroger_delai`, après que les
     * gardes ont été franchies — jamais avant, sinon le compteur avancerait
     * sur une prorogation refusée.
     */
    public function marquerAccordee(\DateTimeImmutable $date): void
    {
        $this->accordeeLe = $date->modify('midnight');
    }

    /**
     * La mention à afficher si la durée demandée dépasse la pratique décrite
     * par la circulaire du parquet.
     *
     * Pourquoi une mention et pas un blocage : la loi et la circulaire ne
     * disent pas la même chose, et les deux sont des faits. L'art. 325 al. 8
     * ouvre « une durée égale ou supérieure » sans plafond ; la circulaire du
     * parquet écrit « 30 jours supplémentaires », point. Une circulaire ne
     * peut pas réduire ce que la loi ouvre, mais c'est elle qui décrit le
     * comportement observable du système.
     *
     * Conduite retenue : la loi commande la garde, la circulaire commande la
     * valeur par défaut et cette mention. Et jamais de plafond à 60 jours,
     * qui n'est écrit nulle part (LOI.md § 4.12).
     */
    public function mentionSiAuDelaDeLaPratiqueDuParquet(): ?string
    {
        if ($this->dureeEnJours <= self::DUREE_PROPOSEE_PAR_DEFAUT_EN_JOURS) {
            return null;
        }

        return \sprintf(
            'Durée de %d jours : au-delà de la pratique décrite par la circulaire de la Présidence '
            .'du ministère public (apparemment n° 2/2026 du 3 février 2026, copie tierce — référence '
            .'de degré probable), qui évoque 30 jours supplémentaires. Conforme à l\'article 325 al. 8, '
            .'qui ouvre une durée « égale ou supérieure » sans aucun plafond.',
            $this->dureeEnJours,
        );
    }

    /**
     * Valeur proposée par défaut dans l'interface : 30 jours.
     *
     * Reflète la pratique du parquet, pas une limite légale. À afficher avec
     * l'étiquette « pratique du parquet », jamais « loi 71-24 ».
     */
    public const int DUREE_PROPOSEE_PAR_DEFAUT_EN_JOURS = 30;
}
