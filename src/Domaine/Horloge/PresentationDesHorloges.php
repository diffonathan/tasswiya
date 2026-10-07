<?php

declare(strict_types=1);

namespace App\Domaine\Horloge;

use App\Domaine\Certitude\RegleAppliquee;

/**
 * Ce que l'écran doit écrire à côté de chaque horloge, et que l'objet
 * {@see Horloge} ne porte pas.
 *
 * ═══ POURQUOI CETTE CLASSE EXISTE : LE POINT DE DÉPART A UN NOM ═══════════
 *
 * `Horloge::$depart` est une DATE. Or la contrainte de l'écran du dossier
 * n'est pas d'afficher cinq dates : c'est d'afficher CINQ ÉVÉNEMENTS
 * DIFFÉRENTS. « 15/04/2026 » ne dit rien ; « écédar du 15/04/2026 » dit tout,
 * parce que le lecteur comprend alors que la ligne d'à côté, « injonction
 * bancaire du 12/02/2026 », part d'autre chose — et que les deux délais
 * courent en parallèle depuis deux faits séparés de deux mois.
 *
 * C'est l'erreur que fait toute la presse sur ce sujet : résumer cinq
 * horloges en « un délai de trente jours » et le faire partir du rejet du
 * chèque. L'écart se compte en semaines. Un écran qui afficherait un délai
 * sans nommer son point de départ reproduirait l'erreur en la rendant
 * crédible.
 *
 * ═══ ET POURQUOI LA DURÉE N'EST PAS LUE SUR L'OBJET ═══════════════════════
 *
 * `Horloge::$joursOuMois` ne porte pas son unité : il vaut 20 pour H1 (des
 * jours), 3 pour H2 (des mois), 24 pour H4 et 60 pour H5 (des mois, soit deux
 * et cinq ans). L'afficher tel quel écrirait « 60 » sous une interdiction de
 * cinq ans, et un lecteur pressé lirait soixante jours. L'unité est donc
 * décidée ici, par code d'horloge, et jamais devinée.
 *
 * ═══ L'ENJEU, EN DEUX MOTS ════════════════════════════════════════════════
 *
 * Une colonne courte, pour que la parallélisme se voie d'un coup d'œil sans
 * lire les cinq phrases d'effet : « action publique » et « interdiction
 * d'émettre » ne se jouent pas devant la même autorité, et trois jours sur
 * l'une ne valent pas trois jours sur l'autre.
 */
final class PresentationDesHorloges
{
    /**
     * Le point de départ, NOMMÉ : l'événement, pas la date.
     *
     * Le cas de H4 est le plus instructif du lot : son point de départ n'est
     * pas un fait daté mais L'ÉCHÉANCE D'UNE AUTRE HORLOGE. Aucun champ
     * « date limite » dans une entité n'aurait pu exprimer cela, et c'est
     * l'argument le plus concret contre le champ unique que `Dossier` refuse.
     */
    public static function pointDeDepart(string $code): string
    {
        return match ($code) {
            'H1' => 'Date d\'émission portée sur le chèque — et non la date de remise au banquier',
            'H2' => 'Injonction bancaire (الإنذار), que la banque doit envoyer dans les deux jours '
                .'de l\'incident, pour chaque chèque séparément',
            'H3' => 'Écédar (الإعذار) — mise en demeure prenant la forme d\'un interrogatoire par un '
                .'officier de police judiciaire, sur instruction du parquet. NI la présentation, NI le '
                .'rejet, NI la plainte',
            'H4' => 'Expiration du délai de présentation au paiement — c\'est-à-dire L\'ÉCHÉANCE DE H1, '
                .'et non un fait daté',
            'H5' => 'Incident de paiement (عارض الأداء)',
            default => 'Point de départ non documenté pour l\'horloge '.$code,
        };
    }

    /**
     * L'hypothèse de comptage des jours, affichée comme une hypothèse.
     *
     * ⚠ CE N'EST PAS DU DROIT, et c'est tout l'intérêt de la faire passer par
     * une {@see RegleAppliquee} : elle arrive à l'écran avec le traitement
     * visuel du degré CHOIX_PRODUIT — filet pointillé, grille de points,
     * retrait — au lieu de se fondre dans les règles sourcées.
     *
     * La loi n° 71.24 ne contient AUCUNE clause sur le calcul des délais.
     * Par contraste, la loi 52.23, publiée dans le MÊME bulletin officiel,
     * précise à son article 150 que tous ses délais sont des délais francs.
     * Ce silence est une vraie question pour une machine à états — jour de
     * l'écédar compté ou non, échéance tombant un vendredi, un jour férié ou
     * pendant le Ramadan — et ce logiciel y répond par un choix, affiché
     * comme tel.
     */
    public static function regleDeComptageDesJours(ReglesDeDelaiInterface $regles): RegleAppliquee
    {
        return new RegleAppliquee(
            enonce: $regles->libelleAffiche(),
            article: 'aucun : la loi n° 71.24 ne règle pas le calcul des délais',
            degre: $regles->degre(),
            sourceReelle: 'hypothèse de calcul de Tasswiya, substituable par configuration '
                .'(App\Domaine\Horloge\ReglesDeDelaiInterface, deux implémentations : délais '
                .'calendaires sans report, et délais francs). Le choix d\'exclure le jour de départ '
                .'est le plus favorable au débiteur, donc le moins susceptible de faire manquer un '
                .'droit par excès de zèle.',
            renvoiLoiMd: 'LOI.md § 4.15 et § 6 H1-H2',
        );
    }

    /** La durée, avec son unité, parce que l'objet ne la porte pas. */
    public static function duree(Horloge $horloge): string
    {
        return match ($horloge->code) {
            // H1 et H3 comptent des jours, et le nombre vient du dossier :
            // 20 ou 60 jours de présentation selon le lieu d'émission, et pour
            // H3 le délai initial augmenté des prorogations accordées.
            'H1', 'H3' => $horloge->joursOuMois.' jours',
            'H2' => '3 mois',
            'H4' => '2 ans',
            'H5' => '5 ans',
            default => (string) $horloge->joursOuMois,
        };
    }

    /** L'enjeu, en deux mots, pour la colonne courte du tableau. */
    public static function enjeu(string $code): string
    {
        return match ($code) {
            'H1' => 'Recours cambiaires',
            'H2' => 'Pénalité bancaire',
            'H3' => 'Action publique',
            'H4' => 'Faculté d\'émettre',
            'H5' => 'Interdiction d\'émettre',
            default => '—',
        };
    }

    /**
     * Vrai pour l'horloge dont tout le projet parle : les trente jours de
     * l'article 325 al. 6.
     *
     * Sert à la mettre en avant sur l'écran, et à n'y mettre QU'ELLE : si
     * toutes les lignes étaient soulignées, aucune ne le serait.
     */
    public static function estLHorlogeDuProjet(string $code): bool
    {
        return 'H3' === $code;
    }

    /**
     * Les cinq horloges du dossier de règles, dans l'ordre, avec ce qu'elles
     * sont — y compris celles qui ne courent pas sur un dossier donné.
     *
     * L'écran s'en sert pour dire ce qui MANQUE : une horloge absente n'est
     * pas une horloge à zéro, c'est une horloge SANS POINT DE DÉPART. Un
     * dossier au stade de la plainte n'a pas une échéance pénale lointaine,
     * il n'en a pas du tout — et c'est la seule manière de le faire voir.
     *
     * @return array<string, string> le code en clé, la raison possible de son
     *                               absence en valeur
     */
    public static function raisonDUneAbsence(): array
    {
        return [
            // H1 existe dès qu'un chèque existe : elle n'est jamais absente.
            'H2' => 'Aucune injonction bancaire n\'est enregistrée : cette horloge n\'a pas de point '
                .'de départ. Elle n\'est pas à zéro, elle n\'existe pas.',
            'H3' => 'Aucun écédar n\'est notifié : LE DÉLAI DE TRENTE JOURS NE COURT PAS. Pas une '
                .'échéance lointaine — pas d\'échéance du tout. C\'est le point que la plupart des '
                .'résumés manquent, en faisant partir ce délai du rejet du chèque.',
            'H4' => 'Aucun incident de paiement n\'est constaté : la question de recouvrer la faculté '
                .'d\'émettre ne se pose pas encore.',
            'H5' => 'Aucun incident de paiement n\'est constaté : aucune interdiction d\'émettre n\'a '
                .'commencé à courir.',
        ];
    }
}
