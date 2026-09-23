<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Contracts;

use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * Sends one prepared request and returns the raw response. Blocking.
 *
 * Implementations throw only TransportException subclasses (ConnectionException, TimeoutException)
 * and never on HTTP status codes — the engine interprets those.
 *
 * ```php
 * $response = $transport->send($prepared, new RequestOptions(timeout: 3.0));
 * ```
 */
interface Transport
{
    public function send(PreparedRequest $request, RequestOptions $options): HttpResponse;
}
