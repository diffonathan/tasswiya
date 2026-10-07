<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Domaine\Horloge\CalculateurDHorloges;
use App\Entity\Dossier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Ce qui doit être écrit une fois qu'une transition A ÉTÉ franchie.
 *
 * Pourquoi `completed` et non `transition` : l'événement `transition` est
 * émis AVANT que le nouvel état soit posé, et `completed` après. Marquer une
 * prorogation comme accordée avant que la machine ait changé d'état
 * avancerait le compteur sur une transition que la suite du traitement peut
 * encore faire échouer — et le quota serait consommé par une prorogation qui
 * n'a jamais eu lieu.
 */
final readonly class EcouteurDeTransitionsAppliquees
{
    public function __construct(
        private CalculateurDHorloges $horloges,
    ) {
    }

    /**
     * La prorogation en attente devient la prorogation accordée.
     *
     * C'est cette écriture, et elle seule, qui fait avancer le compteur lu par
     * `subject.quotaProlongationsEpuise()`. Les gardes ayant déjà vérifié la
     * décision du parquet, l'accord du bénéficiaire et la durée plancher, ce
     * qu'on acte ici est une prorogation complète.
     */
    #[AsEventListener(event: 'workflow.dossier_penal.completed.proroger_delai')]
    public function surProrogationAccordee(CompletedEvent $evenement): void
    {
        $dossier = $evenement->getSubject();
        \assert($dossier instanceof Dossier);

        $dossier->prolongationEnAttente()?->marquerAccordee($this->horloges->aujourdHui());
    }
}
