<?php

namespace App\Services\Apple;

use App\Models\News;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Build a minimal-but-valid Apple News Format document for an
 * article. ANF is a JSON shape Apple's News Publisher accepts —
 * the document tree is `version + identifier + title + language +
 * layout + components + componentTextStyles + componentLayouts +
 * documentStyle + textStyles`.
 *
 * We hand-roll a small handful of styles and one layout column so
 * the article renders cleanly in the News app. Fancier publishers
 * can extend the styles map; for v1 we stay opinionated and tight.
 *
 * The output is the JSON that gets POSTed to:
 *     /channels/{channel_id}/articles
 * (see AppleNewsClient).
 */
class AppleNewsFormatGenerator
{
    public const ANF_VERSION = '1.10';

    /**
     * @return array<string, mixed>
     */
    public function generate(News $article): array
    {
        $title    = trim(strip_tags(stripslashes((string) $article->title))) ?: 'Untitled';
        $excerpt  = trim(strip_tags((string) $article->excerpt));
        $authorName = optional($article->user)->name ?: (getcong('site_name') ?: config('app.name'));

        $components = [
            [
                'role'       => 'title',
                'text'       => $title,
                'layout'     => 'titleLayout',
                'textStyle'  => 'titleStyle',
            ],
        ];

        if ($excerpt !== '') {
            $components[] = [
                'role'       => 'intro',
                'text'       => $excerpt,
                'layout'     => 'introLayout',
                'textStyle'  => 'introStyle',
            ];
        }

        $components[] = [
            'role'       => 'byline',
            'text'       => "By {$authorName}",
            'layout'     => 'bylineLayout',
            'textStyle'  => 'bylineStyle',
        ];

        if ($article->image) {
            $components[] = [
                'role'        => 'photo',
                'URL'         => $this->absoluteImageUrl($article),
                'caption'     => trim((string) $article->image_credit) ?: null,
                'accessibilityCaption' => $title,
                'layout'      => 'heroLayout',
            ];
        }

        // Body: split the rendered HTML at paragraph boundaries and
        // emit each as a Markdown body component. Apple News supports
        // a Markdown-like format inside `body` components, so this
        // path keeps simple inline emphasis / links intact.
        $bodyHtml = (string) $article->content;
        foreach ($this->splitParagraphs($bodyHtml) as $paragraph) {
            $components[] = [
                'role'      => 'body',
                'format'    => 'markdown',
                'text'      => $this->htmlToMarkdown($paragraph),
                'layout'    => 'bodyLayout',
                'textStyle' => 'bodyStyle',
            ];
        }

        return [
            'version'    => self::ANF_VERSION,
            'identifier' => 'article-'.$article->id,
            'title'      => $title,
            'language'   => $article->locale ?: app()->getLocale() ?: 'en',
            'subtitle'   => $excerpt !== '' ? Str::limit($excerpt, 150) : null,
            'metadata'   => array_filter([
                'authors'                  => [$authorName],
                'canonicalURL'             => route('news.details', ['slug' => $article->slug]),
                'datePublished'            => optional($article->published_at)->toAtomString(),
                'dateModified'             => optional($article->updated_at)->toAtomString(),
                'thumbnailURL'             => $article->image ? $this->absoluteImageUrl($article) : null,
                'excerpt'                  => $excerpt ?: null,
                'keywords'                 => $article->tags ? array_map('trim', explode(',', $article->tags)) : null,
            ]),
            'layout' => [
                'columns' => 7,
                'width'   => 1024,
                'margin'  => 60,
                'gutter'  => 20,
            ],
            'components'           => array_values($components),
            'componentLayouts'     => $this->layouts(),
            'componentTextStyles'  => $this->textStyles(),
            'documentStyle'        => ['backgroundColor' => '#FFFFFF'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function splitParagraphs(string $html): array
    {
        if (trim($html) === '') return [];
        // Normalize: collapse <br><br> → paragraph breaks, then split.
        $html = preg_replace('~<br\s*/?>\s*<br\s*/?>~i', "\n\n", $html);
        $parts = preg_split('~</p>\s*<p[^>]*>~i', $html) ?: [];
        $parts = array_map(fn ($p) => preg_replace('~^<p[^>]*>|</p>$~i', '', trim($p)) ?? $p, $parts);
        $parts = array_filter($parts, fn ($p) => trim(strip_tags((string) $p)) !== '');
        return array_values($parts);
    }

    /**
     * Convert a small HTML subset to Apple News' Markdown dialect.
     * Apple supports inline emphasis (*em*, **strong**) and links
     * ([text](url)). Stripping unsupported tags keeps the document
     * accepted at submit time without dragging in a full HTML→MD
     * library.
     */
    private function htmlToMarkdown(string $html): string
    {
        // Inline links — refuse anything that isn't an http(s) URL so
        // a `javascript:` href can't ride through to Apple News and
        // make it past their own sanitizer into the News app.
        $html = preg_replace_callback(
            '~<a\b[^>]*href=([\'"])([^\'"]+)\1[^>]*>(.*?)</a>~is',
            function ($m) {
                $href = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (! preg_match('~^https?://~i', $href)) {
                    return strip_tags($m[3]); // drop the link, keep the visible text
                }
                return '['.strip_tags($m[3]).']('.$href.')';
            },
            $html,
        ) ?? $html;

        $html = preg_replace('~</?(strong|b)\b[^>]*>~i', '**', $html) ?? $html;
        $html = preg_replace('~</?(em|i)\b[^>]*>~i', '*', $html) ?? $html;
        $html = preg_replace('~</?(span|div)\b[^>]*>~i', '', $html) ?? $html;
        $html = strip_tags($html, '<a>'); // last-pass strip everything else
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) $html);
    }

    private function absoluteImageUrl(News $article): string
    {
        $url = $article->image;
        if (! $url) return '';
        if (preg_match('~^https?://~i', $url)) return $url;
        $disk = getcong('site_storage') ?: 'public';
        try {
            return \Storage::disk($disk)->url($url);
        } catch (\Throwable $e) {
            return url($url);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function layouts(): array
    {
        return [
            'titleLayout'   => ['columnStart' => 0, 'columnSpan' => 7, 'margin' => ['top' => 24, 'bottom' => 12]],
            'introLayout'   => ['columnStart' => 0, 'columnSpan' => 7, 'margin' => ['top' => 0, 'bottom' => 12]],
            'bylineLayout'  => ['columnStart' => 0, 'columnSpan' => 7, 'margin' => ['top' => 0, 'bottom' => 16]],
            'heroLayout'    => ['columnStart' => 0, 'columnSpan' => 7, 'margin' => ['top' => 0, 'bottom' => 24]],
            'bodyLayout'    => ['columnStart' => 0, 'columnSpan' => 7, 'margin' => ['top' => 0, 'bottom' => 14]],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function textStyles(): array
    {
        return [
            'titleStyle'  => ['fontName' => 'AvenirNext-Bold', 'fontSize' => 32, 'lineHeight' => 38, 'textColor' => '#111111'],
            'introStyle'  => ['fontName' => 'AvenirNext-Italic', 'fontSize' => 18, 'lineHeight' => 26, 'textColor' => '#444444'],
            'bylineStyle' => ['fontName' => 'AvenirNext-Medium', 'fontSize' => 13, 'lineHeight' => 18, 'textColor' => '#777777'],
            'bodyStyle'   => ['fontName' => 'Georgia', 'fontSize' => 17, 'lineHeight' => 26, 'textColor' => '#222222'],
        ];
    }
}
