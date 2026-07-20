<?php
require_once __DIR__ . '/vendor/autoload.php';

use PB\Parser;
use PB\ZipBuilder;

const MAX_UPLOAD_BYTES        = 50 * 1024 * 1024;
const MIN_PREVIEW_PAGE_LENGTH = 500;
const MAX_PREVIEW_PAGES       = 5;

header('Content-Type: application/json');

function sendError(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    echo json_encode(['error' => $message]);
    exit;
}

$fileError = $_FILES['xhtml']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($fileError !== UPLOAD_ERR_OK) {
    sendError(400, 'No file was uploaded.');
}

if ($_FILES['xhtml']['size'] > MAX_UPLOAD_BYTES) {
    sendError(400, 'File exceeds the 50 MB limit.');
}

$html       = file_get_contents($_FILES['xhtml']['tmp_name']);
$skipImages = !empty($_POST['skip_images']);
$figureHtml = !empty($_POST['figure_html']);

try {
    $parser = new Parser($html);
    if (!$parser->isValid()) {
        throw new \Exception('No Pressbooks structure found in the uploaded file.');
    }

    $builder = new ZipBuilder($parser, $skipImages, $figureHtml, false);
    $zipPath = $builder->build();
    @unlink($zipPath); // preview only needs the in-memory pages, not the zip file itself
} catch (\Throwable $e) {
    sendError(422, $e->getMessage());
}

$pages = pickPreviewPages($builder->getZipFiles(), $builder->getSectionLabels());
if (!$pages) {
    sendError(404, 'No substantial content page was found to preview.');
}

echo json_encode(['pages' => array_map('formatPreviewPage', $pages)]);
exit;

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Samples up to MAX_PREVIEW_PAGES content pages, taking the first substantial
 * section-page.md found within each top-level section folder — gives a
 * representative spread across the book rather than several consecutive
 * pages from the same chapter. Front Matter and Back Matter sections
 * (accessibility statements, prefaces, bibliographies, etc.) are excluded.
 */
function pickPreviewPages(array $zipFiles, array $sectionLabels): array
{
    $skipSections = frontAndBackMatterFolders($sectionLabels);

    $pagesBySection = [];
    foreach ($zipFiles as $path => $content) {
        if (!str_ends_with($path, 'section-page.md')) {
            continue;
        }

        $section = sectionFolderFromPath($path);
        if (isset($skipSections[$section])) {
            continue;
        }

        $body = stripFrontmatter($content);
        if (strlen($body) < MIN_PREVIEW_PAGE_LENGTH) {
            continue; // skip thin/stub pages
        }

        if (!isset($pagesBySection[$section])) {
            $pagesBySection[$section] = ['path' => $path, 'content' => $content];
        }
    }

    return array_slice(array_values($pagesBySection), 0, MAX_PREVIEW_PAGES);
}

function formatPreviewPage(array $page): array
{
    preg_match('/title:\s*"(.+?)"/', $page['content'], $titleMatch);
    return [
        'path'     => $page['path'],
        'title'    => $titleMatch[1] ?? 'Untitled',
        'markdown' => stripFrontmatter($page['content']),
    ];
}

// Removes the leading "--- ... ---" YAML block every page starts with.
function stripFrontmatter(string $pageContent): string
{
    return preg_replace('/^\s*---.*?---\s*/s', '', $pageContent);
}

// e.g. "pages/02.section-2/01.some-page/section-page.md" -> "02.section-2"
function sectionFolderFromPath(string $path): string
{
    preg_match('#^pages/([^/]+)/#', $path, $match);
    return $match[1] ?? '';
}

// section-folder-name => true, for every section titled "Front Matter" or "Back Matter".
function frontAndBackMatterFolders(array $sectionLabels): array
{
    $folders = [];
    foreach ($sectionLabels as [$sectionNumber, $sectionTitle]) {
        if (in_array($sectionTitle, ['Front Matter', 'Back Matter'], true)) {
            $folders[sprintf('%02d.section-%d', $sectionNumber, $sectionNumber)] = true;
        }
    }
    return $folders;
}
