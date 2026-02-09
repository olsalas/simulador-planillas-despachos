<?php

namespace App\Contracts;

interface RoutingProvider
{
    /**
     * Provider identifier used for caching and observability.
     */
    public function name(): string;

    /**
     * Build a street-valid route polyline and aggregate metrics.
     *
     * @param  list<array{lat: float, lng: float}>  $stops
     * @param  array<string, mixed>  $options
     * @return array{
     *   distance_meters: float,
     *   duration_seconds: float,
     *   geometry: list<array{lat: float, lng: float}>,
     *   legs: list<array<string, mixed>>
     * }
     */
    public function buildRoute(array $stops, array $options = []): array;

    /**
     * Build a matrix used for stop ordering and cost estimation.
     *
     * @param  list<array{lat: float, lng: float}>  $stops
     * @param  array<string, mixed>  $options
     * @return array{
     *   distances: list<list<float|null>>,
     *   durations: list<list<float|null>>
     * }
     */
    public function buildMatrix(array $stops, array $options = []): array;
}
