<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Integration;

use OpenCredits\Credits\Service\RequestCache;
use PHPUnit\Framework\TestCase;

/**
 * Request-isolation base: every case starts from an empty RequestCache, the
 * same seam Listener::visitorSetup() uses at the request boundary.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestCache::reset();
    }
}
