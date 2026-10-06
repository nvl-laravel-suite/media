<?php

declare(strict_types=1);

namespace Nvl\Media\Exceptions;

use RuntimeException;

/** Explicit unsupported authorization or storage mode for bounded Media reads.
 *
 * @api
 */
final class MediaBatchReadException extends RuntimeException {}
