<?php

// Rate limiting. Utils\Models\Throttle\Throttle counts attempts through the cache
// backends, so one of those has to be registered before a limit can be enforced.
$config['throttle'] = [
    // Which registered cache backend to count in.
    //
    // Null uses Cache's own default: writes go to every registered backend and reads come
    // from the first. That always reads back what it wrote, so it is correct with any
    // number of backends - name one here only to keep counters off a backend that is
    // shared, slow or wiped on deploy.
    'cache' => null,

    // Prepended to the hashed key. Change it to invalidate every counter at once, which is
    // the quickest way to release everyone currently locked out.
    'prefix' => 'sp_throttle_',

    // What to do when the cache backend cannot be reached.
    //
    // True allows the attempt and writes the reason to the error log: redis being down is
    // an outage of the cache, and refusing every login until it returns turns a degraded
    // dependency into a total one.
    //
    // False rethrows, so the request fails. Use it where the limit is the control itself -
    // metering a paid api, or a route whose cost is the reason it is limited - and letting
    // traffic through uncounted is worse than turning it away.
    'fail_open' => true,
];
