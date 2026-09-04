<?php

namespace App\Service;

use App\Exception\OpenRouteServiceException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OpenRouteService
{
    private const AUTOCOMPLETE_URL = 'https://api.openrouteservice.org/geocode/autocomplete';
    private const DIRECTIONS_URL = 'https://api.openrouteservice.org/v2/directions/driving-car';
    private const DEFAULT_ROUTING_RADIUS_METERS = 1000;
    private const LARGE_POI_ROUTING_RADIUS_METERS = 2000;
    private const NOT_ROUTABLE_ERROR_CODE = 2010;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
    ) {
    }

    /**
     * @return list<array{label: string, latitude: float, longitude: float, layer: ?string}>
     */
    public function autocomplete(string $text, string $country = 'ES', int $limit = 5): array
    {
        $this->assertConfigured();

        [$statusCode, $data] = $this->requestJson('GET', self::AUTOCOMPLETE_URL, [
            'headers' => $this->authorizationHeaders(),
            'query' => [
                'text' => $text,
                'size' => max(1, min(10, $limit)),
                'boundary.country' => strtoupper($country),
            ],
        ]);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new OpenRouteServiceException(OpenRouteServiceException::UPSTREAM_FAILURE);
        }

        $results = [];
        foreach ($data['features'] ?? [] as $feature) {
            $label = trim((string) ($feature['properties']['label'] ?? ''));
            $coordinates = $feature['geometry']['coordinates'] ?? null;
            if (
                $label === ''
                || !is_array($coordinates)
                || !isset($coordinates[0], $coordinates[1])
                || !is_numeric($coordinates[0])
                || !is_numeric($coordinates[1])
            ) {
                continue;
            }

            $results[] = [
                'label' => $label,
                'latitude' => (float) $coordinates[1],
                'longitude' => (float) $coordinates[0],
                'layer' => isset($feature['properties']['layer'])
                    ? (string) $feature['properties']['layer']
                    : null,
            ];
        }

        return $results;
    }

    public function drivingDistanceKilometers(
        float $originLatitude,
        float $originLongitude,
        float $destinationLatitude,
        float $destinationLongitude,
    ): float {
        $this->assertConfigured();

        $coordinates = [
            [$originLongitude, $originLatitude],
            [$destinationLongitude, $destinationLatitude],
        ];

        [$statusCode, $data] = $this->requestDirections(
            $coordinates,
            self::DEFAULT_ROUTING_RADIUS_METERS,
        );

        if ($this->isNotRoutable($data)) {
            [$statusCode, $data] = $this->requestDirections(
                $coordinates,
                self::LARGE_POI_ROUTING_RADIUS_METERS,
            );
        }

        if ($this->isNotRoutable($data)) {
            throw new OpenRouteServiceException(OpenRouteServiceException::NOT_ROUTABLE);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new OpenRouteServiceException(OpenRouteServiceException::UPSTREAM_FAILURE);
        }

        $distanceMeters = $data['routes'][0]['summary']['distance']
            ?? $data['routes'][0]['segments'][0]['distance']
            ?? null;
        if (!is_numeric($distanceMeters) || (float) $distanceMeters < 0) {
            throw new OpenRouteServiceException(OpenRouteServiceException::UPSTREAM_FAILURE);
        }

        return round((float) $distanceMeters / 1000, 2);
    }

    /**
     * @param array<int, array{0: float, 1: float}> $coordinates
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function requestDirections(array $coordinates, int $radiusMeters): array
    {
        return $this->requestJson('POST', self::DIRECTIONS_URL, [
            'headers' => $this->authorizationHeaders() + ['Content-Type' => 'application/json'],
            'json' => [
                'coordinates' => $coordinates,
                'radiuses' => [$radiusMeters, $radiusMeters],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function requestJson(string $method, string $url, array $options): array
    {
        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new OpenRouteServiceException(
                OpenRouteServiceException::UPSTREAM_FAILURE,
                $exception,
            );
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new OpenRouteServiceException(
                OpenRouteServiceException::UPSTREAM_FAILURE,
                $exception,
            );
        }

        if (!is_array($data)) {
            throw new OpenRouteServiceException(OpenRouteServiceException::UPSTREAM_FAILURE);
        }

        return [$statusCode, $data];
    }

    /** @param array<string, mixed> $data */
    private function isNotRoutable(array $data): bool
    {
        return (int) ($data['error']['code'] ?? 0) === self::NOT_ROUTABLE_ERROR_CODE;
    }

    /** @return array{Authorization: string, Accept: string} */
    private function authorizationHeaders(): array
    {
        return [
            'Authorization' => $this->apiKey,
            'Accept' => 'application/json',
        ];
    }

    private function assertConfigured(): void
    {
        if (trim($this->apiKey) === '') {
            throw new OpenRouteServiceException(OpenRouteServiceException::UPSTREAM_FAILURE);
        }
    }
}
