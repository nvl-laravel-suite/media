<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Nvl\Media\Contracts\MediaSearchDriver;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Ownership\MediaOwnershipParentResolver;
use Nvl\Media\Support\MediaActors;
use Nvl\Media\Support\MediaConfiguration;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * MediaReader: read-only queries for media listing, detail, and usage lookups.
 *
 * @api
 */
final class MediaReader
{
    /**
     * Create the media query service.
     */
    public function __construct(
        private readonly MediaLocaleResolver $localeResolver,
        private readonly MediaSearchDriver $searchDriver,
        private readonly MediaAccessService $access,
        private readonly MediaOwnershipParentResolver $owners,
    ) {}

    /**
     * List media with filters, pagination, and optional user scoping.
     *
     * @param  array{search?: string, type?: MediaType|string|null, disk?: string, isPublic?: bool, collection?: string, tag?: string, folder?: string, mimeType?: string, extension?: string, locale?: string, associableType?: string, per_page?: int, page?: int, sortBy?: string, sortDirection?: string}  $filters
     * @return LengthAwarePaginator<int, Media>
     */
    public function paginate(
        Authenticatable|SystemActor $user,
        array $filters = [],
        bool $includeVariations = false,
    ): LengthAwarePaginator {
        $user = MediaActors::assert($user);
        $this->access->authorize($user, MediaAbility::List);
        $allowed = ['search', 'type', 'disk', 'isPublic', 'collection', 'tag', 'folder', 'mimeType', 'extension', 'locale', 'associableType', 'per_page', 'page', 'sortBy', 'sortDirection'];
        if (array_diff(array_keys($filters), $allowed) !== []) {
            throw new \InvalidArgumentException('Unknown Media filter fields.');
        }
        Validator::make($filters, [
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::enum(MediaType::class)],
            'disk' => ['nullable', 'string', 'max:25'],
            'isPublic' => ['nullable', 'boolean'],
            'collection' => ['nullable', 'string', 'max:50'],
            'tag' => ['nullable', 'string', 'max:50'],
            'folder' => ['nullable', 'string', 'max:255'],
            'mimeType' => ['nullable', 'string', 'max:100'],
            'extension' => ['nullable', 'string', 'max:10'],
            'locale' => ['nullable', 'string', 'max:5'],
            'associableType' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sortBy' => ['nullable', Rule::in(['created_at', 'updated_at', 'filename', 'size', 'type', 'extension', 'mime_type'])],
            'sortDirection' => ['nullable', Rule::in(['asc', 'desc'])],
        ])->validate();
        $filters += array_fill_keys($allowed, null);
        $filters['sortBy'] ??= 'created_at';
        $filters['sortDirection'] ??= 'desc';

        $relations = ['associations'];
        if ($includeVariations) {
            $relations[] = 'imageVariations';
        }

        $query = $this->baseQuery($user)
            ->with($relations)
            ->withResolvedTranslations($this->localeResolver->resolve(
                ($filters['locale'] ?? null),
            ))
            ->withCount(['associations']);

        if ($filters['search'] !== null) {
            $this->searchDriver->apply($query, $filters['search']);
        }

        if ($filters['type'] !== null) {
            $type = $filters['type'] instanceof MediaType ? $filters['type'] : MediaType::from($filters['type']);
            $query->where('type', $type->value);
        }

        if ($filters['disk'] !== null) {
            $query->where('disk', $filters['disk']);
        }

        if ($filters['isPublic'] !== null) {
            $query->where('is_public', $filters['isPublic']);
        }

        if ($filters['tag'] !== null) {
            $query->whereJsonContains('tags', $filters['tag']);
        }

        $associableType = ($filters['associableType'] ?? null);
        $collection = ($filters['collection'] ?? null);

        if ($associableType !== null) {
            $associationQuery = MediaAssociation::query()
                ->select('media_id')
                ->where('associable_type', $associableType);

            if ($collection !== null) {
                $associationQuery->where('collection', $collection);
            }

            $query->whereIn('id', $associationQuery);
        } elseif ($collection !== null) {
            $query->whereIn(
                'id',
                MediaAssociation::query()
                    ->select('media_id')
                    ->where('collection', $collection),
            );
        }

        if ($filters['folder'] !== null) {
            $query->where('folder', $filters['folder']);
        }

        if ($filters['mimeType'] !== null) {
            $query->where('mime_type', $filters['mimeType']);
        }

        if ($filters['extension'] !== null) {
            $query->where('extension', $filters['extension']);
        }

        $locale = ($filters['locale'] ?? null);

        if ($locale !== null) {
            $locale = $this->localeResolver->resolve($locale);
            $query->whereIn(
                'id',
                MediaTranslation::query()
                    ->select('media_id')
                    ->where('locale', $locale),
            );
        }

        $sortBy = in_array($filters['sortBy'], ['created_at', 'updated_at', 'filename', 'size', 'type', 'extension', 'mime_type'], true)
            ? $filters['sortBy']
            : 'created_at';
        $sortDirection = in_array($filters['sortDirection'], ['asc', 'desc'], true)
            ? $filters['sortDirection']
            : 'desc';

        $query->orderBy($sortBy, $sortDirection);

        $perPage = ($filters['per_page'] ?? 25);
        $perPage = min(
            max(1, $perPage),
            MediaConfiguration::integer('nvl-media.query.maximum_page_size', 100, 1),
        );
        $page = ($filters['page'] ?? 1);

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Resolve stable option lists for structured media index filters.
     *
     * @return array{
     *   typeOptions: array<int, string>,
     *   collectionOptions: array<int, string>,
     *   folderOptions: array<int, string>,
     *   mimeTypeOptions: array<int, string>,
     *   extensionOptions: array<int, string>
     * }
     */
    public function filterOptions(Authenticatable|SystemActor $user): array
    {
        return [
            'typeOptions' => $this->distinctMediaColumnValues('type', $user),
            'collectionOptions' => $this->distinctCollectionValues($user),
            'folderOptions' => $this->distinctMediaColumnValues('folder', $user),
            'mimeTypeOptions' => $this->distinctMediaColumnValues('mime_type', $user),
            'extensionOptions' => $this->distinctMediaColumnValues('extension', $user),
        ];
    }

    /**
     * Retrieve a single media with all relations.
     */
    public function get(Authenticatable|SystemActor $actor, string $id, bool $includeVariations = true): Media
    {
        $relations = ['translations', 'associations'];

        if ($includeVariations) {
            $relations[] = 'imageVariations';
        }

        $media = $this->baseQuery($actor)->with($relations)->findOrFail($id);
        $this->access->authorize($actor, MediaAbility::View, $media);

        return $media;
    }

    /**
     * List all associations for a media record.
     *
     * @return Collection<int, MediaAssociation>
     */
    public function usages(Authenticatable|SystemActor $actor, string $id): Collection
    {
        $media = $this->get($actor, $id);

        /** @var Collection<int, MediaAssociation> */
        return $media->associations
            ->values();
    }

    /**
     * Resolve media records by UUID for authorization before bulk mutations.
     *
     * @param  array<int, string>  $ids  Media UUIDs
     * @return EloquentCollection<int, Media> Matching media records
     */
    public function findMany(Authenticatable|SystemActor $actor, array $ids): EloquentCollection
    {
        $ids = array_values(array_unique($ids));
        $media = $this->baseQuery($actor)->whereIn('id', $ids)->get();
        if ($media->count() !== count($ids)) {
            throw new OwnershipViolation('One or more Media identifiers are unavailable in the active tenant.');
        }

        return $media;
    }

    /**
     * Build the base media query with the current user's visibility scope.
     *
     * @return Builder<Media>
     */
    private function baseQuery(Authenticatable|SystemActor $user): Builder
    {
        $query = MediaModelRegistry::query();
        $this->applyVisibilityScope($query, $user);

        return $query;
    }

    /**
     * Apply the authenticated user's media visibility rules to a query.
     *
     * @param  Builder<Media>  $query
     */
    private function applyVisibilityScope(Builder $query, Authenticatable|SystemActor $user): void
    {
        $user = MediaActors::assert($user);
        $canManage = $this->access->allows($user, MediaAbility::ListAll);

        if (! $canManage) {
            $actor = $user;
            $ownerId = MediaActors::id($actor) !== null ? (string) MediaActors::id($actor) : '';
            $ownerType = MediaActors::type($actor);

            $query->where(function (Builder $builder) use ($ownerId, $ownerType): void {
                $builder->where('is_public', true)
                    ->orWhere(function (Builder $ownerQuery) use ($ownerId, $ownerType): void {
                        $ownerQuery
                            ->where('uploaded_by', $ownerId)
                            ->where('uploaded_by_type', $ownerType);
                    });
            });
        }
    }

    /**
     * Resolve distinct non-empty values from a media table column.
     *
     * @return array<int, string>
     */
    private function distinctMediaColumnValues(string $column, Authenticatable|SystemActor $user): array
    {
        return $this->baseQuery($user)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->select($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(static fn (mixed $value): ?string => match (true) {
                $value instanceof BackedEnum => (string) $value->value,
                is_string($value) => $value,
                default => null,
            })
            ->filter(static fn (?string $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    /**
     * Resolve distinct non-empty media association collection values.
     *
     * @return array<int, string>
     */
    private function distinctCollectionValues(Authenticatable|SystemActor $user): array
    {
        return MediaAssociation::query()
            ->whereNotNull('collection')
            ->where('collection', '!=', '')
            ->whereIn('media_id', $this->baseQuery($user)->select('id'))
            ->select('collection')
            ->distinct()
            ->orderBy('collection')
            ->pluck('collection')
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    /**
     * Return available authorized assets for exact owner collections.
     *
     * @param  array<array-key, mixed>  $slots
     * @return array<string, list<Media>>
     */
    public function slots(Authenticatable|SystemActor $actor, Model $owner, array $slots, int $limit = 100): array
    {
        $actor = MediaActors::assert($actor);
        $owner = $this->owners->resolve($owner);
        if ($limit < 1 || $limit > 1000 || count($slots) > 100 || ! array_is_list($slots)) {
            throw new \InvalidArgumentException('Media owner reads require bounded slots and limits.');
        }
        $ownerId = $owner->getKey();
        if (! is_int($ownerId) && ! is_string($ownerId)) {
            throw new \InvalidArgumentException('Media owners require a scalar key.');
        }
        $result = [];
        foreach ($slots as $slot) {
            if (! is_string($slot) || trim($slot) === '' || mb_strlen($slot) > 50) {
                throw new \InvalidArgumentException('Media slots require bounded nonempty names.');
            }
            $rows = MediaAssociation::query()->where('associable_type', $owner->getMorphClass())
                ->where('associable_id', (string) $ownerId)->where('collection', $slot)
                ->orderBy('order')->orderBy('id')->limit($limit)->get();
            $result[$slot] = [];
            foreach ($rows as $row) {
                $media = $this->get($actor, $row->media_id, false);
                $this->access->authorize($actor, MediaAbility::View, $media, $owner);
                $result[$slot][] = $media;
            }
        }

        return $result;
    }
}
