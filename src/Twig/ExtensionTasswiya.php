<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domaine\Graphe\LibellesDuGraphe;
use App\Domaine\Horloge\Horloge;
use App\Domaine\Certitude\RegleAppliquee;
use App\Domaine\Certitude\ReglesDAffichage;
use App\Domaine\Horloge\PresentationDesHorloges;
use App\Domaine\Horloge\ReglesDeDelaiInterface;
use App\Enum\EtatDossier;
use App\Enum\EtatInterdictionBancaire;
use App\Enum\TransitionDossier;
use App\Workflow\CatalogueDesBlocages;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Le pont entre les libellés d'écran et les gabarits.
 *
 * ═══ POURQUOI UNE EXTENSION ET NON DES VARIABLES PASSÉES PAR LE CONTRÔLEUR ═
 *
 * Parce que ces libellés sont demandés DANS DES BOUCLES, sur des objets que le
 * contrôleur ne connaît qu'en vrac : les cinq horloges d'un dossier, les
 * quatorze places du graphe, les transitions sortantes. Préparer ces textes
 * dans le contrôleur reviendrait à y construire des tableaux parallèles
 * indexés par code, puis à les parcourir en Twig en espérant que les index
 * correspondent. La première ligne décalée passerait inaperçue.
 *
 * Et parce que la logique d'affichage n'appartient pas au contrôleur : son
 * travail est de réunir des objets du domaine, pas de choisir des mots.
 *
 * ═══ CE QUE CETTE EXTENSION NE FAIT PAS ═══════════════════════════════════
 *
 * Aucune décision. Elle ne calcule aucun délai, n'évalue aucune garde, ne
 * compare aucune date. Chaque fonction est un `match` sur une énumération ou
 * sur un code d'horloge, et son contenu est dans
 * {@see LibellesDuGraphe} ou {@see PresentationDesHorloges}, où il est
 * commenté. Une extension Twig qui se mettrait à calculer serait un endroit
 * de plus où le métier vivrait, et le seul qu'aucun test ne couvrirait.
 */
final class ExtensionTasswiya extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            // ─── Les horloges ────────────────────────────────────────────
            // Le point de départ NOMMÉ : c'est la fonction la plus importante
            // de ce fichier. Un délai affiché sans son événement de départ est
            // l'erreur que ce projet existe pour ne pas commettre.
            new TwigFunction('point_de_depart', [$this, 'pointDeDepart']),
            new TwigFunction('duree_de_lhorloge', [$this, 'dureeDeLHorloge']),
            new TwigFunction('enjeu_de_lhorloge', [$this, 'enjeuDeLHorloge']),
            new TwigFunction('horloge_du_projet', [$this, 'horlogeDuProjet']),
            new TwigFunction('raisons_dabsence', [$this, 'raisonsDAbsence']),
            new TwigFunction('regle_de_comptage', [$this, 'regleDeComptage']),

            // ─── Le graphe d'états ───────────────────────────────────────
            new TwigFunction('libelle_etat', [$this, 'libelleEtat']),
            new TwigFunction('explication_etat', [$this, 'explicationEtat']),
            new TwigFunction('ton_etat', [$this, 'tonEtat']),
            new TwigFunction('libelle_transition', [$this, 'libelleTransition']),
            new TwigFunction('libelle_etat_interdiction', [$this, 'libelleEtatInterdiction']),
            new TwigFunction('libelle_habilitation', [$this, 'libelleHabilitation']),

            // ─── Les règles affichées hors blocage ───────────────────────
            // Elles passent par le MÊME objet `RegleAppliquee` que les
            // blocages, donc par le même fragment Twig, donc avec leur degré
            // de certitude. Une phrase écrite à la main dans un gabarit
            // n'aurait ni degré, ni source, ni garde-fou — et c'est par là
            // qu'« instruction du ministère public » devient « loi 71-24 ».
            new TwigFunction('regle_affichee', [$this, 'regleAffichee']),
            new TwigFunction('regle_de_blocage', [$this, 'regleDeBlocage']),
        ];
    }

    public function pointDeDepart(string $code): string
    {
        return PresentationDesHorloges::pointDeDepart($code);
    }

    public function dureeDeLHorloge(Horloge $horloge): string
    {
        return PresentationDesHorloges::duree($horloge);
    }

    public function enjeuDeLHorloge(string $code): string
    {
        return PresentationDesHorloges::enjeu($code);
    }

    public function horlogeDuProjet(string $code): bool
    {
        return PresentationDesHorloges::estLHorlogeDuProjet($code);
    }

    /** @return array<string, string> */
    public function raisonsDAbsence(): array
    {
        return PresentationDesHorloges::raisonDUneAbsence();
    }

    public function regleDeComptage(ReglesDeDelaiInterface $regles): RegleAppliquee
    {
        return PresentationDesHorloges::regleDeComptageDesJours($regles);
    }

    public function regleAffichee(string $nom): RegleAppliquee
    {
        return ReglesDAffichage::par($nom);
    }

    public function regleDeBlocage(string $code): RegleAppliquee
    {
        return CatalogueDesBlocages::regle($code);
    }

    public function libelleEtat(EtatDossier $etat): string
    {
        return LibellesDuGraphe::etat($etat);
    }

    public function explicationEtat(EtatDossier $etat): string
    {
        return LibellesDuGraphe::expliquerLEtat($etat);
    }

    public function tonEtat(EtatDossier $etat): string
    {
        return LibellesDuGraphe::tonDeLEtat($etat);
    }

    public function libelleTransition(TransitionDossier $transition): string
    {
        return LibellesDuGraphe::transition($transition);
    }

    public function libelleEtatInterdiction(EtatInterdictionBancaire $etat): string
    {
        return LibellesDuGraphe::etatDeLInterdiction($etat);
    }

    public function libelleHabilitation(string $attribut): string
    {
        return LibellesDuGraphe::habilitation($attribut);
    }
}
