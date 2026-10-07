<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domaine\Horloge\CalculateurDHorloges;
use App\Domaine\Horloge\DecalageDeDemonstration;
use App\Domaine\Horloge\HorlogeDeDemonstration;
use App\Domaine\Urgence\ClassementParUrgence;
use App\Repository\DossierRepository;
use App\Workflow\ExpirateurDeDelais;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * L'HORLOGE DE DÉMONSTRATION — le moment qui doit se voir.
 *
 * ═══ CE QU'ON FILME ══════════════════════════════════════════════════════
 *
 * On pousse l'horloge de trente et un jours. Le dossier bascule tout seul en
 * « délai expiré ». Sur le graphe d'états du dossier, la transition se barre.
 * PERSONNE N'A CLIQUÉ SUR « EXPIRER » — ce bouton n'existe pas, et ne peut
 * pas exister : `expirer_delai` est gardée par
 * {@see \App\Workflow\EcouteurDeGardesTemporelles}, qui ne consulte que
 * l'horloge. Aucun rôle, aucune route, aucun drapeau de ligne de commande ne
 * la force. Avancer de vingt-neuf jours ne fait rien basculer, et c'est la
 * meilleure preuve que la règle est tenue par la machine et non par l'écran.
 *
 * ═══ LES DEUX CHOSES QUE CET ÉCRAN NE FAIT PAS ════════════════════════════
 *
 * 1. **Il n'antidate aucune donnée.** Le dossier ne vieillit pas : c'est le
 *    présent qui avance. La différence est tout le sujet — antidater ferait
 *    MENTIR le dossier, déplacer le présent fait VIEILLIR une situation vraie.
 *    Aucune date du dossier n'est touchée par ce contrôleur, et on peut le
 *    vérifier : il n'écrit qu'un entier dans un fichier.
 *
 * 2. **Il ne décide pas de l'expiration.** Après avoir poussé l'horloge, il
 *    appelle {@see ExpirateurDeDelais}, c'est-à-dire LE MÊME service que la
 *    commande `tasswiya:faire-expirer-les-delais` appelle en production. La
 *    démonstration ne prend donc aucun raccourci que la production n'aurait
 *    pas, et le compte rendu qu'elle affiche est celui que la commande
 *    imprime.
 *
 * ═══ POURQUOI CE N'EST PAS UNE FONCTIONNALITÉ, ET CE QUI LE DIT ═══════════
 *
 * Le décalage est GLOBAL à l'installation : deux visiteurs partagent le même
 * présent. C'est ce qu'on veut pour une démonstration filmée — l'écran et le
 * terminal doivent raconter la même histoire — et c'est inacceptable pour un
 * produit. Ce qui le signale :
 *
 *  - un encart en tête d'écran le dit en toutes lettres ;
 *  - dès que le décalage n'est pas nul, TOUS les écrans portent la mention,
 *    parce que les cinq horloges du produit lisent cette même horloge et que
 *    des échéances déplacées sans mention seraient un piège ;
 *  - on ne peut pas « se placer au 14 mars » : on avance, ou l'on remet à
 *    zéro. Choisir une date permettrait de fabriquer un dossier pour une date
 *    choisie, ce qui est exactement ce qu'un outil de production ferait et
 *    qu'une démonstration ne doit pas pouvoir faire.
 */
final class DemonstrationController extends AbstractController
{
    #[Route('/demonstration', name: 'demonstration', methods: ['GET'])]
    public function horloge(
        ClockInterface $horloge,
        DossierRepository $dossiers,
        ClassementParUrgence $classement,
        CalculateurDHorloges $horloges,
    ): Response {
        $urgences = $classement->classer($dossiers->findAll());
        $delais = $this->delaisPenauxEnCours($dossiers, $horloges);

        return $this->render('demonstration/horloge.html.twig', [
            // CE QUI ENCADRE LA DÉMONSTRATION, calculé sur la donnée et jamais
            // écrit en dur : le dossier le plus proche de son échéance, le
            // plus éloigné, et les sauts qui s'en déduisent.
            'delais' => $delais,
            'sauts' => $this->sautsProposes($delais),
            // `null` quand l'horloge injectée n'est pas celle de la
            // démonstration — en test, le conteneur impose une `MockClock`.
            // L'écran affiche alors ce qu'il voit au lieu de supposer, et les
            // boutons disparaissent : un formulaire qui ne peut rien faire est
            // pire qu'un formulaire absent.
            'horlogeDeDemonstration' => $horloge instanceof HorlogeDeDemonstration ? $horloge : null,
            'classeDHorloge' => $horloge::class,
            'maintenant' => $horloge->now(),
            'aujourdHui' => $horloges->aujourdHui(),
            'decalageMaximal' => DecalageDeDemonstration::DECALAGE_MAXIMAL_EN_JOURS,
            'urgences' => $urgences,
            'reglesDeDelai' => $horloges->reglesDeDelai(),
        ]);
    }

    /**
     * Pousse l'horloge, puis laisse la machine à états faire ce qu'elle a à
     * faire.
     *
     * L'ordre des deux opérations est la démonstration elle-même : on déplace
     * le temps, PUIS on demande à la machine si quelque chose a changé. Le
     * contrôleur ne sait pas ce qui va basculer, et ne le décide pas.
     */
    #[Route('/demonstration/avancer', name: 'demonstration_avancer', methods: ['POST'])]
    public function avancer(
        Request $requete,
        ClockInterface $horloge,
        DecalageDeDemonstration $decalage,
        ExpirateurDeDelais $expirateur,
    ): Response {
        if (!$this->isCsrfTokenValid('demonstration', (string) $requete->request->get('_jeton'))) {
            // Jeton invalide : on ne pousse rien. Même sur un écran de
            // démonstration, une route qui modifie l'état de l'application ne
            // s'ouvre pas à une requête venue d'ailleurs.
            $this->addFlash('refus', 'Jeton de formulaire invalide : l\'horloge n\'a pas été poussée.');

            return $this->redirectToRoute('demonstration');
        }

        if (!$horloge instanceof HorlogeDeDemonstration) {
            $this->addFlash('refus', \sprintf(
                'L\'horloge injectée est %s et non l\'horloge de démonstration : ce conteneur ne '
                .'permet pas de déplacer le temps.',
                $horloge::class,
            ));

            return $this->redirectToRoute('demonstration');
        }

        $jours = $requete->request->getInt('jours');
        if ($jours < 1 || $jours > DecalageDeDemonstration::DECALAGE_MAXIMAL_EN_JOURS) {
            $this->addFlash('refus', \sprintf(
                'Nombre de jours hors bornes : il faut un entier entre 1 et %d. L\'horloge avance, '
                .'elle ne recule pas.',
                DecalageDeDemonstration::DECALAGE_MAXIMAL_EN_JOURS,
            ));

            return $this->redirectToRoute('demonstration');
        }

        $nouveauDecalage = $decalage->avancerDe($jours);

        // LE MÊME SERVICE QUE LE SCHEDULER, et le même que la commande
        // `tasswiya:faire-expirer-les-delais`. Rien ici ne sait quand un délai
        // expire : c'est la garde temporelle qui répond, en lisant l'horloge
        // qu'on vient de pousser.
        $compte = $expirateur->faireExpirerLesDelaisEchus();

        $this->addFlash('passe', [
            'jours' => $jours,
            'decalage' => $nouveauDecalage,
            'examines' => $compte['examines'],
            'bascules' => $compte['bascules'],
            'collisions' => $compte['collisions'],
        ]);

        return $this->redirectToRoute('demonstration');
    }

    #[Route('/demonstration/reinitialiser', name: 'demonstration_reinitialiser', methods: ['POST'])]
    public function reinitialiser(
        Request $requete,
        DecalageDeDemonstration $decalage,
    ): Response {
        if (!$this->isCsrfTokenValid('demonstration', (string) $requete->request->get('_jeton'))) {
            $this->addFlash('refus', 'Jeton de formulaire invalide : l\'horloge n\'a pas été remise à zéro.');

            return $this->redirectToRoute('demonstration');
        }

        $decalage->reinitialiser();

        // ⚠ CE QUI NE REVIENT PAS EN ARRIÈRE, ET QU'IL FAUT DIRE.
        //
        // Remettre l'horloge au temps réel ne défait AUCUNE bascule. Un
        // dossier passé en « délai expiré » y reste : la machine à états n'a
        // pas de transition de retour depuis cette place, et c'est un choix de
        // droit — un délai expiré ne se dé-expire pas. Pour retrouver les
        // dossiers dans leur état initial, il faut recharger les données de
        // démonstration, et l'écran le dit plutôt que de laisser croire à une
        // annulation.
        $this->addFlash('remise', 'Horloge revenue au temps réel. Les dossiers déjà basculés y restent : '
            .'la machine à états n\'a pas de transition de retour depuis « délai expiré ». Pour repartir '
            .'de l\'état initial, rechargez les données de démonstration '
            .'(tasswiya:charger-les-donnees-de-demonstration).');

        return $this->redirectToRoute('demonstration');
    }

    /**
     * Les délais pénaux qui courent, et les deux dossiers qui les bornent.
     *
     * ═══ POURQUOI LE PLUS PROCHE EST LE BON POINT D'APPUI ════════════════
     *
     * Parce qu'il rend la démonstration EXACTE À UN JOUR, et vérifiable par
     * un compte que l'écran imprime.
     *
     * Soit `m` le nombre de jours restants sur le dossier le plus proche de
     * son échéance. Un saut de `m` jours amène ce dossier EXACTEMENT à son
     * échéance — et l'échéance atteinte n'est pas l'échéance dépassée, parce
     * que la garde teste `maintenant > échéance`. Donc **aucun** dossier ne
     * bascule, et la passe affiche zéro. Un saut de `m + 1` fait basculer
     * celui-là, et lui seul.
     *
     * Zéro puis un : c'est la plus petite différence observable, et c'est ce
     * qui rend le couple de boutons une preuve plutôt qu'une mise en scène.
     * Si l'écran pouvait forcer l'expiration, les deux sauts donneraient le
     * même résultat.
     *
     * ═══ ET POURQUOI AUCUNE RÉFÉRENCE N'EST ÉCRITE DANS CE FICHIER ═══════
     *
     * Écrire `CH-2026-0107` ici ferait de l'écran une mise en scène : il
     * montrerait le dossier qu'on lui a dit de montrer, et les nombres des
     * boutons seraient des affirmations. Ils sont inférés, et si le jeu de
     * démonstration change, les boutons changent avec lui.
     *
     * @return array{
     *     plusProche: \App\Entity\Dossier,
     *     joursMinimum: int,
     *     nombreAuMinimum: int,
     *     joursMaximum: int,
     * }|array{}
     */
    private function delaisPenauxEnCours(DossierRepository $dossiers, CalculateurDHorloges $horloges): array
    {
        $enCours = [];

        // La requête présélectionne les dossiers dans une position où un délai
        // pénal court ; elle ne décide PAS de l'expiration, qui appartient à
        // la garde temporelle.
        foreach ($dossiers->dossiersDontLeDelaiPenalPeutAvoirExpire() as $dossier) {
            $restants = $horloges->joursRestantsSurLeDelaiPenal($dossier);

            // `null` : aucun délai ne court, il n'y a rien à borner.
            // Négatif : déjà échu, il ne peut plus servir de borne.
            if (null === $restants || $restants < 0) {
                continue;
            }

            $enCours[] = ['dossier' => $dossier, 'restants' => $restants];
        }

        if ([] === $enCours) {
            return [];
        }

        usort($enCours, static fn (array $a, array $b): int => $a['restants'] <=> $b['restants']);

        $minimum = $enCours[0]['restants'];

        return [
            'plusProche' => $enCours[0]['dossier'],
            'joursMinimum' => $minimum,
            // Les ex æquo comptent : à `minimum + 1`, ils basculent ENSEMBLE.
            // Annoncer « un seul dossier » quand ils sont deux ferait du
            // compte rendu de la passe un démenti de l'écran.
            'nombreAuMinimum' => \count(array_filter(
                $enCours,
                static fn (array $ligne): bool => $ligne['restants'] === $minimum,
            )),
            'joursMaximum' => $enCours[\count($enCours) - 1]['restants'],
        ];
    }

    /**
     * Les sauts proposés, et la raison de chacun.
     *
     * Trois des quatre sont CALCULÉS. Le quatrième, « un jour », est là pour
     * qu'on voie les jours restants décroître sur les cinq horloges à la
     * fois — c'est le seul saut dont la valeur ne dépend pas des données,
     * parce qu'il ne promet rien d'autre que lui-même.
     *
     * Sans aucun délai en cours, seul « un jour » reste : afficher des
     * boutons dont la promesse serait fausse vaudrait moins que de ne rien
     * afficher, et l'écran dit pourquoi.
     *
     * @param array{plusProche?: \App\Entity\Dossier, joursMinimum?: int, nombreAuMinimum?: int, joursMaximum?: int} $delais
     *
     * @return array<int, string>
     */
    private function sautsProposes(array $delais): array
    {
        $sauts = [1 => 'un jour — pour voir les jours restants décroître sur les cinq horloges à la fois'];

        if ([] === $delais) {
            return $sauts;
        }

        $minimum = $delais['joursMinimum'];
        $maximum = $delais['joursMaximum'];

        $sauts[$minimum] = 'le délai du dossier le plus proche, JOUR POUR JOUR — son échéance est '
            .'atteinte et non dépassée, donc AUCUN dossier ne bascule. La passe affichera zéro.';

        $sauts[$minimum + 1] = \sprintf(
            'un jour de plus — et %s bascule%s SEUL%s en « délai expiré ». Personne n\'a cliqué sur '
            .'« expirer ».',
            1 === $delais['nombreAuMinimum'] ? 'ce dossier' : 'les '.$delais['nombreAuMinimum'].' dossiers ex æquo',
            1 === $delais['nombreAuMinimum'] ? '' : 'nt',
            1 === $delais['nombreAuMinimum'] ? '' : 's',
        );

        // Le dernier saut n'existe que s'il apporte quelque chose : avec un
        // seul délai en cours, `maximum + 1` vaut `minimum + 1` et le bouton
        // se dédoublerait.
        if ($maximum > $minimum) {
            $sauts[$maximum + 1] = 'tous les délais pénaux en cours sont alors dépassés, y compris '
                .'celui du dossier prorogé — qui tient le plus longtemps, et c\'est précisément '
                .'l\'effet de la prorogation.';
        }

        ksort($sauts);

        return $sauts;
    }
}
