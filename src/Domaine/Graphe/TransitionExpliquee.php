<?php

declare(strict_types=1);

namespace App\Domaine\Graphe;

use App\Domaine\Certitude\RegleAppliquee;
use App\Enum\EtatDossier;
use App\Enum\TransitionDossier;

/**
 * Une transition du graphe, avec ce qui l'ouvre ou ce qui la barre.
 *
 * ─── CE QUE CET OBJET RÉPARE ───────────────────────────────────────────────
 *
 * Le composant Workflow refuse une transition gardée par une expression YAML
 * avec UN SEUL bloqueur, dont le code est
 * `blocked_by_expression_guard_listener` et le message « The transition has
 * been blocked by a guard ». Pour une garde comme celle de `proroger_delai`,
 * qui conjugue cinq conditions, ce bloqueur unique ne dit pas LAQUELLE a
 * échoué. Un écran qui se contenterait de le relayer grise un bouton en
 * silence — exactement ce que la contrainte n° 1 du projet interdit.
 *
 * {@see ExplicateurDuGraphe} évalue donc les conditions une par une et les
 * rattache au {@see \App\Workflow\CatalogueDesBlocages}, qui porte pour
 * chacune son article, son degré de certitude et son renvoi à `LOI.md`.
 *
 * ─── QUI DÉCIDE, ET QUI EXPLIQUE ───────────────────────────────────────────
 *
 * {@see $franchissable} vient de `WorkflowInterface::can()`, et de rien
 * d'autre. L'explication est à côté, jamais à la place : la machine à états
 * reste la seule autorité sur ce qui est possible, et cet objet ne fait que
 * le mettre en mots. Si les deux divergeaient, ce serait un défaut — et c'est
 * pourquoi {@see coherente()} existe et qu'une commande l'imprime.
 */
final readonly class TransitionExpliquee
{
    /**
     * @param list<RegleAppliquee> $reglesOpposees  les règles qui barrent la
     *                                              transition, dédoublonnées par code de blocage
     * @param string|null          $habilitationManquante attribut du voter qui manque, ou `null`.
     *                                              Distinct des règles opposées : une habilitation absente n'est ni
     *                                              une règle de droit ni une hypothèse de calcul, et l'afficher avec
     *                                              un degré de certitude lui donnerait une autorité qu'elle n'a pas
     */
    public function __construct(
        public TransitionDossier $transition,
        public EtatDossier $vers,
        public bool $franchissable,
        public array $reglesOpposees = [],
        public ?string $habilitationManquante = null,
        public bool $declencheeParLeTemps = false,
        public bool $irreversible = false,
        /*
         * ─── POURQUOI CE CHAMP EXISTE ─────────────────────────────────────
         *
         * Les gardes de `proroger_delai` interrogent LA PROROGATION EN
         * ATTENTE : décision du parquet enregistrée, accord du bénéficiaire
         * enregistré, durée au moins égale au délai initial. Quand aucune
         * demande n'est en attente, les trois prédicats rendent faux, et
         * l'écran affichait alors « l'accord du bénéficiaire n'est pas
         * enregistré » sous un dossier DÉJÀ PROROGÉ — ce qui se lit comme si
         * la prorogation acquise était incomplète.
         *
         * Le préalable manquant est donc nommé AVANT les règles : les trois
         * conditions portent sur une demande qui n'existe pas encore, et le
         * dire change le sens de ce qui suit.
         */
        public ?string $prealableManquant = null,
    ) {
    }

    public function libelle(): string
    {
        return LibellesDuGraphe::transition($this->transition);
    }

    public function libelleDeLaDestination(): string
    {
        return LibellesDuGraphe::etat($this->vers);
    }

    /** Vrai si quelque chose, règle ou habilitation, s'oppose à la transition. */
    public function barree(): bool
    {
        return [] !== $this->reglesOpposees || null !== $this->habilitationManquante;
    }

    /**
     * Vrai si l'explication est cohérente avec la décision de la machine à
     * états.
     *
     * L'invariant : une transition impossible a au moins un motif, et une
     * transition possible n'en a aucun. C'est le seul garde-fou contre la
     * dérive de deux endroits qui savent la même chose — et il est
     * vérifiable, pas déclaratif : `tasswiya:verifier-les-ecrans` l'imprime
     * pour tous les dossiers chargés.
     */
    public function coherente(): bool
    {
        return $this->franchissable !== $this->barree();
    }

    /**
     * Vrai si au moins une des règles opposées exige une alerte plutôt qu'une
     * conclusion (degré INCERTAIN, `LOI.md` § 0).
     */
    public function exigeUneAlerte(): bool
    {
        foreach ($this->reglesOpposees as $regle) {
            if ($regle->exigeUneAlerte()) {
                return true;
            }
        }

        return false;
    }
}
