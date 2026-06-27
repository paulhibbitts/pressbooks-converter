<?php
namespace PB;

use League\HTMLToMarkdown\HtmlConverter;
use League\HTMLToMarkdown\Converter\TableConverter;

class ContentConverter
{
    private array        $linkMap;
    private bool         $figureHtml;
    private HtmlConverter $md;
    public  array        $warnings = [];

    public function __construct(array $linkMap, bool $figureHtml = true)
    {
        $this->linkMap    = $linkMap;
        $this->figureHtml = $figureHtml;
        $this->md         = new HtmlConverter([
            'header_style'            => 'atx',
            'suppress_errors'         => true,
            'strip_tags'              => true,
            'strip_placeholder_links' => true,
        ]);
        $this->md->getEnvironment()->addConverter(new TableConverter());
    }

    // Main pipeline: HTML string → Markdown string
    public function convert(string $html): string
    {
        // Strip Pressbooks decorative indicator icons (new-tab, download) — small <img> inside <a>
        $html = $this->stripLinkIcons($html);

        // Remove empty inline code elements (produce stray `` in output)
        $html = preg_replace('/<code>\s*<\/code>/', '', $html);

        [$html, $fnDefs]   = $this->extractFootnotes($html);
        $html               = $this->fixVideos($html);
        $html               = $this->fixDefinitionLists($html);
        $html               = $this->rewriteLinks($html);
        [$html, $figures]   = $this->fixFigures($html);
        [$html, $callouts]  = $this->fixCallouts($html);

        $result = $this->toMarkdown($html);

        // Restore figure placeholders
        foreach ($figures as $ph => $figHtml) {
            $result = str_replace($ph, "\n" . $figHtml . "\n", $result);
        }

        // Restore callout placeholders (reverse order: outer placeholders expand first,
        // making inner H5P placeholders visible for subsequent iterations)
        foreach (array_reverse($callouts, true) as $ph => $shortcode) {
            $result = str_replace($ph, $shortcode, $result);
        }

        // Retag callouts by content
        $result = preg_replace_callback(
            '/\[(announcement|objectives)\]\n(.*?)\n\[\/\1\]/s',
            [$this, 'retagCallout'],
            $result
        );

        // Fix escaped bullets inside shortcodes
        $result = preg_replace_callback(
            '/\[(?:objectives|example|reflection|announcement)[^\]]*\].*?\[\/(?:objectives|example|reflection|announcement)\]/s',
            fn($m) => str_replace('\*', '-', $m[0]),
            $result
        );

        // Fix setext headings inside shortcodes
        $result = preg_replace_callback(
            '/\[(?:announcement|example|reflection|objectives)[^\]]*\].*?\[\/(?:announcement|example|reflection|objectives)\]/s',
            [$this, 'fixSetextHeadings'],
            $result
        );

        // Merge bullet lists that land outside a single-line [announcement] block
        $result = preg_replace(
            '/(\[announcement\]\n[^\n]+\n)\[\/announcement\]\n\n((?:[ \t]*[-*+][ \t][^\n]*\n?(?:[ \t]{2,}[^\n]+\n?)*)+)/',
            "$1\n$2[/announcement]",
            $result
        );

        // Ensure closing shortcode tags are on their own line (content before)
        $result = preg_replace(
            '/(\S)\[\/(announcement|objectives|example|reflection|key-takeaways|definition|case-study|exercise|project-brief|feedback-requested|process-note)\]/',
            "$1\n[/$2]",
            $result
        );

        // Ensure closing shortcode tags are on their own line (content after, with optional space)
        $result = preg_replace(
            '/\[\/(announcement|objectives|example|reflection|key-takeaways|definition|case-study|exercise|project-brief|feedback-requested|process-note)\][ \t]*(\S)/',
            "[/$1]\n$2",
            $result
        );

        // Remove leading space before opening shortcode tags (league whitespace artifact)
        $result = preg_replace(
            '/^ \[(announcement|objectives|example|reflection|key-takeaways|definition|case-study|exercise|project-brief|feedback-requested|process-note)\]/m',
            '[$1]',
            $result
        );

        // Strip trailing whitespace from all lines
        $result = preg_replace('/[ \t]+$/m', '', $result);

        // Remove leading spaces from fenced code fence lines (league artifact from <pre> blocks)
        $result = preg_replace('/^ {1,3}(```)/m', '$1', $result);

        // Strip Pressbooks bibliography backlinks
        $result = preg_replace('/\s*↵\s*Return to (?:Chapter|Appendix)\s+\d+/u', '', $result);

        // Merge double-bold artifacts
        $result = preg_replace('/\*\*([^*]+)\*\*\*\*([^*]+)\*\*/', '**$1$2**', $result);
        $result = preg_replace('/\*\*([^*]+)\*\* \*\*([^*]+)\*\*/', '**$1 $2**', $result);

        // Fix escaped underscores inside URLs
        $result = preg_replace_callback('/(https?:\/\/\S+)/', fn($m) => str_replace('\\_', '_', $m[1]), $result);

        // Replace footnote placeholders with Markdown Extra syntax
        $result = preg_replace('/%%FN(\d+)%%/', '[^$1]', $result);
        if ($fnDefs) {
            $result = rtrim($result) . "\n\n" . $fnDefs;
        }

        $result = preg_replace('/\n{3,}/', "\n\n", $result);
        return trim($result);
    }

    // Pull learning objectives text for section frontmatter
    public function extractObjectives(string $html): ?string
    {
        $dom   = $this->loadFragment($html);
        $xpath = new \DOMXPath($dom);

        $contentEl = $xpath->query(
            "//*[{$this->xc('textbox--learning-objectives')}]//*[{$this->xc('textbox__content')}]"
        )->item(0);

        if (!$contentEl) {
            return null;
        }

        $items = [];
        foreach ($xpath->query('.//li[not(ancestor::li)]', $contentEl) as $li) {
            $items[] = '- ' . trim($li->textContent);
        }
        return $items ? implode("\n", $items) : trim($contentEl->textContent);
    }

    // ── Private pipeline steps ────────────────────────────────────────────────

    private function extractFootnotes(string $html): array
    {
        $dom   = $this->loadFragment($html);
        $xpath = new \DOMXPath($dom);
        $body  = $dom->getElementsByTagName('body')->item(0);

        $footnotesDiv = $xpath->query("//*[{$this->xc('footnotes')}]")->item(0);
        if (!$footnotesDiv) {
            return [$html, ''];
        }

        // Build id → text map from definition divs
        $fnDefs = [];
        foreach ($xpath->query('.//div[@id]', $footnotesDiv) as $div) {
            $fnDefs[$div->getAttribute('id')] = trim($div->textContent);
        }

        // Replace inline refs with sequential placeholders
        $counter    = 0;
        $idToIndex  = [];
        foreach (iterator_to_array($xpath->query("//*[{$this->xc('footnote')}]")) as $span) {
            $inner = $xpath->query(".//*[{$this->xc('footnote-indirect')}]", $span)->item(0);
            if (!$inner) {
                continue;
            }
            $refId = $inner->getAttribute('data-fnref');
            if ($refId && isset($fnDefs[$refId])) {
                if (!isset($idToIndex[$refId])) {
                    $idToIndex[$refId] = ++$counter;
                }
                $ph = '%%FN' . $idToIndex[$refId] . '%%';
                $span->parentNode->replaceChild($dom->createTextNode($ph), $span);
            } elseif ($refId) {
                $this->warnings[] = "footnote ref \"$refId\" has no matching definition — citation dropped";
            }
        }

        $footnotesDiv->parentNode->removeChild($footnotesDiv);

        asort($idToIndex);
        $defs = [];
        foreach ($idToIndex as $refId => $idx) {
            $defs[] = "[^$idx]: " . $fnDefs[$refId];
        }

        return [$this->bodyHtml($body), implode("\n", $defs)];
    }

    private function fixVideos(string $html): string
    {
        $dom   = $this->loadFragment($html);
        $xpath = new \DOMXPath($dom);
        $body  = $dom->getElementsByTagName('body')->item(0);

        // YouTube iframes → [youtube] shortcode
        foreach (iterator_to_array($xpath->query('//iframe')) as $iframe) {
            $src = $iframe->getAttribute('src');
            if (preg_match('/youtube\.com\/embed\/([A-Za-z0-9_-]+)/', $src, $m)) {
                $sc = '[youtube]https://www.youtube.com/watch?v=' . $m[1] . '[/youtube]';
                $iframe->parentNode->replaceChild($dom->createTextNode($sc), $iframe);
            }
        }

        // Pressbooks oembed placeholder divs
        foreach (iterator_to_array($xpath->query("//*[{$this->xc('interactive-content--oembed')}]")) as $div) {
            $firstA = $xpath->query('.//a[@href]', $div)->item(0);
            $title  = $firstA ? ($firstA->getAttribute('title') ?: 'View interactive content') : 'View interactive content';

            $ytId = null;
            foreach ($xpath->query('.//a[@href]', $div) as $a) {
                $href = $a->getAttribute('href') ?: $a->getAttribute('data-url') ?: '';
                if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([A-Za-z0-9_-]+)/', $href, $m)) {
                    $ytId = $m[1];
                    break;
                }
            }

            if ($ytId) {
                $sc = "[youtube]https://www.youtube.com/watch?v={$ytId}[/youtube]\n";
                $div->parentNode->replaceChild($dom->createTextNode($sc), $div);
            } elseif ($firstA) {
                $url = $firstA->getAttribute('data-url') ?: $firstA->getAttribute('href');
                if ($url && strpos($url, '#') !== 0) {
                    // Use a proper <blockquote> so league produces clean block output
                    $bq    = $dom->createElement('blockquote');
                    $p     = $dom->createElement('p');
                    $b     = $dom->createElement('strong');
                    $b->textContent = "$title:";
                    $p->appendChild($b);
                    $p->appendChild($dom->createTextNode(' '));
                    $a2    = $dom->createElement('a');
                    $a2->setAttribute('href', $url);
                    $a2->textContent = $url;
                    $p->appendChild($a2);
                    $bq->appendChild($p);
                    $div->parentNode->replaceChild($bq, $div);
                } else {
                    $div->parentNode->removeChild($div);
                }
            } else {
                $div->parentNode->removeChild($div);
            }
        }

        // wp-caption divs without a caption text element: unwrap to expose the img
        foreach (iterator_to_array($xpath->query("//*[{$this->xc('wp-caption')}]")) as $div) {
            $captionEl = $xpath->query(".//*[{$this->xc('wp-caption-text')}]", $div)->item(0);
            if ($captionEl) {
                continue; // handled by fixFigures()
            }
            $frag = $dom->createDocumentFragment();
            foreach (iterator_to_array($div->childNodes) as $child) {
                $frag->appendChild($child->cloneNode(true));
            }
            $div->parentNode->replaceChild($frag, $div);
        }

        return $this->bodyHtml($body);
    }

    /** @return array{string, array<string,string>} */
    private function fixFigures(string $html): array
    {
        $dom    = $this->loadFragment($html);
        $xpath  = new \DOMXPath($dom);
        $body   = $dom->getElementsByTagName('body')->item(0);
        $figs   = [];
        $count  = 0;

        // HTML mode: replace with a placeholder; league never sees the <figure>
        $makeHtmlFig = function (\DOMElement $imgEl, ?\DOMNode $captionEl) use (&$figs, &$count, $dom): string {
            $count++;
            $ph  = "%%FIG{$count}%%";
            $src = htmlspecialchars($imgEl->getAttribute('src'), ENT_QUOTES);
            $alt = htmlspecialchars($imgEl->getAttribute('alt'), ENT_QUOTES);
            $fig = "<figure>\n<img src=\"{$src}\" alt=\"{$alt}\">";
            if ($captionEl) {
                $inner = '';
                foreach ($captionEl->childNodes as $child) {
                    $inner .= $dom->saveHTML($child);
                }
                $fig .= "\n<figcaption>" . trim($inner) . "</figcaption>";
            }
            $fig .= "\n</figure>";
            $figs[$ph] = $fig;
            return $ph;
        };

        // Markdown mode: image via league, caption as *text* via <em> for league to convert
        $makeMdFig = function (\DOMElement $imgEl, ?\DOMNode $captionEl, \DOMNode $parent) use ($dom): void {
            $frag    = $dom->createDocumentFragment();
            $imgPara = $dom->createElement('p');
            $imgPara->appendChild($imgEl->cloneNode(true));
            $frag->appendChild($imgPara);
            if ($captionEl) {
                $captPara = $dom->createElement('p');
                $em       = $dom->createElement('em');
                foreach (iterator_to_array($captionEl->childNodes) as $child) {
                    $em->appendChild($child->cloneNode(true));
                }
                $captPara->appendChild($em);
                $frag->appendChild($captPara);
            }
            $parent->appendChild($frag);
        };

        // wp-caption divs with a caption
        foreach (iterator_to_array($xpath->query("//*[{$this->xc('wp-caption')}]")) as $div) {
            $captionEl = $xpath->query(".//*[{$this->xc('wp-caption-text')}]", $div)->item(0);
            $imgEl     = $xpath->query('.//img', $div)->item(0);
            if (!$captionEl || !$imgEl) {
                continue;
            }
            if ($this->figureHtml) {
                $ph = $makeHtmlFig($imgEl, $captionEl);
                $div->parentNode->replaceChild($dom->createTextNode($ph), $div);
            } else {
                $frag = $dom->createDocumentFragment();
                $makeMdFig($imgEl, $captionEl, $frag);
                $div->parentNode->replaceChild($frag, $div);
            }
        }

        // Native <figure>/<figcaption>
        foreach (iterator_to_array($xpath->query('//figure')) as $figure) {
            $imgEl = $xpath->query('.//img', $figure)->item(0);
            if (!$imgEl) {
                continue;
            }
            $captionEl = $xpath->query('.//figcaption', $figure)->item(0);
            if ($this->figureHtml) {
                $ph = $makeHtmlFig($imgEl, $captionEl);
                $figure->parentNode->replaceChild($dom->createTextNode($ph), $figure);
            } else {
                $frag = $dom->createDocumentFragment();
                $makeMdFig($imgEl, $captionEl, $frag);
                $figure->parentNode->replaceChild($frag, $figure);
            }
        }

        return [$this->bodyHtml($body), $figs];
    }

    private function rewriteLinks(string $html): string
    {
        if (empty($this->linkMap)) {
            return $html;
        }
        $dom   = $this->loadFragment($html);
        $xpath = new \DOMXPath($dom);
        $body  = $dom->getElementsByTagName('body')->item(0);

        foreach (iterator_to_array($xpath->query('//a[@href]')) as $a) {
            $href = $a->getAttribute('href');
            if (strpos($href, '#') !== 0) {
                continue;
            }
            $frag = substr($href, 1);
            if (strpos($frag, 'return-footnote') === 0) {
                $a->removeAttribute('href');
            } elseif (isset($this->linkMap[$frag])) {
                $a->setAttribute('href', $this->linkMap[$frag]);
            }
        }

        return $this->bodyHtml($body);
    }

    private function fixCallouts(string $html): array
    {
        $dom   = $this->loadFragment($html);
        $xpath = new \DOMXPath($dom);
        $body  = $dom->getElementsByTagName('body')->item(0);

        $callouts = [];
        $counter  = 0;

        // Learning objectives (content div only, skip header)
        foreach (iterator_to_array($xpath->query("//*[{$this->xc('textbox--learning-objectives')}]")) as $div) {
            $contentEl = $xpath->query(".//*[{$this->xc('textbox__content')}]", $div)->item(0);
            $inner     = $contentEl ? $dom->saveHTML($contentEl) : $this->bodyHtml($div);
            $counter++;
            $ph             = "%%CALLOUT{$counter}%%";
            $callouts[$ph]  = "[objectives]\n" . $this->toMarkdown($inner) . "\n[/objectives]";
            $div->parentNode->replaceChild($dom->createTextNode($ph), $div);
        }

        // H5P placeholder divs (class="textbox interactive-content") — must run before generic textbox handler
        foreach (iterator_to_array($xpath->query("//*[{$this->xc('interactive-content')} and not({$this->xc('interactive-content--oembed')})]")) as $div) {
            $firstA = $xpath->query('.//a[@href]', $div)->item(0);
            if (!$firstA) {
                $div->parentNode->removeChild($div);
                continue;
            }
            $url    = $firstA->getAttribute('href');
            $title  = htmlspecialchars($firstA->getAttribute('title') ?: 'Interactive Activity', ENT_QUOTES, 'UTF-8');
            $counter++;
            $ph            = "%%CALLOUT{$counter}%%";
            $callouts[$ph] = "[exercise title=\"{$title}\"]\n<a href=\"{$url}\">View H5P activity online</a>\n[/exercise]";
            $div->parentNode->replaceChild($dom->createTextNode($ph), $div);
        }

        // All remaining textboxes
        foreach (iterator_to_array($xpath->query("//*[{$this->xc('textbox')}]")) as $div) {
            $headerEl  = $xpath->query(".//*[{$this->xc('textbox__header')}]", $div)->item(0);
            $contentEl = $xpath->query(".//*[{$this->xc('textbox__content')}]", $div)->item(0);

            if ($contentEl) {
                $bodyText = $this->toMarkdown($dom->saveHTML($contentEl));
                if ($headerEl) {
                    $bodyText = trim($headerEl->textContent) . "\n" . $bodyText;
                }
            } else {
                $bodyText = $this->toMarkdown($this->bodyHtml($div));
            }

            $counter++;
            $ph             = "%%CALLOUT{$counter}%%";
            $callouts[$ph]  = "[announcement]\n{$bodyText}\n[/announcement]";
            $div->parentNode->replaceChild($dom->createTextNode($ph), $div);
        }

        return [$this->bodyHtml($body), $callouts];
    }

    private function toMarkdown(string $html): string
    {
        // Strip script and style before conversion
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);

        // Remove empty anchor tags (return-footnote backlinks, id-only anchors like <a id="fig2">)
        $html = preg_replace('/<a\b[^>]*>\s*<\/a>/i', '', $html);

        $result = $this->md->convert($html);

        // league/html-to-markdown uses * for bullets; normalise to -
        $result = preg_replace('/^(\s*)\* /m', '$1- ', $result);

        $result = html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $result = str_replace("\xc2\xa0", ' ', $result); // non-breaking space
        $result = preg_replace('/\n{3,}/', "\n\n", $result);
        return trim($result);
    }

    // ── Regex callbacks ───────────────────────────────────────────────────────

    private function retagCallout(array $m): string
    {
        $tag     = $m[1];
        $content = $m[2];
        $first   = trim(explode("\n", trim($content))[0]);
        if (preg_match('/^In Practice/i', $first)) {
            $newTag = 'example';
        } elseif (preg_match('/^Questions to Consider/i', $first)) {
            $newTag = 'reflection';
        } else {
            $newTag = $tag;
        }
        return "[$newTag]\n" . rtrim($content) . "\n[/$newTag]";
    }

    private function fixSetextHeadings(array $m): string
    {
        $block = $m[0];
        $block = preg_replace('/^(.+)\n={3,}$/m', '## $1', $block);
        $block = preg_replace('/^(.+)\n-{3,}$/m', '### $1', $block);
        return $block;
    }

    private function fixDefinitionLists(string $html): string
    {
        $dom   = $this->loadFragment($html);
        $xpath = new \DOMXPath($dom);
        $body  = $dom->getElementsByTagName('body')->item(0);

        $makeTerm = function (\DOMNode $dt) use ($dom): \DOMElement {
            $p = $dom->createElement('p');
            $b = $dom->createElement('strong');
            foreach (iterator_to_array($dt->childNodes) as $child) {
                $b->appendChild($child->cloneNode(true));
            }
            $p->appendChild($b);
            return $p;
        };

        foreach (iterator_to_array($xpath->query('//dl')) as $dl) {
            // Skip nodes detached by a parent <dl> already processed (nested <dl> case)
            if ($dl->parentNode === null) {
                continue;
            }

            $frag = $dom->createDocumentFragment();
            $dt   = null;

            foreach (iterator_to_array($dl->childNodes) as $node) {
                if ($node->nodeName === 'dt') {
                    // Flush orphaned prior dt (dt followed by another dt, no dd)
                    if ($dt !== null) {
                        $frag->appendChild($makeTerm($dt));
                    }
                    $dt = $node;
                } elseif ($node->nodeName === 'dd' && $dt !== null) {
                    $frag->appendChild($makeTerm($dt));
                    foreach (iterator_to_array($node->childNodes) as $child) {
                        $frag->appendChild($child->cloneNode(true));
                    }
                    $dt = null;
                }
            }

            // Flush trailing orphaned dt
            if ($dt !== null) {
                $frag->appendChild($makeTerm($dt));
            }

            $dl->parentNode->replaceChild($frag, $dl);
        }

        return $this->bodyHtml($body);
    }

    // ── DOM helpers ───────────────────────────────────────────────────────────

    private function stripLinkIcons(string $html): string
    {
        $dom   = $this->loadFragment($html);
        $body  = $dom->getElementsByTagName('body')->item(0);
        $xpath = new \DOMXPath($dom);

        foreach (iterator_to_array($xpath->query('//a')) as $a) {
            $newTab    = strtolower($a->getAttribute('target')) === '_blank';
            $iconFound = false;

            // Icons inside the <a> tag
            foreach (iterator_to_array($xpath->query('.//img', $a)) as $img) {
                $w = (int) $img->getAttribute('width');
                $h = (int) $img->getAttribute('height');
                if (($w > 0 && $w <= 20) || ($h > 0 && $h <= 20)) {
                    $img->parentNode->removeChild($img);
                    $iconFound = true;
                }
            }

            // Icons immediately following the <a> tag (sibling, not child)
            $next = $a->nextSibling;
            while ($next && $next->nodeType === XML_TEXT_NODE && trim($next->textContent) === '') {
                $next = $next->nextSibling;
            }
            if ($next && $next->nodeName === 'img') {
                $w = (int) $next->getAttribute('width');
                $h = (int) $next->getAttribute('height');
                if (($w > 0 && $w <= 20) || ($h > 0 && $h <= 20)) {
                    $next->parentNode->removeChild($next);
                    $iconFound = true;
                }
            }

            // Append ↗ to link text for external (new tab) links that had an icon
            if ($iconFound && $newTab) {
                $a->appendChild($dom->createTextNode(' ↗'));
            }
        }

        return $this->bodyHtml($body);
    }

    private function loadFragment(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>');
        libxml_clear_errors();
        return $dom;
    }

    private function bodyHtml(\DOMNode $body): string
    {
        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= $body->ownerDocument->saveHTML($child);
        }
        return $html;
    }

    private function xc(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' $class ')";
    }
}
