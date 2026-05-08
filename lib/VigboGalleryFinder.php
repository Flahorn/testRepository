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

    public function find($inputUrl, $manualSlugs = [])
    {
        $baseUrl = $this->normalizeBaseUrl($inputUrl);
        if ($baseUrl === null) {
            return [
                'baseUrl' => '',
                'host' => '',
                'searchBaseUrls' => [],
                'methods' => [],
                'errors' => ['Укажите корректный URL сайта Vigbo/gallery.photo.'],
                'galleries' => [],
            ];
        }

        $searchBaseUrls = $this->candidateBaseUrls($baseUrl);
        $result = [
            'baseUrl' => $baseUrl,
            'host' => parse_url($baseUrl, PHP_URL_HOST),
            'searchBaseUrls' => $searchBaseUrls,
            'methods' => [],
            'errors' => [],
            'galleries' => [],
        ];

        $candidates = [];

        foreach ($searchBaseUrls as $searchBaseUrl) {
            $home = $this->fetchUrl($searchBaseUrl);
            $result['methods'][] = $this->methodStatus('Главная страница', $searchBaseUrl, $home);
            if ($home['ok']) {
                $this->mergeCandidates(
                    $candidates,
                    $this->extractPortfolioGalleries($home['body'], $searchBaseUrl, 'Next.js/RSC galleries')
                );
                $this->mergeCandidates(
                    $candidates,
                    $this->extractLinkedGalleryPaths($home['body'], $searchBaseUrl, 'Ссылки на главной', true)
                );
                $this->mergeCandidates(
                    $candidates,
                    $this->extractManualSlugCandidates($manualSlugs, $searchBaseUrl)
                );
                $this->mergeCandidates(
                    $candidates,
                    $this->extractLinkedGalleryPaths($home['body'], $searchBaseUrl, 'HTML /gallery/<slug>', false)
                );
            } else {
                $this->mergeCandidates(
                    $candidates,
                    $this->extractManualSlugCandidates($manualSlugs, $searchBaseUrl)
                );
            }

            $sitemapUrls = $this->discoverSitemaps($searchBaseUrl, $result);
            foreach ($sitemapUrls as $sitemapUrl) {
                $sitemap = $this->fetchUrl($sitemapUrl);
                $result['methods'][] = $this->methodStatus('Sitemap', $sitemapUrl, $sitemap);
                if (!$sitemap['ok']) {
                    continue;
                }

                $this->mergeCandidates(
                    $candidates,
                    $this->extractSitemapGalleries($sitemap['body'], $searchBaseUrl)
                );
            }
        }

        $result['galleries'] = $this->validateCandidates($candidates);

        usort($result['galleries'], function ($a, $b) {
            $titleA = function_exists('mb_strtolower') ? mb_strtolower($a['title']) : strtolower($a['title']);
            $titleB = function_exists('mb_strtolower') ? mb_strtolower($b['title']) : strtolower($b['title']);
            return strcmp($titleA, $titleB);
        });

        return $result;
    }

    private function candidateBaseUrls($baseUrl)
    {
        $baseUrls = [$baseUrl];
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return $baseUrls;
        }

        $host = preg_replace('~^www\.~i', '', strtolower($host));
        if (substr($host, -strlen('.gallery.photo')) === '.gallery.photo') {
            return $baseUrls;
        }

        $parts = explode('.', $host);
        if (count($parts) < 2 || $parts[0] === '') {
            return $baseUrls;
        }

        // 2ch threads describe this common Vigbo convention:
        // photographer-site.ru -> photographer-site.gallery.photo.
        $derivedBaseUrl = 'https://' . $parts[0] . '.gallery.photo/';
        if (!in_array($derivedBaseUrl, $baseUrls, true)) {
            $baseUrls[] = $derivedBaseUrl;
        }

        return $baseUrls;
    }

    private function extractManualSlugCandidates($manualSlugs, $baseUrl)
    {
        $found = [];
        foreach ($this->normalizeManualSlugs($manualSlugs) as $slug) {
            $url = $this->galleryUrlFromSlug($baseUrl, $slug);
            if ($url === null) {
                continue;
            }

            $found[] = [
                'url' => $url,
                'title' => '',
                'source' => 'Ручной slug (2ch-подход)',
                'confidence' => 'low',
            ];
        }

        return $found;
    }

    private function normalizeManualSlugs($manualSlugs)
    {
        if (is_string($manualSlugs)) {
            $manualSlugs = preg_split('~[\r\n,;]+~u', $manualSlugs, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (!is_array($manualSlugs)) {
            return [];
        }

        $normalized = [];
        foreach ($manualSlugs as $slug) {
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }

            if (preg_match('~/gallery/([^/?#]+)~i', $slug, $match)) {
                $slug = $match[1];
            }

            $slug = trim($slug, "/ \t\n\r\0\x0B");
            foreach ($this->slugVariants($slug) as $variant) {
                if ($variant !== '' && !in_array($variant, $normalized, true)) {
                    $normalized[] = $variant;
                }
            }
        }

        return array_slice($normalized, 0, $this->maxValidationRequests);
    }

    private function slugVariants($slug)
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return [];
        }

        $variants = [$slug, strtolower($slug)];
        if (preg_match('~\s+~u', $slug)) {
            $spaced = preg_replace('~\s+~u', ' ', $slug);
            $variants[] = str_replace(' ', '-', $spaced);
            $variants[] = str_replace(' ', '_', $spaced);
            $variants[] = str_replace(' ', '', $spaced);
            $variants[] = strtolower(str_replace(' ', '-', $spaced));
            $variants[] = strtolower(str_replace(' ', '_', $spaced));
            $variants[] = strtolower(str_replace(' ', '', $spaced));
        }

        return array_values(array_unique($variants));
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

    private function validateCandidates($candidates)
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
        $title = $this->extractTitle($body);
        if ($title === '' || in_array($title, ['Страница не найдена', 'Ошибка 404', 'Error 404'], true)) {
            return false;
        }

        return stripos($body, 'GalleryPageClient') !== false
            || preg_match('~\\\\?"gallery\\\\?"\s*:\s*\{~', $body)
            || preg_match('~\\\\?"url\\\\?"\s*:\s*\\\\?"' . preg_quote(basename(parse_url($response['url'], PHP_URL_PATH)), '~') . '\\\\?"~', $body);
    }

    private function detectAccess($html)
    {
        if (preg_match('~\\\\?"isPrivate\\\\?"\s*:\s*true~', $html)) {
            return 'закрытая/по паролю';
        }

        if (preg_match('~\\\\?"isPrivate\\\\?"\s*:\s*false~', $html)) {
            return 'публичная';
        }

        if (stripos($html, 'qa-password-input') !== false) {
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
