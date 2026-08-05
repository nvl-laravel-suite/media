<?php

declare(strict_types=1);

namespace Nvl\Media\Services;

use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Nvl\Media\Contracts\MediaSearchDriver;
use Nvl\Media\Data\Display\MediaUsage;
use Nvl\Media\Data\MediaActorData;
use Nvl\Media\Data\MediaFilter;
use Nvl\Media\Enums\MediaAbility;
use Nvl\Media\Enums\MediaType;
use Nvl\Media\Models\Media;
use Nvl\Media\Models\MediaAssociation;
use Nvl\Media\Models\MediaTranslation;
use Nvl\Media\Support\MediaConfiguration;
use Spatie\LaravelData\Optional;

/** MediaQueryService: read-only queries for media listing, detail, and usage lookups. */
final class MediaQueryService
{
    /**
     * Create the media query service.
     */
    public function __construct(
        private readonly MediaLocaleResolver $localeResolver,
        private readonly MediaSearchDriver $searchDriver,
        private readonly MediaAccessService $access,
    ) {}

    /**
     * List media with filters, pagination, and optional user scoping.
     *
     * @return LengthAwarePaginator<int, Media>
     */
    public function index(
        MediaFilter $filters,
        ?Authenticatable $user = null,
        bool $includeVariations = false,
    ): LengthAwarePaginator {
        $relations = ['associations'];
        if ($includeVariations) {
            $relations[] = 'imageVariations';
        }

        $query = $this->baseQuery($user)
            ->with($relations)
            ->withResolvedTranslations($this->localeResolver->resolve(
                $filters->locale instanceof Optional ? null : $filters->locale,
            ))
            ->withCount(['associations']);

        if ($filters->search !== null && ! ($filters->search instanceof Optional)) {
            $this->searchDriver->apply($query, $filters->search);
        }

        if ($filters->type instanceof MediaType) {
            $query->where('type', $filters->type->value);
        }

        if ($filters->disk !== null) {
            $query->where('disk', $filters->disk);
        }

        if ($filters->isPublic !== null) {
            $query->where('is_public', $filters->isPublic);
        }

        if ($filters->tag !== null) {
            $query->whereJsonContains('tags', $filters->tag);
        }

        $associableType = $filters->associableType instanceof Optional ? null : $filters->associableType;
        $collection = $filters->collection instanceof Optional ? null : $filters->collection;

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

        if ($filters->folder !== null) {
            $query->where('folder', $filters->folder);
        }

        if ($filters->mimeType !== null) {
            $query->where('mime_type', $filters->mimeType);
        }

        if ($filters->extension !== null) {
            $query->where('extension', $filters->extension);
        }

        $locale = $filters->locale instanceof Optional ? null : $filters->locale;

        if ($locale !== null) {
            $locale = $this->localeResolver->resolve($locale);
            $query->whereIn(
                'id',
                MediaTranslation::query()
                    ->select('media_id')
                    ->where('locale', $locale),
            );
        }

        $sortBy = in_array($filters->sortBy, MediaFilter::ALLOWED_SORT_COLUMNS, true)
            ? $filters->sortBy
            : 'created_at';
        $sortDirection = in_array($filters->sortDirection, ['asc', 'desc'], true)
            ? $filters->sortDirection
            : 'desc';

        $query->orderBy($sortBy, $sortDirection);

        $perPage = $filters->perPage instanceof Optional ? 25 : ($filters->perPage ?? 25);
        $perPage = min(
            max(1, $perPage),
            MediaConfiguration::integer('media.query.maximum_page_size', 100, 1),
        );
        $page = $filters->page instanceof Optional ? 1 : ($filters->page ?? 1);

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
    public function filterOptions(?Authenticatable $user = null): array
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
    public function show(string $id, bool $includeVariations = true): Media
    {
        $relations = ['translations', 'associations'];

        if ($includeVariations) {
            $relations[] = 'imageVariations';
        }

        return Media::with($relations)
            ->findOrFail($id);
    }

    /**
     * List all associations for a media record.
     *
     * @return Collection<int, MediaUsage>
     */
    public function usages(string $id): Collection
    {
        $media = Media::with('associations')->findOrFail($id);

        /** @var Collection<int, MediaUsage> */
        return $media->associations
            ->map(fn ($association) => MediaUsage::fromModel($association))
            ->values();
    }

    /**
     * Resolve media records by UUID for authorization before bulk mutations.
     *
     * @param  array<int, string>  $ids  Media UUIDs
     * @return EloquentCollection<int, Media> Matching media records
     */
    public function findMany(array $ids): EloquentCollection
    {
        return Media::query()->whereIn('id', $ids)->get();
    }

    /**
     * Build the base media query with the current user's visibility scope.
     *
     * @return Builder<Media>
     */
    private function baseQuery(?Authenticatable $user = null): Builder
    {
        $query = Media::query();
        $this->applyVisibilityScope($query, $user);

        return $query;
    }

    /**
     * Apply the authenticated user's media visibility rules to a query.
     *
     * @param  Builder<Media>  $query
     */
    private function applyVisibilityScope(Builder $query, ?Authenticatable $user = null): void
    {
        $canManage = $user instanceof Authenticatable
            && $this->access->allows($user, MediaAbility::ListAll);

        if ($user !== null && ! $canManage) {
            $actor = MediaActorData::fromAuthenticatable($user);
            $ownerId = $actor->id !== null ? (string) $actor->id : '';
            $ownerType = $actor->type ?? '';

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
    private function distinctMediaColumnValues(string $column, ?Authenticatable $user = null): array
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
    private function distinctCollectionValues(?Authenticatable $user = null): array
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
}
