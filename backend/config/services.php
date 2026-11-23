<?php

return [

    'reus_transport' => [
        'base_url' => env('REUS_TRANSPORT_BASE_URL', 'https://rtp.amersam.net'),
        'sync_time' => env('REUS_SYNC_TIME', '03:30'),
        // Los horarios del proveedor están en hora local de Reus.
        'timezone' => env('REUS_TIMEZONE', 'Europe/Madrid'),
    ],

    'nominatim' => ['url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org')],

    // Red interurbana del Camp de Tarragona (tablas plana_*): GTFS de autobuses interurbanos de la
    // Generalitat (datos abiertos: hay que citar la fuente y la fecha). Se importan las líneas con
    // alguna parada dentro de «area» [latMín, latMáx, lngMín, lngMáx] y, si se indica, solo de los
    // operadores cuyo nombre contiene «agency» (vacío = todos).
    'plana' => [
        'gtfs_url' => env('PLANA_GTFS_URL', 'https://analisi.transparenciacatalunya.cat/download/bca2-b4i3/application/zip'),
        'agency' => env('PLANA_AGENCY', ''),
        // Alt Camp, Baix Camp, Tarragonès, Priorat y Conca de Barberà.
        'area' => [40.95, 41.50, 0.65, 1.45],
    ],

    // Servidor OSRM con perfiles routed-car y routed-foot (FOSSGIS, uso moderado).
    'osm_routing' => ['url' => env('OSM_ROUTING_URL', 'https://routing.openstreetmap.de')],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
