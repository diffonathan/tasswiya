<?php

declare(strict_types=1);

namespace App\Command;

use App\Domaine\Horloge\CalculateurDHorloges;
use App\Domaine\Horloge\DecalageDeDemonstration;
use App\Entity\Cheque;
use App\Entity\Dossier;
use App\Entity\InterdictionBancaire;
use App\Entity\Partie;
use App\Entity\Prolongation;
use App\Enum\DisponibiliteBeneficiaire;
use App\Enum\LienFamilial;
use App\Enum\LieuEmission;
use App\Enum\TransitionDossier;
use App\Enum\TransitionInterdictionBancaire;
use App\Security\ResolveurDeRoleParCourriel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Les dossiers fictifs de la démonstration.
 *
 * ═══ DONNÉES STRICTEMENT FICTIVES — `LOI.md` § 6, H8 ══════════════════════
 *
 * Noms de parties : des lettres de l'alphabet arabe, ce qu'aucune raison
 * sociale ne porte. Adresses de courriel : domaine `.invalid`, réservé par la
 * RFC 2606 et non routable, donc aucun courriel ne peut partir vers quiconque.
 * Références de chèque : préfixées `CHQ-FICTIF-`, format qu'aucune banque
 * n'émet. Aucun RIB, aucune CIN, aucun numéro de compte, aucune banque réelle
 * nommée. Aucune donnée personnelle réelle, nulle part.
 *
 * ═══ CE QUE CETTE COMMANDE NE FAIT PAS, ET C'EST LE POINT ═════════════════
 *
 * **Elle n'appelle pas `setEtat()`.** Chaque dossier atteint sa position en
 * FRANCHISSANT les transitions de la machine à états, gardes comprises. Écrire
 * directement la colonne `etat` serait plus court de quarante lignes, et
 * fabriquerait des dossiers que le droit encodé dans `workflow.yaml`
 * n'autorise pas — un dossier en « écédar notifié » sans procès-verbal au
 * journal, par exemple, dont l'horloge H3 partirait d'un fait non prouvé.
 * Les données de démonstration sont donc, accessoirement, un test de bout en
 * bout du graphe.
 *
 * ═══ POURQUOI ELLE S'AUTHENTIFIE ══════════════════════════════════════════
 *
 * Trois transitions sont gardées par `is_granted(...)` dans `workflow.yaml` :
 * `notifier_ecedar` (le greffe), `proroger_delai` (l'accord est celui DU
 * BÉNÉFICIAIRE, art. 325 al. 8) et `acter_desistement` (sa plainte, son
 * désistement). Une commande console n'a pas de jeton de sécurité, donc ces
 * gardes refuseraient tout.
 *
 * Plutôt que de les contourner, la commande POSE un jeton dans le
 * `TokenStorage` avant chaque transition, avec l'identité qui a le droit de la
 * franchir. Conséquence utile : le {@see \App\Security\Voter\DossierVoter}
 * est réellement exercé par le chargement des données, et une erreur dans ses
 * règles ferait échouer cette commande au lieu de dormir jusqu'au premier
 * clic.
 *
 * ═══ ELLE REMET L'HORLOGE DE DÉMONSTRATION À ZÉRO ═════════════════════════
 *
 * Et en premier, avant de calculer la moindre date. Sans cela, un chargement
 * fait après un saut de trente et un jours construirait des dossiers relatifs
 * à un présent décalé, et le scénario ne serait pas reproductible — la plus
 * désagréable des pannes de démonstration.
 */
#[AsCommand(
    name: 'tasswiya:charger-les-donnees-de-demonstration',
    description: 'Remplace les dossiers par un jeu fictif couvrant les cas intéressants, et remet l\'horloge de démonstration au temps réel.',
)]
final class ChargerLesDonneesDeDemonstrationCommande extends Command
{
    /**
     * Les mesures de contrôle judiciaire, telles que l'écran les affiche.
     *
     * L'article 325 al. 7 est rédigé à l'impératif et parle d'« une ou
     * plusieurs » mesures, bracelet électronique compris. La liste détaillée
     * vient de l'article 161 du Code de procédure pénale par le renvoi de la
     * circulaire du parquet — renvoi de degré PROBABLE, sur une copie tierce
     * OCRisée. L'écran l'attribue donc à l'instruction du ministère public et
     * jamais à la loi 71.24.
     */
    private const array MESURES_DE_CONTROLE_JUDICIAIRE = [
        'Port du bracelet électronique de surveillance',
        'Interdiction de quitter le territoire national',
        'Présentation périodique aux services de la police judiciaire',
    ];

    public function __construct(
        #[Target('dossierPenalStateMachine')]
        private readonly WorkflowInterface $machineDuDossier,
        #[Target('interdictionBancaireStateMachine')]
        private readonly WorkflowInterface $machineDeLInterdiction,
        private readonly EntityManagerInterface $gestionnaireDEntites,
        private readonly CalculateurDHorloges $horloges,
        private readonly DecalageDeDemonstration $decalage,
        private readonly TokenStorageInterface $jetons,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $entree, OutputInterface $sortie): int
    {
        $style = new SymfonyStyle($entree, $sortie);

        // D'ABORD, avant toute date : le scénario doit être reproductible.
        $this->decalage->reinitialiser();
        $style->note('Horloge de démonstration remise au temps réel : le décalage est à zéro jour.');

        $this->viderLesDonnees();

        $aujourdHui = $this->horloges->aujourdHui();
        $style->text(\sprintf('Présent de référence : %s.', $aujourdHui->format('d/m/Y')));

        $construits = [
            $this->dossierPivotDeLaDemonstration($aujourdHui),
            $this->dossierQuiVaExpirer($aujourdHui),
            $this->dossierProroge($aujourdHui),
            $this->dossierRegularise($aujourdHui),
            $this->dossierEnJustificationFamiliale($aujourdHui),
            $this->dossierDontLeBeneficiaireEstInjoignable($aujourdHui),
            $this->dossierExEpouxHorsFenetre($aujourdHui),
            $this->dossierDontLaConvocationEstSansEffet($aujourdHui),
            $this->dossierDontLeChequeVientDEtreEmis($aujourdHui),
        ];

        $this->gestionnaireDEntites->flush();
        $this->jetons->setToken(null);

        $lignes = [];
        foreach ($construits as [$dossier, $propos]) {
            \assert($dossier instanceof Dossier);
            $urgence = $this->horloges->joursRestantsSurLeDelaiPenal($dossier);
            $lignes[] = [
                $dossier->reference(),
                $dossier->etat()->value,
                null === $urgence ? 'aucun délai pénal' : \sprintf('%+d j (H3)', $urgence),
                \count($this->horloges->toutesLesHorloges($dossier)).' horloge(s)',
                $propos,
            ];
        }

        $style->table(
            ['Référence', 'État atteint', 'Délai pénal', 'Horloges', 'Ce que ce dossier montre'],
            $lignes,
        );

        $style->success(\sprintf(
            '%d dossiers fictifs chargés, tous par franchissement des transitions de la machine à états.',
            \count($construits),
        ));

        return Command::SUCCESS;
    }

    /**
     * ★ LE DOSSIER PIVOT DE LA DÉMONSTRATION : écédar daté D'AUJOURD'HUI.
     *
     * ─── POURQUOI UN DOSSIER DONT L'ÉCÉDAR EST DU JOUR ────────────────────
     *
     * Parce que c'est le seul sur lequel la démonstration soit EXACTE. Son
     * délai vaut le délai initial entier : trente jours, pas vingt-sept, pas
     * trois. Un saut de trente jours le laisse donc intact — l'échéance est
     * atteinte, pas dépassée — et un saut de trente et un le fait basculer.
     *
     * C'est ce couple qui prouve que la règle est tenue par la machine et non
     * par la mise en scène : si l'écran pouvait forcer l'expiration, le saut
     * de trente jours basculerait aussi. Les autres dossiers du jeu ont des
     * écédars plus anciens et basculeront plus tôt, ce qui est juste mais ne
     * démontre rien de précis.
     *
     * L'écran de démonstration ne code en dur NI cette référence NI ces deux
     * nombres : il retient le dossier dont il reste le plus de jours et
     * calcule les sauts à partir de lui. Un nombre publié sans la commande ou
     * le code qui le produit n'a pas sa place dans ce projet.
     *
     * @return array{Dossier, string}
     */
    private function dossierPivotDeLaDemonstration(\DateTimeImmutable $aujourdHui): array
    {
        $cheque = new Cheque(
            'CHQ-FICTIF-0221',
            2_780_000, // 27 800,00 DH
            $aujourdHui->modify('-58 days'),
            LieuEmission::MAROC,
        );
        $cheque->chiffrerLeManquant(2_780_000 - 410_000); // provision partielle : 4 100,00 DH restaient

        $dossier = new Dossier(
            'CH-2026-0221',
            $cheque,
            new Partie('Sté QĀF (raison sociale fictive)', 'tireur.qaf@exemple.invalid'),
            new Partie('Sté RĀ (raison sociale fictive)', 'beneficiaire.ra@exemple.invalid'),
        );

        $this->poserLeCircuitBancaire($dossier, $aujourdHui, joursDepuisLIncident: 36, rangDeLInjonction: 1);
        // Écédar DU JOUR : zéro jour écoulé, trente jours pleins devant.
        $this->avancerJusquALEcedar($dossier, $aujourdHui, joursDepuisLaPlainte: 20, joursDepuisLEcedar: 0);

        return [$dossier, 'écédar DU JOUR : 30 jours pleins — le pivot de la démonstration'];
    }

    /**
     * Un dossier à trois jours de l'échéance : le premier de la liste.
     *
     * Écédar notifié il y a vingt-sept jours : il reste TROIS jours sur le
     * délai de l'article 325 al. 6. C'est lui que la liste place en tête de
     * la bande « ce qui presse », et il bascule dès qu'on pousse l'horloge de
     * quatre jours — bien avant le dossier pivot.
     *
     * Et il porte la leçon des cinq horloges : son injonction bancaire est
     * antérieure de soixante jours à son écédar, si bien que H2 (trois mois
     * depuis l'injonction) et H3 (trente jours depuis l'écédar) arrivent à
     * échéance à quelques jours d'intervalle EN PARTANT D'ÉVÉNEMENTS
     * DIFFÉRENTS, séparés de deux mois. Un champ « date limite » unique
     * n'aurait pu en représenter qu'une.
     *
     * @return array{Dossier, string}
     */
    private function dossierQuiVaExpirer(\DateTimeImmutable $aujourdHui): array
    {
        $cheque = new Cheque(
            'CHQ-FICTIF-0107',
            4_850_000, // 48 500,00 DH
            $aujourdHui->modify('-95 days'),
            LieuEmission::MAROC,
        );
        // Provision partielle CHIFFRÉE : le certificat a, pour une fois,
        // indiqué le découvert. C'est le cas minoritaire, et il est ici pour
        // que les montants en or soient visibles sur l'écran principal.
        $cheque->chiffrerLeManquant(1_230_000); // 12 300,00 DH

        $dossier = new Dossier(
            'CH-2026-0107',
            $cheque,
            new Partie('Sté ALIF (raison sociale fictive)', 'tireur.alif@exemple.invalid'),
            new Partie('Sté BĀ (raison sociale fictive)', 'beneficiaire.ba@exemple.invalid'),
        );

        $this->poserLeCircuitBancaire($dossier, $aujourdHui, joursDepuisLIncident: 88, rangDeLInjonction: 1);
        $this->avancerJusquALEcedar($dossier, $aujourdHui, joursDepuisLaPlainte: 40, joursDepuisLEcedar: 27);

        return [$dossier, 'écédar il y a 27 j — 3 jours restants : il ouvre la bande « ce qui presse »'];
    }

    /**
     * Un dossier déjà prorogé, donc celui sur lequel LE QUOTA BLOQUE.
     *
     * ⚠ C'est ici que l'écran doit dire que la limite est UN CHOIX DE CE
     * LOGICIEL. L'article 325 al. 8 dit « لمدة مماثلة أو أكثر » — pour une
     * durée égale ou supérieure — sans plafonner le nombre de prorogations.
     * Les mots « مرة واحدة » (une seule fois) ne figurent ni dans la loi, ni
     * dans la circulaire du parquet.
     *
     * @return array{Dossier, string}
     */
    private function dossierProroge(\DateTimeImmutable $aujourdHui): array
    {
        $cheque = new Cheque(
            'CHQ-FICTIF-0143',
            720_000, // 7 200,00 DH
            $aujourdHui->modify('-120 days'),
            LieuEmission::MAROC,
        );
        // Manquant non chiffré : le cas courant (LOI.md § 4.14).
        $dossier = new Dossier(
            'CH-2026-0143',
            $cheque,
            new Partie('Sté JĪM (raison sociale fictive)', 'tireur.jim@exemple.invalid'),
            new Partie('Sté DĀL (raison sociale fictive)', 'beneficiaire.dal@exemple.invalid'),
        );

        $this->poserLeCircuitBancaire($dossier, $aujourdHui, joursDepuisLIncident: 112, rangDeLInjonction: 2);
        // Écédar il y a 25 jours : le délai initial de 30 jours COURAIT ENCORE
        // au moment de la prorogation, et c'est une condition. L'article 325
        // al. 8 permet de proroger « le délai prévu à l'alinéa sixième »,
        // c'est-à-dire un délai en cours — pas une forclusion. La garde
        // temporelle refuse de proroger un délai déjà expiré, et la question
        // de savoir si le parquet peut le faire rétroactivement n'est tranchée
        // par personne.
        $this->avancerJusquALEcedar($dossier, $aujourdHui, joursDepuisLaPlainte: 45, joursDepuisLEcedar: 25);

        // La prorogation, avec SES DEUX conditions cumulatives : la décision
        // du ministère public ET l'accord du bénéficiaire. Asymétriques — le
        // parquet peut refuser même si le bénéficiaire accepte.
        $prolongation = new Prolongation($aujourdHui->modify('-5 days'), 30);
        $dossier->ajouterProlongation($prolongation);
        $prolongation->enregistrerDecisionDuParquet(
            $aujourdHui->modify('-4 days'),
            'DEC-PARQUET-FICTIVE-0143',
        );
        $prolongation->enregistrerAccordDuBeneficiaire(
            $aujourdHui->modify('-3 days'),
            'ACCORD-FICTIF-0143',
        );

        // L'accord est celui DU BÉNÉFICIAIRE (art. 325 al. 8) : c'est son
        // identité qui franchit la transition, et le voter l'exige.
        $this->seConnecterComme($dossier->beneficiaire()->courrielDeContact() ?? '', []);
        $this->franchir($dossier, TransitionDossier::PROROGER_DELAI);

        return [$dossier, 'prorogé de 30 j (H3 = écédar + 60) : le quota de CE LOGICIEL bloque la suivante, pas la loi'];
    }

    /**
     * Un dossier régularisé de bout en bout, au pénal ET à la banque.
     *
     * L'extinction a demandé DEUX conditions cumulatives : le désistement, ET
     * l'amende de 2 % versée à la caisse du tribunal. La presse résume
     * l'article 325 al. 1 en « payer ou se désister suffit » ; la machine à
     * états refuse cette lecture, et il a fallu les deux pour arriver ici.
     *
     * @return array{Dossier, string}
     */
    private function dossierRegularise(\DateTimeImmutable $aujourdHui): array
    {
        $cheque = new Cheque(
            'CHQ-FICTIF-0088',
            2_100_000, // 21 000,00 DH
            $aujourdHui->modify('-300 days'),
            LieuEmission::MAROC,
        );
        // Provision totalement absente : le manquant est alors égal au montant
        // du chèque, et l'assiette devient déterminée SANS certificat chiffré.
        $cheque->declarerProvisionTotalementAbsente();

        $dossier = new Dossier(
            'CH-2026-0088',
            $cheque,
            new Partie('Sté HĀ (raison sociale fictive)', 'tireur.ha@exemple.invalid'),
            new Partie('Sté WĀW (raison sociale fictive)', 'beneficiaire.waw@exemple.invalid'),
        );

        $interdiction = $this->poserLeCircuitBancaire(
            $dossier,
            $aujourdHui,
            joursDepuisLIncident: 292,
            rangDeLInjonction: 1,
        );
        $this->avancerJusquALEcedar($dossier, $aujourdHui, joursDepuisLaPlainte: 200, joursDepuisLEcedar: 180);

        $dossier->enregistrerPaiementIntegral();
        $dossier->ajouterEvenement(
            \App\Enum\TypePieceJustificative::DESISTEMENT_DE_PLAINTE,
            $aujourdHui->modify('-165 days'),
            'DESIST-FICTIF-0088',
        );
        $dossier->enregistrerAmendeDeuxPourCent(
            $aujourdHui->modify('-160 days'),
            'QUITT-2PC-FICTIVE-0088',
        );

        // Sa plainte, son désistement (art. 325 al. 10, irréversible).
        $this->seConnecterComme($dossier->beneficiaire()->courrielDeContact() ?? '', []);
        $this->franchir($dossier, TransitionDossier::ACTER_DESISTEMENT);
        $this->franchir($dossier, TransitionDossier::ETEINDRE_ACTION_PUBLIQUE);

        // Et à la banque : les deux conditions de l'art. 313, dans la fenêtre
        // de deux ans. La régularisation lève l'interdiction ET purge tous ses
        // effets — ce que l'expiration des cinq ans, elle, ne fait pas.
        $interdiction->enregistrerPaiementOuProvision($aujourdHui->modify('-170 days'));
        $interdiction->enregistrerPenaliteArticle314();
        $this->machineDeLInterdiction->apply($interdiction, TransitionInterdictionBancaire::REGULARISER->value);

        return [$dossier, 'éteint : désistement ET amende de 2 % — les deux conditions, pas une'];
    }

    /**
     * Cause de justification familiale retenue : « ni infraction ni peine ».
     *
     * Trois restrictions que la presse efface et que la garde fait respecter :
     * le POINT 1 de l'article 316 seulement, le PREMIER DEGRÉ seulement, et
     * pour les époux une fenêtre de quatre ans après la dissolution du
     * mariage. Celui-ci est un époux non divorcé, donc la fenêtre ne joue pas.
     *
     * Terminal AU PÉNAL uniquement : l'action civile de la partie lésée reste
     * ouverte, et l'interdiction bancaire de cinq ans continue de courir.
     *
     * @return array{Dossier, string}
     */
    private function dossierEnJustificationFamiliale(\DateTimeImmutable $aujourdHui): array
    {
        $cheque = new Cheque(
            'CHQ-FICTIF-0061',
            945_000, // 9 450,00 DH
            $aujourdHui->modify('-150 days'),
            LieuEmission::MAROC,
        );

        $dossier = new Dossier(
            'CH-2026-0061',
            $cheque,
            new Partie('M. ZĀY (nom fictif)', 'tireur.zay@exemple.invalid'),
            new Partie('Mme ZĀY (nom fictif)', 'beneficiaire.zay@exemple.invalid'),
        );
        $dossier->declarerLienFamilial(LienFamilial::EPOUX);

        $this->poserLeCircuitBancaire($dossier, $aujourdHui, joursDepuisLIncident: 142, rangDeLInjonction: 1);
        $dossier->enregistrerPlainte($aujourdHui->modify('-100 days'));

        $this->seConnecterComme('greffe@exemple.invalid', [ResolveurDeRoleParCourriel::ROLE_SYMFONY_GREFFE]);
        $this->franchir($dossier, TransitionDossier::CONSTATER_IMPAYE);
        $this->franchir($dossier, TransitionDossier::DEPOSER_PLAINTE);
        $this->franchir($dossier, TransitionDossier::RETENIR_JUSTIFICATION_FAMILIALE);

        return [$dossier, 'époux : « ni infraction ni peine » — mais l\'interdiction bancaire court encore'];
    }

    /**
     * Bénéficiaire injoignable : l'ANGLE MORT DU TEXTE, et le seul degré
     * INCERTAIN du jeu de données.
     *
     * Le parquet a décidé, la durée demandée est conforme, le quota est
     * libre — et il manque l'accord du bénéficiaire, que personne ne peut
     * recueillir. L'article 325 al. 8 exige cet accord sans dire comment
     * procéder quand le bénéficiaire est injoignable, décédé ou pluriel : la
     * loi 71.24 n'en traite pas, aucune circulaire ni jurisprudence n'a été
     * trouvée. Ce logiciel bloque et le signale, au lieu de présumer un accord
     * ou de refuser en silence.
     *
     * Son manquant n'est PAS chiffré, et son chèque est le plus gros du jeu :
     * c'est le dossier qui montre le mieux ce que coûterait de retomber sur le
     * montant du chèque.
     *
     * @return array{Dossier, string}
     */
    private function dossierDontLeBeneficiaireEstInjoignable(\DateTimeImmutable $aujourdHui): array
    {
        $cheque = new Cheque(
            'CHQ-FICTIF-0150',
            13_400_000, // 134 000,00 DH
            $aujourdHui->modify('-70 days'),
            LieuEmission::ETRANGER, // 60 jours de présentation, et non 20
        );

        $dossier = new Dossier(
            'CH-2026-0150',
            $cheque,
            new Partie('Sté ḤĀ (raison sociale fictive)', 'tireur.hha@exemple.invalid'),
            new Partie('Sté ṬĀ (raison sociale fictive, injoignable)', 'beneficiaire.tta@exemple.invalid'),
        );
        $dossier->declarerDisponibiliteBeneficiaire(DisponibiliteBeneficiaire::INJOIGNABLE);

        $this->poserLeCircuitBancaire($dossier, $aujourdHui, joursDepuisLIncident: 62, rangDeLInjonction: 1);
        $this->avancerJusquALEcedar($dossier, $aujourdHui, joursDepuisLaPlainte: 35, joursDepuisLEcedar: 20);

        // Une prorogation demandée, décidée par le parquet, et qui attend un
        // accord impossible à obtenir.
        $prolongation = new Prolongation($aujourdHui->modify('-3 days'), 30);
        $dossier->ajouterProlongation($prolongation);
        $prolongation->enregistrerDecisionDuParquet(
            $aujourdHui->modify('-2 days'),
            'DEC-PARQUET-FICTIVE-0150',
        );

        return [$dossier, 'bénéficiaire injoignable : la loi est muette — degré INCERTAIN, et manquant non chiffré'];
    }

    /**
     * Ex-époux hors de la fenêtre de quatre ans.
     *
     * La restriction la moins connue de l'article 325 : pour un ex-époux, la
     * cause de justification ne joue que pendant les QUATRE ANNÉES qui
     * suivent la dissolution du lien conjugal (al. 5). Le divorce de celui-ci
     * a six ans, et la garde temporelle refuse — en comparant à la date DES
     * FAITS et non à celle du jour, parce que c'est au moment de l'infraction
     * que la cause joue ou ne joue pas.
     *
     * @return array{Dossier, string}
     */
    private function dossierExEpouxHorsFenetre(\DateTimeImmutable $aujourdHui): array
    {
        $cheque = new Cheque(
            'CHQ-FICTIF-0199',
            330_000, // 3 300,00 DH
            $aujourdHui->modify('-215 days'),
            LieuEmission::MAROC,
        );
        $cheque->chiffrerLeManquant(110_000); // 1 100,00 DH

        $dossier = new Dossier(
            'CH-2026-0199',
            $cheque,
            new Partie('M. KĀF (nom fictif)', 'tireur.kaf@exemple.invalid'),
            new Partie('Mme LĀM (nom fictif)', 'beneficiaire.lam@exemple.invalid'),
        );
        $dossier->declarerLienFamilial(LienFamilial::EX_EPOUX, $aujourdHui->modify('-6 years'));

        $this->poserLeCircuitBancaire($dossier, $aujourdHui, joursDepuisLIncident: 207, rangDeLInjonction: 1);
        $dossier->enregistrerPlainte($aujourdHui->modify('-150 days'));

        $this->seConnecterComme('greffe@exemple.invalid', [ResolveurDeRoleParCourriel::ROLE_SYMFONY_GREFFE]);
        $this->franchir($dossier, TransitionDossier::CONSTATER_IMPAYE);
        $this->franchir($dossier, TransitionDossier::DEPOSER_PLAINTE);

        return [$dossier, 'ex-époux, divorce vieux de 6 ans : la fenêtre de 4 ans de l\'al. 5 est fermée'];
    }

    /**
     * Tireur introuvable : AUCUN DÉLAI NE COURT, et c'est la leçon.
     *
     * L'horloge des trente jours part de la date de l'écédar. Sans écédar
     * notifié, elle n'a pas de point de départ : ce dossier n'a pas une
     * échéance lointaine, il n'a PAS D'ÉCHÉANCE pénale du tout. Modéliser
     * l'absence du tireur par un simple drapeau laisserait un délai courir
     * contre un homme qu'on n'a pas vu.
     *
     * L'article 325 n'organise pas ce cas. La pratique rapportée — avis de
     * recherche, pas de garde à vue à l'interpellation, audition valant
     * écédar — est de la doctrine de praticien, affichée comme telle.
     *
     * @return array{Dossier, string}
     */
    private function dossierDontLaConvocationEstSansEffet(\DateTimeImmutable $aujourdHui): array
    {
        $cheque = new Cheque(
            'CHQ-FICTIF-0012',
            1_575_000, // 15 750,00 DH
            $aujourdHui->modify('-60 days'),
            LieuEmission::MAROC,
        );

        $dossier = new Dossier(
            'CH-2026-0012',
            $cheque,
            new Partie('M. MĪM (nom fictif, introuvable)', 'tireur.mim@exemple.invalid'),
            new Partie('Sté NŪN (raison sociale fictive)', 'beneficiaire.nun@exemple.invalid'),
        );

        $this->poserLeCircuitBancaire($dossier, $aujourdHui, joursDepuisLIncident: 52, rangDeLInjonction: 1);
        $dossier->enregistrerPlainte($aujourdHui->modify('-30 days'));

        $this->seConnecterComme('greffe@exemple.invalid', [ResolveurDeRoleParCourriel::ROLE_SYMFONY_GREFFE]);
        $this->franchir($dossier, TransitionDossier::CONSTATER_IMPAYE);
        $this->franchir($dossier, TransitionDossier::DEPOSER_PLAINTE);
        $this->franchir($dossier, TransitionDossier::CONSTATER_CONVOCATION_SANS_EFFET);

        return [$dossier, 'tireur introuvable : pas d\'écédar, donc AUCUN délai de 30 jours'];
    }

    /**
     * Un chèque tout juste émis : UNE SEULE HORLOGE EXISTE.
     *
     * Ni incident, ni injonction, ni plainte, ni écédar : seul le délai de
     * présentation au paiement de l'article 268 court. Les quatre autres
     * horloges n'existent pas encore — elles ne sont pas « à zéro », elles
     * n'ont pas de point de départ. C'est la ligne qui fait voir que les cinq
     * horloges du tableau ne sont pas cinq colonnes d'un même délai.
     *
     * Et sa transition `constater_impaye` est barrée par une règle de degré
     * INCERTAIN : l'obligation de remettre le certificat de refus est établie
     * (art. 309), mais son contenu est fixé par une circulaire de Bank
     * Al-Maghrib dont le flux PDF n'est pas extractible — on ne sait donc pas
     * si ce certificat chiffre le manquant, et cette question commande toutes
     * les assiettes d'amende.
     *
     * @return array{Dossier, string}
     */
    private function dossierDontLeChequeVientDEtreEmis(\DateTimeImmutable $aujourdHui): array
    {
        $cheque = new Cheque(
            'CHQ-FICTIF-0204',
            6_200_000, // 62 000,00 DH
            $aujourdHui->modify('-8 days'),
            LieuEmission::MAROC,
        );

        $dossier = new Dossier(
            'CH-2026-0204',
            $cheque,
            new Partie('Sté SĪN (raison sociale fictive)', 'tireur.sin@exemple.invalid'),
            new Partie('Sté ʿAYN (raison sociale fictive)', 'beneficiaire.ayn@exemple.invalid'),
        );

        $this->gestionnaireDEntites->persist($dossier);

        return [$dossier, 'chèque émis : UNE seule horloge existe, celle de l\'art. 268'];
    }

    // ═══════════════════ Les briques communes ═══════════════════

    /**
     * L'incident de paiement, le certificat de refus et l'injonction
     * bancaire — c'est-à-dire les points de départ de H5, H4 et H2.
     *
     * L'injonction est posée deux jours après l'incident, comme l'article 313
     * l'impose à la banque. Ce logiciel ne VÉRIFIE pas ce délai de deux jours :
     * il n'est pas l'auditeur de la banque, et la sanction de ce manquement
     * frappe la banque (art. 319), pas le tireur.
     */
    private function poserLeCircuitBancaire(
        Dossier $dossier,
        \DateTimeImmutable $aujourdHui,
        int $joursDepuisLIncident,
        int $rangDeLInjonction,
    ): InterdictionBancaire {
        $incident = $aujourdHui->modify(\sprintf('-%d days', $joursDepuisLIncident));

        $dossier->enregistrerIncidentDePaiement(
            $incident,
            $incident,
            'CERT-REFUS-FICTIF-'.substr($dossier->reference(), -4),
        );
        $dossier->enregistrerInjonctionBancaire(
            $incident->modify('+2 days'),
            'PREUVE-ENVOI-FICTIVE-'.substr($dossier->reference(), -4),
        );

        // L'échéance du délai de présentation, qui est le POINT DE DÉPART de
        // la fenêtre de deux ans de l'article 313 : le départ d'une horloge
        // est ici l'ÉCHÉANCE d'une autre, et c'est précisément ce qu'un champ
        // « date limite » unique n'aurait jamais pu exprimer.
        $interdiction = new InterdictionBancaire(
            $incident,
            $this->horloges->h1($dossier)->echeance,
            $rangDeLInjonction,
        );
        $dossier->attacherInterdictionBancaire($interdiction);

        $this->gestionnaireDEntites->persist($dossier);
        $this->gestionnaireDEntites->persist($interdiction);

        return $interdiction;
    }

    /**
     * Mène le dossier jusqu'à « écédar notifié », en franchissant les
     * transitions.
     *
     * L'écédar est enregistré AVANT d'appliquer la transition, et avec ses
     * deux pièces : le procès-verbal d'audition qui date le départ des trente
     * jours, et les mesures de contrôle judiciaire. Les gardes de
     * `notifier_ecedar` exigent les deux — une date sans procès-verbal ferait
     * partir l'horloge principale sur un fait non établi, et un écédar sans
     * contrôle judiciaire décrirait un délai de grâce qui n'existe pas.
     */
    private function avancerJusquALEcedar(
        Dossier $dossier,
        \DateTimeImmutable $aujourdHui,
        int $joursDepuisLaPlainte,
        int $joursDepuisLEcedar,
    ): void {
        $dossier->enregistrerPlainte($aujourdHui->modify(\sprintf('-%d days', $joursDepuisLaPlainte)));
        $dossier->enregistrerEcedar(
            $aujourdHui->modify(\sprintf('-%d days', $joursDepuisLEcedar)),
            'PV-AUDITION-FICTIF-'.substr($dossier->reference(), -4),
            self::MESURES_DE_CONTROLE_JUDICIAIRE,
        );

        // Le greffe notifie l'écédar : c'est le voter qui l'exige
        // (`DOSSIER_NOTIFIER_ECEDAR`), et la garde du YAML qui l'appelle.
        $this->seConnecterComme('greffe@exemple.invalid', [ResolveurDeRoleParCourriel::ROLE_SYMFONY_GREFFE]);
        $this->franchir($dossier, TransitionDossier::CONSTATER_IMPAYE);
        $this->franchir($dossier, TransitionDossier::DEPOSER_PLAINTE);
        $this->franchir($dossier, TransitionDossier::NOTIFIER_ECEDAR);
    }

    /**
     * Franchit une transition, et échoue bruyamment si la garde refuse.
     *
     * Bruyamment, et c'est le point : un jeu de données qui « corrigerait »
     * un refus de garde en écrivant l'état à la main fabriquerait des dossiers
     * que le droit encodé dans `workflow.yaml` n'autorise pas. Mieux vaut que
     * le chargement casse.
     */
    private function franchir(Dossier $dossier, TransitionDossier $transition): void
    {
        if (!$this->machineDuDossier->can($dossier, $transition->value)) {
            $motifs = [];
            foreach ($this->machineDuDossier->buildTransitionBlockerList($dossier, $transition->value) as $blocage) {
                $motifs[] = $blocage->getMessage();
            }

            throw new \LogicException(\sprintf(
                'Le jeu de données veut franchir « %s » sur %s depuis « %s », et la machine à états '
                ."refuse :\n- %s",
                $transition->value,
                $dossier->reference(),
                $dossier->etat()->value,
                implode("\n- ", $motifs ?: ['aucun motif rendu — vérifier l\'état de départ de la transition']),
            ));
        }

        $this->machineDuDossier->apply($dossier, $transition->value);
    }

    /**
     * Pose un jeton de sécurité, parce que trois gardes du YAML appellent
     * `is_granted(...)` et qu'une console n'en a pas.
     *
     * `InMemoryUser` avec un mot de passe `null` : aucun mot de passe n'est
     * vérifié ici, et il n'y en a donc aucun à écrire — un mot de passe fictif
     * dans un fichier versionné est une habitude qui finit par en contenir un
     * vrai.
     *
     * @param list<string> $roles
     */
    private function seConnecterComme(string $identifiant, array $roles): void
    {
        $utilisateur = new InMemoryUser($identifiant, null, $roles);

        // `principal` est le nom du pare-feu de `config/packages/security.yaml`.
        $this->jetons->setToken(new UsernamePasswordToken($utilisateur, 'principal', $utilisateur->getRoles()));
    }

    /**
     * Vide les dossiers précédents.
     *
     * Par `remove()` et non par un `DELETE` massif : les suppressions en
     * cascade — chèque, interdiction bancaire, journal des pièces,
     * prorogations — sont déclarées sur les associations de l'entité, et un
     * DQL `DELETE` les ignore. Les parties, elles, ne sont pas en cascade de
     * suppression (une partie pourrait figurer à plusieurs dossiers), donc
     * elles sont nettoyées après.
     */
    private function viderLesDonnees(): void
    {
        $depot = $this->gestionnaireDEntites->getRepository(Dossier::class);
        foreach ($depot->findAll() as $dossier) {
            $this->gestionnaireDEntites->remove($dossier);
        }
        $this->gestionnaireDEntites->flush();

        $this->gestionnaireDEntites->createQuery('DELETE FROM App\Entity\Partie')->execute();
        $this->gestionnaireDEntites->clear();
    }
}
