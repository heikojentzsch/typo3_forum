<?php

namespace Mittwald\Typo3Forum\Domain\Model;

use Mittwald\Typo3Forum\Configuration\ConfigurationBuilder;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;

interface ConfigurableInterface extends DomainObjectInterface
{
    /**
     * @param ConfigurationBuilder $configurationBuilder
     * @return void
     */
    public function injectSettings(ConfigurationBuilder $configurationBuilder);
}
