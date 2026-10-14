<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Service\Lookup;

/**
 * One provider answered 429 / exhausted its budget window. The service treats
 * this as a provider-level cooldown signal — NOT a user-facing failure; the
 * cascade simply moves on (or reports 'unavailable' if nothing else remains).
 */
final class ProviderRateLimitedException extends \RuntimeException
{
}
