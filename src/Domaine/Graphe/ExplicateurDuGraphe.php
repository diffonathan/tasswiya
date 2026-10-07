<?php

declare(strict_types=1);

namespace App\Domaine\Graphe;

use App\Domaine\Certitude\RegleAppliquee;
use App\Domaine\Horloge\CalculateurDHorloges;
use App\Entity\Dossier;
use App\Enum\EtatDossier;
use App\Enum\TransitionDossier;
use App\Security\Voter\DossierVoter;
use App\Workflow\CatalogueDesBlocages;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Traduit le graphe d'états en quelque chose qu'un écran peut montrer : la
 * place courante, les transitions qui en partent, et pour chacune la RÈGLE
 * qui la barre — avec son article, son degré et son renvoi à `LOI.md`.
 *
 * ═══ POURQUOI CETTE CLASSE EXISTE, ET CE QU'ELLE N'EST PAS ════════════════
 *
 * Elle n'est pas une seconde machine à états. `WorkflowInterface::can()` reste
 * la seule autorité sur ce qui est possible : c'est lui qui remplit
 * {@see TransitionExpliquee::$franchissable}, et aucune méthode de ce fichier
 * ne décide à sa place.
 *
 * Elle existe parce que le composant Workflow refuse une garde YAML composée
 * avec UN bloqueur indifférencié. La garde de `proroger_delai` conjugue cinq
 * conditions ; quand elle refuse, le bloqueur dit « blocked by a guard » et
 * rien de plus. Impossible d'en tirer l'article 325 al. 8, le degré de la
 * règle, ni le fait que le quota de prorogations est un CHOIX DE CE LOGICIEL
 * et non une limite légale. Or c'est précisément ce que l'écran doit dire
 * là où il bloque.
 *
 * ═══ LE RISQUE ASSUMÉ, ET CE QUI LE TIENT ═════════════════════════════════
 *
 * Évaluer les conditions ici les met à DEUX endroits : dans `workflow.yaml`
 * et dans ce fichier. Deux endroits qui savent la même chose finissent par
 * divergir, et c'est une objection sérieuse.
 *
 * Trois choses la contiennent :
 *
 *  1. Les conditions ne sont pas RÉÉCRITES : chaque entrée du tableau
 *     ci-dessous appelle le MÊME prédicat du dossier que le YAML
 *     (`subject.accordBeneficiaireEnregistre()` ici comme là-bas). Ce qui est
 *     dupliqué, c'est la liste des conditions, pas leur contenu.
 *  2. L'invariant est vérifiable : une transition impossible a au moins un
 *     motif, une transition possible n'en a aucun
 *     ({@see TransitionExpliquee::coherente()}). Toute condition oubliée ici
 *     et présente dans le YAML casse l'invariant.
 *  3. Une commande l'imprime sur tous les dossiers chargés et sort en échec
 *     si l'invariant tombe : `tasswiya:verifier-les-ecrans`. La cohérence est
 *     donc un résultat mesuré, pas une intention.
 */
final readonly class ExplicateurDuGraphe
{
    public function __construct(
        #[Target('dossierPenalStateMachine')]
        private WorkflowInterface $machineDuDossier,
        private CalculateurDHorloges $horloges,
        private Security $securite,
    ) {
    }

    /**
     * Toutes les transitions qui partent de la place courante, expliquées.
     *
     * Celles qui ne partent PAS de la place courante sont omises : un écran
     * qui listerait les quatorze transitions du graphe en grisant onze
     * d'entre elles pour « mauvais état de départ » noierait les deux refus
     * qui disent quelque chose.
     *
     * @return list<TransitionExpliquee>
     */
    public function transitionsDepuisLaPlaceCourante(Dossier $dossier): array
    {
        $place = $dossier->etat()->value;
        $expliquees = [];

        foreach ($this->machineDuDossier->getDefinition()->getTransitions() as $transition) {
            if (!\in_array($place, $transition->getFroms(), true)) {
                continue;
            }

            $nom = TransitionDossier::from($transition->getName());
            $destination = EtatDossier::from($transition->getTos()[0]);

            $expliquees[] = new TransitionExpliquee(
                transition: $nom,
                vers: $destination,
                // LA SEULE AUTORITÉ. Tout le reste de cette classe est de la
                // mise en mots.
                franchissable: $this->machineDuDossier->can($dossier, $nom->value),
                reglesOpposees: $this->reglesQuiBarrent($dossier, $nom),
                habilitationManquante: $this->habilitationManquante($dossier, $nom),
                declencheeParLeTemps: $nom->estDeclencheeParLeTemps(),
                irreversible: $nom->estIrreversible(),
                prealableManquant: $this->prealableManquant($dossier, $nom),
            );
        }

        return $expliquees;
    }

    /**
     * Les places du graphe dans l'ordre où la procédure les traverse, pour
     * que l'écran dessine un chemin et non un sac de pastilles.
     *
     * L'ordre n'est pas celui de `workflow.yaml` — un fichier de
     * configuration liste des places, il ne raconte pas une chronologie. Il
     * suit la chronologie réelle de `LOI.md` § 2, avec les issues favorables
     * à la fin.
     *
     * @return list<EtatDossier>
     */
    public static function placesDansLOrdreDeLaProcedure(): array
    {
        return [
            EtatDossier::CHEQUE_EMIS,
            EtatDossier::IMPAYE_CONSTATE,
            EtatDossier::PLAINTE_DEPOSEE,
            EtatDossier::CONVOCATION_SANS_EFFET,
            EtatDossier::ECEDAR_NOTIFIE,
            EtatDossier::DELAI_PROLONGE,
            EtatDossier::DELAI_EXPIRE,
            EtatDossier::POURSUITE_ENGAGEE,
            EtatDossier::CONDAMNATION_DEFINITIVE,
            EtatDossier::DESISTEMENT_OU_TRANSACTION_ACTE,
            EtatDossier::ACTION_PUBLIQUE_ETEINTE,
            EtatDossier::PEINE_EFFACEE,
            EtatDossier::REHABILITATION_OUVERTE,
            EtatDossier::JUSTIFICATION_FAMILIALE_RETENUE,
        ];
    }

    /**
     * Les règles qui s'opposent à la transition, dédoublonnées.
     *
     * Dédoublonnées par code, parce que deux conditions d'une même garde
     * peuvent relever d'une même règle : l'article 325 al. 1 pose DEUX
     * conditions cumulatives — le paiement ou le désistement, et l'amende de
     * 2 % — et c'est un seul énoncé qui les porte. Les afficher deux fois
     * ferait croire à deux obstacles distincts.
     *
     * @return list<RegleAppliquee>
     */
    private function reglesQuiBarrent(Dossier $dossier, TransitionDossier $transition): array
    {
        $codes = [];

        foreach ($this->conditionsDe($dossier, $transition) as $code => $satisfaite) {
            if (!$satisfaite) {
                $codes[$code] = true;
            }
        }

        return array_values(array_map(
            static fn (string $code): RegleAppliquee => CatalogueDesBlocages::regle($code),
            array_keys($codes),
        ));
    }

    /**
     * Les conditions de la transition, chacune sous le code de blocage qui
     * l'explique.
     *
     * Les clés sont les codes du {@see CatalogueDesBlocages} ; les valeurs
     * sont le résultat du MÊME prédicat que `workflow.yaml` appelle, ou de la
     * même garde temporelle. Rien n'est recalculé : les délais passent tous
     * par {@see CalculateurDHorloges}, qui est le seul endroit du projet qui
     * sait quel jour on est.
     *
     * @return array<string, bool>
     */
    private function conditionsDe(Dossier $dossier, TransitionDossier $transition): array
    {
        return match ($transition) {
            TransitionDossier::CONSTATER_IMPAYE => [
                CatalogueDesBlocages::CERTIFICAT_DE_REFUS_MANQUANT => $dossier->certificatDeRefusEnregistre(),
            ],

            TransitionDossier::RETENIR_JUSTIFICATION_FAMILIALE => $this->conditionsDeLaJustificationFamiliale($dossier),

            TransitionDossier::NOTIFIER_ECEDAR => [
                CatalogueDesBlocages::ECEDAR_PREALABLE_MANQUANT => $dossier->ecedarNotifieAvecProcesVerbal(),
                CatalogueDesBlocages::CONTROLE_JUDICIAIRE_MANQUANT => $dossier->controleJudiciaireEnPlace(),
            ],

            /*
             * LA TRANSITION DU MOMENT FILMÉ, vue à l'envers : ce qui la barre.
             * Cinq motifs, dont UN SEUL n'est pas du droit — et c'est celui
             * que l'écran doit signaler comme un choix de ce logiciel.
             */
            TransitionDossier::PROROGER_DELAI => [
                CatalogueDesBlocages::DECISION_PARQUET_NON_ENREGISTREE => $dossier->decisionParquetEnregistree(),
                CatalogueDesBlocages::ACCORD_BENEFICIAIRE_NON_ENREGISTRE => $dossier->accordBeneficiaireEnregistre(),
                CatalogueDesBlocages::BENEFICIAIRE_INDISPONIBLE => $dossier->beneficiaireDisponible(),
                CatalogueDesBlocages::DUREE_PROLONGATION_INSUFFISANTE => $dossier
                    ->dureeProlongationDemandeeAuMoinsEgaleAuDelaiInitial(),
                // Le quota est un PARAMÈTRE PRODUIT : la loi 71.24 ne limite
                // pas le nombre de prorogations (art. 325 al. 8, « لمدة مماثلة
                // أو أكثر »). Le catalogue porte un degré CHOIX_PRODUIT, et
                // c'est ce degré que l'écran affiche là où il bloque.
                CatalogueDesBlocages::QUOTA_PROLONGATIONS_EPUISE => !$dossier->quotaProlongationsEpuise(),
                // Garde temporelle : on ne proroge pas un délai déjà expiré.
                CatalogueDesBlocages::DELAI_PENAL_DEJA_EXPIRE => !$this->horloges->delaiPenalExpire($dossier),
            ],

            /*
             * LA TRANSITION QUE PERSONNE NE DÉCLENCHE. Un seul motif, et ce
             * motif est le temps.
             */
            TransitionDossier::EXPIRER_DELAI => [
                CatalogueDesBlocages::DELAI_PENAL_NON_EXPIRE => $this->horloges->delaiPenalExpire($dossier),
            ],

            TransitionDossier::ENGAGER_POURSUITE => [
                CatalogueDesBlocages::ECEDAR_PREALABLE_MANQUANT => $dossier->ecedarNotifieAvecProcesVerbal(),
            ],

            TransitionDossier::ETEINDRE_ACTION_PUBLIQUE => [
                CatalogueDesBlocages::CAS_PENAL_HORS_CHAMP_DE_L_EXTINCTION => $dossier
                    ->extinctionOuverteParLeCasPenal(),
                // Les deux conditions cumulatives de l'art. 325 al. 1 sous un
                // seul code : c'est un seul énoncé qui les porte.
                CatalogueDesBlocages::PAS_D_EXTINCTION_SANS_AMENDE_DE_DEUX_POUR_CENT => $dossier
                    ->paiementOuDesistementAcquis() && $dossier->amendeDeuxPourCentPayee(),
            ],

            TransitionDossier::EFFACER_PEINE => [
                CatalogueDesBlocages::CAS_PENAL_HORS_CHAMP_DE_L_EXTINCTION => $dossier
                    ->extinctionOuverteParLeCasPenal(),
                CatalogueDesBlocages::AMENDE_ARTICLE_316_NON_PAYEE => $dossier->paiementOuDesistementAcquis()
                    && $dossier->amendeArticle316Payee(),
            ],

            TransitionDossier::OUVRIR_REHABILITATION => [
                CatalogueDesBlocages::LES_DEUX_AMENDES_NON_PAYEES => $dossier->lesDeuxAmendesPayees(),
            ],

            // Transitions sans garde de contenu : `deposer_plainte`,
            // `constater_convocation_sans_effet`,
            // `prononcer_condamnation_definitive`, et les deux actes
            // irréversibles qui ne sont gardés que par une habilitation.
            default => [],
        };
    }

    /**
     * Les conditions de la cause de justification familiale, qui demandent un
     * ordre plutôt qu'une conjonction.
     *
     * La fenêtre de quatre ans de l'art. 325 al. 5 ne joue que pour les
     * ex-époux, et elle est INCALCULABLE sans date de dissolution. Évaluer
     * « fenêtre fermée » sans date afficherait deux motifs là où il n'y en a
     * qu'un, et le second serait faux : la fenêtre n'est pas fermée, on ne
     * sait pas si elle l'est.
     *
     * @return array<string, bool>
     */
    private function conditionsDeLaJustificationFamiliale(Dossier $dossier): array
    {
        $conditions = [
            CatalogueDesBlocages::CAS_PENAL_HORS_CHAMP_DE_LA_JUSTIFICATION_FAMILIALE => $dossier
                ->justificationFamilialeOuverteParLeCas(),
            CatalogueDesBlocages::LIEN_FAMILIAL_HORS_PREMIER_DEGRE => $dossier->lienFamilialDansLeChamp(),
        ];

        if (!$dossier->lienFamilialTireurBeneficiaire()->exigeLaFenetreDeQuatreAns()) {
            return $conditions;
        }

        if (null === $dossier->dateDissolutionDuMariage()) {
            $conditions[CatalogueDesBlocages::DATE_DE_DISSOLUTION_INCONNUE] = false;

            return $conditions;
        }

        $conditions[CatalogueDesBlocages::FENETRE_DE_QUATRE_ANS_FERMEE] = $this->horloges
            ->fenetreDeQuatreAnsApresDivorceOuverte($dossier);

        return $conditions;
    }

    /**
     * Le préalable de FORME qui manque, ou `null` — distinct des règles qui
     * barrent.
     *
     * Les trois conditions légales de la prorogation portent sur UNE DEMANDE
     * EN ATTENTE. Sans demande, les trois prédicats du dossier rendent faux,
     * et les trois règles s'affichent — ce qui, sous un dossier déjà prorogé,
     * se lit comme si la prorogation acquise était incomplète. Nommer le
     * préalable rétablit le sens : il n'y a rien à instruire, et c'est pour
     * cela que les conditions ne sont pas remplies.
     *
     * Ce n'est pas une règle de droit et cela n'a donc pas de degré de
     * certitude : c'est l'état du dossier décrit en une phrase.
     */
    private function prealableManquant(Dossier $dossier, TransitionDossier $transition): ?string
    {
        if (TransitionDossier::PROROGER_DELAI !== $transition) {
            return null;
        }

        if (null !== $dossier->prolongationEnAttente()) {
            return null;
        }

        return 'Aucune demande de prorogation n\'est en attente sur ce dossier. Les conditions de '
            .'l\'article 325 al. 8 ci-dessous portent sur une demande qui n\'existe pas encore : '
            .'elles ne disent rien de la ou des prorogations déjà accordées.';
    }

    /**
     * L'habilitation qui manque pour cette transition, ou `null`.
     *
     * ─── POURQUOI SÉPARÉE DES RÈGLES ──────────────────────────────────────
     *
     * Une habilitation absente n'est ni une règle de droit, ni une hypothèse
     * de calcul : c'est l'état de l'utilisateur, pas celui du dossier. Lui
     * donner un degré de certitude la ferait entrer dans la carte des
     * certitudes, où elle n'a rien à faire.
     *
     * Deux de ces trois habilitations traduisent pourtant une vraie règle de
     * droit — l'accord est celui DU BÉNÉFICIAIRE (art. 325 al. 8), le
     * désistement est celui DU PLAIGNANT — et l'écran le dit à côté. Mais
     * c'est le {@see DossierVoter} qui porte cette règle, et l'écran renvoie
     * à lui plutôt que de la redire.
     */
    private function habilitationManquante(Dossier $dossier, TransitionDossier $transition): ?string
    {
        $attribut = match ($transition) {
            TransitionDossier::NOTIFIER_ECEDAR => DossierVoter::NOTIFIER_ECEDAR,
            TransitionDossier::PROROGER_DELAI => DossierVoter::ACCORDER_PROLONGATION,
            TransitionDossier::ACTER_DESISTEMENT,
            TransitionDossier::ACTER_TRANSACTION => DossierVoter::ENREGISTRER_DESISTEMENT,
            default => null,
        };

        if (null === $attribut) {
            return null;
        }

        return $this->securite->isGranted($attribut, $dossier) ? null : $attribut;
    }
}
