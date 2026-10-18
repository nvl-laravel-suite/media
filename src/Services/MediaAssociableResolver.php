<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Nvl\Media\Contracts\HasNvlMedia;
use Nvl\Media\Ownership\MediaOwnershipParentResolver;
use Nvl\Support\OwnerRegistry;

/** MediaAssociableResolver validates and loads Media-capable associable models for API mutations. */
final class MediaAssociableResolver
{
    /** Create the mutation resolver with package ownership and shared identity boundaries. */
    public function __construct(
        private readonly MediaOwnershipParentResolver $owners,
        private readonly OwnerRegistry $identities,
    ) {}

    /**
     * Resolve and authorize a Media-capable associable model for a mutation endpoint.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function resolveForMutation(string $type, string $id): Model&HasNvlMedia
    {
        $modelClass = $this->resolveModelClass($type);
        $model = $modelClass::findOrFail($id);

        $associable = $this->requireMediaAssociable(
            $this->owners->resolve($this->requireMediaAssociable($model)),
        );
        $this->authorizeMutation($associable);

        return $associable;
    }

    /**
     * Resolve and validate the incoming associable class string.
     *
     * @return class-string<Model>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function resolveModelClass(string $type): string
    {
        if (! class_exists($type)) {
            try {
                $modelClass = $this->identities->model($type);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'associableType' => ["The associable type [{$type}] is not a valid class or owner alias."],
                ]);
            }
        } else {
            $modelClass = $type;
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            throw ValidationException::withMessages([
                'associableType' => ["The associable type [{$type}] is not a valid model."],
            ]);
        }

        $allowed = config('nvl-media.allowed_associable_types', []);

        $allowedModels = [];

        if (is_array($allowed)) {
            foreach ($allowed as $index => $reference) {
                if (! is_string($reference)) {
                    throw new InvalidArgumentException('media.allowed_associable_types must contain owner references.');
                }

                $allowedModels[] = $this->identities->reference($reference, "media.allowed_associable_types.{$index}");
            }
        }

        if (! in_array($modelClass, $allowedModels, true)) {
            throw new AuthorizationException("The associable type [{$type}] is not allowed.");
        }

        /** @var class-string<Model> $modelClass */
        return $modelClass;
    }

    /**
     * Ensure the resolved model implements the Media contract.
     *
     * @throws ValidationException
     */
    private function requireMediaAssociable(Model $model): Model&HasNvlMedia
    {
        if ($model instanceof HasNvlMedia) {
            return $model;
        }

        throw ValidationException::withMessages([
            'associableType' => [(string) trans('nvl-media::media/messages.error.associable_type_must_support_media')],
        ]);
    }

    /**
     * Authorize mutation access for the resolved associable model.
     *
     * @param  Model&HasNvlMedia  $model
     *
     * @throws AuthorizationException
     */
    private function authorizeMutation(Model $model): void
    {
        $abilities = config('nvl-media.associable_mutation_abilities', []);
        $ability = null;
        if (is_array($abilities)) {
            $ability = $abilities[$model::class] ?? null;
            if ($ability === null) {
                foreach ($abilities as $reference => $configuredAbility) {
                    if (! is_string($reference)) {
                        continue;
                    }
                    try {
                        $modelClass = $this->identities->model($reference);
                    } catch (InvalidArgumentException) {
                        continue;
                    }
                    if ($modelClass === $model::class) {
                        $ability = $configuredAbility;
                        break;
                    }
                }
            }
        }

        Gate::authorize(is_string($ability) && $ability !== '' ? $ability : 'update', $model);
    }
}
