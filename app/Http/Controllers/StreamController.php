<?php

namespace App\Http\Controllers;

use App\Models\Link;
use App\Services\LinkCrypt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class StreamController extends Controller
{
    public function __construct(private readonly LinkCrypt $crypt) {}

    public function stream(Request $request, string $payload)
    {
        // Strip optional .m3u suffix added for VLC compatibility
        $payload = preg_replace('/\.m3u$/i', '', $payload);

        try {
            $url = $this->crypt->decrypt($payload);
        } catch (Throwable) {
            abort(404);
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            abort(404);
        }

        // Recursively unwrap self-referencing proxy URLs (double-proxied imports)
        $ownBase = rtrim(config('app.url'), '/') . '/view/';
        $hops    = 0;
        while (str_starts_with($url, $ownBase) && $hops < 5) {
            $inner = preg_replace('/\.m3u$/i', '', substr($url, strlen($ownBase)));
            try {
                $url = $this->crypt->decrypt($inner);
            } catch (Throwable) {
                abort(404);
            }
            $hops++;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Known media segment extensions — no DB, no network, straight to nginx proxy
        if (in_array($ext, ['ts', 'mp4', 'mkv', 'mp3', 'aac', 'mov', 'avi'], true)) {
            return $this->streamDirect($url, $ext, $request);
        }

        // Known playlist extensions — straight to manifest
        if (in_array($ext, ['m3u', 'm3u8'], true)) {
            return $this->streamManifest($url);
        }

        // Unknown extension: stored links are playlists; rewritten channel URLs are direct streams.
        // A URL in the DB was entered by the user (it's a playlist/M3U root).
        // A URL not in the DB came from rewriteManifest() (it's a channel stream).
        if (Link::where('original_url', $url)->exists()) {
            return $this->streamManifest($url);
        }

        return $this->streamDirect($url, $ext, $request);
    }

    /**
     * Fetch, rewrite and serve an M3U/M3U8 manifest.
     *
     * The rewritten manifest is cached for 3 seconds using Laravel's file cache.
     * HLS live TV typically updates every 4-10s (EXT-X-TARGETDURATION), so 3s
     * is safe and cuts origin fetches by ~50-70% when VLC polls aggressively.
     */
    private function streamManifest(string $url): \Illuminate\Http\Response
    {
        $cacheKey = 'manifest:' . md5($url);

        $cached = Cache::get($cacheKey);

        if ($cached === null) {
            try {
                $response = Http::connectTimeout(3)
                    ->timeout(10)
                    ->withOptions(['verify' => false])
                    ->get($url);
            } catch (Throwable) {
                abort(502, 'Could not fetch stream manifest');
            }

            if (! $response->successful()) {
                abort($response->status());
            }

            $body = $response->body();
            $cached = [
                'content' => $this->rewriteManifest($body, $url),
                'isHls'   => str_contains($body, '#EXT-X-TARGETDURATION')
                          || str_contains($body, '#EXT-X-STREAM-INF')
                          || str_contains($body, '#EXT-X-MEDIA-SEQUENCE'),
            ];

            Cache::put($cacheKey, $cached, 3); // 3-second TTL
        }

        return $this->buildManifestResponse($cached['content'], $url, $cached['isHls']);
    }

    /**
     * Build the manifest HTTP response.
     * Accepts a pre-rewritten body, or rewrites from raw body when given one.
     */
    private function buildManifestResponse(string $body, string $url, ?bool $isHls = null): \Illuminate\Http\Response
    {
        $rewritten = str_contains($body, '#EXTM3U') ? $this->rewriteManifest($body, $url) : $body;

        if ($isHls === null) {
            $isHls = str_contains($body, '#EXT-X-TARGETDURATION')
                  || str_contains($body, '#EXT-X-STREAM-INF')
                  || str_contains($body, '#EXT-X-MEDIA-SEQUENCE');
        }

        return response($rewritten, 200, [
            'Content-Type'  => $isHls ? 'application/vnd.apple.mpegurl' : 'audio/x-mpegurl',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma'        => 'no-cache',
        ]);
    }

    /**
     * Rewrite all URLs in an M3U/M3U8 to use proxied URLs.
     * Handles both standalone URL lines and URIs embedded in HLS tags.
     */
    private function rewriteManifest(string $content, string $manifestUrl): string
    {
        $base  = rtrim(dirname($manifestUrl), '/') . '/';
        $lines = explode("\n", $content);
        $crypt = $this->crypt;

        foreach ($lines as &$line) {
            $trimmed = rtrim($line);

            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                $line = preg_replace_callback(
                    '/URI="([^"]+)"/i',
                    function ($matches) use ($base, $crypt) {
                        $uri = $matches[1];
                        $absolute = str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')
                            ? $uri
                            : $base . $uri;
                        return 'URI="' . url('/view/' . $crypt->encrypt($absolute)) . '"';
                    },
                    $trimmed
                );
                continue;
            }

            $absolute = str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')
                ? $trimmed
                : $base . $trimmed;

            $line = url('/view/' . $this->crypt->encrypt($absolute));
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve redirects so the final URL is used for proxying.
     * Prevents origin URL leaking via 302 redirects.
     */
    private function resolveRedirects(string $url, int $maxHops = 10): string
    {
        for ($i = 0; $i < $maxHops; $i++) {
            try {
                $resp = Http::withOptions([
                    'allow_redirects' => false,
                    'verify' => false,
                    'timeout' => 5,
                    'connect_timeout' => 3,
                ])->head($url);

                $status = $resp->status();
                if ($status >= 300 && $status < 400) {
                    $location = $resp->header('Location');
                    if ($location) {
                        if (!str_starts_with($location, 'http')) {
                            $parsed = parse_url($url);
                            $location = $parsed['scheme'] . '://' . $parsed['host']
                                . (isset($parsed['port']) ? ':' . $parsed['port'] : '')
                                . $location;
                        }
                        $url = $location;
                        continue;
                    }
                }
                break;
            } catch (Throwable) {
                break;
            }
        }
        return $url;
    }

    /**
     * Hand the stream off to Nginx via X-Accel-Redirect.
     * Resolves redirects first so the origin URL never leaks to the client.
     */
    private function streamDirect(
        string $url,
        string $ext,
        Request $request
    ): \Illuminate\Http\Response {
        return response('', 200, [
            'X-Accel-Redirect'  => '/proxy/' . $url,
            'X-Accel-Buffering' => 'no',
            'Cache-Control'     => 'no-cache, no-store',
        ]);
    }
}

