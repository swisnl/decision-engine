<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Exceptions;

/**
 * Could not connect or the connection dropped before a response arrived.
 */
final class ConnectionException extends TransportException {}
