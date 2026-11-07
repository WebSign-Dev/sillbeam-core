<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\I18n\CoreTranslator;

/**
 * Standalone SVG presentation using only PHP and the existing graph view.
 *
 * Layout follows catalog order, not dependency order. Each scope owns a column;
 * direct relationships use separate lanes beside nodes and above scope columns.
 * No discovery, dependency traversal, cycle detection or external process runs.
 *
 * @internal
 */
final class SvgGraph
{
    public function __construct(private readonly CoreTranslator $translator = new CoreTranslator())
    {
    }

    /** Returns deterministic, self-contained SVG without scripts, links or external assets. */
    public function render(GraphView $view): string
    {
        $caption = [];
        foreach ($view->notes as $note) {
            if (!preg_match('/^[a-z_]+\.[a-z_.]+:/', $note)) {
                array_push($caption, ...$this->wrap($note, 100));
            }
        }
        $degrees = $laneCounts = [];
        $crossings = 0;
        foreach ($view->edges as $edge) {
            foreach (['source', 'target'] as $endpoint) {
                $class = $edge[$endpoint];
                $degrees[$class] = ($degrees[$class] ?? 0) + 1;
                $group = $view->nodes[$class]['group'];
                if ('source' === $endpoint || $group !== $view->nodes[$edge['source']]['group']) {
                    $laneCounts[$group] = ($laneCounts[$group] ?? 0) + 1;
                }
            }
            if ($view->nodes[$edge['source']]['group'] !== $view->nodes[$edge['target']]['group']) {
                ++$crossings;
            }
        }
        $top = 70 + \count($caption) * 19;
        $scopeTop = $top + $crossings * 14;
        $positions = $scopes = [];
        $left = 28;
        $height = $scopeTop + 60;
        foreach ($view->groups as $group => $title) {
            $nodes = array_filter($view->nodes, static fn (array $node): bool => $node['group'] === $group);
            if ([] === $nodes) {
                continue;
            }
            $heading = $this->wrap($title, 38);
            $y = $scopeTop + 24 + \count($heading) * 18;
            foreach ($nodes as $class => $node) {
                $lines = $this->wrap($node['label'].($node['external'] ? "\nexternal to Project context" : ''), 32);
                $nodeHeight = max(84, \count($lines) * 18 + 28, ($degrees[$class] ?? 0) * 9 + 28);
                $positions[$class] = ['x' => $left + 16, 'y' => $y, 'height' => $nodeHeight, 'lines' => $lines];
                $y += $nodeHeight + 26;
            }
            $scopeWidth = 360 + ($laneCounts[$group] ?? 0) * 10;
            $scopes[$group] = ['x' => $left, 'width' => $scopeWidth, 'height' => $y - $scopeTop, 'lines' => $heading];
            $left += $scopeWidth + 24;
            $height = max($height, $y + 28);
        }
        $width = max(850, $left + 20);
        $svg = ['<?xml version="1.0" encoding="UTF-8"?>',
            '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'" role="img" aria-labelledby="graph-title graph-description">',
            '<title id="graph-title">'.$this->escape($this->translator->text('cli.graph.label.title')).'</title>',
            '<desc id="graph-description">'.$this->escape(implode("\n", $view->notes)).'</desc>',
            '<defs><marker id="arrow-declared" viewBox="0 0 10 10" refX="10" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="#64748b"/></marker><marker id="arrow-observed" viewBox="0 0 10 10" refX="10" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="#b45309"/></marker></defs>',
            '<rect width="100%" height="100%" fill="#fff"/>',
            '<g font-family="monospace" fill="#0f172a">',
            '<text x="28" y="34" font-family="sans-serif" font-size="21" font-weight="bold">'.$this->escape($this->translator->text('cli.graph.label.title')).'</text>'];
        foreach ($caption as $index => $line) {
            $svg[] = $this->text($line, 28, 60 + $index * 19, 12, false, $width - 56);
        }
        foreach ($scopes as $group => $scope) {
            $svg[] = '<g id="scope-'.$group.'"><rect x="'.$scope['x'].'" y="'.$scopeTop.'" width="'.$scope['width'].'" height="'.$scope['height'].'" rx="12" fill="#f8fafc" stroke="#cbd5e1"/>';
            foreach ($scope['lines'] as $index => $line) {
                $svg[] = $this->text($line, $scope['x'] + 16, $scopeTop + 24 + $index * 18, 13, true, 320);
            }
            $svg[] = '</g>';
        }
        $ports = $lanes = [];
        $crossIndex = 0;
        foreach ($view->edges as $index => $edge) {
            $source = $view->nodes[$edge['source']];
            $target = $view->nodes[$edge['target']];
            $from = $positions[$edge['source']];
            $to = $positions[$edge['target']];
            $sourcePort = $ports[$edge['source']] = ($ports[$edge['source']] ?? 0) + 1;
            $targetPort = $ports[$edge['target']] = ($ports[$edge['target']] ?? 0) + 1;
            $sourceLane = $lanes[$source['group']] = ($lanes[$source['group']] ?? 0) + 1;
            $targetLane = $sourceLane;
            if ($source['group'] !== $target['group']) {
                $targetLane = $lanes[$target['group']] = ($lanes[$target['group']] ?? 0) + 1;
            }
            $x1 = $from['x'] + 320;
            $x2 = $to['x'] + 320;
            $y1 = $from['y'] + 14 + (int) (($from['height'] - 28) * $sourcePort / ($degrees[$edge['source']] + 1));
            $y2 = $to['y'] + 14 + (int) (($to['height'] - 28) * $targetPort / ($degrees[$edge['target']] + 1));
            $lane1 = $x1 + 12 + $sourceLane * 10;
            $lane2 = $x2 + 12 + $targetLane * 10;
            $path = 'M '.$x1.' '.$y1.' H '.$lane1;
            if ($source['group'] === $target['group']) {
                $path .= ' V '.$y2;
            } else {
                $channel = $top + $crossIndex++ * 14;
                $path .= ' V '.$channel.' H '.$lane2.' V '.$y2;
            }
            $path .= ' H '.$x2;
            $observed = $edge['observed'];
            $kind = $observed ? 'observed' : 'declared';
            $svg[] = '<path id="edge-'.($index + 1).'" class="dependency '.$kind.'" data-source="'.$source['id'].'" data-target="'.$target['id'].'" d="'.$path.'" fill="none" stroke="'.($observed ? '#b45309' : '#64748b').'" stroke-width="1.5"'.($observed ? ' stroke-dasharray="6 4"' : '').' marker-end="url(#arrow-'.$kind.')"><title>'.$this->escape($source['class'].' → '.$target['class'].($observed ? ' (observed undeclared)' : ' (declared)')).'</title></path>';
        }
        foreach ($view->nodes as $class => $node) {
            $position = $positions[$class];
            $svg[] = '<g id="'.$node['id'].'" class="module"><title>'.$this->escape($class).'</title><rect x="'.$position['x'].'" y="'.$position['y'].'" width="320" height="'.$position['height'].'" rx="8" fill="'.($node['disabled'] ? '#eee' : '#eff6ff').'" stroke="'.($node['cycle'] ? '#c22' : '#64748b').'" stroke-width="'.($node['cycle'] ? '3' : '1').'"'.('unknown' === $node['state'] ? ' stroke-dasharray="5 5"' : '').'/>';
            foreach ($position['lines'] as $index => $line) {
                $svg[] = $this->text($line, $position['x'] + 12, $position['y'] + 24 + $index * 18, 0 === $index ? 15 : 12, 0 === $index);
            }
            $svg[] = '</g>';
        }
        $svg[] = '</g></svg>';

        return implode("\n", $svg)."\n";
    }

    /** Wraps presentation text without requiring mbstring or altering the underlying evidence.
     * @return list<string>
     */
    private function wrap(string $text, int $length): array
    {
        $lines = [];
        foreach (explode("\n", wordwrap($text, $length, "\n", false)) as $line) {
            $characters = preg_split('//u', $line, -1, \PREG_SPLIT_NO_EMPTY);
            if (false === $characters) {
                $lines[] = $this->translator->text('cli.graph.label.invalid_utf8');
                continue;
            }
            $chunk = '';
            $width = 0;
            foreach ($characters as $character) {
                $weight = preg_match('/[\p{Han}\p{Hangul}\p{Hiragana}\p{Katakana}\p{So}]/u', $character) ? 2 : 1;
                if ($width + $weight > $length) {
                    $lines[] = $chunk;
                    $chunk = '';
                    $width = 0;
                }
                $chunk .= $character;
                $width += $weight;
            }
            if ('' !== $chunk) {
                $lines[] = $chunk;
            }
        }

        return $lines;
    }

    /** SVG constrains actual glyph advance, including wide fallback fonts, to the text area. */
    private function text(string $line, int $x, int $y, int $size, bool $bold = false, int $limit = 296): string
    {
        $units = (preg_match_all('/./us', $line) ?: 0) + (preg_match_all('/[\p{Han}\p{Hangul}\p{Hiragana}\p{Katakana}\p{So}]/u', $line) ?: 0);
        $width = min($limit, max(1, (int) ceil($units * $size * 0.62)));

        return '<text x="'.$x.'" y="'.$y.'" font-size="'.$size.'" textLength="'.$width.'" lengthAdjust="spacingAndGlyphs"'.($bold ? ' font-weight="bold"' : '').'>'.$this->escape($line).'</text>';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_XML1 | \ENT_SUBSTITUTE | \ENT_DISALLOWED, 'UTF-8');
    }
}
