<?php

declare(strict_types=1);
use Nvl\Media\Exceptions\MediaUploadException;
use Nvl\Media\Services\SvgScanner;

beforeEach(function () {
    config(['nvl-media.svg_scanning' => true]);
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
    config(['nvl-media.svg_scanning' => false]);
    $scanner = new SvgScanner;
    $path = writeSvg('<svg><script>alert("xss")</script></svg>');
    $scanner->scan($path);
    expect(true)->toBeTrue();
    @unlink($path);
});
/* =================================================================
 * XXE / DOCTYPE
 * ================================================================= */
it('rejects DOCTYPE declarations without changing uploaded bytes', function () {
    $scanner = new SvgScanner;
    $path = writeSvg('<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40"/></svg>');
    try {
        $original = file_get_contents($path);
        expect(fn () => $scanner->scan($path))->toThrow(MediaUploadException::class, 'DOCTYPE')
            ->and(file_get_contents($path))->toBe($original);
    } finally {
        @unlink($path);
    }
});

it('rejects uninspected external SVG resources and embedded active documents', function (string $payload): void {
    $path = writeSvg($payload);

    try {
        expect(fn () => (new SvgScanner)->scan($path))->toThrow(MediaUploadException::class)
            ->and(file_get_contents($path))->toBe($payload);
    } finally {
        @unlink($path);
    }
})->with([
    'stylesheet processing instruction' => ['<?xml-stylesheet href="https://example.test/style.css"?><svg xmlns="http://www.w3.org/2000/svg"/>'],
    'escaped CSS import' => ['<svg xmlns="http://www.w3.org/2000/svg"><style>@\\69mport "https://example.test/style.css";</style></svg>'],
    'external style URL' => ['<svg xmlns="http://www.w3.org/2000/svg"><style>rect { fill: url(https://example.test/paint.svg); }</style></svg>'],
    'external attribute URL' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect fill="url(https://example.test/paint.svg)"/></svg>'],
    'XML encoded CSS escape' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect style="fill: u&#x5c;72l(https://example.test/paint.svg)"/></svg>'],
    'nested SVG data URL' => ['<svg xmlns="http://www.w3.org/2000/svg"><use href="data:image/svg+xml,%3Csvg%20onload%3D%22alert(1)%22%2F%3E"/></svg>'],
    'alternate XLink prefix script' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:q="http://www.w3.org/1999/xlink"><a q:href="javascript:alert(1)">x</a></svg>'],
    'alternate XLink prefix external image' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:q="http://www.w3.org/1999/xlink"><image q:href="https://example.test/image.svg"/></svg>'],
    'alternate XLink prefix nested SVG' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:q="http://www.w3.org/1999/xlink"><use q:href="data:image/svg+xml,%3Csvg%20onload%3D%22alert(1)%22%2F%3E"/></svg>'],
    'base override external fragment' => ['<svg xmlns="http://www.w3.org/2000/svg" xml:base="https://example.test/remote.svg"><use href="#shape"/></svg>'],
    'UTF16 external DTD' => ["\xff\xfe".mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE svg SYSTEM "https://example.test/svg.dtd"><svg xmlns="http://www.w3.org/2000/svg"/>', 'UTF-16LE', 'UTF-8')],
]);

it('preserves safe local paint references and ordinary embedded styles', function (): void {
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg" xmlns:q="http://www.w3.org/1999/xlink"><style>rect { fill: url(#paint); stroke: red; }</style><defs><linearGradient id="paint"/><g id="shape"><rect/></g></defs><rect fill="url(&quot;#paint&quot;)"/><use q:href="#shape"/></svg>');

    try {
        (new SvgScanner)->scan($path);
        expect(file_get_contents($path))->toContain('url(#paint)', 'url(&quot;#paint&quot;)');
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

it('rejects executable URI schemes containing XML-encoded browser controls', function (string $attribute, string $uri): void {
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a '.$attribute.'="'.$uri.'"><text>click</text></a></svg>');

    try {
        expect(fn () => (new SvgScanner)->scan($path))->toThrow(MediaUploadException::class);
    } finally {
        @unlink($path);
    }
})->with([
    'tab in javascript href' => ['href', 'java&#x09;script:alert(1)'],
    'newline in javascript xlink href' => ['xlink:href', 'java&#x0a;script:alert(1)'],
    'carriage return in vbscript href' => ['href', 'vb&#x0d;script:MsgBox(1)'],
    'newline in data href' => ['href', 'da&#x0a;ta:text/html;base64,PHNjcmlwdD4='],
]);

it('rejects external loads and embedded HTML that need no script, use or image element', function (string $payload): void {
    $path = writeSvg($payload);

    try {
        expect(fn () => (new SvgScanner)->scan($path))->toThrow(MediaUploadException::class);
    } finally {
        @unlink($path);
    }
})->with([
    'filter image' => ['<svg xmlns="http://www.w3.org/2000/svg"><filter id="f"><feImage href="https://example.test/track.png"/></filter><rect filter="url(#f)"/></svg>'],
    'filter image through XLink' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><filter id="f"><feImage xlink:href="//example.test/track.png"/></filter></svg>'],
    'external pattern template' => ['<svg xmlns="http://www.w3.org/2000/svg"><pattern id="p" href="https://example.test/paint.svg#p"/></svg>'],
    'external gradient template' => ['<svg xmlns="http://www.w3.org/2000/svg"><linearGradient id="g" href="other.svg#g"/></svg>'],
    'external text path' => ['<svg xmlns="http://www.w3.org/2000/svg"><text><textPath href="https://example.test/path.svg#p">x</textPath></text></svg>'],
    'CSS image-set in a style attribute' => ['<svg xmlns="http://www.w3.org/2000/svg" style="background-image: image-set(\'https://example.test/track.png\' 1x)"/>'],
    'CSS image-set in a style element' => ['<svg xmlns="http://www.w3.org/2000/svg"><style>svg { background: -webkit-image-set("https://example.test/track.png" 1x); }</style></svg>'],
    'HTML stylesheet link' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:h="http://www.w3.org/1999/xhtml"><h:link rel="stylesheet" href="https://example.test/style.css"/></svg>'],
    'HTML meta refresh' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:h="http://www.w3.org/1999/xhtml"><h:meta http-equiv="refresh" content="0;url=https://example.test/phish"/></svg>'],
    'HTML base' => ['<svg xmlns="http://www.w3.org/2000/svg"><base xmlns="http://www.w3.org/1999/xhtml" href="https://example.test/"/></svg>'],
    'HTML credential form' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:h="http://www.w3.org/1999/xhtml"><h:form action="https://example.test/collect"><h:input name="password"/></h:form></svg>'],
    'HTML image beacon' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:h="http://www.w3.org/1999/xhtml"><h:img src="https://example.test/track.png"/></svg>'],
    'HTML document root' => ['<html xmlns="http://www.w3.org/1999/xhtml"><body><p>not an image</p></body></html>'],
]);

it('accepts editor metadata, hyperlinks and local references', function (): void {
    $path = writeSvg('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:cc="http://creativecommons.org/ns#" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape"><metadata><rdf:RDF><cc:Work rdf:about=""><dc:format>image/svg+xml</dc:format><dc:type rdf:resource="http://purl.org/dc/dcmitype/StillImage"/></cc:Work></rdf:RDF></metadata><defs><linearGradient id="a"/><linearGradient id="b" xlink:href="#a"/><filter id="f"><feImage href="#shape"/></filter><path id="p" d="M0 0h10"/></defs><g id="shape" inkscape:label="Layer 1"><rect fill="url(#b)" filter="url(#f)"/></g><text><textPath href="#p">text</textPath></text><a href="https://example.test/page" target="_blank"><text>link</text></a><image href="data:image/png;base64,iVBORw0KGgo="/></svg>');

    try {
        (new SvgScanner)->scan($path);
        expect(true)->toBeTrue();
    } finally {
        @unlink($path);
    }
});
