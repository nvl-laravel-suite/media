<?php

declare(strict_types=1);

use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Services\SvgScanner;

beforeEach(function () {
    config(['media.svg_scanning' => true]);
});

function writeSvg(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'svg_test_');
    file_put_contents($path, $content);

    return $path;
}

function cleanSvg(): string
{
    return <<<'SVG'
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
  <circle cx="50" cy="50" r="40" fill="red" />
  <rect x="10" y="10" width="30" height="30" fill="blue" />
</svg>
SVG;
}

/* =================================================================
 * Clean SVG
 * ================================================================= */

it('passes a clean SVG', function () {
    $scanner = new SvgScanner;
    $path = writeSvg(cleanSvg());

    $scanner->scan($path);

    // No exception means it passed
    expect(true)->toBeTrue();

    @unlink($path);
});

it('skips scanning when config is disabled', function () {
    config(['media.svg_scanning' => false]);

    $scanner = new SvgScanner;
    $path = writeSvg('<svg><script>alert("xss")</script></svg>');

    $scanner->scan($path);

    expect(true)->toBeTrue();

    @unlink($path);
});

/* =================================================================
 * XXE / DOCTYPE
 * ================================================================= */

it('silently strips DOCTYPE declaration (common in design tool exports)', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40"/></svg>');

    try {
        $scanner->scan($path);
        expect(true)->toBeTrue();
    } finally {
        @unlink($path);
    }
});

it('rejects SVG with ENTITY declaration', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<?xml version="1.0"?><!ENTITY xxe SYSTEM "file:///etc/passwd"><svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, 'ENTITY');

/* =================================================================
 * Script elements
 * ================================================================= */

it('rejects SVG with script element', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, '<script>');

it('rejects SVG with uppercase SCRIPT element', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><SCRIPT>alert("xss")</SCRIPT></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, '<script>');

/* =================================================================
 * Dangerous elements
 * ================================================================= */

it('rejects SVG with iframe element', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><iframe src="https://evil.com"></iframe></foreignObject></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, 'forbidden');

it('rejects SVG with foreignObject element', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><foreignObject width="100" height="100"><body xmlns="http://www.w3.org/1999/xhtml"><p>html</p></body></foreignObject></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, '<foreignobject>');

it('rejects SVG with embed element', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><embed src="https://evil.com/payload" /></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, '<embed>');

it('rejects SVG with object element', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><object data="https://evil.com/payload"></object></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, '<object>');

/* =================================================================
 * Event handler attributes
 * ================================================================= */

it('rejects SVG with onclick handler', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><rect onclick="alert(1)" width="100" height="100"/></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, 'event handler');

it('rejects SVG with onload handler', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="100" height="100"/></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, 'event handler');

it('rejects SVG with onerror handler', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><rect onerror="alert(1)"/></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, 'event handler');

it('rejects SVG with onmouseover handler', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><rect onmouseover="alert(1)" width="100" height="100"/></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, 'event handler');

/* =================================================================
 * javascript: URIs
 * ================================================================= */

it('rejects SVG with javascript href', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><text>click</text></a></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, 'javascript:');

it('rejects SVG with javascript xlink:href', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript:alert(1)"><text>click</text></a></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, 'javascript:');

/* =================================================================
 * data: URIs
 * ================================================================= */

it('rejects SVG with dangerous data URI', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=="><text>click</text></a></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, 'data: URI');

it('allows SVG with safe image data URI', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><image href="data:image/png;base64,iVBOR" width="10" height="10"/></svg>');

    $scanner->scan($path);

    expect(true)->toBeTrue();

    @unlink($path);
});

/* =================================================================
 * vbscript: URIs
 * ================================================================= */

it('rejects SVG with vbscript URI', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><a href="vbscript:MsgBox(1)"><text>click</text></a></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, 'vbscript:');

/* =================================================================
 * PHP injection
 * ================================================================= */

it('rejects SVG with embedded PHP', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><?php echo "pwned"; ?><rect width="100" height="100"/></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, 'PHP code');

it('rejects SVG with short PHP tag', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><?= "pwned" ?><rect width="100" height="100"/></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, 'PHP code');

/* =================================================================
 * CDATA with script content
 * ================================================================= */

it('rejects SVG with script in CDATA', function () {
    $scanner = new SvgScanner;
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><style><![CDATA[ div { background: url(document.cookie) } ]]></style></svg>';
    $path = writeSvg($svg);

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, 'CDATA');

/* =================================================================
 * Malformed XML
 * ================================================================= */

it('rejects malformed SVG XML', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg><not-closed<broken');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, 'malformed XML');

/* =================================================================
 * Animate / set elements (SMIL-based XSS)
 * ================================================================= */

it('rejects SVG with animate element', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><animate attributeName="href" values="javascript:alert(1)"/></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, '<animate>');

it('rejects SVG with set element', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg"><set attributeName="onmouseover" to="alert(1)"/></svg>');

    try {
        $scanner->scan($path);
    } finally {
        @unlink($path);
    }
})->throws(MediaUploadException::class, '<set>');
