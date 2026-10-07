<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domaine\Horloge\CalculateurDHorloges;
use App\Domaine\Urgence\ClassementParUrgence;
use App\Repository\DossierRepository;
use App\Security\Voter\DossierVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * LA LISTE DES DOSSIERS — ce qu'un créancier ou un avocat ouvre le matin.
 *
 * ═══ CE QUE CET ÉCRAN DOIT FAIRE VOIR AVANT TOUT TEXTE ════════════════════
 *
 * Pas le tableau : CE QUI PRESSE. L'écran est donc organisé en trois bandes
 * — ce qui presse, ce dont l'échéance est passée, ce qui est clos — et non en
 * une grille triée par date de création. Un tri par date classerait un
 * dossier de l'an dernier où plus rien ne court avant un dossier d'avant-hier
 * où il reste deux jours.
 *
 * ═══ ET LA LEÇON QUE LE TRI ENSEIGNE SANS UN MOT ══════════════════════════
 *
 * Chaque ligne nomme L'HORLOGE qui la rend urgente et son POINT DE DÉPART.
 * C'est la contrainte la plus dure de l'écran : afficher « 3 jours » sans
 * dire de quoi est l'erreur que fait toute la presse sur ce sujet. Trois
 * jours avant l'expiration du délai de présentation au paiement (art. 268) et
 * trois jours avant l'expiration du délai de régularisation pénale (art. 325
 * al. 6) ne demandent ni la même action, ni au même acteur.
 *
 * Et un dossier au stade de la plainte apparaît SANS délai de trente jours —
 * pas avec une échéance lointaine, mais sans échéance du tout, parce que
 * l'écédar n'est pas notifié et que les trente jours partent de là.
 *
 * ═══ POURQUOI L'HABILITATION N'EST PAS EXIGÉE POUR ENTRER ICI ═════════════
 *
 * `config/packages/security.yaml` déclare un fournisseur d'utilisateurs EN
 * MÉMOIRE ET VIDE : ce projet n'a pas d'authentification, et c'est écrit tel
 * quel dans son fichier de sécurité. Exiger `DOSSIER_VOIR` rendrait donc 403
 * sur tous les écrans, puisque {@see \App\Security\ResolveurDeRoleParCourriel}
 * répond `AUCUN` sans utilisateur — et une démonstration qui refuse l'accès à
 * tout ne démontre rien.
 *
 * Ce n'est pas pour autant un contournement du voter, et la différence se
 * voit à l'écran : le dossier affiche LE RÔLE RÉSOLU et, pour chaque
 * transition, dit si c'est une règle ou une habilitation qui la barre. Les
 * gardes `is_granted(...)` de `workflow.yaml` sont, elles, pleinement
 * appliquées — aucune transition habilitée n'est franchissable ici.
 */
final class DossiersController extends AbstractController
{
    #[Route('/dossiers', name: 'dossiers_liste', methods: ['GET'])]
    public function __invoke(
        DossierRepository $dossiers,
        ClassementParUrgence $classement,
        CalculateurDHorloges $horloges,
        ClockInterface $horloge,
    ): Response {
        // `findAll()` et non une requête triée : le tri se fait sur des
        // échéances RECALCULÉES, que la base ne connaît pas (voir
        // ClassementParUrgence). Trier en SQL exigerait de persister une date
        // limite, ce que `Dossier` refuse par construction.
        $urgences = $classement->classer($dossiers->findAll());

        return $this->render('dossier/liste.html.twig', [
            'urgences' => $urgences,
            'comptes' => $classement->compterParBande($urgences),
            'aujourdHui' => $horloges->aujourdHui(),
            // Le nom de la classe d'horloge, parce que le décalage de la
            // démonstration déplace TOUTES les échéances de cet écran : un
            // tableau de délais qui ne dirait pas de quel présent il parle
            // serait un piège.
            'horlogeDeDemonstration' => $horloge instanceof \App\Domaine\Horloge\HorlogeDeDemonstration
                && $horloge->temporiseePourLaDemonstration() ? $horloge : null,
            'reglesDeDelai' => $horloges->reglesDeDelai(),
            'attributVoir' => DossierVoter::VOIR,
        ]);
    }
}
