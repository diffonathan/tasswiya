<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Les noms de transitions de la machine `dossier_penal`.
 *
 * Une énumération et non des chaînes libres, pour une raison vérifiable : le
 * nom écrit dans `config/packages/workflow.yaml` et le nom passé à
 * `WorkflowInterface::apply()` doivent être le même, et un test peut
 * comparer les deux listes. Une faute de frappe sur un nom de transition se
 * traduit sinon par une exception au moment précis où l'on filme.
 */
enum TransitionDossier: string
{
    case CONSTATER_IMPAYE = 'constater_impaye';
    case DEPOSER_PLAINTE = 'deposer_plainte';
    case RETENIR_JUSTIFICATION_FAMILIALE = 'retenir_justification_familiale';
    case CONSTATER_CONVOCATION_SANS_EFFET = 'constater_convocation_sans_effet';
    case NOTIFIER_ECEDAR = 'notifier_ecedar';
    case PROROGER_DELAI = 'proroger_delai';
    case EXPIRER_DELAI = 'expirer_delai';
    case ENGAGER_POURSUITE = 'engager_poursuite';
    case PRONONCER_CONDAMNATION_DEFINITIVE = 'prononcer_condamnation_definitive';
    case ACTER_DESISTEMENT = 'acter_desistement';
    case ACTER_TRANSACTION = 'acter_transaction';
    case ETEINDRE_ACTION_PUBLIQUE = 'eteindre_action_publique';
    case EFFACER_PEINE = 'effacer_peine';
    case OUVRIR_REHABILITATION = 'ouvrir_rehabilitation';

    /**
     * Vrai si la transition est déclenchée par le seul passage du temps, et
     * non par l'acte d'un utilisateur.
     *
     * Sert de liste blanche au Scheduler : il n'a le droit de pousser que
     * celles-là. Rien d'automatique ne doit pouvoir éteindre une action
     * publique ni acter un désistement à la place d'un homme.
     */
    public function estDeclencheeParLeTemps(): bool
    {
        return self::EXPIRER_DELAI === $this;
    }

    /**
     * Vrai si la transition est irréversible — aucune transition de retour
     * n'existe dans le graphe, et c'est un choix de droit et non d'ergonomie.
     *
     * Art. 325 al. 10 : on ne peut revenir sur la transaction ni sur le
     * désistement. L'extinction et la réhabilitation s'ensuivent.
     * (La troisième irréversibilité de LOI.md § 5 point 6, la régularisation
     * bancaire qui « purge tous les effets », appartient à l'autre machine.)
     */
    public function estIrreversible(): bool
    {
        return match ($this) {
            self::ACTER_DESISTEMENT,
            self::ACTER_TRANSACTION,
            self::ETEINDRE_ACTION_PUBLIQUE,
            self::OUVRIR_REHABILITATION,
            self::RETENIR_JUSTIFICATION_FAMILIALE => true,
            default => false,
        };
    }
}
