<?php

declare(strict_types=1);

function register_cron_routes(Router $router, Scheduler $scheduler): void
{
    $router->get('/api/cron-log', fn() => json_response(['items' => $scheduler->getLog()]));
}
