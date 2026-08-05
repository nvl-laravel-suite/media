<?php

declare(strict_types=1);

return [
    'fields' => [
        'search' => [
            'label' => 'Search media',
            'placeholder' => 'Search by filename, tag, or type',
        ],
        'type' => [
            'label' => 'Type',
            'placeholder' => 'Filter by type',
        ],
        'collection' => [
            'label' => 'Collection',
            'placeholder' => 'Filter by collection',
        ],
        'files' => [
            'label' => 'Files',
            'placeholder' => 'Click to upload or drag & drop files',
        ],
        'title' => [
            'label' => 'Title',
        ],
        'alt' => [
            'label' => 'Alt Text',
        ],
        'caption' => [
            'label' => 'Caption',
        ],
        'locale' => [
            'label' => 'Locale',
        ],
        'metadata' => [
            'label' => 'Custom Metadata',
        ],
        'metadata_key' => [
            'label' => 'Key',
            'placeholder' => 'e.g. source',
        ],
        'metadata_value' => [
            'label' => 'Value',
            'placeholder' => 'e.g. cms',
        ],
        'tags' => [
            'label' => 'Tags',
        ],
        'visibility' => [
            'label' => 'Public',
        ],
        'disk' => [
            'label' => 'Disk',
        ],
    ],
    'sections' => [
        'filters' => 'Filters',
        'library' => 'Library',
        'usages' => 'Usages',
        'upload' => 'Upload',
        'overview' => 'Media overview',
        'edit' => 'Update media metadata, translations, and visibility',
        'translations' => 'Translations & SEO',
        'raw_metadata' => 'Raw Metadata',
        'raw_metadata_description' => 'Current persisted metadata payload',
    ],
    'hints' => [
        'upload_help' => 'Select one or more files to upload into the media library.',
        'tags_help' => 'Comma-separated tags',
        'metadata_help' => 'Use a JSON object, for example {"source":"cms"}',
    ],
];
