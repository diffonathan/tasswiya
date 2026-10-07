<?php

declare(strict_types=1);

namespace App\Domaine\Urgence;

use App\Domaine\Horloge\CalculateurDHorloges;
use App\Domaine\Horloge\Horloge;
use App\Entity\Dossier;

/**
 * Classe les dossiers par URGENCE RÉELLE, et non par date de création.
 *
 * ═══ POURQUOI CE CLASSEMENT N'EST PAS UNE REQUÊTE SQL ═════════════════════
 *
 * Parce qu'aucune échéance n'est persistée. Les cinq horloges de `LOI.md` § 2
 * se recalculent depuis des FAITS DATÉS — date de l'écédar, date de
 * l'injonction bancaire, date de l'incident — la durée initiale et la somme
 * des prorogations accordées, selon des règles de calcul substituables
 * ({@see \App\Domaine\Horloge\ReglesDeDelaiInterface}).
 *
 * Un `ORDER BY date_limite ASC` exigerait donc une colonne `date_limite`, et
 * cette colonne figerait chaque dossier sous les hypothèses de calcul du jour
 * où il a été écrit : une correction de l'hypothèse H1 ne repasserait jamais
 * sur les dossiers anciens, et le tri mentirait d'autant plus qu'il
 * paraîtrait rapide.
 *
 * Le tri se fait donc en PHP, sur l'horloge recalculée. C'est un choix de
 * correction contre une optimisation, et il est assumé : le coût est linéaire
 * en nombre de dossiers affichés, et une liste de dossiers d'un cabinet se
 * compte en centaines, pas en millions. Le jour où elle se compterait en
 * millions, la bonne réponse serait une colonne dénormalisée RECALCULÉE à
 * chaque changement d'hypothèse, pas un tri approché.
 *
 * ═══ CE QUE LE TRI DIT, ET QUI EST LA LEÇON DE L'ÉCRAN ════════════════════
 *
 * Deux dossiers ouverts le même jour peuvent n'avoir aucune échéance commune,
 * parce que leurs horloges ne partent pas des mêmes événements. Un dossier au
 * stade de la plainte n'a AUCUN délai de trente jours — pas une échéance
 * lointaine, pas d'échéance du tout — tant que l'écédar n'est pas notifié.
 * Le classement le fait voir sans un mot d'explication.
 */
final readonly class ClassementParUrgence
{
    public function __construct(
        private CalculateurDHorloges $horloges,
    ) {
    }

    /**
     * @param iterable<Dossier> $dossiers
     *
     * @return list<UrgenceDuDossier>
     */
    public function classer(iterable $dossiers): array
    {
        $urgences = [];
        foreach ($dossiers as $dossier) {
            $urgences[] = $this->urgenceDe($dossier);
        }

        usort($urgences, static function (UrgenceDuDossier $a, UrgenceDuDossier $b): int {
            // D'abord la bande : ce qui presse avant ce qui est passé, ce qui
            // est passé avant ce qui est clos.
            if ($a->rangDeLaBande() !== $b->rangDeLaBande()) {
                return $a->rangDeLaBande() <=> $b->rangDeLaBande();
            }

            // Dans « presse », le plus petit nombre de jours restants d'abord.
            // Dans « passée », l'échéance la plus RÉCEMMENT dépassée d'abord,
            // c'est-à-dire le nombre négatif le plus proche de zéro : un délai
            // échu avant-hier se rattrape, un délai échu il y a trois ans non.
            // Les deux sont le même ordre croissant sur les jours restants.
            $joursA = $a->joursRestants ?? \PHP_INT_MAX;
            $joursB = $b->joursRestants ?? \PHP_INT_MAX;
            if ($joursA !== $joursB) {
                return $joursA <=> $joursB;
            }

            // À égalité, la référence : un ordre stable et prévisible vaut
            // mieux qu'un ordre qui change d'un rafraîchissement à l'autre.
            return $a->dossier->reference() <=> $b->dossier->reference();
        });

        return $urgences;
    }

    /**
     * L'urgence d'un dossier : quelle horloge presse, et de combien.
     *
     * L'horloge retenue est celle dont il reste le MOINS de jours parmi
     * celles qui courent encore. Si toutes sont échues, c'est celle qui l'est
     * le moins depuis longtemps — parce qu'elle est la seule sur laquelle il
     * reste quelque chose à comprendre.
     */
    public function urgenceDe(Dossier $dossier): UrgenceDuDossier
    {
        $aujourdHui = $this->horloges->aujourdHui();
        $toutes = $this->horloges->toutesLesHorloges($dossier);

        $enCours = [];
        $echues = [];
        foreach ($toutes as $horloge) {
            \assert($horloge instanceof Horloge);
            $restants = $horloge->joursRestants($aujourdHui);
            if ($horloge->estDepassee($aujourdHui)) {
                $echues[] = [$horloge, $restants];
            } else {
                $enCours[] = [$horloge, $restants];
            }
        }

        $etat = $dossier->etat();

        if ([] !== $enCours) {
            usort($enCours, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

            return new UrgenceDuDossier(
                dossier: $dossier,
                // Un état pénal terminal ferme la bande MÊME SI une horloge
                // bancaire court encore : rien ne presse au pénal, et la
                // ligne continuera d'afficher l'horloge qui court. Classer
                // une action publique éteinte sous « ce qui presse » ferait
                // perdre à cette bande le seul sens qu'elle a.
                bande: $etat->estTerminal() ? UrgenceDuDossier::BANDE_CLOSE : UrgenceDuDossier::BANDE_PRESSE,
                horlogePressante: $enCours[0][0],
                joursRestants: $enCours[0][1],
                toutesLesHorloges: $toutes,
                etatTerminalAuPenal: $etat->estTerminal(),
                sousControleJudiciaire: $etat->impliqueUnControleJudiciaire(),
            );
        }

        if ([] !== $echues) {
            // L'échéance la plus récemment dépassée : le négatif le plus
            // proche de zéro, donc le maximum.
            usort($echues, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

            return new UrgenceDuDossier(
                dossier: $dossier,
                bande: $etat->estTerminal() ? UrgenceDuDossier::BANDE_CLOSE : UrgenceDuDossier::BANDE_PASSEE,
                horlogePressante: $echues[0][0],
                joursRestants: $echues[0][1],
                toutesLesHorloges: $toutes,
                etatTerminalAuPenal: $etat->estTerminal(),
                sousControleJudiciaire: $etat->impliqueUnControleJudiciaire(),
            );
        }

        // Aucune horloge du tout. En pratique impossible — H1 existe dès
        // qu'un chèque existe — mais le cas est écrit plutôt que supposé :
        // un classement qui plante sur une liste vide est un classement qui
        // plante le matin où il sert.
        return new UrgenceDuDossier(
            dossier: $dossier,
            bande: UrgenceDuDossier::BANDE_CLOSE,
            horlogePressante: null,
            joursRestants: null,
            toutesLesHorloges: [],
            etatTerminalAuPenal: $etat->estTerminal(),
            sousControleJudiciaire: $etat->impliqueUnControleJudiciaire(),
        );
    }

    /**
     * Le compte par bande, pour l'en-tête de la liste.
     *
     * Existe pour la discipline des chiffres : « 3 dossiers pressent » doit
     * se lire sur l'écran qui l'affiche, pas se calculer de tête.
     *
     * @param list<UrgenceDuDossier> $urgences
     *
     * @return array{presse: int, passee: int, close: int}
     */
    public function compterParBande(array $urgences): array
    {
        $comptes = ['presse' => 0, 'passee' => 0, 'close' => 0];
        foreach ($urgences as $urgence) {
            ++$comptes[$urgence->bande];
        }

        return $comptes;
    }
}
