<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PartieRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une partie au dossier : tireur ou bénéficiaire.
 *
 * DONNÉES STRICTEMENT FICTIVES. Aucun RIB, aucune CIN, aucun numéro de
 * compte, aucune banque réelle nommée (LOI.md § 6, H8). Les champs qui
 * porteraient ces données n'existent pas dans cette classe, et c'est
 * volontaire : un champ absent ne peut pas être rempli par erreur.
 *
 * Entité au sens data mapper : elle ne sait ni se charger, ni se sauver, ni
 * d'où elle vient. Aucune méthode `save()`, aucun accès statique à une
 * connexion. C'est le contraire de l'ActiveRecord, et c'est l'argument qui se
 * tient à l'oral : la persistance est un service extérieur
 * (`EntityManagerInterface`), l'objet reste du domaine pur et se teste sans
 * base de données.
 */
#[ORM\Entity(repositoryClass: PartieRepository::class)]
#[ORM\Table(name: 'partie')]
class Partie
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** Nom manifestement inventé — contrainte n° 3 du cahier des charges. */
    #[ORM\Column(type: Types::STRING, length: 160)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    private string $nomFictif;

    /**
     * Adresse de courriel servant à rattacher un compte utilisateur à cette
     * partie, et donc à décider ce qu'il a le droit de voir.
     *
     * C'est le seul lien entre le domaine et la sécurité, et il passe par un
     * résolveur dédié ({@see \App\Security\ResolveurDeRoleInterface}) plutôt
     * que par une référence à l'entité utilisateur : le domaine ne doit pas
     * connaître le composant Security.
     */
    #[ORM\Column(type: Types::STRING, length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $courrielDeContact = null;

    public function __construct(string $nomFictif, ?string $courrielDeContact = null)
    {
        $this->id = Uuid::v7();
        $this->nomFictif = $nomFictif;
        $this->courrielDeContact = $courrielDeContact;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function nomFictif(): string
    {
        return $this->nomFictif;
    }

    public function courrielDeContact(): ?string
    {
        return $this->courrielDeContact;
    }
}
