<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domaine\Graphe\ExplicateurDuGraphe;
use App\Domaine\Horloge\CalculateurDHorloges;
use App\Domaine\Horloge\HorlogeDeDemonstration;
use App\Domaine\Montant\EstimateurDeMontants;
use App\Domaine\Urgence\ClassementParUrgence;
use App\Entity\Dossier;
use App\Repository\DossierRepository;
use App\Security\Voter\DossierVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * LE DOSSIER — l'écran du projet.
 *
 * ═══ LES QUATRE CHOSES QU'IL DOIT MONTRER ENSEMBLE ════════════════════════
 *
 * 1. **LES CINQ HORLOGES, avec leur point de départ NOMMÉ.** C'est le critère
 *    de réussite ou d'échec de cet écran. Celle de l'écédar (H3, trente jours,
 *    enjeu : l'action publique) et celle de l'injonction bancaire (H2, trois
 *    mois, enjeu : la pénalité) courent EN PARALLÈLE et ne partent pas du même
 *    événement. L'écart entre le rejet du chèque et l'écédar se compte en
 *    semaines, parfois en mois : un écran qui n'afficherait qu'« un délai »
 *    mentirait, et c'est l'erreur que fait toute la presse sur ce sujet.
 *
 * 2. **LE GRAPHE D'ÉTATS**, avec la place courante et les transitions
 *    possibles. Une transition bloquée est VISIBLEMENT barrée et dit POURQUOI
 *    — avec l'article, le degré de certitude et le renvoi à `LOI.md`. C'est
 *    {@see ExplicateurDuGraphe} qui fournit ces motifs, et
 *    `WorkflowInterface::can()` qui reste seul juge de ce qui est possible.
 *
 * 3. **LE CONTRÔLE JUDICIAIRE EN COURS**, bracelet électronique compris. Ce
 *    n'est pas un détail d'affichage : c'est ce qui distingue ces trente jours
 *    d'un délai de grâce. L'article 325 al. 7 est rédigé à l'impératif, et le
 *    contrôle CONTINUE pendant la prorogation. Un écran qui annoncerait
 *    « 30 jours pour régulariser » sans cela décrirait mal la situation de son
 *    propre utilisateur débiteur.
 *
 * 4. **LES MONTANTS**, en or — le rôle « valeur » de la charte, et rien
 *    d'autre ne le prend. Et quand le manquant n'est pas chiffré, l'écran ne
 *    retombe PAS sur le montant du chèque : le domaine rend `null` exprès, et
 *    `.montant--indetermine` garde la place sans l'or, parce qu'il n'y a pas
 *    de valeur.
 *
 * ═══ CE QUI N'EST PAS AFFICHÉ, ET POURQUOI ════════════════════════════════
 *
 * Jamais « la loi limite à une prorogation ». Le quota est un CHOIX_PRODUIT,
 * et l'écran l'écrit LÀ OÙ IL BLOQUE, avec le traitement visuel du degré
 * CHOIX_PRODUIT : filet pointillé, grille de points, retrait. L'article 325
 * al. 8 dit « لمدة مماثلة أو أكثر » — durée égale ou supérieure — sans
 * plafonner le nombre.
 */
final class DossierController extends AbstractController
{
    #[Route(
        '/dossiers/{reference}',
        name: 'dossier_detail',
        requirements: ['reference' => '[A-Za-z0-9\-]{3,32}'],
        methods: ['GET'],
    )]
    public function __invoke(
        string $reference,
        DossierRepository $dossiers,
        CalculateurDHorloges $horloges,
        ExplicateurDuGraphe $explicateur,
        EstimateurDeMontants $estimateur,
        ClassementParUrgence $classement,
        DossierVoter $voter,
        ClockInterface $horloge,
    ): Response {
        // `dossierCompletParReference` et non `findOneBy` : les gardes
        // interrogent la prorogation en attente et le journal des pièces, et
        // sans ces jointures le chargement paresseux déclencherait une requête
        // par collection à CHAQUE évaluation de garde. Le graphe d'états se
        // paierait alors en allers-retours vers la base.
        $dossier = $dossiers->dossierCompletParReference($reference);
        if (!$dossier instanceof Dossier) {
            throw $this->createNotFoundException('Aucun dossier fictif sous la référence « '.$reference.' ».');
        }

        return $this->render('dossier/dossier.html.twig', [
            'dossier' => $dossier,
            'etat' => $dossier->etat(),
            'horlogesDuDossier' => $horloges->toutesLesHorloges($dossier),
            'aujourdHui' => $horloges->aujourdHui(),
            'joursRestantsSurLeDelaiPenal' => $horloges->joursRestantsSurLeDelaiPenal($dossier),
            'dureeEffectiveDuDelaiPenal' => $horloges->dureeEffectiveDuDelaiPenalEnJours($dossier),
            'reglesDeDelai' => $horloges->reglesDeDelai(),
            'transitions' => $explicateur->transitionsDepuisLaPlaceCourante($dossier),
            'places' => ExplicateurDuGraphe::placesDansLOrdreDeLaProcedure(),
            'montants' => $estimateur->montantsDu($dossier),
            'urgence' => $classement->urgenceDe($dossier),
            // Le rôle RÉSOLU, et non les droits qu'on souhaiterait avoir :
            // l'écran dit d'où il parle. Sans authentification, c'est
            // `aucun`, et les transitions habilitées sont barrées pour cette
            // raison-là et non pour une règle de droit.
            'roleCourant' => $voter->roleCourantDans($dossier),
            'horlogeDeDemonstration' => $horloge instanceof HorlogeDeDemonstration
                && $horloge->temporiseePourLaDemonstration() ? $horloge : null,
        ]);
    }
}
