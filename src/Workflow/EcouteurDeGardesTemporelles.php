<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Domaine\Horloge\CalculateurDHorloges;
use App\Domaine\Horloge\Horloge;
use App\Entity\Dossier;
use App\Entity\InterdictionBancaire;
use App\Enum\TransitionDossier;
use App\Enum\TransitionInterdictionBancaire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * Les gardes qui dépendent du TEMPS, et elles seules.
 *
 * POURQUOI ELLES NE SONT PAS DANS `workflow.yaml`, et c'est la question qu'un
 * recruteur Symfony posera en premier.
 *
 * Le langage d'expressions du composant Workflow donne accès à `subject`, à
 * `is_granted()` et à `is_valid()`. Il ne donne pas accès à l'horloge. Pour
 * écrire `subject.delaiExpire()` dans le YAML, il faudrait que l'entité
 * appelle elle-même `new \DateTimeImmutable()` — c'est-à-dire exactement ce
 * que ce projet refuse, puisque c'est ce qui rend un délai intestable et une
 * démonstration impossible.
 *
 * Le partage est donc net, et il se défend :
 *  - dans le YAML, tout ce qui est un ÉTAT DU SUJET : accord enregistré,
 *    décision du parquet, amende payée, cas pénal, lien familial, quota. Ce
 *    sont des expressions pures, lisibles par qui ne connaît pas le code ;
 *  - ici, tout ce qui demande à savoir QUEL JOUR ON EST. L'horloge est
 *    injectée, donc substituable, donc la démonstration peut la pousser de
 *    trente et un jours et les tests de cinq ans.
 *
 * Et le refus porte sa source : chaque `TransitionBlocker` reçoit un code du
 * {@see CatalogueDesBlocages}, et l'interface affiche la règle avec son
 * article et son degré, au lieu d'un « transition non autorisée » muet.
 */
final readonly class EcouteurDeGardesTemporelles
{
    public function __construct(
        private CalculateurDHorloges $horloges,
    ) {
    }

    /**
     * LA GARDE DU MOMENT FILMÉ, vue de l'autre côté.
     *
     * `expirer_delai` n'est franchissable QUE si l'échéance est dépassée.
     * Aucun bouton, aucun appel d'API, aucun rôle ne peut forcer un dossier en
     * « délai expiré » avant le terme : la seule chose qui ouvre cette
     * transition est le passage du temps. C'est ce qui fait que, dans la
     * démonstration, pousser l'horloge de trente et un jours suffit — et
     * c'est ce qui fait qu'avancer de vingt-neuf jours ne suffit pas.
     */
    #[AsEventListener(event: 'workflow.dossier_penal.guard.expirer_delai')]
    public function surExpirationDuDelai(GuardEvent $evenement): void
    {
        $dossier = $evenement->getSubject();
        \assert($dossier instanceof Dossier);

        if (!$this->horloges->delaiPenalExpire($dossier)) {
            $this->bloquer($evenement, CatalogueDesBlocages::DELAI_PENAL_NON_EXPIRE);
        }
    }

    /**
     * On ne proroge pas un délai déjà expiré.
     *
     * L'article 325 al. 8 permet au parquet de proroger « le délai prévu à
     * l'alinéa sixième » : un délai, donc, et non une forclusion. La question
     * de savoir si le parquet peut proroger rétroactivement n'est tranchée
     * par personne — aucune jurisprudence n'est publiée sur le nouvel
     * article 325 — et ce logiciel refuse plutôt que d'inventer la réponse.
     */
    #[AsEventListener(event: 'workflow.dossier_penal.guard.proroger_delai')]
    public function surProrogationDuDelai(GuardEvent $evenement): void
    {
        $dossier = $evenement->getSubject();
        \assert($dossier instanceof Dossier);

        if ($this->horloges->delaiPenalExpire($dossier)) {
            $this->bloquer($evenement, CatalogueDesBlocages::DELAI_PENAL_DEJA_EXPIRE);
        }
    }

    /**
     * La fenêtre de quatre ans après le divorce (art. 325 al. 5).
     *
     * Garde temporelle parce qu'elle compare deux dates, mais sa référence
     * n'est PAS « aujourd'hui » : c'est la date des faits. La cause de
     * justification joue ou ne joue pas au moment de l'infraction, et la
     * mesurer depuis le jour de la consultation ferait disparaître une
     * justification acquise par le simple écoulement de l'instruction.
     */
    #[AsEventListener(event: 'workflow.dossier_penal.guard.retenir_justification_familiale')]
    public function surJustificationFamiliale(GuardEvent $evenement): void
    {
        $dossier = $evenement->getSubject();
        \assert($dossier instanceof Dossier);

        $lien = $dossier->lienFamilialTireurBeneficiaire();
        if (!$lien->exigeLaFenetreDeQuatreAns()) {
            return;
        }

        if (null === $dossier->dateDissolutionDuMariage()) {
            $this->bloquer($evenement, CatalogueDesBlocages::DATE_DE_DISSOLUTION_INCONNUE);

            return;
        }

        if (!$this->horloges->fenetreDeQuatreAnsApresDivorceOuverte($dossier)) {
            $this->bloquer($evenement, CatalogueDesBlocages::FENETRE_DE_QUATRE_ANS_FERMEE);
        }
    }

    /**
     * La fenêtre de deux ans pour régulariser auprès de la banque
     * (art. 313 nouveau, horloge H4).
     *
     * Son point de départ est l'ÉCHÉANCE d'une autre horloge, celle du délai
     * de présentation — ce qu'un champ de date limite unique n'aurait jamais
     * pu exprimer.
     */
    #[AsEventListener(event: 'workflow.interdiction_bancaire.guard.regulariser')]
    public function surRegularisationBancaire(GuardEvent $evenement): void
    {
        $interdiction = $evenement->getSubject();
        \assert($interdiction instanceof InterdictionBancaire);

        $echeance = Horloge::h4FaculteDEmettre($interdiction->echeanceDuDelaiDePresentation());

        $dateExaminee = $interdiction->datePaiementOuProvision() ?? $this->horloges->aujourdHui();

        if ($dateExaminee > $echeance->echeance) {
            $this->bloquer($evenement, CatalogueDesBlocages::FENETRE_DE_REGULARISATION_BANCAIRE_FERMEE);
        }
    }

    /** Les cinq ans de l'interdiction bancaire (art. 312 et 313 nouveaux, horloge H5). */
    #[AsEventListener(event: 'workflow.interdiction_bancaire.guard.expirer')]
    public function surExpirationDeLInterdiction(GuardEvent $evenement): void
    {
        $interdiction = $evenement->getSubject();
        \assert($interdiction instanceof InterdictionBancaire);

        if (!$this->horloges->interdictionBancaireExpiree($interdiction)) {
            $this->bloquer($evenement, CatalogueDesBlocages::INTERDICTION_BANCAIRE_NON_EXPIREE);
        }
    }

    /**
     * Bloque la transition avec un motif qui porte sa règle, son article et
     * son degré de certitude.
     *
     * Le code du blocage est stable : l'interface s'en sert pour griser le
     * bon bouton et choisir la bonne info-bulle, sans avoir à lire le message.
     */
    private function bloquer(GuardEvent $evenement, string $codeDeBlocage): void
    {
        $regle = CatalogueDesBlocages::regle($codeDeBlocage);
        $evenement->addTransitionBlocker(
            new TransitionBlocker($regle->enonce, $codeDeBlocage, [
                'article' => $regle->article,
                'degre' => $regle->degre->value,
                'attribution' => $regle->attribution(),
                'exige_une_alerte' => $regle->exigeUneAlerte(),
                'renvoi_loi_md' => $regle->renvoiLoiMd,
            ])
        );
    }

    /**
     * Noms de transitions, exposés pour que les tests vérifient que les
     * écouteurs ci-dessus visent des transitions qui existent réellement.
     *
     * @return list<string>
     */
    public static function transitionsGardeesTemporellement(): array
    {
        return [
            TransitionDossier::EXPIRER_DELAI->value,
            TransitionDossier::PROROGER_DELAI->value,
            TransitionDossier::RETENIR_JUSTIFICATION_FAMILIALE->value,
            TransitionInterdictionBancaire::REGULARISER->value,
            TransitionInterdictionBancaire::EXPIRER->value,
        ];
    }
}
