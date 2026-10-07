<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DegreCertitude;
use App\Enum\TypePieceJustificative;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Un fait daté du dossier, avec la pièce qui le prouve.
 *
 * LA RÈGLE QUE CETTE CLASSE FAIT RESPECTER : pas de date saisie sans son
 * événement, et pas d'événement sans sa pièce (LOI.md § 5 point 2).
 *
 * Elle n'est pas un journal d'audit décoratif. Les horloges partent de faits
 * précis — la date de l'écédar, celle de l'injonction bancaire — et chaque
 * fait a, dans la procédure réelle, un document qui l'atteste. L'écédar se
 * prouve par un procès-verbal d'audition et pas autrement, parce qu'il prend
 * la forme d'un interrogatoire par un officier de police judiciaire
 * (art. 325 al. 7) : il n'y a ni huissier ni lettre recommandée dans ce
 * dispositif. Faire partir un délai de trente jours d'une date saisie à la
 * main, sans pièce, c'est inventer un point de départ.
 *
 * Et le type de pièce porte son propre degré de certitude : le contenu exact
 * du certificat de refus de paiement est INCERTAIN, parce que le modèle est
 * fixé par la circulaire BAM n° 5/G/97 dont le flux PDF n'est pas
 * extractible. L'écran doit le dire là où il affiche cette pièce.
 */
#[ORM\Entity]
#[ORM\Table(name: 'evenement_dossier')]
#[ORM\Index(name: 'idx_evenement_type', columns: ['type_piece'])]
class EvenementDossier
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Dossier::class, inversedBy: 'evenements')]
    #[ORM\JoinColumn(nullable: false)]
    private Dossier $dossier;

    #[ORM\Column(type: Types::STRING, length: 48, enumType: TypePieceJustificative::class)]
    private TypePieceJustificative $typePiece;

    /**
     * La date du FAIT, telle qu'elle figure sur la pièce.
     *
     * À ne pas confondre avec la date de saisie : c'est elle qui fait partir
     * une horloge, et elle peut être antérieure de plusieurs mois au jour où
     * on enregistre le dossier.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $survenuLe;

    /** Référence de la pièce. Fictive, comme tout le reste des données. */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $referencePieceFictive;

    public function __construct(
        Dossier $dossier,
        TypePieceJustificative $typePiece,
        \DateTimeImmutable $survenuLe,
        string $referencePieceFictive,
    ) {
        $this->id = Uuid::v7();
        $this->dossier = $dossier;
        $this->typePiece = $typePiece;
        $this->survenuLe = $survenuLe->modify('midnight');
        $this->referencePieceFictive = $referencePieceFictive;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function dossier(): Dossier
    {
        return $this->dossier;
    }

    public function typePiece(): TypePieceJustificative
    {
        return $this->typePiece;
    }

    public function survenuLe(): \DateTimeImmutable
    {
        return $this->survenuLe;
    }

    public function referencePieceFictive(): string
    {
        return $this->referencePieceFictive;
    }

    /**
     * Le degré de certitude du CONTENU de la pièce.
     *
     * Remonte jusqu'à l'écran : une pièce dont le modèle officiel n'a pas pu
     * être lu s'affiche avec son avertissement, pas comme une certitude.
     */
    public function degreDeCertitudeDuContenu(): DegreCertitude
    {
        return $this->typePiece->degreDeCertitudeDuContenu();
    }
}
