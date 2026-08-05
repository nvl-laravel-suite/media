<?php

declare(strict_types=1);

use Nvl\Data\Services\TypeScriptSourceRegistry;
use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration)
    ->ignoreErrorsOnPackage('nvl/media', [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackage('nvl/data', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage(
        'orchestra/testbench-core',
        [ErrorType::SHADOW_DEPENDENCY],
    )
    ->addForceUsedSymbol(TypeScriptSourceRegistry::class);
