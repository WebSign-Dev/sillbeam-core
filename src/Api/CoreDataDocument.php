<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

use Sillbeam\Core\I18n\CoreError;

/**
 * Snapshot assembled by a provider. Section contents follow the normative v1 schemas.
 *
 * @api
 */
final readonly class CoreDataDocument implements \JsonSerializable
{
    /** @var list<CoreDataObject> */
    public array $errors;

    /** @param list<CoreDataObject> $errors */
    public function __construct(
        public CoreDataRequest $request,
        public CoreDataObject $metadata,
        public CoreDataObject $i18n,
        public CoreDataObject $analysis,
        public CoreDataObject $export,
        public CoreDataObject $completeness,
        public CoreDataObject $summary,
        public CoreDataObject $architecture,
        public CoreDataObject $diagnostics,
        public CoreDataObject $references,
        array $errors,
        public CoreDataObject $extensions,
        public ?CoreDataObject $graph = null,
        public ?CoreDataObject $sourceInventory = null,
        public ?CoreDataObject $doctor = null,
    ) {
        $extraSections = array_filter([$graph, $sourceInventory, $doctor]);
        $expected = CoreDataProfile::Analysis === $request->profile ? 3 : 0;
        if ($expected !== \count($extraSections)) {
            throw CoreError::create(\InvalidArgumentException::class, 'api.profile_sections', ['profile' => $request->profile->value]);
        }
        $this->errors = array_map(static fn (CoreDataObject $error): CoreDataObject => $error, array_values($errors));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = [
            'document_type' => 'sillbeam_core_data',
            'schema_version' => $this->request->schemaVersion,
            'profile' => $this->request->profile->value,
            'metadata' => $this->metadata,
            'i18n' => $this->i18n,
            'analysis' => $this->analysis,
            'export' => $this->export,
            'completeness' => $this->completeness,
            'summary' => $this->summary,
            'architecture' => $this->architecture,
            'diagnostics' => $this->diagnostics,
            'references' => $this->references,
            'errors' => $this->errors,
            'extensions' => $this->extensions,
        ];
        if (CoreDataProfile::Analysis === $this->request->profile) {
            $data['graph'] = $this->graph;
            $data['source_inventory'] = $this->sourceInventory;
            $data['doctor'] = $this->doctor;
        }

        return $data;
    }
}
