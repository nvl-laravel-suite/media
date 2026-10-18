<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Enums\MediaOwnerSlotOperationType;
use Nvl\Media\Providers\MediaServiceProvider;
use Nvl\Media\Services\MediaOwnerSlotIdempotency;
use Nvl\Media\Tests\Fixtures\OwnershipActor;
use Nvl\Media\Tests\Fixtures\OwnershipCompany;
use Nvl\Media\Tests\Fixtures\OwnershipMediaOwner;
use Nvl\Media\Tests\Fixtures\OwnershipWorkerProvider;
use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\Foundation\Application;

require dirname(__DIR__, 5).'/vendor/autoload.php';
$source = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$options = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
if (! is_array($source) || ($source['driver'] ?? null) !== 'pgsql'
    || ! is_string($source['database'] ?? null) || preg_match('/^nvl_own_test_[a-z0-9_]+$/D', $source['database']) !== 1
    || ! is_array($options) || ! is_string($options['root'] ?? null)) {
    throw new RuntimeException('Media workers require a dedicated native persistence source.');
}
OwnershipWorkerProvider::$source = $source;
OwnershipWorkerProvider::$root = $options['root'];
$app = Application::create(options: ['load_environment_variables' => false, 'extra' => ['dont-discover' => ['*'], 'providers' => [LocaleServiceProvider::class, SupportServiceProvider::class, DataServiceProvider::class, FilterableServiceProvider::class, TranslatableServiceProvider::class, OwnershipServiceProvider::class, MediaServiceProvider::class, OwnershipWorkerProvider::class]]]);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (($options['mode'] ?? 'queue') === 'receipt') {
    file_put_contents($options['ready'], 'ready');
    $deadline = microtime(true) + 15;
    while (file_get_contents($options['gate']) !== 'go') {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Media race barrier timed out.');
        }
        usleep(10000);
    }
    $id = $app->make(OwnershipRunner::class)->run(OwnershipCompany::query()->findOrFail($options['company']), OwnershipActor::query()->firstOrFail(), function () use ($options): string {
        $receipts = app(MediaOwnerSlotIdempotency::class);
        $claim = $receipts->begin($options['key'], MediaActorData::system(), OwnershipMediaOwner::query()->findOrFail($options['owner']), 'documents', MediaOwnerSlotOperationType::Clear, []);
        $receipts->complete($claim, null);

        return $claim->operationId;
    });
    fwrite(STDOUT, $id);
} else {
    $status = $kernel->call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 10, '--force' => true, '--no-interaction' => true]);
    if ($status !== 0) {
        fwrite(STDERR, $kernel->output());
        exit($status);
    }
    fwrite(STDOUT, $app->make(OwnershipContext::class)->snapshot()->mode->value);
}
