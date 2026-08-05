<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Nvl\Media\Contracts\HasMedia;

/** MediaAssociableResolver validates and loads Media-capable associable models for API mutations. */
final class MediaAssociableResolver
{
    /**
     * Resolve and authorize a Media-capable associable model for a mutation endpoint.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function resolveForMutation(string $type, string $id): Model&HasMedia
    {
        $modelClass = $this->resolveModelClass($type);
        $model = $modelClass::findOrFail($id);

        $associable = $this->requireMediaAssociable($model);
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
            throw ValidationException::withMessages([
                'associableType' => ["The associable type [{$type}] is not a valid class."],
            ]);
        }

        if (! is_subclass_of($type, Model::class)) {
            throw ValidationException::withMessages([
                'associableType' => ["The associable type [{$type}] is not a valid model."],
            ]);
        }

        $allowed = config('media.allowed_associable_types', []);

        if (! is_array($allowed) || ! in_array($type, $allowed, true)) {
            throw new AuthorizationException("The associable type [{$type}] is not allowed.");
        }

        /** @var class-string<Model> $type */
        return $type;
    }

    /**
     * Ensure the resolved model implements the Media contract.
     *
     * @throws ValidationException
     */
    private function requireMediaAssociable(Model $model): Model&HasMedia
    {
        if ($model instanceof HasMedia) {
            return $model;
        }

        throw ValidationException::withMessages([
            'associableType' => [(string) trans('media::media/messages.error.associable_type_must_support_media')],
        ]);
    }

    /**
     * Authorize mutation access for the resolved associable model.
     *
     * @param  Model&HasMedia  $model
     *
     * @throws AuthorizationException
     */
    private function authorizeMutation(Model $model): void
    {
        $abilities = config('media.associable_mutation_abilities', []);
        $ability = is_array($abilities) ? ($abilities[$model::class] ?? 'update') : 'update';

        Gate::authorize(is_string($ability) && $ability !== '' ? $ability : 'update', $model);
    }
}
