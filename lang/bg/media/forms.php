<?php

declare(strict_types=1);

return [
    'fields' => [
        'search' => [
            'label' => 'Търсене на файлове',
            'placeholder' => 'Търси по име, етикет или тип',
        ],
        'type' => [
            'label' => 'Тип',
            'placeholder' => 'Филтрирай по тип',
        ],
        'collection' => [
            'label' => 'Колекция',
            'placeholder' => 'Филтрирай по колекция',
        ],
        'files' => [
            'label' => 'Файлове',
            'placeholder' => 'Кликни за качване или пусни файлове тук',
        ],
        'title' => [
            'label' => 'Заглавие',
        ],
        'alt' => [
            'label' => 'Алтернативен текст',
        ],
        'caption' => [
            'label' => 'Описание',
        ],
        'locale' => [
            'label' => 'Език',
        ],
        'metadata' => [
            'label' => 'Потребителски метаданни',
        ],
        'metadata_key' => [
            'label' => 'Ключ',
            'placeholder' => 'напр. source',
        ],
        'metadata_value' => [
            'label' => 'Стойност',
            'placeholder' => 'напр. cms',
        ],
        'tags' => [
            'label' => 'Етикети',
        ],
        'visibility' => [
            'label' => 'Публичен',
        ],
        'disk' => [
            'label' => 'Диск',
        ],
    ],
    'sections' => [
        'filters' => 'Филтри',
        'library' => 'Библиотека',
        'usages' => 'Свързани ресурси',
        'upload' => 'Качване',
        'overview' => 'Преглед на файл',
        'edit' => 'Редактиране на метаданни, преводи и видимост',
        'translations' => 'Преводи и SEO',
        'raw_metadata' => 'Сурови метаданни',
        'raw_metadata_description' => 'Текущо запазено съдържание на метаданните',
    ],
    'hints' => [
        'upload_help' => 'Избери един или повече файлове за качване в медийната библиотека.',
        'tags_help' => 'Разделени със запетая етикети',
        'metadata_help' => 'Използвайте JSON обект, например {"source":"cms"}',
    ],
];
