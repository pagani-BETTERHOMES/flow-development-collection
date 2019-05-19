<?php
namespace Neos\Flow\Tests\Unit\Http;

/*
 * This file is part of the Neos.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Neos\Flow\Http\Client;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Http\Factories\ServerRequestFactory;
use Neos\Http\Factories\UriFactory;
use Psr\Http\Message\RequestInterface;

/**
 * Test case for the Http Cookie class
 */
class BrowserTest extends UnitTestCase
{
    /**
     * @var Client\Browser
     */
    protected $browser;

    /**
     *
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->browser = new Client\Browser();
        $this->inject($this->browser, 'serverRequestFactory', new ServerRequestFactory(new UriFactory()));
    }

    /**
     * @test
     */
    public function requestingUriQueriesRequestEngine()
    {
        $requestEngine = $this->createMock(Client\RequestEngineInterface::class);
        $requestEngine
            ->expects($this->once())
            ->method('sendRequest')
            ->with($this->isInstanceOf(RequestInterface::class))
            ->will($this->returnValue(new Response()));
        $this->browser->setRequestEngine($requestEngine);
        $this->browser->request('http://localhost/foo');
    }

    /**
     * @test
     */
    public function automaticHeadersAreSetOnEachRequest()
    {
        $requestEngine = $this->createMock(Client\RequestEngineInterface::class);
        $requestEngine
            ->expects($this->any())
            ->method('sendRequest')
            ->willReturn(new Response());
        $this->browser->setRequestEngine($requestEngine);

        $this->browser->addAutomaticRequestHeader('X-Test-Header', 'Acme');
        $this->browser->addAutomaticRequestHeader('Content-Type', 'text/plain');
        $this->browser->request('http://localhost/foo');

        $this->assertTrue($this->browser->getLastRequest()->hasHeader('X-Test-Header'));
        $this->assertSame('Acme', $this->browser->getLastRequest()->getHeaderLine('X-Test-Header'));
        $this->assertStringContainsString('text/plain', $this->browser->getLastRequest()->getHeaderLine('Content-Type'));
    }

    /**
     * @test
     * @depends automaticHeadersAreSetOnEachRequest
     */
    public function automaticHeadersCanBeRemovedAgain()
    {
        $requestEngine = $this->createMock(Client\RequestEngineInterface::class);
        $requestEngine
            ->expects($this->once())
            ->method('sendRequest')
            ->will($this->returnValue(new Response()));
        $this->browser->setRequestEngine($requestEngine);

        $this->browser->addAutomaticRequestHeader('X-Test-Header', 'Acme');
        $this->browser->removeAutomaticRequestHeader('X-Test-Header');
        $this->browser->request('http://localhost/foo');
        $this->assertFalse($this->browser->getLastRequest()->hasHeader('X-Test-Header'));
    }

    /**
     * @test
     */
    public function browserFollowsRedirectionIfResponseTellsSo()
    {
        $initialUri = new Uri('http://localhost/foo');
        $redirectUri = new Uri('http://localhost/goToAnotherFoo');

        $firstResponse = new Response(301, ['Location' => (string)$redirectUri]);
        $secondResponse = new Response(202);

        $requestEngine = $this->createMock(Client\RequestEngineInterface::class);
        $requestEngine
            ->expects($this->at(0))
            ->method('sendRequest')
            ->with($this->callback(function (Http\Request $request) use ($initialUri) {
                return $request->getUri() == $initialUri;
            }))
            ->will($this->returnValue($firstResponse));
        $requestEngine
            ->expects($this->at(1))
            ->method('sendRequest')
            ->with($this->callback(function (Http\Request $request) use ($redirectUri) {
                return $request->getUri() == $redirectUri;
            }))
            ->will($this->returnValue($secondResponse));

        $this->browser->setRequestEngine($requestEngine);
        $actual = $this->browser->request($initialUri);
        $this->assertSame($secondResponse, $actual);
    }

    /**
     * @test
     */
    public function browserDoesNotRedirectOnLocationHeaderButNot3xxResponseCode()
    {
        $twoZeroOneResponse = new Response(201, ['Location' => 'http://localhost/createdResource/isHere']);

        $requestEngine = $this->createMock(Client\RequestEngineInterface::class);
        $requestEngine
            ->expects($this->once())
            ->method('sendRequest')
            ->will($this->returnValue($twoZeroOneResponse));

        $this->browser->setRequestEngine($requestEngine);
        $actual = $this->browser->request('http://localhost/createSomeResource');
        $this->assertSame($twoZeroOneResponse, $actual);
    }

    /**
     * @test
     */
    public function browserHaltsOnAttemptedInfiniteRedirectionLoop()
    {
        $this->expectException(Client\InfiniteRedirectionException::class);
        $wildResponses = [];
        $wildResponses[0] = new Response(301, ['Location' => 'http://localhost/pleaseGoThere']);
        $wildResponses[1] = new Response(301, ['Location' => 'http://localhost/ahNoPleaseRatherGoThere']);
        $wildResponses[2] = new Response(301, ['Location' => 'http://localhost/youNoWhatISendYouHere']);
        $wildResponses[3] = new Response(301, ['Location' => 'http://localhost/ahNoPleaseRatherGoThere']);

        $requestEngine = $this->createMock(Client\RequestEngineInterface::class);
        for ($i=0; $i<=3; $i++) {
            $requestEngine
                ->expects($this->at($i))
                ->method('sendRequest')
                ->will($this->returnValue($wildResponses[$i]));
        }

        $this->browser->setRequestEngine($requestEngine);
        $this->browser->request('http://localhost/mayThePaperChaseBegin');
    }

    /**
     * @test
     */
    public function browserHaltsOnExceedingMaximumRedirections()
    {
        $this->expectException(Client\InfiniteRedirectionException::class);
        $requestEngine = $this->createMock(Client\RequestEngineInterface::class);
        for ($i=0; $i<=10; $i++) {
            $response = new Response(301, ['Location' => 'http://localhost/this/willLead/you/knowhere/' . $i]);
            $requestEngine
                ->expects($this->at($i))
                ->method('sendRequest')
                ->will($this->returnValue($response));
        }

        $this->browser->setRequestEngine($requestEngine);
        $this->browser->request('http://localhost/some/initialRequest');
    }
}
