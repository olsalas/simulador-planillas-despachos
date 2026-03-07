<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Routing Provider
    |--------------------------------------------------------------------------
    |
    | Supported: auto, here, mock
    |
    */
    'provider' => env('ROUTING_PROVIDER', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Route Cache
    |--------------------------------------------------------------------------
    |
    | Route previews are cached using the provider name + ordered waypoints.
    |
    */
    'cache_ttl_seconds' => (int) env('ROUTING_CACHE_TTL_SECONDS', 86400),

    /*
    |--------------------------------------------------------------------------
    | Fallback Depot
    |--------------------------------------------------------------------------
    |
    | Used when a batch has no driver CEDIS assigned and no active depot exists.
    |
    */
    'fallback_depot' => [
        'name' => env('ROUTING_FALLBACK_DEPOT_NAME', 'CEDIS Fallback'),
        'latitude' => env('ROUTING_FALLBACK_DEPOT_LAT'),
        'longitude' => env('ROUTING_FALLBACK_DEPOT_LNG'),
    ],
];
