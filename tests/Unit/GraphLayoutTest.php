<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Architecture\Console\GraphLayout;
use Sillbeam\Core\Architecture\Console\GraphView;

/** @phpstan-import-type Layout from GraphLayout */
final class GraphLayoutTest extends TestCase
{
    public function testRanksDirectDependenciesAndKeepsScopeAndEvidence(): void
    {
        $modules = [
            $this->module('A', ['B', 'B', 'D'], 'Shop', ['B', 'Missing']),
            $this->module('B', ['C'], 'Admin'),
            $this->module('C', ['B', 'D']),
            $this->module('D', ['D'], 'UnknownProject'),
            $this->module('Independent', [], 'Shop'),
        ];
        $projects = [
            ['id' => 'shop', 'class' => 'Shop', 'modules' => null],
            ['id' => 'admin', 'class' => 'Admin', 'modules' => null],
        ];
        $view = $this->view($modules, $projects);
        $engine = new GraphLayout();
        $layout = $engine->layout($view);
        self::assertSame($layout, $engine->layout($this->view(array_reverse($modules), array_reverse($projects))));
        $nodes = $layout['nodes'];
        self::assertLessThan($nodes['B']['x'], $nodes['A']['x']);
        self::assertSame($nodes['B']['x'], $nodes['C']['x']);
        self::assertLessThan($nodes['D']['x'], $nodes['C']['x']);
        self::assertLessThan($nodes['Missing']['x'], $nodes['A']['x']);
        self::assertSame(['shared_scope', 'project_scope_0001', 'project_scope_0002', 'unknown_owner', 'unresolved'], array_keys($layout['scopes']));
        self::assertSame('unknown', $view->nodes['A']['state']);
        self::assertFalse($view->nodes['B']['cycle']);
        self::assertCount(7, $layout['edges']);
        self::assertSame($view->edges, array_column($layout['edges'], 'edge'));
        $this->assertGeometry($view, $layout);
    }

    public function testLargerMixedCatalogAndLongLabels(): void
    {
        $modules = [];
        for ($index = 0; $index < 120; ++$index) {
            $targets = [];
            if ($index < 78) {
                $targets = ['M'.($index + 1), 'M'.($index + 2), 'M'.(80 + $index % 12), 'Absent'];
            }
            $modules[] = $this->module('M'.$index, $targets, ['Shop', 'Admin', null][$index % 3]);
        }
        $modules[0]['id'] = str_repeat('長い名前', 55);
        $projects = [
            ['id' => 'shop', 'class' => 'Shop', 'modules' => []],
            ['id' => 'admin', 'class' => 'Admin', 'modules' => []],
        ];
        $view = $this->view($modules, $projects);
        $layout = (new GraphLayout())->layout($view);
        self::assertCount(121, $layout['nodes']);
        self::assertCount(312, $layout['edges']);
        self::assertSame($modules[0]['id'], implode('', \array_slice($layout['nodes']['M0']['lines'], 0, -1)));
        self::assertGreaterThan($layout['nodes']['M3']['height'], $layout['nodes']['M0']['height']);
        $this->assertGeometry($view, $layout);
    }

    public function testIsolatesUseMultipleColumnsAndEmptyCatalogStillHasCanvas(): void
    {
        $modules = [];
        for ($index = 0; $index < 100; ++$index) {
            $modules[] = $this->module('M'.$index);
        }
        $engine = new GraphLayout();
        $view = $this->view($modules);
        $layout = $engine->layout($view);
        self::assertCount(10, array_unique(array_column($layout['nodes'], 'x')));
        self::assertSame([], $layout['edges']);
        $this->assertGeometry($view, $layout);
        $empty = $engine->layout($this->view([]));
        self::assertSame([], $empty['nodes']);
        self::assertSame([], $empty['scopes']);
        self::assertGreaterThan(0, $empty['width']);
        self::assertGreaterThan(0, $empty['height']);
    }

    public function testOrderingAlignsIndependentEdgesAcrossNeighboringLayers(): void
    {
        $view = $this->view([
            $this->module('A', ['Y']),
            $this->module('B', ['X']),
            $this->module('X'),
            $this->module('Y'),
        ]);
        $layout = (new GraphLayout())->layout($view);
        self::assertSame($layout['nodes']['A']['y'], $layout['nodes']['Y']['y']);
        self::assertSame($layout['nodes']['B']['y'], $layout['nodes']['X']['y']);
        foreach ($layout['edges'] as $route) {
            self::assertCount(2, $route['points']);
        }
    }

    public function testProjectFilterKeepsExternalLabelAndUnresolvedTarget(): void
    {
        $view = $this->view([
            $this->module('Source', ['External', 'Missing'], 'Shop'),
            $this->module('External', [], 'Admin'),
            $this->module('Hidden', [], 'Admin'),
        ], [
            ['id' => 'shop', 'class' => 'Shop', 'modules' => null],
            ['id' => 'admin', 'class' => 'Admin', 'modules' => null],
        ], 'shop');

        $layout = (new GraphLayout())->layout($view);
        self::assertArrayNotHasKey('Hidden', $layout['nodes']);
        self::assertContains('external to Project context', $layout['nodes']['External']['lines']);
        self::assertContains('external to Project context', $layout['nodes']['Missing']['lines']);
        $this->assertGeometry($view, $layout);
    }

    /** @param Layout $layout */
    private function assertGeometry(GraphView $view, array $layout): void
    {
        foreach ($layout['nodes'] as $class => $node) {
            $scope = $layout['scopes'][$view->nodes[$class]['group']];
            self::assertGreaterThan($scope['x'], $node['x']);
            self::assertGreaterThan($scope['y'], $node['y']);
            self::assertLessThan($scope['x'] + $scope['width'], $node['x'] + $node['width']);
            self::assertLessThan($scope['y'] + $scope['height'], $node['y'] + $node['height']);
            self::assertLessThan($layout['width'], $scope['x'] + $scope['width']);
            self::assertLessThan($layout['height'], $scope['y'] + $scope['height']);
            foreach ($layout['nodes'] as $otherClass => $other) {
                if ($class === $otherClass) {
                    continue;
                }
                self::assertTrue(
                    $node['x'] + $node['width'] < $other['x'] || $other['x'] + $other['width'] < $node['x']
                    || $node['y'] + $node['height'] < $other['y'] || $other['y'] + $other['height'] < $node['y'],
                    "$class overlaps $otherClass",
                );
            }
        }
        foreach ($layout['edges'] as ['edge' => $edge, 'points' => $points]) {
            $source = $layout['nodes'][$edge['source']];
            $target = $layout['nodes'][$edge['target']];
            self::assertSame($source['x'] + $source['width'], $points[0][0]);
            $end = $points[array_key_last($points)];
            self::assertContains($end[0], [$target['x'], $target['x'] + $target['width']]);
            self::assertGreaterThan($source['y'], $points[0][1]);
            self::assertLessThan($source['y'] + $source['height'], $points[0][1]);
            self::assertGreaterThan($target['y'], $end[1]);
            self::assertLessThan($target['y'] + $target['height'], $end[1]);
            for ($index = 1; $index < \count($points); ++$index) {
                [$x1, $y1] = $points[$index - 1];
                [$x2, $y2] = $points[$index];
                self::assertTrue($x1 === $x2 || $y1 === $y2);
                self::assertTrue($x1 !== $x2 || $y1 !== $y2);
                self::assertTrue($x2 > 0 && $x2 < $layout['width'] && $y2 > 0 && $y2 < $layout['height']);
                foreach ($layout['nodes'] as $class => $node) {
                    self::assertTrue(
                        max($x1, $x2) <= $node['x'] || min($x1, $x2) >= $node['x'] + $node['width']
                        || max($y1, $y2) <= $node['y'] || min($y1, $y2) >= $node['y'] + $node['height'],
                        $edge['source'].' → '.$edge['target']." intersects $class",
                    );
                }
            }
        }
    }

    /**
     * @param list<string> $targets
     * @param list<string> $observed
     *
     * @return array<string, mixed>
     */
    private function module(string $class, array $targets = [], ?string $owner = null, array $observed = []): array
    {
        return [
            'id' => $class, 'class' => $class, 'scope' => null === $owner ? 'shared' : 'private', 'project' => $owner,
            'enabled' => true, 'active' => null, 'declared_dependencies' => $targets, 'observed_dependencies' => $observed,
        ];
    }

    /**
     * @param list<array<string, mixed>> $modules
     * @param list<array<string, mixed>> $projects
     */
    private function view(array $modules, array $projects = [], ?string $project = null): GraphView
    {
        return GraphView::fromReport(new ArchitectureReport([
            'schema_version' => 1, 'status' => 'unavailable', 'complete' => false, 'valid' => false,
            'modules' => $modules, 'projects' => $projects, 'findings' => [],
        ]), project: $project);
    }
}
