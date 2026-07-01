<?php
namespace PB;

class ZipBuilder
{
    private Parser           $parser;
    private ContentConverter $converter;
    private bool             $skipImages;

    public array $warnings       = [];
    public array $errors         = [];
    public array $imageFailures  = [];
    public int   $fileCount      = 0;
    public array $sectionLabels  = [];

    private array   $zipFiles = []; // zipPath => string content
    private array   $zipBin   = []; // zipPath => binary content (images)

    private ?string $coverImageData     = null;
    private ?string $coverImageFilename = null;

    private bool   $sslHintShown  = false;
    private string $sectionLabel  = 'Section';

    public function setCoverImage(string $data, string $filename): void
    {
        $this->coverImageData     = $data;
        $this->coverImageFilename = $filename;
    }

    public function __construct(Parser $parser, bool $skipImages = true, bool $figureHtml = true)
    {
        $this->parser     = $parser;
        $this->skipImages = $skipImages;
        $this->converter  = new ContentConverter($parser->linkMap, $figureHtml);
    }

    // Build the zip and return the path to the temp file
    public function build(): string
    {
        $this->warnings      = array_merge($this->warnings, $this->parser->warnings);
        $this->sectionLabel  = $this->detectSectionLabel();

        $this->buildSectionList();
        $this->buildFrontMatter();
        $this->buildParts();
        $this->buildBackMatter();
        $this->buildVersioningConfig();

        $this->warnings = array_merge($this->warnings, $this->converter->warnings);

        return $this->createZip();
    }

    // ── Section builders ──────────────────────────────────────────────────────

    private function buildSectionList(): void
    {
        $p = $this->parser;

        $coverFilename = '';
        if ($this->coverImageData !== null) {
            $coverFilename = $this->coverImageFilename;
            $this->zipBin['pages/00.sections/' . $coverFilename] = $this->coverImageData;
        } elseif ($p->bookCoverUrl) {
            $data = $this->downloadFile($p->bookCoverUrl);
            if ($data !== null) {
                $coverFilename = basename(parse_url($p->bookCoverUrl, PHP_URL_PATH));
                $this->zipBin['pages/00.sections/' . $coverFilename] = $data;
            }
        }

        $lines = ['---', 'title: ' . Helpers::yamlStr($p->bookTitle), 'menu: Home'];
        if ($p->bookSubtitle) {
            $lines[] = 'subtitle: ' . Helpers::yamlStr($p->bookSubtitle);
        }
        if ($coverFilename) {
            $lines[] = 'cover_image: ' . $coverFilename;
            $lines[] = 'cover_image_layout: sidebar';
        }

        $authorsStr = $this->formatAuthors($p->bookAuthors);
        if ($authorsStr) {
            $lines[] = 'authors: ' . Helpers::yamlStr($authorsStr);
            $lines[] = 'show_oer_attribution: true';
        }
        if ($p->bookLicense) {
            $lines[] = 'license: ' . Helpers::yamlStr($p->bookLicense);
        }
        if ($p->bookLicenseUrl) {
            $lines[] = 'license_url: ' . Helpers::yamlStr($p->bookLicenseUrl);
        }
        if ($this->sectionLabel !== 'Section') {
            $lines[] = 'section_label: ' . $this->sectionLabel;
            $lines[] = 'show_section_label: false';
        }
        if ($p->bookLicense && $authorsStr) {
            $parts = [$p->bookTitle . ' by ' . $authorsStr];
            if ($p->bookYear) {
                $parts[] = '© ' . $p->bookYear;
            }
            $parts[] = 'is licensed under <a href="' . $p->bookLicenseUrl . '">' . $p->bookLicense . '</a>.';
            $lines[] = 'attribution_text: ' . Helpers::yamlStr(implode(' ', $parts));
        }
        $lines[] = '---';

        $content = implode("\n", $lines) . "\n\n" . trim($p->bookAbout) . "\n";
        $this->addFile('pages/00.sections/section-list.md', $content);
    }

    private function buildFrontMatter(): void
    {
        $pages = [];
        foreach ($this->parser->frontMatters as $fm) {
            try {
                $content = $this->converter->convert($fm['html']);
            } catch (\Exception $e) {
                $this->errors[] = "failed to convert front-matter page \"{$fm['title']}\": " . $e->getMessage();
                $content = '> **Conversion error:** ' . $e->getMessage() . "\n";
            }
            $pages[] = [$fm['title'], $content];
        }

        $this->writeSection(
            1, 'front-matter', 'Front Matter',
            'Accessibility, about, preface, and acknowledgements.',
            null, null, $pages
        );
    }

    private function buildParts(): void
    {
        $secNum = 1;
        foreach ($this->parser->parts as $part) {
            $secNum++;

            $partBody = '';
            if ($part['html']) {
                try {
                    $partBody = $this->converter->convert($part['html']);
                } catch (\Exception $e) {
                    $this->errors[] = "failed to convert part intro \"{$part['title']}\": " . $e->getMessage();
                }
            }

            $objectivesText = null;
            if (!empty($part['chapters'])) {
                $objectivesText = $this->converter->extractObjectives($part['chapters'][0]['html']);
            }

            $pages = [];
            foreach ($part['chapters'] as $ch) {
                try {
                    $content = $this->converter->convert($ch['html']);
                } catch (\Exception $e) {
                    $this->errors[] = "failed to convert chapter \"{$ch['title']}\": " . $e->getMessage();
                    $content = '> **Conversion error:** ' . $e->getMessage() . "\n";
                }
                $pages[] = [$ch['title'], $content];
            }

            $slugTitle = $part['title'];
            if (preg_match('/^(?:Module|Chapter|Part|Unit)\s+\d+[.:]?\s*/i', $part['title'], $m)) {
                $slugTitle = trim(substr($part['title'], strlen($m[0])));
            } elseif ($this->sectionLabel !== 'Section' && preg_match('/^(\d+)[.:]\s+/i', $part['title'], $m)) {
                $slugTitle = trim(substr($part['title'], strlen($m[0])));
            }

            $this->writeSection(
                $secNum, Helpers::slugify($slugTitle ?: $part['title']), $part['title'],
                null, $objectivesText, $partBody, $pages
            );
        }
    }

    private function buildBackMatter(): void
    {
        if (!$this->parser->backMatters) {
            return;
        }

        $secNum = 1 + count($this->parser->parts) + 1;
        $pages  = [];

        foreach ($this->parser->backMatters as $bm) {
            try {
                $content = $this->converter->convert($bm['html']);
            } catch (\Exception $e) {
                $this->errors[] = "failed to convert back-matter page \"{$bm['title']}\": " . $e->getMessage();
                $content = '> **Conversion error:** ' . $e->getMessage() . "\n";
            }
            $pages[] = [$bm['title'], $content];
        }

        $this->writeSection(
            $secNum, 'back-matter', 'Back Matter',
            'Appendices, bibliography, and versioning history.',
            null, null, $pages
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function detectSectionLabel(): string
    {
        foreach ($this->parser->parts as $part) {
            if (preg_match('/^(Module|Chapter|Part|Unit)\s+\d+[.:]?\s*/i', $part['title'], $m)) {
                return ucfirst(strtolower($m[1]));
            }
        }
        return 'Section';
    }

    // ── Core write helper ─────────────────────────────────────────────────────

    private function writeSection(
        int     $secNum,
        string  $secSlug,
        string  $secTitle,
        ?string $secDesc,
        ?string $objectivesText,
        ?string $secBody,
        array   $pages
    ): void {
        $secFolder = sprintf('pages/%02d.section-%d', $secNum, $secNum);

        $fm = ['---', 'title: ' . Helpers::yamlStr($secTitle)];
        if ($secDesc) {
            $fm[] = 'description: ' . Helpers::yamlStr($secDesc);
        }
        if ($objectivesText) {
            $fm[] = 'learning_objectives: ' . Helpers::yamlStr($objectivesText);
        }
        if (!$secBody && $pages) {
            $firstSlug = Helpers::slugify($pages[0][0]);
            $fm[] = 'redirect: /section-' . $secNum . '/' . $firstSlug;
        }
        $fm[] = '---';

        $bodyContent = $secBody ? $this->processImages($secBody, $secFolder) : '';
        $this->addFile($secFolder . '/section.md', implode("\n", $fm) . "\n\n" . trim($bodyContent) . "\n");

        $this->sectionLabels[] = [$secNum, $secTitle];

        $usedSlugs = [];
        foreach ($pages as $pageNum => [$pageTitle, $pageContent]) {
            $pageNum++;
            $pageSlug    = Helpers::uniqueSlug($pageTitle, $usedSlugs);
            $pageFolder  = sprintf('%s/%02d.%s', $secFolder, $pageNum, $pageSlug);
            $pageContent = $this->processImages($pageContent, $pageFolder);
            $pageFm      = ['---', 'title: ' . Helpers::yamlStr($pageTitle), '---'];
            $this->addFile($pageFolder . '/section-page.md', implode("\n", $pageFm) . "\n\n" . $pageContent . "\n");
        }
    }

    // ── Image handling ────────────────────────────────────────────────────────

    private function processImages(string $markdown, string $zipFolder): string
    {
        if ($this->skipImages) {
            return $markdown;
        }
        return preg_replace_callback(
            '/!\[([^\]]*)\]\((https?:\/\/(?:[^()]+|\((?:[^()]+|\([^()]*\))*\))+)\)/',
            function ($m) use ($zipFolder) {
                $alt      = $m[1];
                $url      = $m[2];
                $filename = basename(parse_url($url, PHP_URL_PATH));
                if (!$filename) {
                    return $m[0];
                }
                $zipPath = $zipFolder . '/' . $filename;
                if (!isset($this->zipBin[$zipPath])) {
                    $data = $this->downloadFile($url);
                    if ($data !== null) {
                        $this->zipBin[$zipPath] = $data;
                    }
                }
                return isset($this->zipBin[$zipPath])
                    ? "![$alt]($filename)"
                    : $m[0];
            },
            $markdown
        );
    }

    private function downloadFile(string $url): ?string
    {
        // SSRF guard: reject private/loopback/link-local addresses
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            $this->imageFailures[] = $url;
            return null;
        }
        $ip = gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $this->warnings[]      = "Skipped image from private/internal address: $host";
            $this->imageFailures[] = $url;
            return null;
        }

        $opts = [
            'http' => [
                'method'          => 'GET',
                'header'          => 'User-Agent: Mozilla/5.0',
                'timeout'         => 15,
                'follow_location' => 1,
                'max_length'      => 20 * 1024 * 1024, // 20 MB cap per image
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ];

        // Try with SSL verification, fall back without
        foreach ([true, false] as $verify) {
            $opts['ssl']['verify_peer']      = $verify;
            $opts['ssl']['verify_peer_name'] = $verify;
            $ctx  = stream_context_create($opts);
            $data = @file_get_contents($url, false, $ctx);
            if ($data !== false) {
                return $data;
            }
            if ($verify && !$this->sslHintShown) {
                $this->sslHintShown = true;
                $this->warnings[]   = 'SSL certificate error — retrying without verification';
            }
        }
        $this->imageFailures[] = $url;
        return null;
    }

    private function buildVersioningConfig(): void
    {
        $lines = [
            '# Paste the indented block below into user/config/themes/helios.yaml',
            '# under the existing versioning: key to set the section card titles.',
            'versioning:',
            '  labels:',
        ];
        foreach ($this->sectionLabels as [$num, $title]) {
            $lines[] = "    section-{$num}: '" . str_replace("'", "''", $title) . "'";
        }
        $this->addFile('versioning-labels.yaml', implode("\n", $lines) . "\n");
    }

    // ── Zip assembly ──────────────────────────────────────────────────────────

    private function addFile(string $path, string $content): void
    {
        $this->zipFiles[$path] = $content;
        $this->fileCount++;
    }

    private function createZip(): string
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZipArchive extension is not available on this server.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pb_convert_');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create temporary zip file.');
        }

        foreach ($this->zipFiles as $path => $content) {
            $zip->addFromString($path, $content);
        }
        foreach ($this->zipBin as $path => $data) {
            $zip->addFromString($path, $data);
        }

        // Add failed image URLs as a text file if any
        if ($this->imageFailures) {
            $zip->addFromString('pages/images-not-downloaded.txt', implode("\n", $this->imageFailures) . "\n");
        }

        $zip->close();
        return $tmp;
    }

    // ── Misc helpers ──────────────────────────────────────────────────────────

    private function formatAuthors(array $authors): string
    {
        if (count($authors) > 1) {
            return implode(', ', array_slice($authors, 0, -1)) . ' and ' . end($authors);
        }
        return $authors[0] ?? '';
    }
}
