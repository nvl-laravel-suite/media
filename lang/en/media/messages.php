<?php

declare(strict_types=1);

return [
    'success' => [
        'uploaded' => 'Files uploaded successfully.',
        'updated' => 'Media updated successfully.',
        'deleted' => 'Media deleted successfully.',
        'attached' => 'Media attached successfully.',
        'detached' => 'Media detached successfully.',
        'reordered' => 'Media reordered successfully.',
        'renamed' => 'Media renamed successfully.',
        'replaced' => 'Media replaced successfully.',
        'variations_regenerated' => 'Variations regenerated successfully.',
        'bulk_completed' => 'Bulk :action completed successfully.',
    ],
    'error' => [
        'operation_failed' => 'Operation failed. Please try again.',
        'variations_unsupported' => 'Media type does not support variations.',
        'file_not_found_on_disk' => 'File not found on disk.',
        'resource_not_found' => 'Resource not found.',
        'associable_type_must_support_media' => 'The selected associable type does not support Media module operations.',
        'unauthorized' => 'This action is unauthorized.',
        'unexpected' => 'An unexpected error occurred.',
    ],
];
