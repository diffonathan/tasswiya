<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LieuEmission;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le chèque impayé.
 *
 * Deux décisions de modèle qui portent tout le reste :
 *
 *  1. LES MONTANTS SONT EN CENTIMES, en entiers. Un `float` sur une assiette
 *     d'amende produit des écarts d'arrondi qu'on retrouverait affichés à
 *     l'utilisateur comme un décompte. Ce logiciel n'affiche pas de décompte
 *     (LOI.md § 6, H7), mais même une estimation ne doit pas dériver.
 *
 *  2. LE MANQUANT EST NULLABLE, ET IL LE RESTE. L'article 325 al. 1 et
 *     l'article 314 posent tous deux une assiette alternative — « du montant
 *     du chèque OU du manquant » (الخصاص). Mais en pratique, l'attestation
 *     bancaire se borne à dire que la provision est inexistante ou
 *     insuffisante SANS CHIFFRER LE DÉCOUVERT (juge Saïd Bouttouil, source
 *     unique mais signée et spécialisée — degré PROBABLE).
 *
 *     Conséquence refusée : retomber silencieusement sur le montant du chèque
 *     quand le manquant est inconnu, ce qui SURESTIMERAIT l'amende.
 *     Conséquence retenue : l'assiette est `indeterminee`, aucun montant
 *     n'est calculé, et l'écran dit pourquoi (LOI.md § 6, H6).
 */
#[ORM\Entity]
#[ORM\Table(name: 'cheque')]
class Cheque
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Référence du titre, manifestement fictive.
     *
     * Volontairement libre et non contrainte à un format de numéro de chèque
     * marocain : un format plausible inviterait à y saisir un vrai numéro.
     */
    #[ORM\Column(type: Types::STRING, length: 40)]
    #[Assert\NotBlank]
    private string $referenceFictive;

    #[ORM\Column(type: Types::BIGINT)]
    #[Assert\Positive]
    private int $montantEnCentimes;

    /**
     * Montant du manquant en centimes, en cas de provision partielle.
     *
     * `null` signifie « non chiffré par le certificat de refus », et c'est le
     * cas le plus fréquent. `null` ne signifie PAS « provision nulle » : ces
     * deux situations se distinguent par {@see $provisionTotalementAbsente}.
     */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $manquantEnCentimes = null;

    /**
     * Vrai lorsque le certificat atteste une provision inexistante, par
     * opposition à insuffisante.
     *
     * Si la provision est totalement absente, le manquant est égal au montant
     * du chèque et l'assiette est déterminée sans qu'on ait eu besoin d'un
     * chiffre. C'est le seul cas où l'on peut conclure sans le certificat
     * chiffré, et il mérite son champ pour cette raison.
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $provisionTotalementAbsente = false;

    /**
     * Date d'émission PORTÉE SUR LE CHÈQUE, et non date de remise au banquier.
     * C'est le point de départ de l'horloge H1 (art. 268, non modifié).
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateEmissionPorteeSurLeCheque;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: LieuEmission::class)]
    private LieuEmission $lieuEmission;

    public function __construct(
        string $referenceFictive,
        int $montantEnCentimes,
        \DateTimeImmutable $dateEmissionPorteeSurLeCheque,
        LieuEmission $lieuEmission = LieuEmission::MAROC,
    ) {
        $this->id = Uuid::v7();
        $this->referenceFictive = $referenceFictive;
        $this->montantEnCentimes = $montantEnCentimes;
        $this->dateEmissionPorteeSurLeCheque = $dateEmissionPorteeSurLeCheque->modify('midnight');
        $this->lieuEmission = $lieuEmission;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function referenceFictive(): string
    {
        return $this->referenceFictive;
    }

    public function montantEnCentimes(): int
    {
        return $this->montantEnCentimes;
    }

    public function dateEmissionPorteeSurLeCheque(): \DateTimeImmutable
    {
        return $this->dateEmissionPorteeSurLeCheque;
    }

    public function lieuEmission(): LieuEmission
    {
        return $this->lieuEmission;
    }

    /** Durée de l'horloge H1, en jours : 20 au Maroc, 60 depuis l'étranger. */
    public function joursDePresentation(): int
    {
        return $this->lieuEmission->joursDePresentation();
    }

    public function declarerProvisionTotalementAbsente(): void
    {
        $this->provisionTotalementAbsente = true;
        // Provision nulle : le manquant est le montant entier. On l'écrit,
        // parce que l'assiette devient alors déterminée sans certificat chiffré.
        $this->manquantEnCentimes = $this->montantEnCentimes;
    }

    public function chiffrerLeManquant(int $manquantEnCentimes): void
    {
        if ($manquantEnCentimes <= 0 || $manquantEnCentimes > $this->montantEnCentimes) {
            throw new \InvalidArgumentException(
                'Le manquant est strictement positif et ne peut excéder le montant du chèque.'
            );
        }
        $this->manquantEnCentimes = $manquantEnCentimes;
        $this->provisionTotalementAbsente = false;
    }

    public function manquantEnCentimes(): ?int
    {
        return $this->manquantEnCentimes;
    }

    /**
     * Vrai si une assiette d'amende peut être déterminée.
     *
     * Faux dans le cas courant du certificat qui ne chiffre pas le découvert :
     * c'est alors `null` qui doit remonter jusqu'à l'écran, pas un montant
     * approché.
     */
    public function assietteEstDeterminee(): bool
    {
        return null !== $this->manquantEnCentimes;
    }

    /**
     * L'assiette, en centimes, ou `null` si elle est indéterminée.
     *
     * @param bool $reductibleAuManquant faux pour l'amende du chèque de
     *                                   garantie (art. 316 dernier bloc), dont le texte dit « de la valeur du
     *                                   chèque » SANS « ou du manquant » — deux amendes de 2 % différentes
     */
    public function assietteEnCentimes(bool $reductibleAuManquant): ?int
    {
        if (!$reductibleAuManquant) {
            return $this->montantEnCentimes;
        }

        return $this->manquantEnCentimes;
    }
}
