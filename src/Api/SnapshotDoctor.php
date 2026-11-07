<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

use Sillbeam\Core\Console\Doctor\DoctorReport;
use Sillbeam\Core\Console\Doctor\InstallationInspector;
use Sillbeam\Core\I18n\CoreTranslator;

/** @internal */
final class SnapshotDoctor
{
    /** @param array<string, CoreTranslator> $locales
     * @return array{CoreDataObject, string}
     */
    public static function collect(?string $projectDir, string $environment, ?string $kernelFile, array $locales): array
    {
        $captured = CoreTranslator::capture();
        try {
            if (null === $projectDir) {
                throw new \RuntimeException();
            }
            $report = DoctorReport::fromChecks((new InstallationInspector())->inspect($projectDir, $environment, $kernelFile, $captured, safeErrors: true));
        } catch (\Throwable $error) {
            $report = DoctorReport::failure($error, 'doctor.execution');
        }
        $data = $report->data;
        $checks = $errors = [];
        foreach ($data['checks'] as $index => $row) {
            $translations = [];
            foreach ($locales as $locale => $translator) {
                $translations[$locale] = new CoreDataObject([
                    'description' => $captured->resolve($row['description'], $translator),
                    'detail' => $captured->resolve($row['detail'], $translator),
                ]);
            }
            $checks[] = new CoreDataObject([
                'index' => $index, 'id' => $row['id'], 'status' => self::status($row['status']),
                'translations' => new CoreDataObject($translations),
            ]);
        }
        foreach ($data['errors'] as $error) {
            $translations = [];
            foreach ($locales as $locale => $translator) {
                $translations[$locale] = new CoreDataObject(['message' => $translator->text('api.doctor_unavailable')]);
            }
            $errors[] = new CoreDataObject([
                'code' => $error['code'], 'message' => (new CoreTranslator())->text('api.doctor_unavailable'),
                'translations' => new CoreDataObject($translations),
            ]);
        }
        $summary = [];
        foreach ($data['summary'] as $status => $count) {
            $summary[self::status($status)] = $count;
        }

        return [new CoreDataObject([
            'status' => self::status($data['status']), 'exit_code' => $report->exitCode(),
            'summary' => new CoreDataObject($summary), 'checks' => $checks, 'errors' => $errors,
        ]), [] === $errors ? 'complete' : ([] === $checks ? 'unavailable' : 'partial')];
    }

    private static function status(string $status): string
    {
        return str_replace(' ', '_', $status);
    }
}
