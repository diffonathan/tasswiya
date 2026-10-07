<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CasArticle316;
use App\Enum\DisponibiliteBeneficiaire;
use App\Enum\EtatDossier;
use App\Enum\LienFamilial;
use App\Enum\TypePieceJustificative;
use App\Repository\DossierRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Le dossier de chèque impayé : l'agrégat autour duquel tout tourne.
 *
 * TROIS PRINCIPES DE CONSTRUCTION, et chacun se défend séparément.
 *
 * 1. DATA MAPPER, PAS ACTIVERECORD. Cette classe n'a aucune méthode qui
 *    parle à la base. Pas de `save()`, pas de `find()`, pas de connexion
 *    statique. Elle ne connaît même pas son repository. Conséquence
 *    pratique : on l'instancie dans un test unitaire et on fait courir toute
 *    la logique de délais sans démarrer PostgreSQL. C'est l'écart le plus net
 *    avec Eloquent, et il se mesure au temps de la suite de tests.
 *
 * 2. AUCUNE HORLOGE À L'INTÉRIEUR. On ne trouvera pas un `new \DateTime()`
 *    dans ce fichier. Le dossier stocke des FAITS DATÉS — date de l'écédar,
 *    date de l'injonction, date de la plainte — et les ÉCHÉANCES se
 *    recalculent au-dehors par {@see \App\Domaine\Horloge\CalculateurDHorloges},
 *    qui reçoit l'instant par injection. C'est ce qui permet de pousser
 *    l'horloge de trente et un jours dans la démonstration sans tricher sur
 *    les données, et de tester un délai de cinq ans en une milliseconde.
 *    Une échéance persistée serait figée sous les règles de calcul du jour
 *    où elle a été écrite.
 *
 * 3. CINQ HORLOGES, PAS UNE. Il n'existe volontairement AUCUN champ
 *    `dateLimiteRegularisation`. Les cinq délais de LOI.md § 2 n'ont ni le
 *    même départ, ni la même durée, ni le même effet : les écraser dans un
 *    champ unique ferait mentir le produit. Les points de départ sont donc
 *    cinq champs distincts, chacun nommé d'après son événement.
 */
#[ORM\Entity(repositoryClass: DossierRepository::class)]
#[ORM\Table(name: 'dossier')]
#[ORM\Index(name: 'idx_dossier_etat', columns: ['etat'])]
#[ORM\Index(name: 'idx_dossier_date_ecedar', columns: ['date_ecedar'])]
class Dossier
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Verrou optimiste.
     *
     * Pourquoi ici précisément : deux personnes travaillent sur le même
     * dossier — le créancier qui enregistre son accord à la prorogation, le
     * greffe qui saisit la décision du parquet — et le Scheduler qui pousse
     * les expirations passe par-dessus. Sans ce verrou, la dernière écriture
     * gagne en silence et peut écraser un accord qui vient d'être donné ;
     * avec lui, Doctrine lève `OptimisticLockException` et l'interface
     * recharge au lieu de perdre une donnée.
     *
     * Le verrou optimiste plutôt que pessimiste parce que les collisions sont
     * rares et les transactions courtes : on ne veut pas tenir un verrou de
     * base pendant qu'un utilisateur remplit un formulaire.
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    /** Référence interne fictive du dossier. */
    #[ORM\Column(type: Types::STRING, length: 32, unique: true)]
    private string $reference;

    /**
     * L'état courant, stocké en CHAÎNE et non en énumération.
     *
     * Et ce n'est pas un relâchement de typage : c'est le `marking_store` de
     * la machine `dossier_penal`, déclaré en `type: method` sur la propriété
     * `etat`. Le `MethodMarkingStore` de Symfony appelle `getEtat()` et
     * `setEtat()` et travaille sur des CHAÎNES, parce que les places d'un
     * graphe sont des chaînes dans la configuration. Lui donner une propriété
     * typée {@see EtatDossier} le ferait échouer au premier `apply()`.
     *
     * Le typage fort n'est pas perdu pour autant : le domaine passe par
     * {@see etat()}, qui rend l'énumération, et `getEtat()`/`setEtat()` sont
     * marqués `@internal` pour le seul composant Workflow.
     *
     * Un seul état à la fois — d'où `state_machine` et non `workflow`.
     */
    #[ORM\Column(type: Types::STRING, length: 48)]
    private string $etat = 'cheque_emis';

    #[ORM\OneToOne(targetEntity: Cheque::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: false)]
    private Cheque $cheque;

    #[ORM\ManyToOne(targetEntity: Partie::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private Partie $tireur;

    #[ORM\ManyToOne(targetEntity: Partie::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private Partie $beneficiaire;

    /**
     * Le fait incriminé, qui commande deux régimes de faveur.
     *
     * L'extinction de l'art. 325 al. 1 et la cause de justification familiale
     * de l'art. 325 al. 4 sont limitées au POINT 1 de l'art. 316, celui de
     * l'omission de provision. Ce champ est donc lu par deux gardes, et non
     * décoratif.
     */
    #[ORM\Column(type: Types::STRING, length: 40, enumType: CasArticle316::class)]
    private CasArticle316 $casArticle316 = CasArticle316::OMISSION_DE_PROVISION;

    // ───────── Les cinq points de départ, un champ par horloge ─────────
    //
    // Le départ de H1 n'est pas ici : il est porté par le chèque
    // (date d'émission). Celui de H4 n'est pas ici non plus : c'est
    // l'ÉCHÉANCE de H1, donc une valeur calculée — ce qu'un champ unique
    // n'aurait jamais pu exprimer.

    /** Départ de H5 (interdiction bancaire, 5 ans) : l'incident de paiement. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateIncidentDePaiement = null;

    /** Départ de H2 (3 mois d'exonération) : l'injonction bancaire de l'art. 313. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateInjonctionBancaire = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $datePlainte = null;

    /**
     * Départ de H3 (30 jours de régularisation pénale) : la date de l'écédar,
     * et rien d'autre.
     *
     * Art. 325 al. 6, verbatim : « خلال أجل ثلاثين (30) يوما من تاريخ هذا
     * الإعذار » — dans un délai de trente jours à compter de la date de cet
     * écédar. Ni la présentation, ni le rejet, ni la plainte, ni l'injonction
     * bancaire. L'écédar est postérieur de plusieurs semaines ou mois au rejet,
     * et confondre les deux est l'erreur que la plupart des résumés commettent.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateEcedar = null;

    /**
     * Durée du délai initial, en jours, figée à la notification de l'écédar.
     *
     * Trente jours aujourd'hui (art. 325 al. 6). Stockée et non codée en dur
     * pour une raison de données et non de droit : si le législateur change
     * la durée, les dossiers ouverts sous l'ancienne doivent garder la leur.
     */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $dureeDelaiInitialEnJours = 30;

    /**
     * Nombre de prorogations que CE LOGICIEL autorise sur ce dossier.
     *
     * ⚠ CE N'EST PAS UNE RÈGLE DE DROIT, ET LE NOM DU CHAMP LE DIT.
     *
     * La loi 71.24 NE LIMITE PAS le nombre de prorogations. L'art. 325 al. 8
     * dit que le ministère public peut proroger « لمدة مماثلة أو أكثر » —
     * pour une durée égale ou supérieure — après accord du bénéficiaire, sans
     * plafond de durée et sans limite de nombre. Les mots « مرة واحدة » (une
     * seule fois) ne figurent NI dans la loi, NI dans la circulaire du
     * parquet : la vérification est une absence, et elle est reproductible —
     *
     *     grep -c "مرة واحدة" docs/sources/loi_71-24_extrait_BO7478.txt       # 0
     *     grep -c "مرة واحدة" docs/sources/circulaire_parquet_2-2026_OCR.txt  # 0
     *     grep -c "مماثلة أو أكثر" docs/sources/loi_71-24_extrait_BO7478.txt  # 1
     *
     * L'Observatoire national de la criminalité, sur le site du ministère de
     * la Justice, écrit indépendamment « pour une durée équivalente ou plus ».
     * La presse qui annonce « renouvelable une seule fois » est donc en tort,
     * et une info-bulle qui attribuerait cette limite à la loi 71-24 serait
     * une fausse citation.
     *
     * Ce champ est donc un PARAMÈTRE PRODUIT, persisté par dossier pour qu'un
     * changement de configuration ne modifie pas rétroactivement un dossier
     * ouvert. Degré affiché : CHOIX_PRODUIT. Voir LOI.md § 4.1 et § 6, H3.
     */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $quotaProlongationsDeCeLogiciel = 1;

    /**
     * Disponibilité du bénéficiaire pour donner l'accord de l'art. 325 al. 8.
     *
     * Propriété et non place de la machine à états, bien que LOI.md § 6 H4
     * parle d'un « état » : l'indisponibilité est ORTHOGONALE à la position
     * procédurale. Un bénéficiaire décédé ne déplace pas le dossier dans la
     * procédure, il empêche une transition. En faire une place obligerait à
     * dupliquer chaque état procédural en deux versions.
     */
    #[ORM\Column(type: Types::STRING, length: 24, enumType: DisponibiliteBeneficiaire::class)]
    private DisponibiliteBeneficiaire $disponibiliteBeneficiaire = DisponibiliteBeneficiaire::DISPONIBLE;

    // ───────── La cause de justification familiale (art. 325 al. 4 et 5) ─────────

    #[ORM\Column(type: Types::STRING, length: 32, enumType: LienFamilial::class)]
    private LienFamilial $lienFamilialTireurBeneficiaire = LienFamilial::AUCUN;

    /**
     * Date de dissolution du lien conjugal, indispensable au calcul de la
     * fenêtre de quatre ans de l'art. 325 al. 5.
     *
     * Champ de date supplémentaire imposé par une règle que, d'après le
     * dépouillement de LOI.md, aucune source secondaire ne mentionne : pour
     * les époux, la cause de justification joue encore pendant les quatre
     * années qui suivent le divorce.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateDissolutionDuMariage = null;

    // ───────── Les faits qui conditionnent l'extinction ─────────

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $paiementIntegralEffectue = false;

    /**
     * Amende de 2 % de l'art. 325 al. 1, versée À LA CAISSE DU TRIBUNAL.
     *
     * C'est une amende (غرامة), PAS une indemnité au bénéficiaire.
     * L'indemnisation du bénéficiaire est un objet séparé — la consignation
     * de l'art. 325 al. 9 — et confondre les deux dans le modèle produirait
     * un écran qui promet au créancier un argent qui va au tribunal.
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $amendeDeuxPourCentPayee = false;

    /** Amende pénale de l'art. 316 al. 1 (5 000 à 20 000 DH), exigée par l'art. 325 al. 2. */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $amendeArticle316Payee = false;

    /**
     * Consignation de la valeur du chèque à la caisse du tribunal SANS
     * transaction ni désistement (art. 325 al. 9).
     *
     * N'éteint rien : le bénéficiaire conserve son action civile en
     * indemnisation. Champ distinct de l'amende de 2 % pour cette raison.
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $consignationAuGreffeEffectuee = false;

    /**
     * Les mesures de contrôle judiciaire effectivement prises.
     *
     * L'art. 325 al. 7 est à l'impératif et parle d'« une ou plusieurs »
     * mesures (لواحد أو أكثر), bracelet électronique compris. La circulaire du
     * parquet écrit « l'UNE des mesures » et renvoie à l'article 161 du Code
     * de procédure pénale — renvoi de degré PROBABLE, sur une copie tierce
     * OCRisée, donc à afficher comme « instruction du ministère public » et
     * jamais comme la loi.
     *
     * Une collection et non un booléen, parce que le texte dit « ou
     * plusieurs » : un booléen effacerait la pluralité que le texte autorise.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $mesuresDeControleJudiciaire = [];

    /** @var Collection<int, Prolongation> */
    #[ORM\OneToMany(targetEntity: Prolongation::class, mappedBy: 'dossier', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['demandeeLe' => 'ASC'])]
    private Collection $prolongations;

    /**
     * Le journal : chaque fait daté avec la pièce qui le prouve.
     *
     * Pas de date saisie sans son événement, et pas d'événement sans sa pièce
     * (LOI.md § 5 point 2). C'est ce qui empêche qu'une date d'écédar
     * apparaisse dans le dossier sans procès-verbal d'audition derrière, et
     * donc qu'une horloge de trente jours parte d'un fait non prouvé.
     *
     * @var Collection<int, EvenementDossier>
     */
    #[ORM\OneToMany(targetEntity: EvenementDossier::class, mappedBy: 'dossier', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['survenuLe' => 'ASC'])]
    private Collection $evenements;

    #[ORM\OneToOne(targetEntity: InterdictionBancaire::class, cascade: ['persist', 'remove'])]
    private ?InterdictionBancaire $interdictionBancaire = null;

    public function __construct(
        string $reference,
        Cheque $cheque,
        Partie $tireur,
        Partie $beneficiaire,
        int $quotaProlongationsDeCeLogiciel = 1,
    ) {
        $this->id = Uuid::v7();
        $this->reference = $reference;
        $this->cheque = $cheque;
        $this->tireur = $tireur;
        $this->beneficiaire = $beneficiaire;
        $this->quotaProlongationsDeCeLogiciel = $quotaProlongationsDeCeLogiciel;
        $this->prolongations = new ArrayCollection();
        $this->evenements = new ArrayCollection();
    }

    // ═══════════════════ Lecture ═══════════════════

    public function id(): Uuid
    {
        return $this->id;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function cheque(): Cheque
    {
        return $this->cheque;
    }

    public function tireur(): Partie
    {
        return $this->tireur;
    }

    public function beneficiaire(): Partie
    {
        return $this->beneficiaire;
    }

    public function casArticle316(): CasArticle316
    {
        return $this->casArticle316;
    }

    public function dateIncidentDePaiement(): ?\DateTimeImmutable
    {
        return $this->dateIncidentDePaiement;
    }

    public function dateInjonctionBancaire(): ?\DateTimeImmutable
    {
        return $this->dateInjonctionBancaire;
    }

    public function dateEcedar(): ?\DateTimeImmutable
    {
        return $this->dateEcedar;
    }

    public function dureeDelaiInitialEnJours(): int
    {
        return $this->dureeDelaiInitialEnJours;
    }

    public function dateDissolutionDuMariage(): ?\DateTimeImmutable
    {
        return $this->dateDissolutionDuMariage;
    }

    public function lienFamilialTireurBeneficiaire(): LienFamilial
    {
        return $this->lienFamilialTireurBeneficiaire;
    }

    public function disponibiliteBeneficiaire(): DisponibiliteBeneficiaire
    {
        return $this->disponibiliteBeneficiaire;
    }

    public function interdictionBancaire(): ?InterdictionBancaire
    {
        return $this->interdictionBancaire;
    }

    /** @return list<string> */
    public function mesuresDeControleJudiciaire(): array
    {
        return $this->mesuresDeControleJudiciaire;
    }

    /** @return Collection<int, Prolongation> */
    public function prolongations(): Collection
    {
        return $this->prolongations;
    }

    /** @return Collection<int, EvenementDossier> */
    public function evenements(): Collection
    {
        return $this->evenements;
    }

    // ═══════════════════ Le marquage de la machine à états ═══════════════════
    //
    // `marking_store` de type `method` : le composant Workflow appelle
    // `etat()` pour lire et `setEtat()` pour écrire. Le setter est public
    // parce que le composant en a besoin, mais il porte un avertissement :
    // il ne doit jamais être appelé depuis un contrôleur ou un service
    // métier. Toute transition passe par `WorkflowInterface::apply()`, qui
    // seul fait jouer les gardes. Un `setEtat()` direct contournerait tout le
    // droit encodé dans ce projet.

    /** L'état, typé. C'est par là que passe tout le domaine. */
    public function etat(): EtatDossier
    {
        return EtatDossier::from($this->etat);
    }

    /** @internal Réservé au `MethodMarkingStore`. Le domaine lit {@see etat()}. */
    public function getEtat(): string
    {
        return $this->etat;
    }

    /**
     * @internal Réservé au `MethodMarkingStore`. Toute transition passe par
     * `WorkflowInterface::apply()`, qui seul fait jouer les gardes : un appel
     * direct depuis un contrôleur contournerait tout le droit encodé ici.
     */
    public function setEtat(string $etat): void
    {
        // Validation défensive : le marking store écrit une chaîne venue de la
        // configuration. Si une place du YAML n'a pas son cas dans
        // l'énumération, mieux vaut échouer ici que persister un état que le
        // domaine ne saura pas relire.
        $this->etat = EtatDossier::from($etat)->value;
    }

    // ═══════════════════ Les faits, enregistrés avec leur pièce ═══════════════════

    /**
     * Enregistre l'incident de paiement et le certificat de refus.
     *
     * Les deux ensemble, parce que l'art. 309 oblige la banque à remettre le
     * certificat : un incident sans certificat est un dossier qu'on ne peut
     * pas instruire, et la garde `constater_impaye` le refuse.
     */
    public function enregistrerIncidentDePaiement(
        \DateTimeImmutable $dateIncident,
        \DateTimeImmutable $dateCertificat,
        string $referenceCertificatFictive,
    ): void {
        $this->dateIncidentDePaiement = $dateIncident->modify('midnight');
        $this->ajouterEvenement(
            TypePieceJustificative::CERTIFICAT_DE_REFUS_DE_PAIEMENT,
            $dateCertificat,
            $referenceCertificatFictive,
        );
    }

    /**
     * Enregistre l'injonction bancaire de l'art. 313, qui fait partir H2.
     *
     * La banque doit l'envoyer DANS LES DEUX JOURS de l'incident, POUR CHAQUE
     * CHÈQUE SÉPARÉMENT, par tout moyen prouvant l'envoi — et une SEULE
     * injonction si plusieurs chèques sans provision sont présentés le même
     * jour. Ces deux règles sont nouvelles. Ce logiciel ne vérifie pas le
     * délai de deux jours : il n'est pas l'auditeur de la banque, et la
     * sanction de ce manquement frappe la banque (art. 319), pas le tireur.
     */
    public function enregistrerInjonctionBancaire(
        \DateTimeImmutable $dateInjonction,
        string $preuveEnvoiFictive,
    ): void {
        $this->dateInjonctionBancaire = $dateInjonction->modify('midnight');
        $this->ajouterEvenement(
            TypePieceJustificative::INJONCTION_BANCAIRE,
            $dateInjonction,
            $preuveEnvoiFictive,
        );
    }

    public function enregistrerPlainte(\DateTimeImmutable $datePlainte): void
    {
        $this->datePlainte = $datePlainte->modify('midnight');
    }

    /**
     * Enregistre la notification de l'écédar, et avec elle le départ de H3.
     *
     * @param string        $referenceProcesVerbal la pièce qui DATE le départ des 30 jours.
     *                                             L'écédar prend la forme d'un interrogatoire par un officier de police
     *                                             judiciaire (art. 325 al. 7) : il n'y a ni huissier ni lettre
     *                                             recommandée dans ce dispositif, donc aucun accusé de réception postal
     *                                             à attendre. Le procès-verbal est la preuve, et la seule.
     * @param list<string> $mesuresDeControle      art. 325 al. 7, à l'impératif : « une ou
     *                                             plusieurs » mesures de contrôle judiciaire, bracelet électronique
     *                                             compris. Les trente jours ne sont pas un délai de grâce, et un écran
     *                                             qui les annonce sans dire cela décrit mal la situation du débiteur.
     */
    public function enregistrerEcedar(
        \DateTimeImmutable $dateEcedar,
        string $referenceProcesVerbal,
        array $mesuresDeControle,
        int $dureeDelaiInitialEnJours = 30,
    ): void {
        $this->dateEcedar = $dateEcedar->modify('midnight');
        $this->dureeDelaiInitialEnJours = $dureeDelaiInitialEnJours;
        $this->mesuresDeControleJudiciaire = array_values($mesuresDeControle);
        $this->ajouterEvenement(
            TypePieceJustificative::PROCES_VERBAL_VALANT_ECEDAR,
            $dateEcedar,
            $referenceProcesVerbal,
        );
    }

    public function declarerDisponibiliteBeneficiaire(DisponibiliteBeneficiaire $disponibilite): void
    {
        $this->disponibiliteBeneficiaire = $disponibilite;
    }

    public function declarerLienFamilial(
        LienFamilial $lien,
        ?\DateTimeImmutable $dateDissolutionDuMariage = null,
    ): void {
        $this->lienFamilialTireurBeneficiaire = $lien;
        $this->dateDissolutionDuMariage = $dateDissolutionDuMariage?->modify('midnight');
    }

    public function declarerCasArticle316(CasArticle316 $cas): void
    {
        $this->casArticle316 = $cas;
    }

    public function enregistrerPaiementIntegral(): void
    {
        $this->paiementIntegralEffectue = true;
    }

    public function enregistrerAmendeDeuxPourCent(
        \DateTimeImmutable $dateQuittance,
        string $referenceQuittanceFictive,
    ): void {
        $this->amendeDeuxPourCentPayee = true;
        $this->ajouterEvenement(
            TypePieceJustificative::QUITTANCE_AMENDE_DEUX_POUR_CENT,
            $dateQuittance,
            $referenceQuittanceFictive,
        );
    }

    public function enregistrerAmendeArticle316(
        \DateTimeImmutable $dateQuittance,
        string $referenceQuittanceFictive,
    ): void {
        $this->amendeArticle316Payee = true;
        $this->ajouterEvenement(
            TypePieceJustificative::QUITTANCE_AMENDE_ARTICLE_316,
            $dateQuittance,
            $referenceQuittanceFictive,
        );
    }

    public function enregistrerConsignationAuGreffe(
        \DateTimeImmutable $dateRecepisse,
        string $referenceRecepisseFictive,
    ): void {
        $this->consignationAuGreffeEffectuee = true;
        $this->ajouterEvenement(
            TypePieceJustificative::RECEPISSE_DE_CONSIGNATION,
            $dateRecepisse,
            $referenceRecepisseFictive,
        );
    }

    public function attacherInterdictionBancaire(InterdictionBancaire $interdiction): void
    {
        $this->interdictionBancaire = $interdiction;
    }

    public function ajouterProlongation(Prolongation $prolongation): void
    {
        if (!$this->prolongations->contains($prolongation)) {
            $this->prolongations->add($prolongation);
            $prolongation->rattacherA($this);
        }
    }

    public function ajouterEvenement(
        TypePieceJustificative $typePiece,
        \DateTimeImmutable $survenuLe,
        string $referencePieceFictive,
    ): EvenementDossier {
        $evenement = new EvenementDossier($this, $typePiece, $survenuLe, $referencePieceFictive);
        $this->evenements->add($evenement);

        return $evenement;
    }

    // ═══════════════════ Les prédicats que les gardes appellent ═══════════════════
    //
    // Toutes sans argument et sans effet de bord, parce que le langage
    // d'expressions de `workflow.yaml` les appelle sous la forme
    // `subject.monPredicat()`. Toutes PURES AU REGARD DU TEMPS : aucune ne
    // sait quel jour on est. Les gardes temporelles vivent dans
    // `App\Workflow\EcouteurDeGardesTemporelles`, qui reçoit l'horloge.

    /** Le certificat de refus de paiement de l'art. 309 est au dossier. */
    public function certificatDeRefusEnregistre(): bool
    {
        return null !== $this->dateIncidentDePaiement
            && $this->possedePiece(TypePieceJustificative::CERTIFICAT_DE_REFUS_DE_PAIEMENT);
    }

    public function plainteDeposee(): bool
    {
        return null !== $this->datePlainte;
    }

    /**
     * L'écédar est notifié ET prouvé par un procès-verbal d'audition.
     *
     * Les deux conditions ensemble : une date sans pièce ferait partir
     * l'horloge principale sur un fait non établi.
     */
    public function ecedarNotifieAvecProcesVerbal(): bool
    {
        return null !== $this->dateEcedar
            && $this->possedePiece(TypePieceJustificative::PROCES_VERBAL_VALANT_ECEDAR);
    }

    /**
     * Au moins une mesure de contrôle judiciaire est enregistrée.
     *
     * Art. 325 al. 7, rédigé à l'impératif : le tireur « est soumis » à une ou
     * plusieurs mesures. Un écédar sans contrôle judiciaire enregistré est
     * donc un dossier incomplet, et la garde de `notifier_ecedar` le refuse.
     */
    public function controleJudiciaireEnPlace(): bool
    {
        return [] !== $this->mesuresDeControleJudiciaire;
    }

    /** Le bénéficiaire est en situation de donner l'accord de l'art. 325 al. 8. */
    public function beneficiaireDisponible(): bool
    {
        return $this->disponibiliteBeneficiaire->permetDeRecueillirUnAccord();
    }

    /**
     * Le quota de prorogations DE CE LOGICIEL est épuisé.
     *
     * Nom choisi avec soin : `quotaProlongationsEpuise` et non
     * `prolongationDejaJoueeUneFois`. La loi 71.24 ne limite pas le nombre de
     * prorogations (art. 325 al. 8) ; cette garde est un paramètre produit,
     * et l'info-bulle doit le dire. Voir {@see $quotaProlongationsDeCeLogiciel}
     * et LOI.md § 4.1.
     */
    public function quotaProlongationsEpuise(): bool
    {
        return $this->nombreDeProlongationsAccordees() >= $this->quotaProlongationsDeCeLogiciel;
    }

    public function nombreDeProlongationsAccordees(): int
    {
        return $this->prolongations
            ->filter(static fn (Prolongation $p): bool => $p->estAccordee())
            ->count();
    }

    public function quotaProlongationsDeCeLogiciel(): int
    {
        return $this->quotaProlongationsDeCeLogiciel;
    }

    /**
     * Une prorogation est en attente, c'est-à-dire saisie mais pas encore
     * appliquée à la machine à états.
     */
    public function prolongationEnAttente(): ?Prolongation
    {
        // La plus récemment demandée : si plusieurs demandes traînent, c'est
        // la dernière que le greffe instruit.
        $enAttente = null;
        foreach ($this->prolongations as $prolongation) {
            \assert($prolongation instanceof Prolongation);
            if ($prolongation->estAccordee()) {
                continue;
            }
            if (null === $enAttente || $prolongation->demandeeLe() >= $enAttente->demandeeLe()) {
                $enAttente = $prolongation;
            }
        }

        return $enAttente;
    }

    /**
     * La décision du ministère public est enregistrée sur la prorogation en
     * attente.
     *
     * CONDITION QUE LE BRIEF DU PROJET AVAIT OUBLIÉE. L'art. 325 al. 8 dit
     * « يمكن للنيابة العامة تمديد الأجل » : la décision appartient au
     * MINISTÈRE PUBLIC. L'accord du bénéficiaire est une condition, pas le
     * déclencheur. Les deux sont cumulatives et asymétriques : le parquet
     * peut refuser même si le bénéficiaire accepte, et l'accord du
     * bénéficiaire est sans effet s'il refuse. Une prorogation sans décision
     * du parquet enregistrée n'est pas une prorogation.
     */
    public function decisionParquetEnregistree(): bool
    {
        return true === $this->prolongationEnAttente()?->decisionDuParquetEnregistree();
    }

    /** L'accord du bénéficiaire est enregistré sur la prorogation en attente (art. 325 al. 8). */
    public function accordBeneficiaireEnregistre(): bool
    {
        return true === $this->prolongationEnAttente()?->accordDuBeneficiaireEnregistre();
    }

    /**
     * La durée demandée est « égale ou supérieure » au délai initial.
     *
     * Art. 325 al. 8, verbatim « لمدة مماثلة أو أكثر ». C'est un PLANCHER de
     * trente jours, pas un plafond : il n'y a AUCUN plafond légal, et la
     * valeur de 60 jours que la presse annonce n'est écrite nulle part. La
     * garde refuse donc une durée trop COURTE, et jamais une durée trop
     * longue.
     */
    public function dureeProlongationDemandeeAuMoinsEgaleAuDelaiInitial(): bool
    {
        $enAttente = $this->prolongationEnAttente();

        return null !== $enAttente
            && $enAttente->dureeEnJours() >= $this->dureeDelaiInitialEnJours;
    }

    /**
     * Le cas pénal ouvre l'extinction de l'art. 325 al. 1.
     *
     * Vrai pour l'omission de provision seulement. L'alinéa désigne nommément
     * « ساحب الشيك الذي أغفل الحفاظ على المؤونة أو تكوينها » : il ne couvre ni
     * l'opposition irrégulière, ni le faux, ni le chèque de garantie.
     */
    public function extinctionOuverteParLeCasPenal(): bool
    {
        return $this->casArticle316->ouvreExtinctionArticle325();
    }

    /**
     * Paiement intégral OU désistement OU transaction : la première des deux
     * conditions cumulatives de l'art. 325 al. 1.
     */
    public function paiementOuDesistementAcquis(): bool
    {
        return $this->paiementIntegralEffectue
            || EtatDossier::DESISTEMENT_OU_TRANSACTION_ACTE === $this->etat()
            || $this->possedePiece(TypePieceJustificative::DESISTEMENT_DE_PLAINTE);
    }

    /**
     * L'amende de 2 % est payée : la SECONDE condition, cumulative.
     *
     * Sans elle, la poursuite subsiste. C'est la garde qui s'appelle
     * `pasDExtinctionSansAmendeDeDeuxPourCent` dans le YAML, et le nom est
     * explicite parce que c'est l'erreur la plus facile à commettre : la
     * presse résume l'art. 325 al. 1 en « payer ou se désister suffit ».
     */
    public function amendeDeuxPourCentPayee(): bool
    {
        return $this->amendeDeuxPourCentPayee;
    }

    public function amendeArticle316Payee(): bool
    {
        return $this->amendeArticle316Payee;
    }

    /**
     * Les DEUX amendes sont payées : condition de la réhabilitation judiciaire
     * (art. 325 al. 3, « بعد أداء الغرامتين »).
     */
    public function lesDeuxAmendesPayees(): bool
    {
        return $this->amendeDeuxPourCentPayee && $this->amendeArticle316Payee;
    }

    public function consignationAuGreffeEffectuee(): bool
    {
        return $this->consignationAuGreffeEffectuee;
    }

    /**
     * L'assiette de l'amende est déterminée.
     *
     * Faux dans le cas courant où le certificat de refus ne chiffre pas le
     * découvert. Le produit ne calcule alors RIEN et le dit, plutôt que de
     * retomber sur le montant du chèque — ce qui surestimerait l'amende
     * (LOI.md § 4.14 et § 6, H6).
     *
     * Conséquence sur la machine à états : l'extinction reste possible, parce
     * que c'est le tribunal qui fixe l'amende et non ce logiciel. Ce qui est
     * refusé, c'est d'AFFICHER UN MONTANT, pas de franchir la transition.
     */
    public function assietteDeterminee(): bool
    {
        return $this->cheque->assietteEstDeterminee();
    }

    /** Le cas pénal ouvre la cause de justification familiale (art. 325 al. 4). */
    public function justificationFamilialeOuverteParLeCas(): bool
    {
        return $this->casArticle316->ouvreJustificationFamiliale();
    }

    /** Le lien entre tireur et bénéficiaire est dans la liste limitative de l'art. 325 al. 4. */
    public function lienFamilialDansLeChamp(): bool
    {
        return $this->lienFamilialTireurBeneficiaire->estDansLeChampArticle325();
    }

    /**
     * La date de dissolution du mariage est connue quand elle est nécessaire.
     *
     * Pour un ex-époux, la fenêtre de quatre ans de l'art. 325 al. 5 est
     * incalculable sans cette date : la garde refuse alors plutôt que de
     * supposer que le divorce est récent.
     */
    public function dateDissolutionConnueSiNecessaire(): bool
    {
        if (!$this->lienFamilialTireurBeneficiaire->exigeLaFenetreDeQuatreAns()) {
            return true;
        }

        return null !== $this->dateDissolutionDuMariage;
    }

    /** Une pièce de ce type figure au journal. */
    public function possedePiece(TypePieceJustificative $type): bool
    {
        foreach ($this->evenements as $evenement) {
            if ($evenement->typePiece() === $type) {
                return true;
            }
        }

        return false;
    }
}
