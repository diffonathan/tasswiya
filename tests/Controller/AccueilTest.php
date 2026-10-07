<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Le test de fumee de l'ecran de verification.
 *
 * Il verifie surtout une chose qui n'est pas technique : l'avertissement
 * « ceci n'est pas un conseil juridique » et la mention de la source sont
 * PRESENTS. Ce sont des contraintes du cahier des charges, donc elles sont
 * epinglees par un test — sinon la premiere refonte du gabarit les emportera
 * sans que rien ne le signale.
 */
final class AccueilTest extends WebTestCase
{
    public function testLAccueilRepondEtPorteSonAvertissement(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tasswiya');
        self::assertAnySelectorTextContains('.avertissement', "Ceci n'est pas un conseil juridique");
        self::assertAnySelectorTextContains('footer', 'Seule la version arabe fait foi');
    }

    /**
     * LA SUBSTITUTION D'IMPLEMENTATION, prouvee et non racontee.
     *
     * Le conteneur remplace l'horloge systeme par une horloge figee en
     * environnement de test. Aucune ligne du controleur ne change : il demande
     * `ClockInterface`, et c'est le conteneur qui decide laquelle il recoit.
     * La page affiche donc la date figee — ce qui serait impossible si une
     * seule classe de la chaine ecrivait `new DateTimeImmutable()`.
     */
    public function testLeConteneurSubstitueUneHorlogeFigeeEtLaPageLAffiche(): void
    {
        $client = static::createClient();

        $horloge = static::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(MockClock::class, $horloge);
        self::assertSame('2026-01-30', $horloge->now()->format('Y-m-d'));

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // Si le controleur lisait l'horloge systeme, cette assertion
        // echouerait des demain. Elle tient parce qu'il ne la lit pas.
        self::assertAnySelectorTextContains('td', '30/01/2026');
        self::assertAnySelectorTextContains('td', MockClock::class);
    }
}
