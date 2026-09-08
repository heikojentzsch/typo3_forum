<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Domain\Model\Format\BBCode;
use Mittwald\Typo3Forum\Domain\Model\Moderation\Report;
use Mittwald\Typo3Forum\Domain\Model\Moderation\ReportComment;
use PHPUnit\Framework\TestCase;

final class LegacyArrayTest extends TestCase
{
    public function testBbCodeWrapKeepsFirstAndLastSegments(): void
    {
        $bbCode = new BBCode();
        foreach ([['', '', ''], ['text', 'text', 'text'], ['|', '', ''], ['a|', 'a', ''], ['|b', '', 'b'], ['a|middle|b', 'a', 'b'], ['[b]|[/b]', '[b]', '[/b]']] as [$wrap, $left, $right]) {
            $bbCode->setBbcodeWrap($wrap);
            self::assertSame($left, $bbCode->getLeftBBCode());
            self::assertSame($right, $bbCode->getRightBBCode());
        }
    }

    public function testFirstReportCommentDoesNotMutateStorage(): void
    {
        $report = new Report();
        self::assertNull($report->getFirstComment());
        $first = $this->createStub(ReportComment::class);
        $second = $this->createStub(ReportComment::class);
        $report->getComments()->attach($first);
        $report->getComments()->attach($second);
        self::assertSame($first, $report->getFirstComment());
        self::assertSame($first, $report->getFirstComment());
        self::assertCount(2, $report->getComments());
    }
}
