<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

use Sillbeam\Core\Architecture\ArchitectureAnalysisResult;
use Sillbeam\Core\Architecture\ArchitectureAnalyzer;
use Sillbeam\Core\Architecture\SourceInspector;
use Sillbeam\Core\I18n\CoreError;

/** @api Collects a document from a single run of Core's existing analyzer. */
final readonly class CoreDataProvider implements CoreDataProviderInterface
{
    /** @param CoreDataConfiguration|\Closure(): CoreDataConfiguration $configuration */
    public function __construct(private CoreDataConfiguration|\Closure $configuration)
    {
    }

    /** @return list<CoreDataProfile> */
    public static function supportedProfiles(): array
    {
        return [CoreDataProfile::Live];
    }

    public function collect(CoreDataRequest $request): CoreDataDocument
    {
        if (!\in_array($request->profile, self::supportedProfiles(), true)) {
            throw CoreError::create(\InvalidArgumentException::class, 'api.unsupported_profile', ['profile' => $request->profile->value]);
        }

        $input = null;
        $started = null;
        $failure = null;
        try {
            $input = $this->configuration instanceof \Closure ? ($this->configuration)() : $this->configuration;
            $started = hrtime(true);
            $result = (new ArchitectureAnalyzer(new SourceInspector(limitParseFailuresToDiscoveryRoots: true)))->analyze($input->options, $input->config);
        } catch (\Throwable $error) {
            $failure = $error::class;
            $result = new ArchitectureAnalysisResult();
        }
        $duration = null === $started ? 0 : (int) ((hrtime(true) - $started) / 1_000_000);
        $analyzedAt = gmdate('Y-m-d\TH:i:s\Z');

        return (new LiveDataAssembler($request, $input?->projectDir))->assemble($result, $input->config ?? [], $duration, $analyzedAt, $failure);
    }
}
