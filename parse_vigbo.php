<?php
require __DIR__ . '/lib/VigboGalleryFinder.php';

if (php_sapi_name() !== 'cli') {
    echo "Run this script from CLI.\n";
    exit(1);
}

$url = isset($argv[1]) ? $argv[1] : 'https://valeriashukh.gallery.photo/';
$slugs = isset($argv[2]) ? $argv[2] : '';

$finder = new VigboGalleryFinder(10, 80);
$result = $finder->find($url, $slugs);

echo "URL: {$result['baseUrl']}\n";
if (!empty($result['searchBaseUrls'])) {
    echo 'Checked bases: ' . implode(', ', $result['searchBaseUrls']) . "\n";
}

if (!empty($result['errors'])) {
    foreach ($result['errors'] as $error) {
        echo "ERROR: {$error}\n";
    }
}

echo 'Galleries: ' . count($result['galleries']) . "\n";
foreach ($result['galleries'] as $gallery) {
    echo "- {$gallery['title']} | {$gallery['url']} | {$gallery['access']} | {$gallery['source']}\n";
}
