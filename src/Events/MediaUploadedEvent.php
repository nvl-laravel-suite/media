<?php

declare(strict_types=1);

namespace Nvl\Media\Events;

/** @api
 * @deprecated Use MediaUploaded with the versioned scalar payload; removed no earlier than major 6. */
class_alias(MediaUploaded::class, __NAMESPACE__.'\\MediaUploadedEvent');
