<?php

declare(strict_types=1);

namespace App\Domaine\Montant;

use App\Domaine\Certitude\RegleAppliquee;
use App\Entity\Dossier;
use App\Entity\InterdictionBancaire;
use App\Enum\DegreCertitude;

/**
 * Les montants du dossier, estimés, avec leur assiette et leur règle.
 *
 * ═══ TROIS AMENDES DE 2 % QUI N'EN SONT PAS TROIS ═════════════════════════
 *
 * `LOI.md` § 4.3 arbitre une confusion qui circule partout : « Taux 2 %,
 * minimum 500 DH, plafond 50 000 DH » est la FUSION DE DEUX AMENDES qui n'ont
 * ni la même assiette, ni le même débiteur, ni le même effet — et le
 * plancher/plafond appartient à une TROISIÈME. Reprendre ce triplet mettrait
 * un chiffre faux sur chaque écran.
 *
 * Ce qui est donc tenu séparé ici, et pourquoi :
 *
 *  - **art. 325 al. 1 — 2 %** : assiette « montant du chèque ou du manquant »,
 *    débiteur le TIREUR, effet l'extinction de l'action publique.
 *    **AUCUN plancher, AUCUN plafond** : ni le texte, ni la circulaire du
 *    parquet, ni l'Observatoire national de la criminalité n'en mentionnent.
 *  - **art. 316 — 2 %** : assiette « valeur du chèque » SANS « ou du
 *    manquant », débiteur CELUI QUI ACCEPTE OU ENDOSSE un chèque de garantie.
 *    La différence d'assiette est dans le texte, pas dans l'interprétation.
 *  - **art. 314 — 0,5 / 1 / 1,5 %** : débiteur le TITULAIRE DU COMPTE envers
 *    SA BANQUE, et c'est à celle-là seule qu'appartiennent le plancher de
 *    500 DH et le plafond de 50 000 DH.
 *
 * Il n'existe donc PAS de champ `tauxAmende` dans ce projet, et il ne doit
 * pas en apparaître : un taux unique ferait disparaître trois débiteurs.
 *
 * ═══ CE QUE CETTE CLASSE REFUSE DE CALCULER ═══════════════════════════════
 *
 * L'amende de l'art. 316 al. 1 est donnée par le texte EN FOURCHETTE —
 * 5 000 à 20 000 DH — et c'est le tribunal qui la fixe. Aucun pourcentage ne
 * la produit, et prendre le milieu de la fourchette serait inventer une
 * décision de justice. Elle s'affiche donc comme fourchette, sans montant.
 *
 * Et aucune phrase de ce fichier ne compare un barème à l'ancien : l'ancien
 * art. 314 imprime 1 % dans l'édition officielle et 5 % dans la doctrine
 * courante, le point n'est pas tranché (`LOI.md` § 4.6), donc « les pénalités
 * ont été divisées par dix » ne s'écrit nulle part.
 */
final readonly class EstimateurDeMontants
{
    /** Minimum de l'amende de l'art. 307 al. 3, en centimes : 100 DH. */
    private const int MINIMUM_AMENDE_307_EN_CENTIMES = 10_000;

    /**
     * Les montants à afficher pour ce dossier, dans l'ordre de lecture :
     * la valeur du titre, puis ce qui manque, puis ce qui est dû.
     *
     * @return list<MontantEstime>
     */
    public function montantsDu(Dossier $dossier): array
    {
        $cheque = $dossier->cheque();

        $montants = [
            new MontantEstime(
                libelle: 'Montant du chèque',
                centimes: $cheque->montantEnCentimes(),
                assiette: 'valeur portée sur le titre',
                regle: new RegleAppliquee(
                    enonce: 'La valeur du chèque est une donnée du dossier, pas une estimation. '
                        .'C\'est elle qui sert d\'assiette quand le manquant n\'est pas en cause.',
                    article: 'donnée du titre',
                    degre: DegreCertitude::ETABLI,
                ),
            ),
            $this->manquant($dossier),
            $this->amendeDeDeuxPourCent($dossier),
            $this->amendeArticle316($dossier),
        ];

        $interdiction = $dossier->interdictionBancaire();
        if (null !== $interdiction) {
            $montants[] = $this->penaliteArticle314($dossier, $interdiction);
            $montants[] = $this->amendeArticle307($dossier);
        }

        return $montants;
    }

    /**
     * Le manquant (الخصاص) : la pièce qui décide de tout le reste.
     *
     * Non chiffré dans la majorité des cas réels, et c'est le point de
     * praticien le plus directement opératoire du dossier de règles
     * (`LOI.md` § 4.14).
     */
    private function manquant(Dossier $dossier): MontantEstime
    {
        $cheque = $dossier->cheque();

        return new MontantEstime(
            libelle: 'Manquant (الخصاص)',
            centimes: $cheque->manquantEnCentimes(),
            assiette: 'découvert constaté par la banque',
            regle: new RegleAppliquee(
                enonce: 'Le manquant est le découvert réellement constaté. Le certificat de refus de '
                    .'paiement, en pratique, se borne à indiquer que la provision est inexistante ou '
                    .'insuffisante SANS chiffrer le découvert : le champ est alors vide, et il le reste.',
                article: 'art. 309, non modifié — contenu du certificat fixé par circulaire de '
                    .'Bank Al-Maghrib',
                degre: DegreCertitude::PROBABLE,
                arabeSource: 'الخصاص',
                sourceReelle: 'point de pratique rapporté par le juge Saïd Bouttouil (source unique, '
                    .'signée et spécialisée). La circulaire BAM n° 5/G/97 qui fixe les mentions du '
                    .'certificat a été récupérée mais son flux PDF n\'est pas extractible : on ne sait '
                    .'donc pas si ce certificat doit chiffrer le manquant.',
                renvoiLoiMd: 'LOI.md § 4.14 et § 7 point 13',
            ),
            raisonDeLIndetermination: $cheque->assietteEstDeterminee()
                ? null
                : 'Le certificat de refus ne chiffre pas le découvert. Ce logiciel ne calcule donc rien '
                    .'et ne retombe PAS sur le montant du chèque, ce qui surestimerait l\'amende — '
                    .'d\'autant plus que la provision était proche d\'être suffisante.',
        );
    }

    /**
     * L'amende de 2 % de l'art. 325 al. 1 — celle du tireur, versée à la
     * caisse du tribunal.
     *
     * ⚠ CE N'EST PAS UNE INDEMNITÉ AU BÉNÉFICIAIRE. C'est une amende
     * (غرامة), et l'indemnisation du bénéficiaire est un objet séparé : la
     * consignation de l'art. 325 al. 9. Confondre les deux produirait un écran
     * qui promet au créancier un argent qui va au tribunal.
     */
    private function amendeDeDeuxPourCent(Dossier $dossier): MontantEstime
    {
        $cas = $dossier->casArticle316();
        $reductible = $cas->assietteReductibleAuManquant();
        $assietteEnCentimes = $dossier->cheque()->assietteEnCentimes($reductible);

        $libelle = $cas->amendeDueParLeBeneficiaire()
            // Piège de l'art. 316 : pour le chèque de garantie, l'amende de
            // 2 % est due par CELUI QUI ACCEPTE OU ENDOSSE, et son assiette
            // est « la valeur du chèque » tout court.
            ? 'Amende de 2 % — due par celui qui a accepté ou endossé'
            : 'Amende de 2 % — due par le tireur';

        return new MontantEstime(
            libelle: $libelle,
            centimes: null === $assietteEnCentimes ? null : (int) round($assietteEnCentimes * 2 / 100),
            assiette: $reductible
                ? 'montant du chèque OU du manquant (art. 325 al. 1)'
                : 'valeur du chèque, sans réduction au manquant (art. 316)',
            regle: new RegleAppliquee(
                enonce: 'L\'extinction de l\'action publique exige DEUX conditions cumulatives : le '
                    .'paiement ou le désistement, ET le versement d\'une amende de 2 % du montant du '
                    .'chèque ou du manquant, à la caisse du tribunal. Cette amende n\'a NI PLANCHER NI '
                    .'PLAFOND : le « minimum de 500 DH » qui circule appartient à la pénalité bancaire '
                    .'de l\'article 314, qui est une autre amende, due à la banque par le titulaire du '
                    .'compte.',
                article: 'art. 325 al. 1',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'غرامة تحدد قيمتها في اثنين (2%) بالمائة من مبلغ الشيك أو الخصاص',
                renvoiLoiMd: 'LOI.md § 4.3 et § 4.4',
            ),
            raisonDeLIndetermination: null === $assietteEnCentimes
                ? 'Assiette indéterminée : le manquant n\'est pas chiffré. L\'article 325 al. 1 donne '
                    .'l\'assiette comme « le montant du chèque OU le manquant » ; sans le second, le '
                    .'calcul n\'est pas celui de la loi. Ce logiciel ne l\'approche pas.'
                : null,
            mentionDePlancherOuPlafond: 'Aucun plancher, aucun plafond — ni dans le texte, ni dans la '
                .'circulaire du parquet, ni chez l\'Observatoire national de la criminalité.',
        );
    }

    /**
     * L'amende pénale de l'art. 316 al. 1 : une FOURCHETTE, pas un calcul.
     *
     * Elle entre en jeu après condamnation définitive (art. 325 al. 2), et
     * c'est elle qui fait que « le montant dû dépend de l'état du dossier, pas
     * d'une constante » : avant condamnation, les 2 % suffisent ; après, les
     * deux amendes se cumulent.
     */
    private function amendeArticle316(Dossier $dossier): MontantEstime
    {
        $cas = $dossier->casArticle316();

        // Deux fourchettes distinctes selon le point de l'art. 316, lues au
        // BO qui reproduit cet article INTÉGRALEMENT (il est abrogé-remplacé).
        $fourchette = match ($cas) {
            \App\Enum\CasArticle316::FAUX_OU_FALSIFICATION,
            \App\Enum\CasArticle316::ACCEPTATION_DE_FAUX,
            \App\Enum\CasArticle316::USAGE_DE_FAUX => '20 000 à 50 000 DH',
            default => '5 000 à 20 000 DH',
        };

        return new MontantEstime(
            libelle: 'Amende de l\'art. 316 al. 1 — fixée par le tribunal',
            centimes: null,
            assiette: 'aucune : le texte donne une fourchette, pas un pourcentage',
            regle: new RegleAppliquee(
                enonce: 'Après condamnation définitive, le paiement ou le désistement ne met fin à '
                    .'l\'exécution de la peine qu\'APRÈS paiement de l\'amende prononcée au titre de '
                    .'l\'article 316 al. 1. Deux amendes se cumulent donc à ce stade. Son montant est '
                    .'fixé par le tribunal dans la fourchette du texte : aucun calcul ne le produit, et '
                    .'en prendre le milieu serait inventer une décision de justice.',
                article: 'art. 316 al. 1, exigée par l\'art. 325 al. 2',
                degre: DegreCertitude::ETABLI,
            ),
            fourchette: $fourchette,
        );
    }

    /**
     * La pénalité bancaire de l'art. 314 : 0,5 / 1 / 1,5 % selon le rang de
     * l'injonction, plancher 500 DH, plafond 50 000 DH.
     *
     * « Rang de l'injonction » et non « récidive » : l'article compte les
     * injonctions reçues, pas les condamnations. Importer le mot « récidive »
     * dans l'interface y amènerait une notion pénale que ce texte ne porte pas.
     */
    private function penaliteArticle314(Dossier $dossier, InterdictionBancaire $interdiction): MontantEstime
    {
        $pointsDeBase = $interdiction->tauxPenaliteArticle314EnPointsDeBase();
        // Réduite au manquant si la provision est partielle — donc
        // indéterminée quand le manquant ne l'est pas, comme les 2 %.
        $assietteEnCentimes = $dossier->cheque()->assietteEnCentimes(true);

        $penalite = null;
        if (null !== $assietteEnCentimes) {
            $brute = (int) round($assietteEnCentimes * $pointsDeBase / 10_000);
            $penalite = max(
                InterdictionBancaire::PLANCHER_PENALITE_314_EN_CENTIMES,
                min(InterdictionBancaire::PLAFOND_PENALITE_314_EN_CENTIMES, $brute),
            );
        }

        return new MontantEstime(
            libelle: \sprintf(
                'Pénalité bancaire de l\'art. 314 — %s %% (injonction de rang %d)',
                rtrim(rtrim(number_format($pointsDeBase / 100, 1, ',', ''), '0'), ','),
                $interdiction->rangDeLInjonction(),
            ),
            centimes: $penalite,
            assiette: 'montant du ou des chèques impayés, réduit au manquant si la provision est partielle',
            regle: new RegleAppliquee(
                enonce: 'Cette pénalité est due par le TITULAIRE DU COMPTE à SA BANQUE, et elle '
                    .'conditionne le recouvrement de la faculté d\'émettre. Elle n\'est pas due si le '
                    .'titulaire régularise dans les trois mois de l\'injonction bancaire — avec elle '
                    .'tombe aussi l\'amende de 6 % de l\'article 307 al. 3.',
                article: 'art. 314 nouveau',
                degre: DegreCertitude::ETABLI,
            ),
            raisonDeLIndetermination: null === $penalite
                ? 'Assiette indéterminée : le manquant n\'est pas chiffré, et le texte réduit '
                    .'l\'assiette au manquant quand la provision est partielle. Sans lui, on ne sait '
                    .'même pas si la réduction s\'applique.'
                : null,
            mentionDePlancherOuPlafond: 'Plancher 500 DH, plafond 50 000 DH — qui appartiennent à CETTE '
                .'pénalité et non à l\'amende de 2 % de l\'article 325 al. 1.',
        );
    }

    /**
     * L'amende de 6 % de l'art. 307 al. 3, que la loi 71.24 ne modifie pas.
     *
     * Elle est dans ce tableau parce que la fenêtre de trois mois de
     * l'art. 314 en exonère DEUX amendes et non une : un écran qui
     * n'afficherait que la pénalité de l'art. 314 sous-estimerait de 6 % du
     * chèque ce que l'expiration de H2 coûte.
     */
    private function amendeArticle307(Dossier $dossier): MontantEstime
    {
        $montantDuCheque = $dossier->cheque()->montantEnCentimes();

        return new MontantEstime(
            libelle: 'Amende de 6 % de l\'art. 307 al. 3',
            centimes: max(self::MINIMUM_AMENDE_307_EN_CENTIMES, (int) round($montantDuCheque * 6 / 100)),
            // Pas de « ou du manquant » ici : l'article dit « du montant du
            // chèque », et l'assiette est donc toujours déterminée.
            assiette: 'montant du chèque, minimum 100 DH',
            regle: new RegleAppliquee(
                enonce: 'La fenêtre de trois mois de l\'article 314 exonère DEUX amendes, pas une : la '
                    .'pénalité de l\'article 314 ET celle du troisième alinéa de l\'article 307. '
                    .'L\'assiette de celle-ci est le montant du chèque, sans réduction au manquant.',
                article: 'art. 307 al. 3, non modifié par la loi 71.24, visé par l\'art. 314 nouveau',
                degre: DegreCertitude::ETABLI,
            ),
        );
    }
}
