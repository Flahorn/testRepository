<?php

class VigboGalleryFinder
{
    private $timeout;
    private $userAgent;
    private $maxValidationRequests;

    public function __construct($timeout = 10, $maxValidationRequests = 40)
    {
        $this->timeout = $timeout;
        $this->maxValidationRequests = $maxValidationRequests;
        $this->userAgent = 'Mozilla/5.0 (compatible; VigboGalleryFinder/1.0)';
    }

    public function find($inputUrl)
    {
        $baseUrl = $this->normalizeBaseUrl($inputUrl);
        if ($baseUrl === null) {
            return [
                'baseUrl' => '',
                'host' => '',
                'methods' => [],
                'errors' => ['Укажите корректный URL сайта Vigbo/gallery.photo.'],
                'galleries' => [],
            ];
        }

        $result = [
            'baseUrl' => $baseUrl,
            'host' => parse_url($baseUrl, PHP_URL_HOST),
            'methods' => [],
            'errors' => [],
            'galleries' => [],
        ];

        $candidates = [];

        $home = $this->fetchUrl($baseUrl);
        $result['methods'][] = $this->methodStatus('Главная страница', $baseUrl, $home);
        if ($home['ok']) {
            $this->mergeCandidates(
                $candidates,
                $this->extractPortfolioGalleries($home['body'], $baseUrl, 'Next.js/RSC galleries')
            );
            $this->mergeCandidates(
                $candidates,
                $this->extractLinkedGalleryPaths($home['body'], $baseUrl, 'Ссылки на главной', true)
            );
            $this->mergeCandidates(
                $candidates,
                $this->extractLinkedGalleryPaths($home['body'], $baseUrl, 'HTML /gallery/<slug>', false)
            );
        }

        $sitemapUrls = $this->discoverSitemaps($baseUrl, $result);
        foreach ($sitemapUrls as $sitemapUrl) {
            $sitemap = $this->fetchUrl($sitemapUrl);
            $result['methods'][] = $this->methodStatus('Sitemap', $sitemapUrl, $sitemap);
            if (!$sitemap['ok']) {
                continue;
            }

            $this->mergeCandidates(
                $candidates,
                $this->extractSitemapGalleries($sitemap['body'], $baseUrl)
            );
        }

        $result['galleries'] = $this->validateCandidates($candidates, $baseUrl);

        usort($result['galleries'], function ($a, $b) {
            $titleA = function_exists('mb_strtolower') ? mb_strtolower($a['title']) : strtolower($a['title']);
            $titleB = function_exists('mb_strtolower') ? mb_strtolower($b['title']) : strtolower($b['title']);
            return strcmp($titleA, $titleB);
        });

        return $result;
    }

    private function normalizeBaseUrl($inputUrl)
    {
        $inputUrl = trim((string) $inputUrl);
        if ($inputUrl === '') {
            return null;
        }

        if (!preg_match('~^https?://~i', $inputUrl)) {
            $inputUrl = 'https://' . $inputUrl;
        }

        $parts = parse_url($inputUrl);
        if (empty($parts['host'])) {
            return null;
        }

        $scheme = isset($parts['scheme']) && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            ? strtolower($parts['scheme'])
            : 'https';

        return $scheme . '://' . strtolower($parts['host']) . '/';
    }

    private function fetchUrl($url)
    {
        $headers = [
            'User-Agent: ' . $this->userAgent,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $body = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
            curl_close($ch);

            return [
                'ok' => $body !== false && $status >= 200 && $status < 400,
                'status' => $status,
                'url' => $finalUrl,
                'body' => $body === false ? '' : $body,
                'error' => $error,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $match)) {
            $status = (int) $match[1];
        }

        return [
            'ok' => $body !== false && $status >= 200 && $status < 400,
            'status' => $status,
            'url' => $url,
            'body' => $body === false ? '' : $body,
            'error' => $body === false ? 'Не удалось загрузить URL' : '',
        ];
    }

    private function methodStatus($name, $url, $response)
    {
        return [
            'name' => $name,
            'url' => $url,
            'ok' => $response['ok'],
            'status' => $response['status'],
            'error' => $response['error'],
        ];
    }

    private function discoverSitemaps($baseUrl, &$result)
    {
        $sitemaps = [];
        $robotsUrl = rtrim($baseUrl, '/') . '/robots.txt';
        $robots = $this->fetchUrl($robotsUrl);
        $result['methods'][] = $this->methodStatus('robots.txt', $robotsUrl, $robots);

        if ($robots['ok']) {
            if (preg_match_all('~^\s*Sitemap:\s*(\S+)~mi', $robots['body'], $matches)) {
                foreach ($matches[1] as $sitemap) {
                    $this->addUnique($sitemaps, $this->absoluteUrl($baseUrl, trim($sitemap)));
                }
            }
        }

        $this->addUnique($sitemaps, rtrim($baseUrl, '/') . '/sitemap.xml');
        $this->addUnique($sitemaps, rtrim($baseUrl, '/') . '/sitemap_index.xml');

        return $sitemaps;
    }

    private function extractPortfolioGalleries($html, $baseUrl, $source)
    {
        $found = [];
        $decodedHtml = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $payloads = [$decodedHtml, stripcslashes($decodedHtml)];

        foreach ($payloads as $payload) {
            if (!preg_match_all('~"galleries"\s*:\s*(\[[\s\S]*?\])\s*,\s*"generalSettings"~u', $payload, $matches)) {
                continue;
            }

            foreach ($matches[1] as $json) {
                $items = json_decode($json, true);
                if (!is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $slug = '';
                    if (!empty($item['url'])) {
                        $slug = $item['url'];
                    } elseif (!empty($item['slug'])) {
                        $slug = $item['slug'];
                    }

                    $url = $this->galleryUrlFromSlug($baseUrl, $slug);
                    if ($url === null) {
                        continue;
                    }

                    $found[] = [
                        'url' => $url,
                        'title' => isset($item['name']) ? $item['name'] : '',
                        'source' => $source,
                        'confidence' => 'high',
                    ];
                }
            }
        }

        return $found;
    }

    private function extractLinkedGalleryPaths($html, $baseUrl, $source, $onlyAnchors)
    {
        $found = [];
        $decodedHtml = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($onlyAnchors && class_exists('DOMDocument')) {
            $document = new DOMDocument();
            libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="utf-8" ?>' . $decodedHtml);
            libxml_clear_errors();

            foreach ($document->getElementsByTagName('a') as $link) {
                $href = $link->getAttribute('href');
                if ($href === '') {
                    continue;
                }

                $url = $this->galleryUrlFromSlug($baseUrl, $href);
                if ($url === null) {
                    continue;
                }

                $found[] = [
                    'url' => $url,
                    'title' => trim($link->textContent),
                    'source' => $source,
                    'confidence' => 'high',
                ];
            }

            return $found;
        }

        if ($onlyAnchors) {
            return $found;
        }

        if (preg_match_all('#(?:https?://[^"\'<>\s]+)?/gallery/([A-Za-z0-9_.~%+-]+)#u', $decodedHtml, $matches)) {
            foreach ($matches[0] as $rawUrl) {
                $url = $this->galleryUrlFromSlug($baseUrl, $rawUrl);
                if ($url === null) {
                    continue;
                }

                $found[] = [
                    'url' => $url,
                    'title' => '',
                    'source' => $source,
                    'confidence' => 'low',
                ];
            }
        }

        return $found;
    }

    private function extractSitemapGalleries($xml, $baseUrl)
    {
        $found = [];
        if (!preg_match_all('~<loc>\s*([^<]+)\s*</loc>~i', $xml, $matches)) {
            return $found;
        }

        foreach ($matches[1] as $loc) {
            $url = $this->galleryUrlFromSlug($baseUrl, html_entity_decode(trim($loc), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url === null) {
                continue;
            }

            $found[] = [
                'url' => $url,
                'title' => '',
                'source' => 'Sitemap',
                'confidence' => 'high',
            ];
        }

        return $found;
    }

    private function validateCandidates($candidates, $baseUrl)
    {
        $galleries = [];
        $validationRequests = 0;

        foreach ($candidates as $candidate) {
            $url = $candidate['url'];
            $needsValidation = $candidate['confidence'] !== 'high';

            if ($needsValidation) {
                if ($validationRequests >= $this->maxValidationRequests) {
                    continue;
                }

                $validationRequests++;
                $response = $this->fetchUrl($url);
                if (!$this->isGalleryResponse($response)) {
                    continue;
                }

                if ($candidate['title'] === '') {
                    $candidate['title'] = $this->extractTitle($response['body']);
                }

                $candidate['access'] = $this->detectAccess($response['body']);
            } else {
                $candidate['access'] = 'публичная';
            }

            if ($candidate['title'] === '') {
                $candidate['title'] = $this->titleFromUrl($url);
            }

            $candidate['slug'] = trim(parse_url($url, PHP_URL_PATH), '/');
            $galleries[$url] = $candidate;
        }

        return array_values($galleries);
    }

    private function isGalleryResponse($response)
    {
        if (!$response['ok']) {
            return false;
        }

        $body = $response['body'];
        if (stripos($body, 'Страница не найдена') !== false || stripos($body, 'Ошибка 404') !== false) {
            return false;
        }

        return stripos($body, '/gallery/') !== false
            || stripos($body, 'GalleryPage') !== false
            || stripos($body, 'passwordPage') !== false;
    }

    private function detectAccess($html)
    {
        if (stripos($html, 'закрытой галерее') !== false || stripos($html, 'passwordPage') !== false) {
            return 'закрытая/по паролю';
        }

        return 'публичная';
    }

    private function extractTitle($html)
    {
        if (preg_match('~<title>\s*(.*?)\s*</title>~isu', $html, $match)) {
            return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        if (preg_match('~property=["\']og:title["\']\s+content=["\']([^"\']+)["\']~isu', $html, $match)) {
            return trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function titleFromUrl($url)
    {
        $path = trim(parse_url($url, PHP_URL_PATH), '/');
        $parts = explode('/', $path);
        $slug = end($parts);
        $slug = urldecode($slug);
        $slug = str_replace(['-', '_'], ' ', $slug);

        return $slug === '' ? $url : $slug;
    }

    private function mergeCandidates(&$target, $items)
    {
        foreach ($items as $item) {
            if (empty($item['url'])) {
                continue;
            }

            if (!isset($target[$item['url']])) {
                $target[$item['url']] = $item;
                continue;
            }

            if ($target[$item['url']]['confidence'] !== 'high' && $item['confidence'] === 'high') {
                $target[$item['url']]['confidence'] = 'high';
            }
            if ($target[$item['url']]['title'] === '' && $item['title'] !== '') {
                $target[$item['url']]['title'] = $item['title'];
            }
            if (strpos($target[$item['url']]['source'], $item['source']) === false) {
                $target[$item['url']]['source'] .= ', ' . $item['source'];
            }
        }
    }

    private function galleryUrlFromSlug($baseUrl, $slug)
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return null;
        }

        $url = $this->absoluteUrl($baseUrl, $slug);
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);

        if ($host !== $baseHost || !is_string($path)) {
            return null;
        }

        if (!preg_match('~^/(?:v/)?gallery/([^/]+)/?$~i', $path, $match)) {
            if (!preg_match('#^[A-Za-z0-9_.~%+-]+$#', $slug)) {
                return null;
            }

            $path = '/gallery/' . rawurlencode($slug) . '/';
        }

        return $this->origin($baseUrl) . rtrim($path, '/') . '/';
    }

    private function absoluteUrl($baseUrl, $url)
    {
        $url = trim((string) $url);
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }

        if (strpos($url, '//') === 0) {
            return parse_url($baseUrl, PHP_URL_SCHEME) . ':' . $url;
        }

        if (strpos($url, '/') === 0) {
            return $this->origin($baseUrl) . $url;
        }

        return $this->origin($baseUrl) . '/gallery/' . ltrim($url, '/');
    }

    private function origin($baseUrl)
    {
        return parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST);
    }

    private function addUnique(&$items, $value)
    {
        if ($value !== null && !in_array($value, $items, true)) {
            $items[] = $value;
        }
    }
}
