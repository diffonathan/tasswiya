<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Domaine\Certitude\RegleAppliquee;
use App\Entity\Dossier;
use App\Enum\TransitionDossier;
use App\Repository\DossierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Le service qui fait basculer les dossiers dont le délai a expiré.
 *
 * C'EST LUI QUE LA DÉMONSTRATION APPELLE après avoir poussé l'horloge de
 * trente et un jours, et c'est lui que le Scheduler appelle en production.
 * Les deux chemins passent par le même code : la démonstration ne prend
 * aucun raccourci que la production n'aurait pas.
 *
 * TROIS CHOSES QU'IL NE FAIT PAS, et chacune est une décision.
 *
 *  1. Il ne décide RIEN. Il demande à la machine à états si la transition
 *     `expirer_delai` est possible, et la garde temporelle répond. Si ce
 *     service calculait lui-même l'expiration, il existerait deux endroits
 *     qui savent quand un délai expire — et un jour ils divergeraient.
 *
 *  2. Il ne touche qu'à des transitions déclenchées par le temps. La liste
 *     blanche est dans {@see TransitionDossier::estDeclencheeParLeTemps()}.
 *     Rien d'automatique ne doit pouvoir éteindre une action publique, acter
 *     un désistement ou engager une poursuite : ce sont des actes humains.
 *
 *  3. Il n'avale pas les collisions. Un `OptimisticLockException` est
 *     journalisé et le dossier est laissé pour la prochaine passe : si le
 *     greffe enregistrait une prorogation à la seconde même où la passe
 *     tourne, écraser son écriture ferait expirer un délai qui venait d'être
 *     prorogé.
 */
final readonly class ExpirateurDeDelais
{
    public function __construct(
        /*
         * `dossierPenalStateMachine` et non `dossier_penal` : le bundle
         * enregistre un alias d'autowiring nommé d'après le nom de la machine
         * en casse chameau, suffixé de `StateMachine` pour un
         * `type: state_machine` (et de `Workflow` pour un `type: workflow`).
         * Passer le nom brut du YAML laisse le conteneur sans correspondance,
         * et l'erreur ne se voit qu'au moment du câblage.
         */
        #[Target('dossierPenalStateMachine')]
        private WorkflowInterface $machineDuDossier,
        private DossierRepository $dossiers,
        private EntityManagerInterface $gestionnaireDEntites,
        private LoggerInterface $journal,
    ) {
    }

    /**
     * Fait expirer tous les délais échus, et rend le compte de ce qui a basculé.
     *
     * @return array{examines: int, bascules: int, collisions: int}
     *
     * Le détail du compte est rendu pour que la commande qui l'appelle puisse
     * l'imprimer : aucun chiffre de ce projet ne doit être publié sans la
     * commande qui le produit.
     */
    public function faireExpirerLesDelaisEchus(): array
    {
        $examines = 0;
        $bascules = 0;
        $collisions = 0;

        foreach ($this->dossiers->dossiersDontLeDelaiPenalPeutAvoirExpire() as $dossier) {
            \assert($dossier instanceof Dossier);
            ++$examines;

            // La garde temporelle est la seule autorité sur l'expiration.
            if (!$this->machineDuDossier->can($dossier, TransitionDossier::EXPIRER_DELAI->value)) {
                continue;
            }

            try {
                $this->machineDuDossier->apply($dossier, TransitionDossier::EXPIRER_DELAI->value);
                $this->gestionnaireDEntites->flush();
                ++$bascules;
            } catch (OptimisticLockException) {
                ++$collisions;
                $this->journal->notice(
                    'Dossier modifié pendant la passe d\'expiration : laissé pour la passe suivante.',
                    ['dossier' => $dossier->reference()]
                );
                // On repart d'un gestionnaire propre : après une collision, les
                // entités en mémoire ne reflètent plus la base.
                $this->gestionnaireDEntites->clear();
            }
        }

        return ['examines' => $examines, 'bascules' => $bascules, 'collisions' => $collisions];
    }

    /**
     * Fait expirer le délai d'UN dossier, pour la démonstration.
     *
     * Rend `null` si la bascule a eu lieu, ou la RÈGLE qui s'y oppose — ce qui
     * permet à l'écran de démonstration d'afficher « le délai court encore »
     * au lieu de ne rien dire.
     *
     * ⚠ POURQUOI UNE `RegleAppliquee` ET NON LE MESSAGE DU BLOCAGE.
     *
     * Cette méthode rendait `$blocage->getMessage()`, c'est-à-dire l'énoncé
     * SEUL : l'article, le degré de certitude et le renvoi à `LOI.md` étaient
     * calculés par {@see EcouteurDeGardesTemporelles}, placés dans les
     * paramètres du `TransitionBlocker`… et jetés ici. L'écran recevait donc un
     * refus SANS SA SOURCE, ce qui est exactement ce que la contrainte n° 1 du
     * projet interdit — et ce qu'un test a fini par dire, en constatant que le
     * motif rendu ne contenait pas « 325 ».
     *
     * En rendant la règle, l'écran dispose de l'énoncé, de l'article, du
     * verbatim arabe quand il existe, de l'attribution autorisée et du renvoi
     * au dossier de règles. C'est-à-dire de tout ce qu'il faut pour griser un
     * bouton AVEC son info-bulle, au lieu de le griser en silence.
     */
    public function faireExpirerLeDelaiDe(Dossier $dossier): ?RegleAppliquee
    {
        $blocages = $this->machineDuDossier->buildTransitionBlockerList(
            $dossier,
            TransitionDossier::EXPIRER_DELAI->value
        );

        foreach ($blocages as $blocage) {
            // Le code du blocage, et non son message : c'est lui qui est
            // stable, et c'est par lui que le catalogue retrouve l'article et
            // le degré. Un message se reformule ; un code ne doit pas.
            return CatalogueDesBlocages::regle($blocage->getCode());
        }

        $this->machineDuDossier->apply($dossier, TransitionDossier::EXPIRER_DELAI->value);
        $this->gestionnaireDEntites->flush();

        return null;
    }
}
