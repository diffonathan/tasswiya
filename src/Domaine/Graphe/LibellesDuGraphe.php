<?php

declare(strict_types=1);

namespace App\Domaine\Graphe;

use App\Enum\EtatDossier;
use App\Enum\EtatInterdictionBancaire;
use App\Enum\TransitionDossier;

/**
 * Ce que l'écran écrit à la place des noms techniques du graphe.
 *
 * ─── POURQUOI CE N'EST PAS DANS LES ÉNUMÉRATIONS ───────────────────────────
 *
 * Parce que `EtatDossier` et `TransitionDossier` sont le vocabulaire du
 * DOMAINE, et que leur valeur est le marquage persisté de la machine à états.
 * Un libellé d'écran n'a pas la même durée de vie qu'un nom d'état : il se
 * reformule quand une relecture le trouve ambigu, tandis qu'un nom d'état
 * figuré en base ne se change pas sans migration. Les séparer évite qu'une
 * retouche de formulation touche un fichier dont dépend le contenu de la
 * colonne `etat`.
 *
 * ─── LA RÈGLE DE RÉDACTION, QUI N'EST PAS DÉCORATIVE ───────────────────────
 *
 * Aucun libellé ne porte de conclusion que le droit ne porte pas. En
 * particulier :
 *
 *  - « délai expiré » et jamais « dossier perdu » : l'expiration fait tomber
 *    l'obstacle procédural à la poursuite, elle n'éteint aucun droit, et le
 *    paiement plus l'amende de 2 % éteignent encore l'action publique
 *    (art. 325 al. 1) ;
 *  - « prorogation » et jamais « prolongation accordée par la loi » : la
 *    prorogation est une décision du ministère public (art. 325 al. 8) ;
 *  - « justification familiale retenue » et jamais « affaire classée » :
 *    l'action civile de la partie lésée reste ouverte.
 */
final class LibellesDuGraphe
{
    /** Le nom d'un état du dossier, tel qu'il s'affiche. */
    public static function etat(EtatDossier $etat): string
    {
        return match ($etat) {
            EtatDossier::CHEQUE_EMIS => 'Chèque émis',
            EtatDossier::IMPAYE_CONSTATE => 'Impayé constaté',
            EtatDossier::PLAINTE_DEPOSEE => 'Plainte déposée',
            EtatDossier::CONVOCATION_SANS_EFFET => 'Convocation sans effet',
            EtatDossier::ECEDAR_NOTIFIE => 'Écédar notifié — délai en cours',
            EtatDossier::DELAI_PROLONGE => 'Délai prorogé',
            EtatDossier::DELAI_EXPIRE => 'Délai expiré',
            EtatDossier::POURSUITE_ENGAGEE => 'Poursuite engagée',
            EtatDossier::CONDAMNATION_DEFINITIVE => 'Condamnation définitive',
            EtatDossier::DESISTEMENT_OU_TRANSACTION_ACTE => 'Désistement ou transaction acté',
            EtatDossier::ACTION_PUBLIQUE_ETEINTE => 'Action publique éteinte',
            EtatDossier::PEINE_EFFACEE => 'Peine effacée',
            EtatDossier::REHABILITATION_OUVERTE => 'Réhabilitation ouverte',
            EtatDossier::JUSTIFICATION_FAMILIALE_RETENUE => 'Justification familiale retenue',
        };
    }

    /**
     * Ce que l'état veut dire, en une phrase. Affiché sous la place courante.
     *
     * Volontairement redondant avec la documentation de `EtatDossier` : le
     * lecteur de l'écran n'a pas le code sous les yeux, et c'est pour lui que
     * la phrase est écrite.
     */
    public static function expliquerLEtat(EtatDossier $etat): string
    {
        return match ($etat) {
            EtatDossier::CHEQUE_EMIS => 'Le chèque est émis. Seule l\'horloge du délai de présentation '
                .'au paiement court (art. 268, non modifié).',
            EtatDossier::IMPAYE_CONSTATE => 'L\'incident de paiement est constaté et le certificat de '
                .'refus est au dossier (art. 309). Aucun délai pénal ne court : il n\'y a pas encore '
                .'d\'écédar.',
            EtatDossier::PLAINTE_DEPOSEE => 'Le parquet est saisi. L\'écédar n\'est pas notifié, donc '
                .'le délai de trente jours n\'a pas de point de départ.',
            EtatDossier::CONVOCATION_SANS_EFFET => 'Le tireur est introuvable ou ne défère pas à la '
                .'convocation. Aucun écédar n\'a pu être notifié : le délai de trente jours NE COURT '
                .'PAS. L\'article 325 n\'organise pas ce cas.',
            EtatDossier::ECEDAR_NOTIFIE => 'L\'écédar est notifié sous forme d\'interrogatoire par un '
                .'officier de police judiciaire. Le délai de trente jours court depuis cette date — et '
                .'le tireur est simultanément sous contrôle judiciaire : ce n\'est pas un délai de grâce.',
            EtatDossier::DELAI_PROLONGE => 'Le ministère public a prorogé le délai après accord du '
                .'bénéficiaire (art. 325 al. 8). Le contrôle judiciaire CONTINUE pendant la prorogation.',
            EtatDossier::DELAI_EXPIRE => 'Le délai est expiré : l\'obstacle procédural à la poursuite est '
                .'tombé. Le dossier n\'est pas perdu — le paiement ou le désistement, plus l\'amende de '
                .'2 %, éteignent encore l\'action publique (art. 325 al. 1).',
            EtatDossier::POURSUITE_ENGAGEE => 'L\'action publique est mise en mouvement, l\'écédar '
                .'préalable étant acquis (art. 325 al. 6).',
            EtatDossier::CONDAMNATION_DEFINITIVE => 'La décision est passée en force de chose jugée. À ce '
                .'stade DEUX amendes se cumulent : les 2 % et celle de l\'article 316 al. 1 (art. 325 al. 2).',
            EtatDossier::DESISTEMENT_OU_TRANSACTION_ACTE => 'Le désistement ou la transaction est acté, et '
                .'on ne peut pas y revenir (art. 325 al. 10). L\'état n\'est pas terminal : l\'extinction '
                .'exige EN PLUS le versement de l\'amende de 2 %.',
            EtatDossier::ACTION_PUBLIQUE_ETEINTE => 'L\'action publique est éteinte (art. 325 al. 1). '
                .'Les deux conditions cumulatives sont réunies.',
            EtatDossier::PEINE_EFFACEE => 'L\'exécution de la peine privative de liberté a pris fin et ses '
                .'effets sont effacés (art. 325 al. 2).',
            EtatDossier::REHABILITATION_OUVERTE => 'La réhabilitation judiciaire est ouverte, les deux '
                .'amendes ayant été payées (art. 325 al. 3).',
            EtatDossier::JUSTIFICATION_FAMILIALE_RETENUE => 'Cause de justification familiale : « ni '
                .'infraction ni peine » (art. 325 al. 4 et 5). Terminal AU PÉNAL seulement — l\'action '
                .'civile de la partie lésée reste ouverte.',
        };
    }

    /** Le rôle visuel de l'état : réglé, perdu, ou en cours. */
    public static function tonDeLEtat(EtatDossier $etat): string
    {
        return match ($etat) {
            // Vert : ce qui est réglé. L'état final favorable, et lui seul.
            EtatDossier::ACTION_PUBLIQUE_ETEINTE,
            EtatDossier::PEINE_EFFACEE,
            EtatDossier::REHABILITATION_OUVERTE,
            EtatDossier::JUSTIFICATION_FAMILIALE_RETENUE => 'regle',

            // Rouge : ce qui est perdu. C'est le signal que la démonstration
            // fait apparaître au seul passage du temps.
            EtatDossier::DELAI_EXPIRE,
            EtatDossier::POURSUITE_ENGAGEE,
            EtatDossier::CONDAMNATION_DEFINITIVE => 'perdu',

            default => 'en-cours',
        };
    }

    /** Le nom d'une transition, à l'infinitif, tel qu'il s'affiche. */
    public static function transition(TransitionDossier $transition): string
    {
        return match ($transition) {
            TransitionDossier::CONSTATER_IMPAYE => 'Constater l\'impayé',
            TransitionDossier::DEPOSER_PLAINTE => 'Déposer plainte',
            TransitionDossier::RETENIR_JUSTIFICATION_FAMILIALE => 'Retenir la justification familiale',
            TransitionDossier::CONSTATER_CONVOCATION_SANS_EFFET => 'Constater la convocation sans effet',
            TransitionDossier::NOTIFIER_ECEDAR => 'Notifier l\'écédar',
            TransitionDossier::PROROGER_DELAI => 'Proroger le délai',
            TransitionDossier::EXPIRER_DELAI => 'Expirer le délai',
            TransitionDossier::ENGAGER_POURSUITE => 'Engager la poursuite',
            TransitionDossier::PRONONCER_CONDAMNATION_DEFINITIVE => 'Prononcer la condamnation définitive',
            TransitionDossier::ACTER_DESISTEMENT => 'Acter le désistement',
            TransitionDossier::ACTER_TRANSACTION => 'Acter la transaction',
            TransitionDossier::ETEINDRE_ACTION_PUBLIQUE => 'Éteindre l\'action publique',
            TransitionDossier::EFFACER_PEINE => 'Effacer la peine',
            TransitionDossier::OUVRIR_REHABILITATION => 'Ouvrir la réhabilitation',
        };
    }

    /** L'état de l'interdiction bancaire, tel qu'il s'affiche. */
    public static function etatDeLInterdiction(EtatInterdictionBancaire $etat): string
    {
        return match ($etat) {
            EtatInterdictionBancaire::ACTIVE => 'Interdiction active',
            EtatInterdictionBancaire::PURGEE => 'Régularisée — effets purgés',
            EtatInterdictionBancaire::EXPIREE => 'Expirée par écoulement des cinq ans',
        };
    }

    /**
     * Le nom lisible d'une habilitation du {@see \App\Security\Voter\DossierVoter}.
     *
     * Sert à expliquer un refus d'habilitation sans afficher une constante en
     * majuscules à un utilisateur.
     */
    public static function habilitation(string $attribut): string
    {
        return match ($attribut) {
            'DOSSIER_NOTIFIER_ECEDAR' => 'notifier l\'écédar (greffe)',
            'DOSSIER_ACCORDER_PROLONGATION' => 'donner l\'accord du bénéficiaire à la prorogation (bénéficiaire seul)',
            'DOSSIER_ENREGISTRER_DESISTEMENT' => 'enregistrer le désistement (bénéficiaire seul)',
            default => $attribut,
        };
    }
}
