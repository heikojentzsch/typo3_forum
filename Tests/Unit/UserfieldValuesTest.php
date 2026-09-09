<?php

declare(strict_types=1);

namespace Mittwald\Typo3Forum\Tests\Unit;

use Mittwald\Typo3Forum\Domain\Model\User\FrontendUser;
use Mittwald\Typo3Forum\Domain\Model\User\Userfield\AbstractUserfield;
use Mittwald\Typo3Forum\Domain\Model\User\Userfield\Value;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

final class UserfieldValuesTest extends TestCase
{
    public function testMissingValueReturnsAnEmptyList(): void
    {
        $field = new class extends AbstractUserfield {};
        $field->setUserObjectPropertyName(null);
        $user = $this->createStub(FrontendUser::class);
        $user->method('getUserfieldValues')->willReturn(new ObjectStorage());

        self::assertSame([], $field->getValuesForUser($user));
    }

    public function testStoredValueIsPreserved(): void
    {
        $field = new class extends AbstractUserfield {};
        $field->setUserObjectPropertyName(null);
        $value = $this->createStub(Value::class);
        $value->method('getUserfield')->willReturn($field);
        $value->method('getValue')->willReturn('stored value');
        $values = new ObjectStorage();
        $values->attach($value);
        $user = $this->createStub(FrontendUser::class);
        $user->method('getUserfieldValues')->willReturn($values);

        self::assertSame(['stored value'], $field->getValuesForUser($user));
    }

    public function testMappedPropertiesKeepTheirOrderAndValues(): void
    {
        $field = new class extends AbstractUserfield {};
        $field->setUserObjectPropertyName('firstName|lastName');
        $user = $this->createStub(FrontendUser::class);
        $user->method('_getProperty')->willReturnMap([
            ['firstName', 'First'],
            ['lastName', 'Last'],
        ]);

        self::assertSame(['First', 'Last'], $field->getValuesForUser($user));
    }
}
