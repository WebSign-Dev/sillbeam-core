<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture\Console;

use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\I18n\CoreTranslator;

/** @internal Deterministic Mermaid presentation of the shared report graph view. */
final class MermaidGraph
{
    public function __construct(private readonly CoreTranslator $translator = new CoreTranslator())
    {
    }

    /** Returns raw source, with captions/comments rather than disconnected status nodes. */
    public function render(ArchitectureReport|GraphView $report): string
    {
        $view = $report instanceof GraphView ? $report : GraphView::fromReport($report);
        $lines = ['flowchart LR'];
        foreach ($view->notes as $note) {
            $lines[] = '    %% '.$this->plain($note);
        }
        // A containing subgraph makes status and the legend visible without inventing modules.
        $caption = implode("\n", array_filter($view->notes, static fn (string $note): bool => !preg_match('/^[a-z_]+\.[a-z_.]+:/', $note)));
        $lines[] = '    subgraph architecture["'.$this->label($caption).'"]';
        foreach ($view->groups as $group => $title) {
            $nodes = array_filter($view->nodes, static fn (array $node): bool => $node['group'] === $group);
            if ([] === $nodes) {
                continue;
            }
            $lines[] = '    subgraph '.$group.'["'.$this->label($title).'"]';
            foreach ($nodes as $node) {
                $lines[] = '        '.$node['id'].'["'.$this->label($node['label']).'"]';
            }
            $lines[] = '    end';
        }
        foreach ($view->edges as $edge) {
            $lines[] = '    '.$view->nodes[$edge['source']]['id'].($edge['observed'] ? (' -.->|'.$this->translator->text('cli.graph.label.observed_undeclared').'| ') : ' --> ').$view->nodes[$edge['target']]['id'];
        }
        $lines[] = '    end';
        foreach (['disabled', 'unknown', 'cycle'] as $style) {
            $ids = [];
            foreach ($view->nodes as $node) {
                if ('unknown' === $style ? 'unknown' === $node['state'] : $node[$style]) {
                    $ids[] = $node['id'];
                }
            }
            if ([] !== $ids) {
                $lines[] = '    class '.implode(',', $ids).' '.$style.';';
            }
        }
        foreach ($view->edges as $index => $edge) {
            if ($edge['observed']) {
                $lines[] = '    linkStyle '.$index.' stroke:#b45309,stroke-width:2px;';
            }
        }
        $lines[] = '    classDef disabled fill:#eee,color:#555,stroke:#777;';
        $lines[] = '    classDef unknown stroke-dasharray:5 5;';
        $lines[] = '    classDef cycle stroke:#c22,stroke-width:3px;';

        return implode("\n", $lines)."\n";
    }

    private function plain(string $value): string
    {
        return preg_replace('/[\p{C}\p{Zl}\p{Zp}]/u', ' ', $value) ?? $this->translator->text('cli.graph.label.invalid_utf8');
    }

    /** Encodes all external punctuation before inserting renderer-owned line breaks. */
    private function label(string $value): string
    {
        return implode('<br/>', array_map(function (string $line): string {
            return preg_replace_callback('/[^a-zA-Z0-9 _\-\x80-\xFF]/', static fn (array $match): string => '#'.\ord($match[0]).';', $this->plain($line)) ?? '';
        }, explode("\n", $value)));
    }
}
