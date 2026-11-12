<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\I18n\CoreTranslator;

/**
 * Computes geometry for an already filtered GraphView in SVG user units.
 * Node keys and edge records refer directly to the input view.
 *
 * @internal
 *
 * @phpstan-type Box array{x: int, y: int, width: int, height: int, lines: list<string>}
 * @phpstan-type Node array{x: int, y: int, width: int, height: int, layer: int, lines: list<string>, titleLines: int}
 * @phpstan-type Edge array{source: string, target: string, observed: bool}
 * @phpstan-type Layout array{width: int, height: int, caption: Box, analysisLines: int, nodes: array<string, Node>, scopes: array<string, Box>, edges: list<array{edge: Edge, points: list<array{int, int}>}>}
 */
final class GraphLayout
{
    private const MARGIN = 24;
    private const CARD_WIDTH = 320;
    private const ROW_GAP = 22;
    public const LINE_HEIGHT = 18;
    private const TRACK_GAP = 8;
    private const WIDE_GLYPH = '/[\p{Han}\p{Hangul}\p{Hiragana}\p{Katakana}\p{So}]/u';

    public function __construct(private readonly CoreTranslator $translator = new CoreTranslator())
    {
    }

    /** @return Layout */
    public function layout(GraphView $view): array
    {
        $outgoing = $incoming = array_fill_keys(array_keys($view->nodes), []);
        foreach ($view->edges as $edge) {
            $outgoing[$edge['source']][] = $edge['target'];
            $incoming[$edge['target']][] = $edge['source'];
        }
        $layers = $this->layers($outgoing);
        $isolates = [];
        $cells = [];
        foreach ($layers as $class => $layer) {
            $group = $view->nodes[$class]['group'];
            if ([] === $incoming[$class] && [] === $outgoing[$class]) {
                $isolates[$group][] = $class;
            } else {
                $cells[$layer][$group][] = $class;
            }
        }
        foreach ($isolates as $group => $classes) {
            $columns = (int) ceil(sqrt(\count($classes)));
            foreach ($classes as $index => $class) {
                $layer = $index % $columns;
                $cells[$layer][$group][] = $class;
                $layers[$class] = $layer;
            }
        }
        ksort($cells, \SORT_NUMERIC);
        $groupOrder = array_flip(array_keys($view->groups));
        $this->orderCells($cells, $layers, $incoming, $outgoing, $groupOrder);

        $railGroups = $railIntervals = $ports = [];
        foreach ($view->edges as $index => $edge) {
            $source = $edge['source'];
            $target = $edge['target'];
            $ports[$source]['right'][] = [$index, $target, 0];
            $ports[$target][$layers[$source] === $layers[$target] ? 'right' : 'left'][] = [$index, $source, 1];
            if ($layers[$target] > $layers[$source] + 1) {
                $sourceGroup = $view->nodes[$source]['group'];
                $targetGroup = $view->nodes[$target]['group'];
                // For cross-scope edges, the lower scope's tracks lie between the endpoints.
                $group = $groupOrder[$sourceGroup] >= $groupOrder[$targetGroup] ? $sourceGroup : $targetGroup;
                $railGroups[$index] = $group;
                $railIntervals[$group][$index] = [$layers[$source], $layers[$target] - 1];
            }
        }
        $rails = $railCounts = [];
        foreach ($railIntervals as $group => $intervals) {
            [$assignments, $railCounts[$group]] = $this->tracks($intervals);
            $rails += $assignments;
        }

        $captionLines = [];
        $analysisLines = 0;
        foreach ($view->notes as $index => $note) {
            if (!preg_match('/^[a-z_]+\.[a-z_.]+:/', $note)) {
                $lines = $this->wrap($note, 90);
                array_push($captionLines, ...$lines);
                if (0 === $index) {
                    $analysisLines = \count($lines);
                }
            }
        }
        $caption = [
            'x' => self::MARGIN, 'y' => 54, 'width' => 0,
            'height' => 16 + \count($captionLines) * self::LINE_HEIGHT, 'lines' => $captionLines,
        ];
        $captionWidth = 0;
        foreach ($captionLines as $line) {
            $captionWidth = max($captionWidth, 28 + 9 * self::textUnits($line));
        }

        $nodes = $scopes = $columnHeights = [];
        $external = $this->translator->text('cli.graph.label.external');
        foreach ($cells as $layer => $groups) {
            foreach ($groups as $group => $classes) {
                $offset = 0;
                foreach ($classes as $class) {
                    $node = $view->nodes[$class];
                    $label = $node['label'].($node['external'] ? "\n".$external : '');
                    [$title, $details] = explode("\n", $label, 2);
                    $lines = $this->wrap($title, 32);
                    $titleLines = \count($lines);
                    array_push($lines, ...$this->wrap($details, 36));
                    $portCount = max(\count($ports[$class]['left'] ?? []), \count($ports[$class]['right'] ?? []));
                    $height = max(64, 24 + self::LINE_HEIGHT * \count($lines), 24 + self::TRACK_GAP * $portCount);
                    $nodes[$class] = ['x' => 0, 'y' => $offset, 'width' => self::CARD_WIDTH, 'height' => $height, 'layer' => $layer, 'lines' => $lines, 'titleLines' => $titleLines];
                    $offset += $height + self::ROW_GAP;
                }
                $columnHeights[$group] = max($columnHeights[$group] ?? 0, $offset - self::ROW_GAP);
            }
        }
        $top = $caption['y'] + $caption['height'] + self::MARGIN;
        $groupOffsets = $railOrigins = [];
        foreach ($view->groups as $group => $title) {
            if (!isset($columnHeights[$group])) {
                continue;
            }
            $lines = $this->wrap($title, 36);
            $railOrigins[$group] = $top + 18 + \count($lines) * self::LINE_HEIGHT;
            $groupOffsets[$group] = $railOrigins[$group] + 14 + ($railCounts[$group] ?? 0) * self::TRACK_GAP;
            $height = $groupOffsets[$group] - $top + $columnHeights[$group] + 18;
            $scopes[$group] = ['x' => self::MARGIN, 'y' => $top, 'width' => 0, 'height' => $height, 'lines' => $lines];
            $top += $height + self::MARGIN;
        }
        foreach ($nodes as $class => &$node) {
            $node['y'] += $groupOffsets[$view->nodes[$class]['group']];
        }
        unset($node);

        // Every vertical leg stays in a column gutter. Long edges change gutters
        // on a horizontal track above the cards of one of the endpoint scopes.
        $portY = $this->placePorts($nodes, $ports);
        $verticalIntervals = $railY = [];
        foreach ($view->edges as $index => $edge) {
            $sourceLayer = $layers[$edge['source']];
            $targetLayer = $layers[$edge['target']];
            [$from, $to] = $portY[$index];
            if (isset($rails[$index])) {
                $railY[$index] = $railOrigins[$railGroups[$index]] + $rails[$index] * self::TRACK_GAP;
                $verticalIntervals[$sourceLayer][2 * $index] = [min($from, $railY[$index]), max($from, $railY[$index])];
                $verticalIntervals[$targetLayer - 1][2 * $index + 1] = [min($to, $railY[$index]), max($to, $railY[$index])];
            } elseif ($from !== $to) {
                $verticalIntervals[$sourceLayer][2 * $index] = [min($from, $to), max($from, $to)];
            }
        }

        $columnX = $trackX = [];
        $x = self::MARGIN + 18;
        foreach ($cells as $layer => $groups) {
            $columnX[$layer] = $x;
            $x += self::CARD_WIDTH;
            [$assignments, $count] = $this->tracks($verticalIntervals[$layer] ?? []);
            foreach ($assignments as $leg => $track) {
                $trackX[$leg] = $x + 14 + $track * self::TRACK_GAP;
            }
            $x += max(48, 30 + $count * self::TRACK_GAP);
        }
        $width = max($x + self::MARGIN, $captionWidth + 2 * self::MARGIN);
        $caption['width'] = $captionWidth;
        foreach ($scopes as &$scope) {
            $scope['width'] = $width - 2 * self::MARGIN;
        }
        unset($scope);
        foreach ($nodes as &$node) {
            $node['x'] = $columnX[$node['layer']];
        }
        unset($node);

        $edges = [];
        foreach ($view->edges as $index => $edge) {
            $source = $nodes[$edge['source']];
            $target = $nodes[$edge['target']];
            [$from, $to] = $portY[$index];
            $points = [[$source['x'] + self::CARD_WIDTH, $from]];
            if (isset($railY[$index])) {
                $first = $trackX[2 * $index];
                $last = $trackX[2 * $index + 1];
                array_push($points, [$first, $from], [$first, $railY[$index]], [$last, $railY[$index]], [$last, $to]);
            } elseif ($from !== $to) {
                $track = $trackX[2 * $index];
                array_push($points, [$track, $from], [$track, $to]);
            }
            $points[] = [$target['x'] + ($source['layer'] === $target['layer'] ? self::CARD_WIDTH : 0), $to];
            $edges[] = ['edge' => $edge, 'points' => $points];
        }

        return ['width' => $width, 'height' => $top, 'caption' => $caption, 'analysisLines' => $analysisLines, 'nodes' => $nodes, 'scopes' => $scopes, 'edges' => $edges];
    }

    /**
     * Tarjan emits strongly connected components in reverse topological order.
     * Propagate longest-path ranks in the opposite order on the condensed graph.
     *
     * @param array<string, list<string>> $outgoing
     *
     * @return array<string, int>
     */
    private function layers(array $outgoing): array
    {
        $discovery = $low = $stack = $component = $members = [];
        $visit = static function (string $node) use (&$visit, &$discovery, &$low, &$stack, &$component, &$members, $outgoing): void {
            $discovery[$node] = $low[$node] = \count($discovery);
            $stack[] = $node;
            foreach ($outgoing[$node] as $target) {
                if (!isset($discovery[$target])) {
                    $visit($target);
                    $low[$node] = min($low[$node], $low[$target]);
                } elseif (!isset($component[$target])) {
                    $low[$node] = min($low[$node], $discovery[$target]);
                }
            }
            if ($low[$node] !== $discovery[$node]) {
                return;
            }
            $id = \count($members);
            do {
                $member = array_pop($stack);
                \assert(null !== $member);
                $component[$member] = $id;
                $members[$id][] = $member;
            } while ($member !== $node);
        };
        foreach (array_keys($outgoing) as $node) {
            if (!isset($discovery[$node])) {
                $visit($node);
            }
        }
        unset($visit); // Release the recursive closure and its captured graph.
        $ranks = array_fill(0, \count($members), 0);
        foreach (array_reverse($members, true) as $id => $classes) {
            foreach ($classes as $class) {
                foreach ($outgoing[$class] as $target) {
                    $next = $component[$target];
                    if ($id !== $next) {
                        $ranks[$next] = max($ranks[$next], $ranks[$id] + 1);
                    }
                }
            }
        }

        return array_map(static fn (string $node): int => $ranks[$component[$node]], array_combine(array_keys($outgoing), array_keys($outgoing)));
    }

    /**
     * Two forward/backward rounds of barycentric ordering, keeping each node in its scope and layer.
     *
     * @param array<int, array<string, list<string>>> $cells
     * @param array<string, int>                      $layers
     * @param array<string, list<string>>             $incoming
     * @param array<string, list<string>>             $outgoing
     * @param array<string, int>                      $groupOrder
     */
    private function orderCells(array &$cells, array $layers, array $incoming, array $outgoing, array $groupOrder): void
    {
        $positions = [];
        foreach ($cells as $groups) {
            foreach ($groups as $group => $classes) {
                foreach ($classes as $index => $class) {
                    $positions[$class] = $groupOrder[$group] + ($index + 0.5) / \count($classes);
                }
            }
        }
        $forward = array_keys($cells);
        $backward = array_reverse($forward);
        foreach ([true, false, true, false] as $sweepForward) {
            $neighbors = $sweepForward ? $incoming : $outgoing;
            foreach ($sweepForward ? $forward : $backward as $layer) {
                foreach ($cells[$layer] as $group => &$classes) {
                    $scores = [];
                    foreach ($classes as $class) {
                        $sum = $count = 0;
                        foreach ($neighbors[$class] as $neighbor) {
                            if ($layers[$neighbor] !== $layer) {
                                $sum += $positions[$neighbor];
                                ++$count;
                            }
                        }
                        $scores[$class] = 0 === $count ? $positions[$class] : $sum / $count;
                    }
                    usort($classes, static fn (string $a, string $b): int => $scores[$a] <=> $scores[$b] ?: $positions[$a] <=> $positions[$b]);
                    foreach ($classes as $index => $class) {
                        $positions[$class] = $groupOrder[$group] + ($index + 0.5) / \count($classes);
                    }
                }
                unset($classes);
            }
        }
    }

    /**
     * @param array<string, Node>                                         $nodes
     * @param array<string, array<string, list<array{int, string, int}>>> $ports
     *
     * @return array<int, array<int, int>>
     */
    private function placePorts(array $nodes, array $ports): array
    {
        $centres = array_map(static fn (array $node): int => $node['y'] + intdiv($node['height'], 2), $nodes);
        $portY = [];
        foreach ($ports as $class => $sides) {
            $node = $nodes[$class];
            foreach ($sides as $endpoints) {
                usort($endpoints, static fn (array $a, array $b): int => [$centres[$a[1]], $a[0], $a[2]] <=> [$centres[$b[1]], $b[0], $b[2]]);
                foreach ($endpoints as $position => [$edge, , $end]) {
                    $portY[$edge][$end] = $node['y'] + 12 + intdiv(($node['height'] - 24) * ($position + 1), \count($endpoints) + 1);
                }
            }
        }

        return $portY;
    }

    /**
     * Closed intervals may share a track only when separated by a gap.
     *
     * @param array<int, array{int, int}> $intervals
     *
     * @return array{array<int, int>, int}
     */
    private function tracks(array $intervals): array
    {
        uasort($intervals, static fn (array $a, array $b): int => $a <=> $b);
        $busy = new \SplMinHeap();
        $free = new \SplMinHeap();
        $assigned = [];
        $count = 0;
        foreach ($intervals as $id => [$start, $end]) {
            while (!$busy->isEmpty() && $busy->top()[0] < $start) {
                $free->insert($busy->extract()[1]);
            }
            $track = $free->isEmpty() ? $count++ : $free->extract();
            $assigned[$id] = $track;
            $busy->insert([$end, $track]);
        }

        return [$assigned, $count];
    }

    /** @return list<string> */
    private function wrap(string $label, int $limit): array
    {
        $lines = [];
        foreach (explode("\n", wordwrap($label, $limit, "\n", false)) as $line) {
            $characters = preg_split('//u', $line, -1, \PREG_SPLIT_NO_EMPTY);
            if (false === $characters) {
                $lines[] = $this->translator->text('cli.graph.label.invalid_utf8');
                continue;
            }
            $chunk = '';
            $width = 0;
            foreach ($characters as $character) {
                $weight = preg_match(self::WIDE_GLYPH, $character) ? 2 : 1;
                if ($width + $weight > $limit) {
                    $lines[] = $chunk;
                    $chunk = '';
                    $width = 0;
                }
                $chunk .= $character;
                $width += $weight;
            }
            $lines[] = $chunk;
        }

        return $lines;
    }

    public static function textUnits(string $line): int
    {
        return (preg_match_all('/./us', $line) ?: 0) + (preg_match_all(self::WIDE_GLYPH, $line) ?: 0);
    }
}
