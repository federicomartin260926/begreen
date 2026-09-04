<?php

namespace App\Tests\Service;

use App\Exception\OpenRouteServiceException;
use App\Service\OpenRouteService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenRouteServiceTest extends TestCase
{
    private const API_KEY = 'test-key-not-a-secret';

    public function testCalculatesRegularDrivingDistanceWithDefaultRadius(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.openrouteservice.org/v2/directions/driving-car', $url);
            self::assertStringContainsString(
                'authorization: '.self::API_KEY,
                strtolower(implode("\n", $options['headers'])),
            );
            self::assertStringNotContainsString(self::API_KEY, $url);

            $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([1000, 1000], $payload['radiuses']);
            self::assertSame([[-3.7038, 40.4168], [2.1734, 41.3851]], $payload['coordinates']);

            return $this->routeResponse(623960.0);
        });

        $distance = $this->service($client)->drivingDistanceKilometers(
            40.4168,
            -3.7038,
            41.3851,
            2.1734,
        );

        self::assertSame(623.96, $distance);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testRetriesWithBoundedRadiusForLargePoiCentroid(): void
    {
        $attempt = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$attempt): MockResponse {
            $attempt++;
            $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);

            if ($attempt === 1) {
                self::assertSame([1000, 1000], $payload['radiuses']);

                return $this->notRoutableResponse(1000);
            }

            self::assertSame([2000, 2000], $payload['radiuses']);

            return $this->routeResponse(607933.6);
        });

        $distance = $this->service($client)->drivingDistanceKilometers(
            40.41831,
            -3.70275,
            41.294636,
            2.084644,
        );

        self::assertSame(607.93, $distance);
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testKeepsTrulyNonRoutableCoordinatesAsControlledError(): void
    {
        $client = new MockHttpClient([
            $this->notRoutableResponse(1000),
            $this->notRoutableResponse(2000),
        ]);

        try {
            $this->service($client)->drivingDistanceKilometers(28.0, -17.0, 28.1, -17.1);
            self::fail('A non-routable route must fail.');
        } catch (OpenRouteServiceException $exception) {
            self::assertSame(OpenRouteServiceException::NOT_ROUTABLE, $exception->reason);
        }

        self::assertSame(2, $client->getRequestsCount());
    }

    #[DataProvider('upstreamFailureProvider')]
    public function testRejectsUpstreamHttpErrors(int $statusCode): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'error' => ['code' => 2000, 'message' => 'Upstream failure'],
        ], JSON_THROW_ON_ERROR), ['http_code' => $statusCode]));

        try {
            $this->service($client)->drivingDistanceKilometers(40.4, -3.7, 41.3, 2.1);
            self::fail('An upstream HTTP error must fail.');
        } catch (OpenRouteServiceException $exception) {
            self::assertSame(OpenRouteServiceException::UPSTREAM_FAILURE, $exception->reason);
        }

        self::assertSame(1, $client->getRequestsCount());
    }

    public static function upstreamFailureProvider(): iterable
    {
        yield 'client error' => [400];
        yield 'server error' => [500];
    }

    public function testAutocompleteStaysBehindBackendAndReturnsOnlyRequiredData(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringNotContainsString(self::API_KEY, $url);
            self::assertStringContainsString(
                'authorization: '.self::API_KEY,
                strtolower(implode("\n", $options['headers'])),
            );
            self::assertSame('Madrid', $options['query']['text']);
            self::assertSame('ES', $options['query']['boundary.country']);

            return new MockResponse(json_encode([
                'features' => [[
                    'geometry' => ['coordinates' => [-3.70275, 40.41831]],
                    'properties' => [
                        'label' => 'Sol, Madrid, Spain',
                        'layer' => 'neighbourhood',
                        'source_id' => 'not-exposed',
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));
        });

        self::assertSame([[
            'label' => 'Sol, Madrid, Spain',
            'latitude' => 40.41831,
            'longitude' => -3.70275,
            'layer' => 'neighbourhood',
        ]], $this->service($client)->autocomplete('Madrid'));
    }

    private function service(MockHttpClient $client): OpenRouteService
    {
        return new OpenRouteService($client, self::API_KEY);
    }

    private function routeResponse(float $distanceMeters): MockResponse
    {
        return new MockResponse(json_encode([
            'routes' => [[
                'summary' => ['distance' => $distanceMeters],
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    private function notRoutableResponse(int $radius): MockResponse
    {
        return new MockResponse(json_encode([
            'error' => [
                'code' => 2010,
                'message' => sprintf('Could not find routable point within %d meters.', $radius),
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 404]);
    }
}
