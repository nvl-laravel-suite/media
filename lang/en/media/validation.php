<?php

declare(strict_types=1);

return [
    'attributes' => [
        'filename' => 'filename',
        'disk' => 'disk',
        'folder' => 'folder',
        'is_public' => 'public status',
        'tags' => 'tags',
        'metadata' => 'metadata',
        'title' => 'title',
        'alt' => 'alt text',
        'caption' => 'caption',
        'locale' => 'locale',
        'collection' => 'collection',
        'search' => 'search',
        'type' => 'type',
        'mime_type' => 'MIME type',
        'extension' => 'extension',
        'associable_type' => 'associable type',
        'associable_id' => 'associable id',
        'media_id' => 'media',
        'per_page' => 'items per page',
        'page' => 'page',
        'sort_by' => 'sort by',
        'sort_direction' => 'sort direction',
        'order' => 'order',
    ],
    'custom' => [
        'mediaId' => [
            'required' => 'The media field is required.',
            'uuid' => 'The media field must be a valid UUID.',
        ],
        'associableType' => [
            'required' => 'The associable type field is required.',
        ],
        'associableId' => [
            'required' => 'The associable id field is required.',
            'uuid' => 'The associable id must be a valid UUID.',
        ],
        'collection' => [
            'required' => 'The collection field is required.',
        ],
        'sortBy' => [
            'in' => 'The selected sort column is invalid.',
        ],
        'sortDirection' => [
            'in' => 'The selected sort direction is invalid.',
        ],
    ],
    'rules' => [
        'file_required' => 'The :attribute must be a file.',
        'max_file_size' => 'The :attribute must not exceed :size_mb MB.',
        'unsupported_mime_type' => 'The :attribute has an unsupported MIME type [:mime].',
        'image_width_exceeds' => 'The :attribute image width (:width px) exceeds the maximum (:max_width px).',
        'image_height_exceeds' => 'The :attribute image height (:height px) exceeds the maximum (:max_height px).',
        'aspect_ratio_mismatch' => 'The :attribute aspect ratio (:actual_ratio) does not match :required_ratio within tolerance :tolerance.',
    ],
];
