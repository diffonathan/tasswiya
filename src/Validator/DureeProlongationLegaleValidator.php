<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\Prolongation;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Le validateur de {@see DureeProlongationLegale}.
 *
 * Un plancher, et rien d'autre. Chercher ici un plafond serait chercher une
 * règle que le texte ne contient pas.
 */
final class DureeProlongationLegaleValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof DureeProlongationLegale) {
            throw new UnexpectedValueException($constraint, DureeProlongationLegale::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof Prolongation) {
            throw new UnexpectedValueException($value, Prolongation::class);
        }

        $dossier = $value->dossier();
        if (null === $dossier) {
            $this->context->buildViolation($constraint->messageDossierAbsent)
                ->atPath('dureeEnJours')
                ->addViolation();

            return;
        }

        $plancher = $dossier->dureeDelaiInitialEnJours();
        if ($value->dureeEnJours() < $plancher) {
            $this->context->buildViolation($constraint->messageDureeInsuffisante)
                ->setParameter('{{ duree }}', (string) $value->dureeEnJours())
                ->setParameter('{{ plancher }}', (string) $plancher)
                ->atPath('dureeEnJours')
                ->addViolation();
        }

        // Aucune vérification de maximum. Volontairement. Voir le commentaire
        // de classe de DureeProlongationLegale.
    }
}
