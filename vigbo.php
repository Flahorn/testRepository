<?php
require __DIR__ . '/lib/VigboGalleryFinder.php';

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$defaultUrl = 'https://valeriashukh.gallery.photo/';
$inputUrl = isset($_GET['url']) ? trim($_GET['url']) : $defaultUrl;
$inputSlugs = isset($_GET['slugs']) ? trim($_GET['slugs']) : '';
$shouldSearch = isset($_GET['url']);
$result = null;

if ($shouldSearch) {
    $finder = new VigboGalleryFinder();
    $result = $finder->find($inputUrl, $inputSlugs);
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Поиск Vigbo-галерей</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.5;
            margin: 32px;
            color: #222;
        }
        form, .card {
            max-width: 980px;
            margin-bottom: 24px;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fafafa;
        }
        input[type="url"], input[type="text"], textarea {
            width: min(720px, 100%);
            padding: 10px;
            font-size: 16px;
            box-sizing: border-box;
        }
        textarea {
            min-height: 90px;
        }
        button {
            padding: 10px 16px;
            font-size: 16px;
            cursor: pointer;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #f0f0f0;
        }
        .ok {
            color: #0b7a22;
        }
        .fail {
            color: #a21b1b;
        }
        .muted {
            color: #666;
        }
        .errors {
            color: #a21b1b;
        }
    </style>
</head>
<body>
    <p><a href="/">На главную</a></p>
    <h1>Поиск Vigbo-галерей</h1>

    <div class="card">
        <h2>Рабочие способы поиска</h2>
        <ol>
            <li>Разбор Next.js/RSC payload: Vigbo публикует список в компоненте <code>PortfolioPageClient</code> как массив <code>galleries</code>.</li>
            <li>Проверка <code>robots.txt</code> и стандартных sitemap-файлов, где могут быть URL вида <code>/gallery/&lt;slug&gt;/</code>.</li>
            <li>Сбор ссылок и путей <code>/gallery/&lt;slug&gt;</code> из HTML с валидацией на том же домене, чтобы отсечь служебные чужие ссылки из шаблона Vigbo.</li>
            <li>Безопасная часть метода из 2ch: для обычного сайта фотографа проверяется производный домен <code>имя-сайта.gallery.photo</code>, а вручную заданные slug'и проверяются как <code>/gallery/&lt;slug&gt;/</code>.</li>
        </ol>
        <p class="muted">Обход пароля и API-скачивание закрытых галерей намеренно не реализованы.</p>
    </div>

    <form method="get" action="vigbo.php">
        <p>
            <label for="url">Сайт фотографа или Vigbo/gallery.photo</label><br>
            <input id="url" type="url" name="url" value="<?=h($inputUrl)?>" placeholder="https://example.gallery.photo/">
        </p>
        <p>
            <label for="slugs">Slug-кандидаты, по одному на строку или через запятую</label><br>
            <textarea id="slugs" name="slugs" placeholder="anna&#10;2024-05-10&#10;ivan-i-maria"><?=h($inputSlugs)?></textarea>
            <br><span class="muted">Это ручная проверка формата из тредов: <code>/gallery/&lt;slug&gt;/</code>. Максимум 40 кандидатов за запуск.</span>
        </p>
        <button type="submit">Найти галереи</button>
    </form>

    <?php if ($result !== null): ?>
        <div class="card">
            <h2>Результат для <?=h($result['baseUrl'])?></h2>
            <?php if (!empty($result['searchBaseUrls'])): ?>
                <p class="muted">Проверенные базы: <?=h(implode(', ', $result['searchBaseUrls']))?></p>
            <?php endif; ?>

            <?php if (!empty($result['errors'])): ?>
                <div class="errors">
                    <?php foreach ($result['errors'] as $error): ?>
                        <p><?=h($error)?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($result['galleries'])): ?>
                <p><strong>Галереи не найдены.</strong></p>
                <p class="muted">Для <?=h($inputUrl)?> страница Vigbo сейчас возвращает пустой список опубликованных галерей.</p>
            <?php else: ?>
                <p>Найдено галерей: <strong><?=count($result['galleries'])?></strong></p>
                <table>
                    <thead>
                        <tr>
                            <th>Название</th>
                            <th>URL</th>
                            <th>Доступ</th>
                            <th>Источник</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result['galleries'] as $gallery): ?>
                            <tr>
                                <td><?=h($gallery['title'])?></td>
                                <td><a href="<?=h($gallery['url'])?>" target="_blank" rel="noopener noreferrer"><?=h($gallery['url'])?></a></td>
                                <td><?=h($gallery['access'])?></td>
                                <td><?=h($gallery['source'])?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Проверенные источники</h2>
            <table>
                <thead>
                    <tr>
                        <th>Метод</th>
                        <th>URL</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($result['methods'] as $method): ?>
                        <tr>
                            <td><?=h($method['name'])?></td>
                            <td><a href="<?=h($method['url'])?>" target="_blank" rel="noopener noreferrer"><?=h($method['url'])?></a></td>
                            <td class="<?=$method['ok'] ? 'ok' : 'fail'?>">
                                <?=h($method['status'] ?: '-')?>
                                <?=h($method['error'] ? ' ' . $method['error'] : '')?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</body>
</html>
