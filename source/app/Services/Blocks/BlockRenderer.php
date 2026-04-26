<?php

namespace App\Services\Blocks;

use Illuminate\Support\Str;

/**
 * Converts a `content_blocks` array into rendered HTML for the
 * public site. Schema:
 *
 *   [
 *     {type:'paragraph',  data:{html:'…'}},
 *     {type:'heading',    data:{level:2|3|4, text:'…', anchor:'…'}},
 *     {type:'image',      data:{src,caption,credit,alt}},
 *     {type:'quote',      data:{text,attribution}},
 *     {type:'callout',    data:{tone:'info'|'warn'|'note', html}},
 *     {type:'embed',      data:{html, provider}},
 *     {type:'gallery',    data:{images:[{src,caption,alt}, …]}},
 *     {type:'separator',  data:{}},
 *     {type:'html',       data:{html:'…'}},   // escape hatch
 *   ]
 *
 * Every text/HTML payload runs through sanitize_rich_html() (or
 * sanitize_embed_html for embeds) so an editor pasting markup can't
 * smuggle in `<script>` / inline event handlers.
 */
class BlockRenderer
{
    public function render(array|string|null $blocks): string
    {
        if (is_string($blocks)) {
            $blocks = json_decode($blocks, true) ?: [];
        }
        if (! is_array($blocks) || empty($blocks)) return '';

        $out = [];
        foreach ($blocks as $block) {
            if (! is_array($block) || empty($block['type'])) continue;
            $type = (string) $block['type'];
            $data = (array) ($block['data'] ?? []);

            $rendered = match ($type) {
                'paragraph' => $this->paragraph($data),
                'heading'   => $this->heading($data),
                'image'     => $this->image($data),
                'quote'     => $this->quote($data),
                'callout'   => $this->callout($data),
                'embed'     => $this->embed($data),
                'gallery'   => $this->gallery($data),
                'separator' => '<hr class="block-separator">',
                'html'      => $this->htmlBlock($data),
                default     => '',
            };
            if ($rendered !== '') $out[] = $rendered;
        }
        return implode("\n\n", $out);
    }

    private function paragraph(array $d): string
    {
        $html = trim((string) ($d['html'] ?? ''));
        if ($html === '') return '';
        return '<p>'.sanitize_rich_html($html).'</p>';
    }

    private function heading(array $d): string
    {
        $level = max(2, min(4, (int) ($d['level'] ?? 2)));
        $text  = trim(strip_tags((string) ($d['text'] ?? '')));
        if ($text === '') return '';
        $id = trim((string) ($d['anchor'] ?? '')) ?: Str::slug($text);
        $idAttr = $id ? ' id="'.htmlspecialchars($id, ENT_QUOTES, 'UTF-8').'"' : '';
        return "<h{$level}{$idAttr}>".htmlspecialchars($text, ENT_QUOTES, 'UTF-8')."</h{$level}>";
    }

    private function image(array $d): string
    {
        $src = trim((string) ($d['src'] ?? ''));
        if ($src === '') return '';
        // Only http(s) absolute URLs and root-relative paths are
        // allowed — htmlspecialchars escapes attribute breakers but
        // does NOT defang the `javascript:` / `data:` / `vbscript:`
        // scheme inside an <img src>. We refuse those schemes outright.
        if (! $this->isSafeUrl($src)) return '';
        $alt     = htmlspecialchars((string) ($d['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
        $caption = trim((string) ($d['caption'] ?? ''));
        $credit  = trim((string) ($d['credit'] ?? ''));
        $safeSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');

        $cap = '';
        if ($caption !== '' || $credit !== '') {
            $cap  = '<figcaption>';
            if ($caption !== '') $cap .= htmlspecialchars($caption, ENT_QUOTES, 'UTF-8');
            if ($credit !== '')  $cap .= ' <span class="credit">— '.htmlspecialchars($credit, ENT_QUOTES, 'UTF-8').'</span>';
            $cap .= '</figcaption>';
        }
        return '<figure class="block-image"><img src="'.$safeSrc.'" alt="'.$alt.'" loading="lazy">'.$cap.'</figure>';
    }

    private function quote(array $d): string
    {
        $text = trim((string) ($d['text'] ?? ''));
        if ($text === '') return '';
        $attr = trim(strip_tags((string) ($d['attribution'] ?? '')));
        $body = '<p>'.sanitize_rich_html($text).'</p>';
        if ($attr !== '') $body .= '<cite>— '.htmlspecialchars($attr, ENT_QUOTES, 'UTF-8').'</cite>';
        return '<blockquote class="block-quote">'.$body.'</blockquote>';
    }

    private function callout(array $d): string
    {
        $tone = in_array($d['tone'] ?? 'note', ['info', 'warn', 'note', 'tip'], true)
            ? (string) $d['tone']
            : 'note';
        $html = trim((string) ($d['html'] ?? ''));
        if ($html === '') return '';
        return '<aside class="block-callout block-callout--'.$tone.'">'.sanitize_rich_html($html).'</aside>';
    }

    private function embed(array $d): string
    {
        $html = trim((string) ($d['html'] ?? ''));
        if ($html === '') return '';
        // sanitize_embed_html allows the YouTube/Vimeo/Twitch/Dailymotion/
        // Facebook iframe allowlist exclusively; everything else is
        // stripped at render so a hostile editor can't paste arbitrary
        // markup.
        return '<div class="block-embed">'.sanitize_embed_html($html).'</div>';
    }

    private function gallery(array $d): string
    {
        $images = $d['images'] ?? [];
        if (! is_array($images) || empty($images)) return '';
        $items = [];
        foreach ($images as $img) {
            if (! is_array($img)) continue;
            // Defensive: a hostile JSON payload could include a
            // `type` key in a gallery item, hoping a future renderer
            // dispatches it as a nested block. We render galleries
            // as images only — strip any type key before passing on.
            unset($img['type']);
            $rendered = $this->image($img);
            if ($rendered !== '') $items[] = $rendered;
        }
        if (empty($items)) return '';
        return '<div class="block-gallery">'.implode('', $items).'</div>';
    }

    private function htmlBlock(array $d): string
    {
        $html = trim((string) ($d['html'] ?? ''));
        if ($html === '') return '';
        // Same allowlist as the rich-text editor: strips <script>,
        // event handlers, dangerous schemes; preserves benign markup.
        return sanitize_rich_html($html);
    }

    /**
     * Whether a URL is safe to embed in an <img src> / <a href>.
     * Accepts absolute http(s) and root-relative paths, both with and
     * without HTML-entity-encoded variants. Anything else (data:,
     * javascript:, vbscript:, file:, mailto:, tel:, custom schemes)
     * is rejected outright. Used by image() at render time so a
     * compromised editor account can't paste a `javascript:alert(1)`
     * that survives htmlspecialchars (which escapes attribute
     * breakers but not URL schemes).
     */
    private function isSafeUrl(string $url): bool
    {
        $normalized = strtolower(trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        // Absolute http/https
        if (preg_match('~^https?://~', $normalized)) return true;
        // Root-relative or query-only
        if ($normalized === '') return false;
        if ($normalized[0] === '/' || $normalized[0] === '?' || $normalized[0] === '#') return true;
        return false;
    }
}
