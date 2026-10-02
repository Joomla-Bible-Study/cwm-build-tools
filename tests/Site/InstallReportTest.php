<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\InstallReport;
use CWM\BuildTools\Site\SiteException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class InstallReportTest extends TestCase
{
    #[Test]
    public function readsTheLastResultLineAndIgnoresNoiseBeforeIt(): void
    {
        $output = "Deprecated: something in x.php on line 4\nCWM_RESULT:{\"ok\":false}\nnoise\nCWM_RESULT:"
            . json_encode(['ok' => true, 'name' => 'PKG_X', 'type' => 'package', 'version' => '1.2.3', 'messages' => ['message' => ['hi']], 'error' => null])
            . "\n";

        $report = InstallReport::fromOutput($output);

        $this->assertTrue($report->ok);
        $this->assertSame('PKG_X', $report->name);
        $this->assertSame('package', $report->type);
        $this->assertSame('1.2.3', $report->version);
        $this->assertSame(['message' => ['hi']], $report->messages);
    }

    #[Test]
    public function warningsIncludeWarningAndErrorMessagesButNotPlainOnes(): void
    {
        $report = InstallReport::fromOutput('CWM_RESULT:' . json_encode([
            'ok' => true,
            'messages' => ['message' => ['fine'], 'warning' => ['step A did not finish'], 'error' => ['step B failed']],
        ]));

        $this->assertSame(['step A did not finish', 'step B failed'], $report->warnings());
    }

    #[Test]
    public function aMissingResultLineShowsTheTailOfWhatWasPrinted(): void
    {
        try {
            InstallReport::fromOutput("line1\nPHP Fatal error:  Uncaught Error: Class \"X\" not found");
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('fatal error', $e->getMessage());
            $this->assertStringContainsString('Class "X" not found', $e->getMessage());
        }
    }

    #[Test]
    public function emptyOutputSaysItPrintedNothing(): void
    {
        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('It printed nothing.');

        InstallReport::fromOutput("\n");
    }

    #[Test]
    public function malformedJsonIsTreatedAsNoResult(): void
    {
        $this->expectException(SiteException::class);

        InstallReport::fromOutput('CWM_RESULT:{not json');
    }
}
