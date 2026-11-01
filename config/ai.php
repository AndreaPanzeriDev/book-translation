<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Timeout delle richieste AI
    |--------------------------------------------------------------------------
    |
    | I modelli locali possono richiedere molto tempo al primo caricamento.
    | Aumentare AI_REQUEST_TIMEOUT se si usano modelli grandi su CPU/GPU
    | con poca memoria.
    |
    */

    'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 15),

    'request_timeout' => (int) env('AI_REQUEST_TIMEOUT', 300),
];
