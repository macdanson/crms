<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The addresses of the load balancers and reverse proxies in front of this
    | application (comma-separated; "*" trusts whichever one connects, only
    | safe when nothing can reach the application except through them).
    |
    | Must be set behind a proxy. Otherwise every client appears to come from
    | the proxy, so all guests share one rate limit and audit records show the
    | wrong address, and HTTPS is not recognised, so HSTS is not sent.
    |
    | Read by the framework's TrustProxies middleware.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
