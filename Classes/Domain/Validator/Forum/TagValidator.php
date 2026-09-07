<?php
namespace Mittwald\Typo3Forum\Domain\Validator\Forum;

/*                                                                      *
*  COPYRIGHT NOTICE                                                    *
*                                                                      *
*  (c) 2015 Mittwald CM Service GmbH & Co KG                           *
*           All rights reserved                                        *
*                                                                      *
*  This script is part of the TYPO3 project. The TYPO3 project is      *
*  free software; you can redistribute it and/or modify                *
*  it under the terms of the GNU General Public License as published   *
*  by the Free Software Foundation; either version 2 of the License,   *
*  or (at your option) any later version.                              *
*                                                                      *
*  The GNU General Public License can be found at                      *
*  http://www.gnu.org/copyleft/gpl.html.                               *
*                                                                      *
*  This script is distributed in the hope that it will be useful,      *
*  but WITHOUT ANY WARRANTY; without even the implied warranty of      *
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the       *
*  GNU General Public License for more details.                        *
*                                                                      *
*  This copyright notice MUST APPEAR in all copies of the script!      *
*                                                                      */

use Mittwald\Typo3Forum\Domain\Model\Forum\Tag;
use Mittwald\Typo3Forum\Domain\Repository\Forum\TagRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Validation\Validator\AbstractValidator;

class TagValidator extends AbstractValidator
{
    protected TagRepository $tagRepository;

    public function __construct()
    {
        $this->tagRepository = GeneralUtility::makeInstance(TagRepository::class);
    }

    /**
    * Check if $value is valid. If it is not valid, needs to add an error
    * to Result.
    *
    * @param Tag $name
    * @return void
    */
    protected function isValid($tag):void
    {
        if (!$tag instanceof Tag) {
            //return false;
        }

        $result = true;

        $name = $tag->getName();
        if (trim($name) === '') {
            $this->addError('The name can\'t be empty!.', 1373871955);
            $result = false;
        }
        $name = ucwords($name);
        $res = $this->tagRepository->findOneByName($name);
        if ($res !== null) {
            $this->addError('The tag already exists!.', 1373871960);
            $result = false;
        }
    }
}
