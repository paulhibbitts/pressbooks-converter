<?php
namespace PB;

class Parser
{
    public string $bookTitle      = '';
    public string $bookSubtitle   = '';
    public string $bookAbout      = '';
    public array  $bookAuthors    = [];
    public string $bookYear       = '';
    public string $bookLicenseRaw = '';
    public string $bookLicense    = '';
    public string $bookLicenseUrl = '';
    public string $bookCoverUrl   = '';

    // Each entry: ['id' => string, 'title' => string, 'html' => string]
    public array $frontMatters = [];
    public array $backMatters  = [];

    // Each entry: ['id' => string, 'title' => string, 'html' => string, 'chapters' => [...]]
    // chapters entries: ['id' => string, 'title' => string, 'html' => string]
    public array $parts = [];

    // div_id => '/section-N/slug' (or '/section-N' for parts)
    public array $linkMap = [];

    public array $warnings = [];

    private \DOMDocument $dom;
    private \DOMXPath    $xpath;

    private static array $licenseMap = [
        'cc-by'       => ['CC BY 4.0',       'https://creativecommons.org/licenses/by/4.0/'],
        'cc-by-sa'    => ['CC BY-SA 4.0',    'https://creativecommons.org/licenses/by-sa/4.0/'],
        'cc-by-nc'    => ['CC BY-NC 4.0',    'https://creativecommons.org/licenses/by-nc/4.0/'],
        'cc-by-nc-sa' => ['CC BY-NC-SA 4.0', 'https://creativecommons.org/licenses/by-nc-sa/4.0/'],
        'cc-by-nd'    => ['CC BY-ND 4.0',    'https://creativecommons.org/licenses/by-nd/4.0/'],
        'cc-by-nc-nd' => ['CC BY-NC-ND 4.0', 'https://creativecommons.org/licenses/by-nc-nd/4.0/'],
    ];

    public function __construct(string $html)
    {
        $this->dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $this->dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $this->xpath = new \DOMXPath($this->dom);

        $this->extractMeta();
        $this->extractStructure();
        $this->buildLinkMap();
    }

    public function isValid(): bool
    {
        return !empty($this->frontMatters)
            || !empty($this->parts)
            || !empty($this->backMatters);
    }

    // Returns XPath predicate for class containment
    private function xc(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' $class ')";
    }

    private function extractMeta(): void
    {
        $meta = [];
        foreach ($this->xpath->query('//meta[@name]') as $m) {
            $name    = $m->getAttribute('name');
            $content = $m->getAttribute('content');
            if ($name === 'pb-authors') {
                $this->bookAuthors[] = $content;
            } else {
                $meta[$name] = $content;
            }
        }

        $this->bookTitle      = $meta['pb-title'] ?? '';
        $this->bookSubtitle   = $meta['pb-subtitle'] ?? '';
        $this->bookAbout      = $meta['pb-about-50'] ?? '';
        $this->bookYear       = $meta['pb-copyright-year'] ?? '';
        $this->bookLicenseRaw = $meta['pb-book-license'] ?? '';
        $this->bookCoverUrl   = $meta['pb-cover-image'] ?? '';

        if (!$this->bookTitle) {
            $this->warnings[] = 'no book title found in metadata — using "Publication"';
            $this->bookTitle  = 'Publication';
        }
        if (empty($this->bookAuthors)) {
            $this->warnings[] = 'no authors found in metadata — attribution block will be omitted';
        }
        if (!$this->bookLicenseRaw) {
            $this->warnings[] = 'no license found in metadata — license fields will be omitted';
        } elseif (!isset(self::$licenseMap[$this->bookLicenseRaw])) {
            $raw = $this->bookLicenseRaw;
            $this->warnings[] = "unknown license code \"$raw\" — license fields will be omitted";
        } else {
            [$this->bookLicense, $this->bookLicenseUrl] = self::$licenseMap[$this->bookLicenseRaw];
        }
    }

    private function extractStructure(): void
    {
        // Build document order map (all divs with id)
        $divOrder = [];
        $i        = 0;
        foreach ($this->xpath->query('//div[@id]') as $div) {
            $divOrder[$div->getAttribute('id')] = $i++;
        }

        // Build title lookup from TOC navigation (covers part-wrapper books without part-title elements)
        $tocTitles = [];
        foreach ($this->xpath->query("//li[{$this->xc('part')}]/a[@href]") as $a) {
            $href = ltrim($a->getAttribute('href'), '#');
            $tocTitles[$href]              = html_entity_decode(trim($a->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $tocTitles[$href . '-wrapper'] = $tocTitles[$href]; // part-wrapper id variant
        }

        // Collect and sort parts by document order
        // Match 'part' divs always; match 'part-wrapper' only when no inner 'part' child exists (UDL-style books)
        $rawParts = [];
        foreach ($this->xpath->query("//div[@id and ({$this->xc('part')} or ({$this->xc('part-wrapper')} and not(div[{$this->xc('part')}])))]") as $node) {
            $rawParts[] = $node;
        }
        usort($rawParts, fn($a, $b) =>
            ($divOrder[$a->getAttribute('id')] ?? 0) <=> ($divOrder[$b->getAttribute('id')] ?? 0)
        );

        // Map chapters to their nearest preceding part
        $partChapters = [];
        foreach ($rawParts as $part) {
            $partChapters[$part->getAttribute('id')] = [];
        }
        foreach ($this->xpath->query("//div[{$this->xc('chapter')} and @id]") as $ch) {
            $chPos    = $divOrder[$ch->getAttribute('id')] ?? 0;
            $parentId = null;
            foreach ($rawParts as $part) {
                if (($divOrder[$part->getAttribute('id')] ?? 0) < $chPos) {
                    $parentId = $part->getAttribute('id');
                }
            }
            if ($parentId !== null) {
                $partChapters[$parentId][] = $ch;
            } else {
                $titleNode        = $this->xpath->query(".//*[{$this->xc('chapter-title')}]", $ch)->item(0);
                $chTitle          = $titleNode ? trim($titleNode->textContent) : $ch->getAttribute('id');
                $this->warnings[] = "chapter \"$chTitle\" has no parent part — skipped";
            }
        }

        // Serialize front matter
        foreach ($this->xpath->query("//div[{$this->xc('front-matter')} and @id]") as $node) {
            $titleNode = $this->xpath->query(".//*[{$this->xc('front-matter-title')}]", $node)->item(0);
            $title     = $titleNode ? trim($titleNode->textContent) : 'Front Matter';
            $this->cleanTitleWrap($node);
            $ugc = $this->xpath->query(".//*[{$this->xc('front-matter-ugc')}]", $node)->item(0) ?? $node;
            $this->frontMatters[] = [
                'id'    => $node->getAttribute('id'),
                'title' => $title,
                'html'  => $this->dom->saveHTML($ugc),
            ];
        }

        // Serialize parts and their chapters
        foreach ($rawParts as $part) {
            $id        = $part->getAttribute('id');
            $titleNode = $this->xpath->query(".//*[{$this->xc('part-title')}]", $part)->item(0);
            if ($titleNode) {
                $title = trim($titleNode->textContent);
            } elseif (isset($tocTitles[$id])) {
                $title = $tocTitles[$id];
            } else {
                // Last resort: derive title from id slug
                $slug  = preg_replace('/^part-|-wrapper$/', '', $id);
                $title = ucwords(str_replace('-', ' ', $slug));
            }

            $partUgc  = $this->xpath->query(
                ".//*[{$this->xc('ugc')} and {$this->xc('part-ugc')}]", $part
            )->item(0);
            if ($partUgc) {
                foreach (iterator_to_array($this->xpath->query(".//*[{$this->xc('media-attributions')}]", $partUgc)) as $el) {
                    $el->parentNode->removeChild($el);
                }
            }

            $chapters = [];
            foreach ($partChapters[$id] as $ch) {
                $chTitleNode = $this->xpath->query(".//*[{$this->xc('chapter-title')}]", $ch)->item(0);
                $chTitle     = $chTitleNode ? trim($chTitleNode->textContent) : 'Page';
                $this->cleanTitleWrap($ch);
                $ugc = $this->xpath->query(".//*[{$this->xc('chapter-ugc')}]", $ch)->item(0) ?? $ch;
                $chapters[] = [
                    'id'    => $ch->getAttribute('id'),
                    'title' => $chTitle,
                    'html'  => $this->dom->saveHTML($ugc),
                ];
            }

            $this->parts[] = [
                'id'       => $id,
                'title'    => $title,
                'html'     => $partUgc ? $this->dom->saveHTML($partUgc) : '',
                'chapters' => $chapters,
            ];
        }

        // Serialize back matter
        foreach ($this->xpath->query("//div[{$this->xc('back-matter')} and @id]") as $node) {
            $titleNode = $this->xpath->query(".//*[{$this->xc('back-matter-title')}]", $node)->item(0);
            $title     = $titleNode ? trim($titleNode->textContent) : 'Appendix';
            $this->cleanTitleWrap($node);
            $ugc = $this->xpath->query(".//*[{$this->xc('back-matter-ugc')}]", $node)->item(0) ?? $node;
            $this->backMatters[] = [
                'id'    => $node->getAttribute('id'),
                'title' => $title,
                'html'  => $this->dom->saveHTML($ugc),
            ];
        }
    }

    private function cleanTitleWrap(\DOMNode $node): void
    {
        foreach ([
            'front-matter-title-wrap', 'chapter-title-wrap', 'part-title-wrap',
            'front-matter-number', 'chapter-number', 'part-number',
        ] as $cls) {
            foreach (iterator_to_array($this->xpath->query(".//*[{$this->xc($cls)}]", $node)) as $el) {
                $el->parentNode->removeChild($el);
            }
        }
    }

    private function buildLinkMap(): void
    {
        $sec = 1;
        foreach ($this->frontMatters as $fm) {
            if ($fm['id']) {
                $this->linkMap[$fm['id']] = '/section-' . $sec . '/' . Helpers::slugify($fm['title']);
            }
        }
        foreach ($this->parts as $part) {
            $sec++;
            if ($part['id']) {
                $this->linkMap[$part['id']] = '/section-' . $sec;
            }
            foreach ($part['chapters'] as $ch) {
                if ($ch['id']) {
                    $this->linkMap[$ch['id']] = '/section-' . $sec . '/' . Helpers::slugify($ch['title']);
                }
            }
        }
        $sec++;
        foreach ($this->backMatters as $bm) {
            if ($bm['id']) {
                $this->linkMap[$bm['id']] = '/section-' . $sec . '/' . Helpers::slugify($bm['title']);
            }
        }
    }
}
