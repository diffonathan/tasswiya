<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Les pièces que la procédure produit réellement, et elles seules.
 *
 * Liste close, dressée en LOI.md § 4.13 sur les textes lus. Il circule une
 * prétendue « attestation de régularisation » : cette pièce N'EXISTE PAS
 * sous ce nom, et la nommer dans un logiciel serait inventer un document
 * administratif marocain. Ajouter un cas à cette énumération suppose donc
 * d'avoir trouvé le texte qui crée la pièce, et de l'écrire ici.
 */
enum TypePieceJustificative: string
{
    /**
     * Certificat de refus de paiement, art. 309 (non modifié).
     * Mentions fixées par la circulaire BAM n° 5/G/97 du 18/09/1997.
     *
     * INCERTAIN quant à son contenu : le PDF de la circulaire a été récupéré
     * mais son flux n'est pas extractible (LOI.md § 7 point 13). La question
     * est directement opératoire — le certificat chiffre-t-il le manquant ? —
     * parce que l'assiette des 2 % et celle de l'art. 314 en dépendent.
     */
    case CERTIFICAT_DE_REFUS_DE_PAIEMENT = 'certificat_de_refus_de_paiement';

    /** Injonction bancaire de restituer les formules, art. 313 nouveau. */
    case INJONCTION_BANCAIRE = 'injonction_bancaire';

    /**
     * Procès-verbal d'audition valant écédar, art. 325 al. 6 et 7.
     *
     * C'est LA pièce qui date le départ des 30 jours. L'écédar prend la forme
     * d'un interrogatoire par un officier de police judiciaire : il n'y a ni
     * acte d'huissier ni lettre recommandée dans ce dispositif, donc pas
     * d'accusé de réception postal à attendre.
     */
    case PROCES_VERBAL_VALANT_ECEDAR = 'proces_verbal_valant_ecedar';

    /** Décision du ministère public prorogeant le délai, art. 325 al. 8. */
    case DECISION_PARQUET_DE_PROROGATION = 'decision_parquet_de_prorogation';

    /** Accord écrit du bénéficiaire à la prorogation, art. 325 al. 8. */
    case ACCORD_DU_BENEFICIAIRE = 'accord_du_beneficiaire';

    /**
     * Quittance de paiement de l'amende de 2 % à la caisse du tribunal,
     * art. 325 al. 1, mécanique décrite par la circulaire du parquet.
     *
     * C'est une amende versée au tribunal, PAS une indemnité au bénéficiaire.
     * Le modèle ne doit pas les confondre.
     */
    case QUITTANCE_AMENDE_DEUX_POUR_CENT = 'quittance_amende_deux_pour_cent';

    /** Désistement de plainte, art. 325 al. 1 et 10. */
    case DESISTEMENT_DE_PLAINTE = 'desistement_de_plainte';

    /** Récépissé de consignation à la caisse du tribunal, art. 325 al. 9. */
    case RECEPISSE_DE_CONSIGNATION = 'recepisse_de_consignation';

    /** Quittance de l'amende pénale de l'art. 316 al. 1, exigée par l'art. 325 al. 2. */
    case QUITTANCE_AMENDE_ARTICLE_316 = 'quittance_amende_article_316';

    /** Quittance de la pénalité bancaire de l'art. 314, exigée par l'art. 313. */
    case QUITTANCE_PENALITE_ARTICLE_314 = 'quittance_penalite_article_314';

    /** Le degré de certitude attaché au contenu de la pièce, pas à son existence. */
    public function degreDeCertitudeDuContenu(): DegreCertitude
    {
        return match ($this) {
            self::CERTIFICAT_DE_REFUS_DE_PAIEMENT => DegreCertitude::INCERTAIN,
            self::QUITTANCE_AMENDE_DEUX_POUR_CENT => DegreCertitude::PROBABLE,
            default => DegreCertitude::ETABLI,
        };
    }
}
