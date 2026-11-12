<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\I18n\CoreTranslator;

/** @internal Paints the existing graph evidence using geometry from GraphLayout. */
final class SvgGraph
{
    private const DECLARED_COLOR = '#486079';
    private const OBSERVED_COLOR = '#b45309';

    public function __construct(private readonly CoreTranslator $translator = new CoreTranslator())
    {
    }

    /** Returns deterministic, self-contained SVG without scripts, links or external assets. */
    public function render(GraphView $view): string
    {
        $layout = (new GraphLayout($this->translator))->layout($view);
        ['width' => $width, 'height' => $height, 'caption' => $caption] = $layout;
        $title = $this->escape($this->translator->text('cli.graph.label.title'));
        $svg = ['<?xml version="1.0" encoding="UTF-8"?>',
            '<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'" xml:lang="'.$this->escape($this->translator->locale).'" role="img" aria-labelledby="graph-title graph-description">',
            '<title id="graph-title">'.$title.'</title>',
            '<desc id="graph-description">'.$this->escape(implode("\n", $view->notes)).'</desc>',
            '<defs>'.$this->marker('declared', self::DECLARED_COLOR).$this->marker('observed', self::OBSERVED_COLOR).'</defs>',
            '<rect width="100%" height="100%" fill="#fff"/>',
            '<g font-family="monospace" fill="#17263a">',
            '<text x="'.$caption['x'].'" y="'.($caption['y'] - 18).'" font-family="sans-serif" font-size="24" font-weight="bold">'.$title.'</text>',
            '<g id="graph-status">',
            $this->rect($caption, 8, '#eef3f8', '#d2dce7')];
        foreach ($caption['lines'] as $index => $line) {
            $svg[] = $this->text($line, $caption['x'] + 14, $caption['y'] + 21 + $index * GraphLayout::LINE_HEIGHT, 13, $index < $layout['analysisLines'], $caption['width'] - 28);
        }
        $svg[] = '</g>';

        foreach ($layout['scopes'] as $group => $scope) {
            [$fill, $stroke] = match ($group) {
                'shared_scope' => ['#edf4fa', '#bdcddd'],
                'unknown_owner', 'unresolved' => ['#fff8ed', '#ddcbb2'],
                default => ['#f6f8fb', '#cad5e1'],
            };
            $svg[] = '<g id="scope-'.$group.'">'.$this->rect($scope, 10, $fill, $stroke);
            foreach ($scope['lines'] as $index => $line) {
                $svg[] = $this->text($line, $scope['x'] + 18, $scope['y'] + 24 + $index * GraphLayout::LINE_HEIGHT, 14, true, $scope['width'] - 36);
            }
            $svg[] = '</g>';
        }
        $declaredLabel = $this->translator->text('cli.graph.label.declared');
        $observedLabel = $this->translator->text('cli.graph.label.observed_undeclared');
        foreach ($layout['edges'] as $index => ['edge' => $edge, 'points' => $points]) {
            $source = $view->nodes[$edge['source']];
            $target = $view->nodes[$edge['target']];
            $observed = $edge['observed'];
            $kind = $observed ? 'observed' : 'declared';
            $label = $observed ? $observedLabel : $declaredLabel;
            $path = 'M '.implode(' L ', array_map(static fn (array $point): string => implode(' ', $point), $points));
            $svg[] = '<path id="edge-'.($index + 1).'" class="dependency '.$kind.'" data-source="'.$source['id'].'" data-target="'.$target['id'].'" d="'.$path.'" fill="none" stroke="'.($observed ? self::OBSERVED_COLOR : self::DECLARED_COLOR).'" stroke-width="1.5" stroke-linejoin="round"'.($observed ? ' stroke-dasharray="6 4"' : '').' marker-end="url(#arrow-'.$kind.')"><title>'.$this->escape($source['class'].' → '.$target['class'].' ('.$label.')').'</title></path>';
        }
        foreach ($layout['nodes'] as $class => $position) {
            $node = $view->nodes[$class];
            $stroke = $node['cycle'] ? '#c22' : ('unresolved' === $node['group'] ? '#9a6b34' : '#64788d');
            $border = $node['cycle'] ? ' stroke-width="2.5"' : ' stroke-width="1.25"';
            if ('unknown' === $node['state']) {
                $border .= ' stroke-dasharray="5 5"';
            }
            $svg[] = '<g id="'.$node['id'].'" class="module"><title>'.$this->escape((string) $class).'</title>'
                .$this->rect($position, 7, $node['disabled'] ? '#eee' : '#fff', $stroke, $border);
            foreach ($position['lines'] as $index => $line) {
                $heading = $index < $position['titleLines'];
                $svg[] = $this->text($line, $position['x'] + 12, $position['y'] + 22 + $index * GraphLayout::LINE_HEIGHT, $heading ? 15 : 12, $heading, $position['width'] - 24);
            }
            $svg[] = '</g>';
        }
        $svg[] = '</g></svg>';

        return implode("\n", $svg)."\n";
    }

    private function marker(string $kind, string $color): string
    {
        return '<marker id="arrow-'.$kind.'" viewBox="0 0 10 10" refX="10" refY="5" markerWidth="8" markerHeight="8" markerUnits="userSpaceOnUse" orient="auto"><path d="M 0 0 L 10 5 L 0 10 z" fill="'.$color.'"/></marker>';
    }

    /** @param array{x: int, y: int, width: int, height: int, ...} $box */
    private function rect(array $box, int $radius, string $fill, string $stroke, string $border = ''): string
    {
        return '<rect x="'.$box['x'].'" y="'.$box['y'].'" width="'.$box['width'].'" height="'.$box['height'].'" rx="'.$radius.'" fill="'.$fill.'" stroke="'.$stroke.'"'.$border.'/>';
    }

    /** Constrain glyph advance to the measured text area, including with fallback fonts. */
    private function text(string $line, int $x, int $y, int $size, bool $bold, int $limit): string
    {
        $width = min($limit, max(1, (int) ceil(GraphLayout::textUnits($line) * $size * 0.62)));

        return '<text x="'.$x.'" y="'.$y.'" font-size="'.$size.'" textLength="'.$width.'" lengthAdjust="spacingAndGlyphs"'.($bold ? ' font-weight="bold"' : '').'>'.$this->escape($line).'</text>';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_XML1 | \ENT_SUBSTITUTE | \ENT_DISALLOWED, 'UTF-8');
    }
}
