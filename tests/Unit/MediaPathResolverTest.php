<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Models\Media;
use Nvl\Media\Services\MediaPathResolver;
use Nvl\Media\Slots\MediaSlot;

beforeEach(function () {
    config([
        'media.default_path' => '{model_type}/{model_id}',
        'media.conversions_folder' => 'conversions',
        'filesystems.default' => 'public',
        'media.conversions_disk' => null,
    ]);

    $this->resolver = new MediaPathResolver;
});

/* =================================================================
 * Helper: mock model via Mockery partial
 * ================================================================= */

function createMockModel(mixed $key = 'abc-123', string $table = 'users', array $attributes = []): Model
{
    $model = new class extends Model
    {
        protected $guarded = [];

        public mixed $mockKey = null;

        public function getKey(): mixed
        {
            return $this->mockKey;
        }
    };

    $model->mockKey = $key;
    $model->setTable($table);

    foreach ($attributes as $attr_key => $attr_value) {
        $model->setAttribute($attr_key, $attr_value);
    }

    return $model;
}

/* =================================================================
 * resolve()
 * ================================================================= */

describe('resolve', function () {

    it('interpolates the default template without coupling collection to the path', function () {
        $model = createMockModel('abc-123', 'users');
        $collection = new MediaSlot('avatar');

        $path = $this->resolver->resolve($model, $collection);

        // Anonymous class basename is unpredictable; check the key only.
        expect($path)->toContain('abc-123');
        expect($path)->not->toContain('avatar');
    });

    it('uses the collection pathTemplate', function () {
        $model = createMockModel('xyz-789', 'products');
        $collection = new MediaSlot('gallery');
        $collection->pathTemplate = 'media/{collection}/{model_id}';

        $path = $this->resolver->resolve($model, $collection);

        expect($path)->toBe('media/gallery/xyz-789');
    });
});

/* =================================================================
 * resolveForConversions()
 * ================================================================= */

describe('resolveForConversions', function () {

    it('appends the conversions subfolder from config', function () {
        $model = createMockModel('abc-123', 'users');
        $collection = new MediaSlot('avatar');

        $path = $this->resolver->resolveForConversions($model, $collection);

        expect($path)->toContain('abc-123/conversions');
    });

    it('uses custom conversions folder from config', function () {
        config(['media.conversions_folder' => 'thumbs']);

        $model = createMockModel('abc-123', 'users');
        $collection = new MediaSlot('photos');

        $path = $this->resolver->resolveForConversions($model, $collection);

        expect($path)->toContain('abc-123/thumbs');
    });
});

/* =================================================================
 * interpolate() — placeholders
 * ================================================================= */

describe('interpolate', function () {

    describe('built-in placeholders', function () {

        it('replaces {id} with model key', function () {
            $model = createMockModel('uuid-001');
            $path = $this->resolver->interpolate('{id}', $model);

            expect($path)->toBe('uuid-001');
        });

        it('replaces {uuid} with model key (alias for id)', function () {
            $model = createMockModel('uuid-001');
            $path = $this->resolver->interpolate('{uuid}', $model);

            expect($path)->toBe('uuid-001');
        });

        it('replaces {model_id} with model key', function () {
            $model = createMockModel('uuid-001');
            $path = $this->resolver->interpolate('{model_id}', $model);

            expect($path)->toBe('uuid-001');
        });

        it('replaces {model_type} with snake_case class basename', function () {
            $model = createMockModel('1', 'users');
            $path = $this->resolver->interpolate('{model_type}', $model);

            // Anonymous class basename varies, but it will be snake_cased and non-empty
            expect($path)->toBeString();
            expect(strlen($path))->toBeGreaterThan(0);
            expect($path)->not->toContain('{');
        });

        it('replaces {collection} with the collection name', function () {
            $model = createMockModel('1');
            $path = $this->resolver->interpolate('{collection}', $model, 'gallery');

            expect($path)->toBe('gallery');
        });

        it('defaults collection to "default"', function () {
            $model = createMockModel('1');
            $path = $this->resolver->interpolate('{collection}', $model);

            expect($path)->toBe('default');
        });

        it('replaces {date} with Y/m/d format', function () {
            $model = createMockModel('1');
            $path = $this->resolver->interpolate('{date}', $model);

            expect($path)->toMatch('/^\d{4}\/\d{2}\/\d{2}$/');
        });

        it('replaces {year} with four-digit year', function () {
            $model = createMockModel('1');
            $path = $this->resolver->interpolate('{year}', $model);

            expect($path)->toMatch('/^\d{4}$/');
        });

        it('replaces {month} with two-digit month', function () {
            $model = createMockModel('1');
            $path = $this->resolver->interpolate('{month}', $model);

            expect($path)->toMatch('/^\d{2}$/');
        });

        it('replaces {day} with two-digit day', function () {
            $model = createMockModel('1');
            $path = $this->resolver->interpolate('{day}', $model);

            expect($path)->toMatch('/^\d{2}$/');
        });
    });

    describe('model attribute interpolation', function () {

        it('replaces placeholder with model attribute value', function () {
            $model = createMockModel('1', 'users', ['slug' => 'john-doe']);
            $path = $this->resolver->interpolate('users/{slug}/media', $model);

            expect($path)->toBe('users/john-doe/media');
        });

        it('handles multiple attribute placeholders', function () {
            $model = createMockModel('1', 'products', ['slug' => 'widget', 'sku' => 'WDG-001']);
            $path = $this->resolver->interpolate('{slug}/{sku}', $model);

            expect($path)->toBe('widget/WDG-001');
        });
    });

    describe('unknown placeholders', function () {

        it('removes unknown placeholders', function () {
            $model = createMockModel('1');
            $path = $this->resolver->interpolate('prefix/{unknown_thing}/suffix', $model);

            expect($path)->toBe('prefix/suffix');
        });

        it('removes multiple unknown placeholders', function () {
            $model = createMockModel('1');
            $path = $this->resolver->interpolate('{foo}/{bar}/{collection}', $model);

            expect($path)->toBe('default');
        });
    });

    describe('path sanitization', function () {

        it('removes double slashes', function () {
            $model = createMockModel('1');
            $path = $this->resolver->interpolate('path//to///media', $model);

            expect($path)->toBe('path/to/media');
        });

        it('trims leading slashes', function () {
            $model = createMockModel('1');
            $path = $this->resolver->interpolate('/leading/{id}', $model);

            expect($path)->toBe('leading/1');
        });

        it('trims trailing slashes', function () {
            $model = createMockModel('1');
            $path = $this->resolver->interpolate('{id}/trailing/', $model);

            expect($path)->toBe('1/trailing');
        });

        it('handles double slashes from empty placeholder removal', function () {
            $model = createMockModel('1');
            $path = $this->resolver->interpolate('a/{nonexistent}/b', $model);

            expect($path)->toBe('a/b');
        });
    });

    describe('unsaved model', function () {

        it('uses "unsaved" when model key is null', function () {
            $model = createMockModel(null);
            $path = $this->resolver->interpolate('{id}/{model_id}/{uuid}', $model, 'docs');

            expect($path)->toBe('unsaved/unsaved/unsaved');
        });
    });

    describe('combined template', function () {

        it('resolves a complex template with multiple placeholders', function () {
            $model = createMockModel('abc-123', 'blog_posts', ['slug' => 'hello-world']);
            $path = $this->resolver->interpolate(
                '{model_type}/{model_id}/{collection}/{slug}',
                $model,
                'featured-images',
            );

            expect($path)->toContain('abc-123');
            expect($path)->toContain('featured-images');
            expect($path)->toContain('hello-world');
            expect($path)->not->toContain('{');
            expect($path)->not->toContain('}');
        });
    });
});

/* =================================================================
 * Static utilities: rootFolder, storagePath, conversionsFolder, assertSafe
 * ================================================================= */

describe('static utilities', function () {

    it('rootFolder reads from config', function () {
        config(['media.root_folder' => 'media-files']);
        expect(MediaPathResolver::rootFolder())->toBe('media-files');
    });

    it('rootFolder trims slashes', function () {
        config(['media.root_folder' => '/media/']);
        expect(MediaPathResolver::rootFolder())->toBe('media');
    });

    it('rootFolder returns empty string when not configured', function () {
        config(['media.root_folder' => '']);
        expect(MediaPathResolver::rootFolder())->toBe('');
    });

    it('storagePath prepends root folder', function () {
        config(['media.root_folder' => 'media']);
        expect(MediaPathResolver::storagePath('users/123'))->toBe('media/users/123');
    });

    it('storagePath returns just root when folder is empty', function () {
        config(['media.root_folder' => 'media']);
        expect(MediaPathResolver::storagePath(''))->toBe('media');
    });

    it('storagePath returns folder when root is empty', function () {
        config(['media.root_folder' => '']);
        expect(MediaPathResolver::storagePath('users/123'))->toBe('users/123');
    });

    it('conversionsFolder reads from config', function () {
        config(['media.conversions_folder' => 'thumbs']);
        expect(MediaPathResolver::conversionsFolder())->toBe('thumbs');
    });

    it('conversionsFolder defaults to conversions', function () {
        config(['media.conversions_folder' => null]);
        expect(MediaPathResolver::conversionsFolder())->toBe('conversions');
    });

    it('assertSafe passes for normal paths', function () {
        MediaPathResolver::assertSafe('users/123/media');
        expect(true)->toBeTrue();
    });

    it('assertSafe throws on path traversal', function () {
        expect(fn () => MediaPathResolver::assertSafe('users/../etc/passwd'))
            ->toThrow(MediaUploadException::class);
    });

    it('assertSafe throws on null bytes', function () {
        expect(fn () => MediaPathResolver::assertSafe("users/\0evil"))
            ->toThrow(MediaUploadException::class);
    });

    it('assertSafe throws on encoded traversal', function () {
        expect(fn () => MediaPathResolver::assertSafe('users/%2e%2e/etc'))
            ->toThrow(MediaUploadException::class);
    });
});

/* =================================================================
 * mediaPath, variationFolder, variationPath
 * ================================================================= */

describe('media path building', function () {

    it('mediaPath combines root + folder + hash', function () {
        config(['media.root_folder' => 'media']);

        $media = new Media;
        $media->folder = 'users/123';
        $media->hash = 'abc123.jpg';

        $resolver = new MediaPathResolver;
        expect($resolver->mediaPath($media))->toBe('media/users/123/abc123.jpg');
    });

    it('mediaPath handles empty folder', function () {
        config(['media.root_folder' => 'media']);

        $media = new Media;
        $media->folder = '';
        $media->hash = 'abc123.jpg';

        $resolver = new MediaPathResolver;
        expect($resolver->mediaPath($media))->toBe('media/abc123.jpg');
    });

    it('mediaPath handles empty root folder', function () {
        config(['media.root_folder' => '']);

        $media = new Media;
        $media->folder = 'uploads';
        $media->hash = 'abc123.jpg';

        $resolver = new MediaPathResolver;
        expect($resolver->mediaPath($media))->toBe('uploads/abc123.jpg');
    });

    it('variationFolder builds correct path', function () {
        config(['media.root_folder' => 'media', 'media.conversions_folder' => 'conversions']);

        $media = new Media;
        $media->folder = 'users/123';

        $resolver = new MediaPathResolver;
        expect($resolver->variationFolder($media))->toBe('media/users/123/conversions');
    });

    it('variationFolder handles empty folder', function () {
        config(['media.root_folder' => '', 'media.conversions_folder' => 'conversions']);

        $media = new Media;
        $media->folder = '';

        $resolver = new MediaPathResolver;
        expect($resolver->variationFolder($media))->toBe('conversions');
    });

    it('variationPath combines folder and filename', function () {
        config(['media.root_folder' => 'media', 'media.conversions_folder' => 'conversions']);

        $media = new Media;
        $media->folder = 'users/123';

        $resolver = new MediaPathResolver;
        expect($resolver->variationPath($media, 'abc-thumb.webp'))->toBe('media/users/123/conversions/abc-thumb.webp');
    });
});
