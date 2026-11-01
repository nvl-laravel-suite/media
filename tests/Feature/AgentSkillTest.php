<?php

declare(strict_types=1);

use Nvl\Media\Enums\MediaDeliveryMode;
use Nvl\Media\Enums\MediaVisibility;
use Nvl\Media\Services\MediaDeliveryPolicy;

test('the shipped skill configures separate public and private delivery policies', function (): void {
    $skill = file_get_contents(dirname(__DIR__, 2).'/resources/boost/skills/nvl-media/SKILL.md');
    expect(preg_match('/```php\n(.*?)\n```/s', $skill, $example))->toBe(1);
    $configuration = eval($example[1]);
    foreach ($configuration as $key => $value) {
        config(['nvl-media.'.$key => $value]);
    }
    foreach (['public', 'private', 's3'] as $disk) {
        config(['filesystems.disks.'.$disk => ['driver' => 'local', 'root' => sys_get_temp_dir().'/nvl-media-skill/'.$disk]]);
    }

    $policy = app(MediaDeliveryPolicy::class);
    $policy->validate();

    expect($policy->mode('public', MediaVisibility::Public))->toBe(MediaDeliveryMode::Direct)
        ->and($policy->mode('public', MediaVisibility::Public, MediaDeliveryMode::Application))->toBe(MediaDeliveryMode::Application)
        ->and($policy->mode('private', MediaVisibility::Private))->toBe(MediaDeliveryMode::Application)
        ->and($policy->mode('private', MediaVisibility::Private, MediaDeliveryMode::SignedApplication))->toBe(MediaDeliveryMode::SignedApplication)
        ->and($policy->mode('private', MediaVisibility::Private, MediaDeliveryMode::TemporaryStorage))->toBe(MediaDeliveryMode::TemporaryStorage);

    $policy->assertExpiration('private', now()->addSeconds(60));
    expect(fn () => $policy->assertVisibility('public', MediaVisibility::Private))->toThrow(RuntimeException::class)
        ->and(fn () => $policy->assertVisibility('private', MediaVisibility::Public))->toThrow(RuntimeException::class)
        ->and(fn () => $policy->assertExpiration('private', now()->addSeconds(121)))->toThrow(RuntimeException::class);
});
