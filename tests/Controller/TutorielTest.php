<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ce que le tutoriel doit continuer de montrer.
 *
 * POURQUOI CE TEST EXISTE. Le tutoriel est une page de documentation, et une
 * page de documentation ment plus facilement qu'un écran de saisie : personne
 * ne s'en sert tous les jours, donc personne ne voit qu'elle a cessé d'être
 * vraie. Sa règle d'or — AUCUNE SORTIE N'EST MAQUETTÉE — n'est tenue que tant
 * que les sorties viennent réellement du moteur, et c'est précisément ce que
 * ces tests épinglent.
 *
 * Ils ne vérifient donc pas une mise en page. Ils vérifient quatre choses, qui
 * sont les quatre promesses de la page :
 *
 *  1. qu'un délai non échu est REFUSÉ et qu'un délai échu BASCULE, au cran
 *     près et sans que personne ait cliqué ;
 *  2. qu'un dossier sans écédar n'a AUCUNE horloge pénale — pas une échéance
 *     lointaine, pas d'échéance du tout ;
 *  3. que le quota de prorogations s'affiche comme un CHOIX DE CE LOGICIEL et
 *     jamais comme la loi 71-24 ;
 *  4. que la page ne laisse rien derrière elle.
 *
 * L'horloge est figée par le conteneur de test (`config/services.yaml`, bloc
 * `when@test`), et le tutoriel calcule ses dossiers à partir de l'instant que
 * le moteur lit : les assertions ci-dessous tiennent donc quel que soit le
 * jour où la suite tourne.
 */
final class TutorielTest extends WebTestCase
{
    /**
     * LE MOMENT FILMÉ, au cran près.
     *
     * Vingt-neuf jours ne suffisent pas. Trente non plus — l'échéance tombe ce
     * jour-là et `Horloge::estDepassee()` exige un dépassement STRICT. Trente
     * et un suffisent. C'est toute la démonstration, et c'est la seule chose
     * de cette page qu'une régression rendrait fausse sans bruit.
     */
    public function testLeDossierNeBasculeQuAuTrenteEtUniemeJour(): void
    {
        $client = static::createClient();

        foreach ([0, 29, 30] as $jours) {
            $client->request('GET', '/tutoriel', ['jours' => $jours]);

            self::assertResponseIsSuccessful();
            self::assertAnySelectorTextContains(
                '.tut-bascule',
                'ecedar_notifie',
                \sprintf('À %d jours, le dossier doit être resté à l\'écédar.', $jours)
            );
            self::assertSelectorTextNotContains(
                '.tut-bascule',
                'delai_expire',
                \sprintf('À %d jours, aucune bascule ne doit avoir eu lieu.', $jours)
            );
        }

        $client->request('GET', '/tutoriel', ['jours' => 31]);

        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('.tut-bascule', 'delai_expire');
    }

    /**
     * Et la bascule est obtenue par la GARDE, pas par le tutoriel.
     *
     * Tant que le délai court, le refus affiché porte le code de blocage du
     * catalogue, son article et son degré. Un écran qui griserait un bouton
     * sans dire pourquoi échouerait ici.
     */
    public function testLeRefusPorteSonCodeSonArticleEtSonDegre(): void
    {
        $client = static::createClient();
        $client->request('GET', '/tutoriel', ['jours' => 29]);

        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('.tut-volet', 'delai_penal_non_expire');
        self::assertAnySelectorTextContains('.regle--etabli', 'art. 325 al. 6');
        self::assertAnySelectorTextContains('.regle--etabli .regle__degre', 'établi');
    }

    /**
     * Sans écédar, il n'y a pas de délai — et c'est le point contre-intuitif
     * du § 1.
     *
     * `joursRestantsSurLeDelaiPenal()` rend `null` et non zéro : zéro voudrait
     * dire « il expire aujourd'hui », ce qui est faux et grave pour qui compte
     * sur ce chiffre.
     */
    public function testUnDossierSansEcedarNAAucuneHorlogePenale(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/tutoriel', ['jours' => 29]);

        self::assertResponseIsSuccessful();

        $colonnes = $crawler->filter('.tut-deux .tut-volet');
        self::assertGreaterThanOrEqual(2, $colonnes->count());

        $sansEcedar = $crawler->filter('.tut-predicats')->eq(1)->text();
        self::assertStringContainsString('null', $sansEcedar);

        // Et le refus n'est même pas temporel : c'est la FORME DU GRAPHE qui
        // s'y oppose, puisque la transition ne part pas de sa place.
        self::assertAnySelectorTextContains('.tut-volet', 'Refusée par le marquage');
    }

    /**
     * LE PASSAGE LE PLUS IMPORTANT, et celui qu'une relecture distraite
     * casserait.
     *
     * Le quota de prorogations est un CHOIX_PRODUIT. La loi 71.24 ne limite
     * PAS le nombre de prorogations : l'article 325 al. 8 ouvre une durée
     * « égale ou supérieure » sans plafond ni limite de nombre. L'écran doit
     * donc le dire LÀ OÙ IL BLOQUE, et son attribution ne doit jamais être
     * « loi n° 71.24 ».
     */
    public function testLeQuotaSAfficheCommeUnChoixDeCeLogicielEtJamaisCommeLaLoi(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/tutoriel', ['jours' => 29]);

        self::assertResponseIsSuccessful();

        $choix = $crawler->filter('.regle--choix-produit');
        self::assertGreaterThan(0, $choix->count(), 'Le traitement visuel du choix produit doit être posé.');

        $texte = $choix->first()->text();
        self::assertStringContainsString('Limite de ce logiciel', $texte);
        self::assertStringContainsString('ne limite PAS le nombre de prorogations', $texte);

        // L'attribution d'une règle CHOIX_PRODUIT ne doit pas invoquer la loi.
        $attribution = $choix->first()->filter('.regle__attribution')->first()->text();
        self::assertStringContainsString('Hypothèse de ce logiciel', $attribution);
        self::assertStringNotContainsString('BO n° 7478', $attribution);

        // Et la page ne doit nulle part affirmer que la loi limite les
        // prorogations : c'est l'erreur que tout le dossier de règles combat.
        self::assertStringNotContainsString(
            'la loi limite',
            mb_strtolower((string) $client->getResponse()->getContent())
        );
    }

    /**
     * Un lien recopié de travers ne doit pas rendre une erreur.
     *
     * Depuis Symfony 7, `InputBag::getInt()` lève sur une valeur non
     * numérique : un `?jours=abc` aurait rendu 400. Une page de documentation
     * se partage par lien, par message, par capture d'écran ; elle doit se
     * montrer même quand son paramètre est abîmé, et repartir du premier cran.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('saisiesAbimees')]
    public function testUneSaisieAbimeeNeRendPasUneErreur(string $valeur): void
    {
        $client = static::createClient();
        $client->request('GET', '/tutoriel', ['jours' => $valeur]);

        self::assertResponseIsSuccessful(\sprintf('« jours=%s » doit rendre la page, pas une erreur.', $valeur));
        self::assertSelectorExists('.tut-commande');
    }

    /** @return iterable<string, array{string}> */
    public static function saisiesAbimees(): iterable
    {
        yield 'du texte' => ['abc'];
        yield 'un nombre négatif' => ['-5'];
        yield 'un décimal' => ['3.7'];
        yield 'vide' => [''];
        yield 'au-delà du plafond' => ['99999'];
    }

    /**
     * L'avertissement est sur CET écran aussi, et il n'a pas eu à y être posé
     * à la main : il vient du gabarit de base.
     */
    public function testLAvertissementJuridiqueEstPresent(): void
    {
        $client = static::createClient();
        $client->request('GET', '/tutoriel');

        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('.avertissement', "Ceci n'est pas un conseil juridique");
    }

    /**
     * Le tutoriel ne laisse rien derrière lui.
     *
     * Une page de documentation qui écrirait en base, ou qui déplacerait
     * l'horloge globale de l'installation, ferait mentir les écrans de
     * consultation d'à côté. Deux appels successifs doivent donc rendre
     * exactement la même chose, et l'horloge doit être intacte après.
     */
    public function testLaPageNeLaisseAucunEffetDeBord(): void
    {
        $client = static::createClient();

        $horloge = static::getContainer()->get(\Psr\Clock\ClockInterface::class);
        $avant = $horloge->now()->format('c');

        $client->request('GET', '/tutoriel', ['jours' => 31]);
        $premier = (string) $client->getResponse()->getContent();

        $client->request('GET', '/tutoriel', ['jours' => 31]);
        $second = (string) $client->getResponse()->getContent();

        self::assertSame(
            $this->sansJetonsVolatils($premier),
            $this->sansJetonsVolatils($second),
            'Deux chargements identiques doivent rendre la même page : sinon la démonstration a un état.'
        );
        self::assertSame($avant, $horloge->now()->format('c'), 'L\'horloge injectée doit être intacte.');
    }

    /**
     * Retire ce qui change légitimement d'une requête à l'autre.
     *
     * Les entités du domaine tirent un UUID v7 à la construction : deux
     * chargements créent donc des dossiers différents, et c'est exactement ce
     * qu'on veut — rien n'est partagé. Ces identifiants ne s'affichent pas,
     * mais la barre de débogage les voit passer ; on neutralise donc aussi son
     * jeton, qui est unique par requête par construction.
     */
    private function sansJetonsVolatils(string $html): string
    {
        return (string) preg_replace('/[0-9a-f]{6,}/i', 'X', $html);
    }
}
