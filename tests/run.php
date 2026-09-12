<?php
declare(strict_types=1);

/**
 * Runner de testes minimalista (sem PHPUnit).
 *   php bin/hr.php test
 */

if (!defined('HR_ROOT')) {
    require __DIR__ . '/../config/bootstrap.php';
}

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    /** @var array<int,string> */
    public static array $failures = [];
    public static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        fwrite(STDOUT, "\n# $name\n");
    }

    public static function ok(bool $cond, string $label): void
    {
        if ($cond) {
            self::$pass++;
            fwrite(STDOUT, "  ✓ $label\n");
        } else {
            self::$fail++;
            self::$failures[] = self::$group . ' :: ' . $label;
            fwrite(STDOUT, "  ✗ $label\n");
        }
    }

    public static function eq(mixed $expected, mixed $actual, string $label): void
    {
        self::ok($expected === $actual, $label . "  (esperado " . json_encode($expected) . ", obtido " . json_encode($actual) . ")");
    }
}

require __DIR__ . '/TestDb.php';

foreach ([
    'ParserTest', 'HotScoreTest', 'DedupeTest',
    'SettingsTest', 'RadarTest', 'IntegrationStatusTest', 'ReportTest',
    'SecurityTest', 'EditorialPreservationTest',
    'ProductRadarM2MTest', 'FallbackMergeTest', 'MigrationBackfillTest',
    'MigrationLegacyBackfillTest', 'OpenAiOptionalTest',
    'AuthTest', 'RadarLifecycleTest', 'ExportAndAnalyzeTest', 'MigrationManualRadarTest',
    'HotScoreV2Test', 'EnvironmentValidationTest', 'HealthCheckTest', 'SystemResetServiceTest',
] as $suite) {
    require __DIR__ . '/' . $suite . '.php';
}

fwrite(STDOUT, "\n----------------------------------------\n");
fwrite(STDOUT, sprintf("Testes: %d passaram, %d falharam\n", T::$pass, T::$fail));
foreach (T::$failures as $f) {
    fwrite(STDOUT, "  FALHA: $f\n");
}
exit(T::$fail > 0 ? 1 : 0);
