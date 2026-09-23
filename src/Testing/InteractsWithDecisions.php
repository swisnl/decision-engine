<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Testing;

use PHPUnit\Framework\Attributes\After;
use Swis\DecisionEngine\Decision;

/**
 * Restores the real client after each test that used `Decision::fake()`.
 * In Pest you can instead call `afterEach(fn () => Decision::restore())`.
 */
trait InteractsWithDecisions
{
    #[After]
    protected function restoreDecisions(): void
    {
        Decision::restore();
    }
}
