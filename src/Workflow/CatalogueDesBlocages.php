<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Domaine\Certitude\RegleAppliquee;
use App\Enum\DegreCertitude;

/**
 * Le catalogue des motifs de blocage : à chaque garde, sa règle, son article,
 * son degré et son libellé d'écran.
 *
 * POURQUOI UN CATALOGUE PLUTÔT QUE DES CHAÎNES AU FIL DU CODE. Le composant
 * Workflow refuse une transition avec un `TransitionBlocker`, qui porte un
 * message et un code. Si ce message est écrit à la main là où la garde se
 * trouve, trois choses arrivent : il se répète, il dérive, et il finit par
 * attribuer à la loi une règle qui vient d'ailleurs.
 *
 * Ici chaque code est associé une fois pour toutes à une {@see RegleAppliquee}
 * qui porte son degré. L'interface n'a plus qu'à afficher l'attribution que
 * la règle autorise — et une règle de degré CHOIX_PRODUIT s'affiche comme un
 * choix de ce logiciel, jamais comme la loi 71-24.
 *
 * Les codes sont stables : l'interface peut s'en servir pour griser un bouton
 * et choisir une info-bulle, sans parser un message.
 */
final class CatalogueDesBlocages
{
    // ───────── Codes de blocage, stables et utilisables par l'interface ─────────

    public const string QUOTA_PROLONGATIONS_EPUISE = 'quota_prolongations_epuise';
    public const string ACCORD_BENEFICIAIRE_NON_ENREGISTRE = 'accord_beneficiaire_non_enregistre';
    public const string DECISION_PARQUET_NON_ENREGISTREE = 'decision_parquet_non_enregistree';
    public const string BENEFICIAIRE_INDISPONIBLE = 'beneficiaire_indisponible';
    public const string DUREE_PROLONGATION_INSUFFISANTE = 'duree_prolongation_insuffisante';
    public const string DELAI_PENAL_DEJA_EXPIRE = 'delai_penal_deja_expire';
    public const string DELAI_PENAL_NON_EXPIRE = 'delai_penal_non_expire';
    public const string ECEDAR_PREALABLE_MANQUANT = 'ecedar_prealable_manquant';
    public const string CONTROLE_JUDICIAIRE_MANQUANT = 'controle_judiciaire_manquant';
    public const string CERTIFICAT_DE_REFUS_MANQUANT = 'certificat_de_refus_manquant';
    public const string PAS_D_EXTINCTION_SANS_AMENDE_DE_DEUX_POUR_CENT = 'pas_d_extinction_sans_amende_de_deux_pour_cent';
    public const string CAS_PENAL_HORS_CHAMP_DE_L_EXTINCTION = 'cas_penal_hors_champ_de_l_extinction';
    public const string CAS_PENAL_HORS_CHAMP_DE_LA_JUSTIFICATION_FAMILIALE = 'cas_penal_hors_champ_justification_familiale';
    public const string LIEN_FAMILIAL_HORS_PREMIER_DEGRE = 'lien_familial_hors_premier_degre';
    public const string FENETRE_DE_QUATRE_ANS_FERMEE = 'fenetre_de_quatre_ans_fermee';
    public const string DATE_DE_DISSOLUTION_INCONNUE = 'date_de_dissolution_inconnue';
    public const string AMENDE_ARTICLE_316_NON_PAYEE = 'amende_article_316_non_payee';
    public const string LES_DEUX_AMENDES_NON_PAYEES = 'les_deux_amendes_non_payees';
    public const string FENETRE_DE_REGULARISATION_BANCAIRE_FERMEE = 'fenetre_de_regularisation_bancaire_fermee';
    public const string CONDITIONS_DE_REGULARISATION_BANCAIRE_INCOMPLETES = 'conditions_de_regularisation_bancaire_incompletes';
    public const string INTERDICTION_BANCAIRE_NON_EXPIREE = 'interdiction_bancaire_non_expiree';

    /** @var array<string, RegleAppliquee>|null */
    private static ?array $catalogue = null;

    public static function regle(string $code): RegleAppliquee
    {
        $catalogue = self::catalogue();

        return $catalogue[$code] ?? throw new \InvalidArgumentException(
            'Aucune règle au catalogue pour le code de blocage « '.$code.' ». '
            .'Un blocage sans règle afficherait un refus sans source.'
        );
    }

    /** @return array<string, RegleAppliquee> */
    public static function catalogue(): array
    {
        return self::$catalogue ??= [
            /*
             * LA GARDE DU MOMENT FILMÉ — et c'est un PARAMÈTRE PRODUIT.
             *
             * La loi 71.24 NE LIMITE PAS le nombre de prorogations. L'art. 325
             * al. 8 ouvre une durée « égale ou supérieure » après accord du
             * bénéficiaire, sans plafond de durée et sans limite de nombre.
             * Les mots « مرة واحدة » (une seule fois) ne figurent NI dans la
             * loi, NI dans la circulaire du parquet.
             *
             * L'info-bulle que le brief du projet prévoyait — « déjà prolongé
             * une fois, loi 71-24 » — attribuerait donc à la loi une règle
             * qu'elle ne porte pas. Le libellé ci-dessous est le seul juste.
             * Voir LOI.md § 4.1.
             */
            self::QUOTA_PROLONGATIONS_EPUISE => new RegleAppliquee(
                enonce: 'Limite de ce logiciel : une prorogation. La loi 71-24 (art. 325 al. 8) ne limite '
                    .'PAS le nombre de prorogations ; elle exige une durée au moins égale au délai initial, '
                    .'une décision du ministère public et l\'accord du bénéficiaire.',
                article: 'art. 325 al. 8',
                degre: DegreCertitude::CHOIX_PRODUIT,
                arabeSource: 'لمدة مماثلة أو أكثر، بعد موافقة المستفيد',
                sourceReelle: 'paramètre de configuration de Tasswiya, modifiable par dossier. '
                    .'Vérification par absence, reproductible : grep -c "مرة واحدة" sur le texte de la loi '
                    .'et sur la circulaire du parquet renvoie 0 dans les deux cas.',
                renvoiLoiMd: 'LOI.md § 4.1 et § 6 H3',
            ),

            /*
             * LA GARDE VRAIMENT LÉGALE, celle qui mérite autant la caméra que
             * la précédente — et plus, puisque celle-là est dans le texte.
             */
            self::ACCORD_BENEFICIAIRE_NON_ENREGISTRE => new RegleAppliquee(
                enonce: 'La prorogation exige l\'accord du bénéficiaire, et cet accord n\'est pas enregistré '
                    .'au dossier. Le bénéficiaire peut refuser : son accord est une condition, pas une formalité.',
                article: 'art. 325 al. 8',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'بعد موافقة المستفيد',
            ),

            /*
             * LA CONDITION QUE LE BRIEF DU PROJET AVAIT OUBLIÉE, et c'est la
             * plus structurante : la prorogation n'est pas un accord entre le
             * créancier et le débiteur, c'est une décision du parquet.
             */
            self::DECISION_PARQUET_NON_ENREGISTREE => new RegleAppliquee(
                enonce: 'La prorogation est une décision du ministère public, et aucune décision n\'est '
                    .'enregistrée. Les deux conditions sont cumulatives et asymétriques : le parquet peut '
                    .'refuser même si le bénéficiaire accepte, et l\'accord du bénéficiaire est sans effet '
                    .'s\'il refuse.',
                article: 'art. 325 al. 8',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'يمكن للنيابة العامة تمديد الأجل',
            ),

            self::BENEFICIAIRE_INDISPONIBLE => new RegleAppliquee(
                enonce: 'L\'accord du bénéficiaire ne peut pas être recueilli — injoignable, décédé ou '
                    .'pluriel. L\'article 325 al. 8 exige cet accord sans dire comment procéder dans ces '
                    .'situations : la loi 71.24 n\'en traite pas. Ce logiciel bloque et le signale, plutôt '
                    .'que de présumer un accord ou de refuser en silence.',
                article: 'art. 325 al. 8 — angle mort du texte',
                degre: DegreCertitude::INCERTAIN,
                sourceReelle: 'aucune source : la loi est muette, aucune circulaire ni jurisprudence '
                    .'trouvée. Point à faire trancher par un avocat.',
                renvoiLoiMd: 'LOI.md § 4.15 et § 6 H4',
            ),

            self::DUREE_PROLONGATION_INSUFFISANTE => new RegleAppliquee(
                enonce: 'La durée de prorogation demandée est inférieure au délai initial. L\'article 325 '
                    .'al. 8 impose une durée « égale ou supérieure » : c\'est un plancher, et il n\'y a '
                    .'AUCUN plafond légal. La valeur de 60 jours que l\'on lit dans la presse n\'est écrite '
                    .'nulle part.',
                article: 'art. 325 al. 8',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'لمدة مماثلة أو أكثر',
            ),

            self::DELAI_PENAL_DEJA_EXPIRE => new RegleAppliquee(
                enonce: 'Le délai de régularisation est déjà expiré : il n\'y a plus de délai à proroger. '
                    .'L\'article 325 al. 8 permet de proroger « le délai prévu à l\'alinéa sixième », donc '
                    .'un délai en cours.',
                article: 'art. 325 al. 8, renvoyant à l\'al. 6',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'الأجل المنصوص عليه في الفقرة السادسة أعلاه',
            ),

            self::DELAI_PENAL_NON_EXPIRE => new RegleAppliquee(
                enonce: 'Le délai de trente jours court encore. Rien ne peut le faire expirer avant son '
                    .'terme : cette transition est franchie par le seul passage du temps.',
                article: 'art. 325 al. 6',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'خلال أجل ثلاثين (30) يوما من تاريخ هذا الإعذار',
                renvoiLoiMd: 'LOI.md § 4.1 et § 6 H3',
            ),

            /*
             * L'écédar est une condition PRÉALABLE OBLIGATOIRE de la
             * poursuite, et donc une garde sur la transition — pas une étape
             * informative dans un fil d'activité.
             */
            self::ECEDAR_PREALABLE_MANQUANT => new RegleAppliquee(
                enonce: 'Les poursuites doivent être précédées d\'une mise en demeure du tireur, prouvée '
                    .'par un procès-verbal d\'audition. L\'écédar prend la forme d\'un interrogatoire par un '
                    .'officier de police judiciaire sur instruction du parquet : il n\'y a ni acte '
                    .'d\'huissier ni lettre recommandée dans ce dispositif.',
                article: 'art. 325 al. 6 et 7',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'يجب أن يسبق المتابعة إعذار ساحب الشيك',
            ),

            self::CONTROLE_JUDICIAIRE_MANQUANT => new RegleAppliquee(
                enonce: 'Aucune mesure de contrôle judiciaire n\'est enregistrée. L\'article 325 al. 7 est '
                    .'rédigé à l\'impératif : le tireur est soumis à une ou plusieurs mesures, bracelet '
                    .'électronique compris, et le contrôle continue pendant la prorogation. Les trente '
                    .'jours ne sont pas un délai de grâce.',
                article: 'art. 325 al. 7 et 8',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'لواحد أو أكثر من تدابير المراقبة القضائية بما فيها السوار الإلكتروني',
                sourceReelle: null,
            ),

            self::CERTIFICAT_DE_REFUS_MANQUANT => new RegleAppliquee(
                enonce: 'Le certificat de refus de paiement n\'est pas au dossier. La banque qui refuse le '
                    .'paiement doit le remettre au porteur. Son contenu exact est fixé par la circulaire de '
                    .'Bank Al-Maghrib n° 5/G/97 du 18 septembre 1997, que nous n\'avons pas pu lire : on ne '
                    .'sait donc pas si ce certificat chiffre le manquant, et cette question commande les '
                    .'assiettes d\'amende.',
                article: 'art. 309, non modifié par la loi 71.24',
                degre: DegreCertitude::INCERTAIN,
                sourceReelle: 'obligation établie par l\'art. 309 ; contenu du certificat non vérifié '
                    .'(circulaire BAM n° 5/G/97 récupérée mais flux PDF non extractible).',
                renvoiLoiMd: 'LOI.md § 3.A3 et § 7 point 13',
            ),

            /*
             * La faute la plus facile à commettre, parce que la presse résume
             * l'article 325 al. 1 en « payer ou se désister suffit ».
             */
            self::PAS_D_EXTINCTION_SANS_AMENDE_DE_DEUX_POUR_CENT => new RegleAppliquee(
                enonce: 'L\'extinction exige DEUX conditions cumulatives : le paiement ou le désistement, '
                    .'ET le versement d\'une amende de 2 % du montant du chèque ou du manquant. Sans '
                    .'l\'amende, la poursuite subsiste. Cette amende est versée à la caisse du tribunal : '
                    .'ce n\'est pas une indemnité au bénéficiaire.',
                article: 'art. 325 al. 1',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'وذلك بعد أدائه غرامة تحدد قيمتها في اثنين (2%) بالمائة من مبلغ الشيك أو الخصاص',
            ),

            self::CAS_PENAL_HORS_CHAMP_DE_L_EXTINCTION => new RegleAppliquee(
                enonce: 'Ce régime d\'extinction est limité au tireur qui a omis de maintenir ou de '
                    .'constituer la provision — le point 1 de l\'article 316. Il ne couvre ni l\'opposition '
                    .'irrégulière, ni le faux, ni le chèque de garantie.',
                article: 'art. 325 al. 1',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'ساحب الشيك الذي أغفل الحفاظ على المؤونة أو تكوينها',
            ),

            self::CAS_PENAL_HORS_CHAMP_DE_LA_JUSTIFICATION_FAMILIALE => new RegleAppliquee(
                enonce: 'La cause de justification familiale ne joue que dans les cas du point 1 de '
                    .'l\'article 316. Elle ne couvre ni l\'opposition irrégulière, ni le faux, ni le chèque '
                    .'de garantie.',
                article: 'art. 325 al. 4',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'لا جريمة ولا عقوبة',
            ),

            self::LIEN_FAMILIAL_HORS_PREMIER_DEGRE => new RegleAppliquee(
                enonce: 'La cause de justification est limitée aux époux, aux ascendants et aux descendants '
                    .'AU PREMIER DEGRÉ. Un frère, un oncle, un petit-fils ne sont pas dans le texte, quelles '
                    .'que soient les formulations plus larges qu\'on lit ailleurs.',
                article: 'art. 325 al. 4',
                degre: DegreCertitude::ETABLI,
            ),

            self::FENETRE_DE_QUATRE_ANS_FERMEE => new RegleAppliquee(
                enonce: 'Pour un ex-époux, la cause de justification ne joue que pendant les quatre années '
                    .'qui suivent la dissolution du lien conjugal, et cette fenêtre est fermée à la date '
                    .'des faits.',
                article: 'art. 325 al. 5',
                degre: DegreCertitude::ETABLI,
            ),

            self::DATE_DE_DISSOLUTION_INCONNUE => new RegleAppliquee(
                enonce: 'La date de dissolution du mariage est inconnue : la fenêtre de quatre ans de '
                    .'l\'article 325 al. 5 est donc incalculable. Ce logiciel refuse plutôt que de supposer '
                    .'que le divorce est récent.',
                article: 'art. 325 al. 5',
                degre: DegreCertitude::CHOIX_PRODUIT,
                sourceReelle: 'conduite de ce logiciel en l\'absence de donnée : refuser plutôt que présumer.',
                /*
                 * La fenêtre de quatre ans, elle, est ÉTABLIE (art. 325 al. 5,
                 * LOI.md § 3.B9). Ce qui est un choix de ce logiciel, c'est la
                 * CONDUITE QUAND LA DATE MANQUE : refuser, et le dire. Le
                 * renvoi pointe donc l'arbitrage de la règle et la restriction
                 * que presse et notes de cabinet effacent, pour que
                 * l'utilisateur à qui l'on refuse puisse aller lire pourquoi.
                 */
                renvoiLoiMd: 'LOI.md § 3.B9 et § 4.11',
            ),

            self::AMENDE_ARTICLE_316_NON_PAYEE => new RegleAppliquee(
                enonce: 'Après une condamnation définitive, le paiement ou le désistement ne met fin à '
                    .'l\'exécution de la peine qu\'APRÈS paiement de l\'amende prononcée au titre de '
                    .'l\'article 316 al. 1. Deux amendes se cumulent donc à ce stade, là où les 2 % '
                    .'suffisaient avant condamnation : le montant dû dépend de l\'état du dossier, pas '
                    .'d\'une constante.',
                article: 'art. 325 al. 2',
                degre: DegreCertitude::ETABLI,
            ),

            self::LES_DEUX_AMENDES_NON_PAYEES => new RegleAppliquee(
                enonce: 'La réhabilitation judiciaire n\'est ouverte qu\'après paiement des DEUX amendes '
                    .'visées aux deux alinéas précédents.',
                article: 'art. 325 al. 3',
                degre: DegreCertitude::ETABLI,
            ),

            self::FENETRE_DE_REGULARISATION_BANCAIRE_FERMEE => new RegleAppliquee(
                enonce: 'La régularisation bancaire exige le paiement du chèque ou la constitution d\'une '
                    .'provision suffisante et disponible dans un délai de DEUX ANS à compter de '
                    .'l\'expiration du délai de présentation au paiement. Cette fenêtre est fermée. Elle '
                    .'est nouvelle : l\'ancien article 313 posait les mêmes conditions sans aucun délai.',
                article: 'art. 313 nouveau',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'خلال مدة سنتين ابتداء من تاريخ انتهاء أجل التقديم للوفاء',
            ),

            self::CONDITIONS_DE_REGULARISATION_BANCAIRE_INCOMPLETES => new RegleAppliquee(
                enonce: 'La levée de l\'interdiction exige DEUX conditions cumulatives : le paiement du '
                    .'chèque ou la constitution d\'une provision suffisante et disponible, ET le paiement '
                    .'de la pénalité de l\'article 314. La régularisation lève alors l\'interdiction et '
                    .'purge tous ses effets.',
                article: 'art. 313 nouveau',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'تؤدي التسوية إلى رفع المنع … وتطهير جميع الآثار المترتبة عليه',
            ),

            self::INTERDICTION_BANCAIRE_NON_EXPIREE => new RegleAppliquee(
                enonce: 'Les cinq ans de l\'interdiction bancaire ne sont pas écoulés. Cette durée était de '
                    .'dix ans avant la réforme. À ne pas confondre avec l\'interdiction judiciaire de '
                    .'l\'article 317, qui est une autre interdiction, prononcée par le tribunal.',
                article: 'art. 312 et 313 nouveaux',
                degre: DegreCertitude::ETABLI,
                arabeSource: 'خمس سنوات',
            ),
        ];
    }
}
