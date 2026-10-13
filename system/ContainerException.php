<?php

declare(strict_types=1);

namespace Kanin;

/**
 * Thrown when the container cannot build or call something.
 *
 * Example: asking for an interface that was never bound, or a class whose
 * constructor needs a value the container cannot guess (like a plain string).
 */
class ContainerException extends \RuntimeException
{
}
