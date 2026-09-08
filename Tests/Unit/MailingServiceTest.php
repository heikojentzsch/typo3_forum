<?php

declare(strict_types=1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Configuration\ConfigurationBuilder;
use Mittwald\Typo3Forum\Domain\Model\User\FrontendUser;
use Mittwald\Typo3Forum\Service\Mailing\HTMLMailingService;
use Mittwald\Typo3Forum\Service\Mailing\PlainMailingService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Mail\MailerInterface;

final class MailingServiceTest extends TestCase
{
    public function testBothFormatsSendThroughTheInjectedMailer(): void
    {
        foreach ([HTMLMailingService::class, PlainMailingService::class] as $class) {
            $configuration = $this->createStub(ConfigurationBuilder::class);
            $configuration->method('getSettings')->willReturn(['mailing.' => ['sender.' => ['address' => 'forum@example.test', 'name' => 'Forum']]]);
            $recipient = $this->createStub(FrontendUser::class);
            $recipient->method('getEmail')->willReturn('recipient@example.test');
            $mailer = $this->createMock(MailerInterface::class);
            $mailer->expects(self::once())->method('send')->with(self::callback(static function (Email $message) use ($class): bool {
                self::assertSame('Subject', $message->getSubject());
                self::assertSame('recipient@example.test', $message->getTo()[0]->getAddress());
                self::assertSame('forum@example.test', $message->getFrom()[0]->getAddress());
                self::assertSame('Forum', $message->getFrom()[0]->getName());
                self::assertSame('Body', $class === HTMLMailingService::class ? $message->getHtmlBody() : $message->getTextBody());
                return true;
            }));
            (new $class($configuration, $mailer))->sendMail($recipient, 'Subject', 'Body');
        }
    }

    public function testEmptyRecipientDoesNotSendMail(): void
    {
        $configuration = $this->createStub(ConfigurationBuilder::class);
        $configuration->method('getSettings')->willReturn([]);
        $recipient = $this->createStub(FrontendUser::class);
        $recipient->method('getEmail')->willReturn('');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        foreach ([HTMLMailingService::class, PlainMailingService::class] as $class) {
            (new $class($configuration, $mailer))->sendMail($recipient, 'Subject', 'Body');
        }
    }
}
