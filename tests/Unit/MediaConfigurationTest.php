<?php

declare(strict_types=1);

use Nvl\Media\Support\MediaConfiguration;

it('reads an environment-supplied numeric string as an integer', function (mixed $configured, int $expected): void {
    config(['nvl-media.max_file_size' => $configured]);

    expect(MediaConfiguration::integer('nvl-media.max_file_size', 10, 1))->toBe($expected);
})->with([
    'integer' => [2048, 2048],
    'numeric string' => ['2048', 2048],
    'padded numeric string' => [' 2048 ', 2048],
    'below the minimum' => ['0', 10],
    'not a whole number' => ['20.5', 10],
    'not numeric' => ['2k', 10],
    'empty' => ['', 10],
    'null' => [null, 10],
    'boolean' => [true, 10],
]);
