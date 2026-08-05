<?php

declare(strict_types=1);

return [
    'attributes' => [
        'filename' => 'име на файл',
        'disk' => 'диск',
        'folder' => 'папка',
        'is_public' => 'публичност',
        'tags' => 'етикети',
        'metadata' => 'метаданни',
        'title' => 'заглавие',
        'alt' => 'алт текст',
        'caption' => 'описание',
        'locale' => 'език',
        'collection' => 'колекция',
        'search' => 'търсене',
        'type' => 'тип',
        'mime_type' => 'MIME тип',
        'extension' => 'разширение',
        'associable_type' => 'тип на асоциирания модел',
        'associable_id' => 'идентификатор на асоциирания модел',
        'media_id' => 'файл',
        'per_page' => 'брой на страница',
        'page' => 'страница',
        'sort_by' => 'сортиране по',
        'sort_direction' => 'посока на сортиране',
        'order' => 'ред',
    ],
    'custom' => [
        'mediaId' => [
            'required' => 'Полето за файл е задължително.',
            'uuid' => 'Полето за файл трябва да бъде валиден UUID.',
        ],
        'associableType' => [
            'required' => 'Полето за тип на асоциирания модел е задължително.',
        ],
        'associableId' => [
            'required' => 'Полето за идентификатор на асоциирания модел е задължително.',
            'uuid' => 'Идентификаторът на асоциирания модел трябва да бъде валиден UUID.',
        ],
        'collection' => [
            'required' => 'Полето за колекция е задължително.',
        ],
        'sortBy' => [
            'in' => 'Избраната колона за сортиране е невалидна.',
        ],
        'sortDirection' => [
            'in' => 'Избраната посока за сортиране е невалидна.',
        ],
    ],
    'rules' => [
        'file_required' => 'Полето :attribute трябва да бъде файл.',
        'max_file_size' => 'Полето :attribute не може да надвишава :size_mb MB.',
        'unsupported_mime_type' => 'Полето :attribute има неподдържан MIME тип [:mime].',
        'image_width_exceeds' => 'Ширината на :attribute (:width px) надвишава максималната (:max_width px).',
        'image_height_exceeds' => 'Височината на :attribute (:height px) надвишава максималната (:max_height px).',
        'aspect_ratio_mismatch' => 'Съотношението на :attribute (:actual_ratio) не съвпада с :required_ratio в рамките на толеранса :tolerance.',
    ],
];
