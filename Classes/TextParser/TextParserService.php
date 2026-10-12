<?php

namespace Mittwald\Typo3Forum\TextParser;

use Mittwald\Typo3Forum\Domain\Model\Forum\Post;

/*                                                                    - *
 *  COPYRIGHT NOTICE                                                    *
 *                                                                      *
 *  (c) 2015 Mittwald CM Service GmbH & Co KG                           *
 *           All rights reserved                                        *
 *                                                                      *
 *  This script is part of the TYPO3 project. The TYPO3 project is      *
 *  free software; you can redistribute it and/or modify                *
 *  it under the terms of the GNU General public License as published   *
 *  by the Free Software Foundation; either version 2 of the License,   *
 *  or (at your option) any later version.                              *
 *                                                                      *
 *  The GNU General public License can be found at                      *
 *  http://www.gnu.org/copyleft/gpl.html.                               *
 *                                                                      *
 *  This script is distributed in the hope that it will be useful,      *
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of      *
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the       *
 *  GNU General public License for more details.                        *
 *                                                                      *
 *  This copyright notice MUST APPEAR in all copies of the script!      *
 *                                                                      */

class TextParserService
{
    public function parseText(
        string $text,
        ?Post $post = null
    ): string {
        if ($post !== null && $post->getTextHtml() !== null) {
            return (new \Mittwald\Typo3Forum\RichText\Html())->sanitize($post->getTextHtml());
        }
        return (new \Mittwald\Typo3Forum\RichText\LegacyConverter())->convert($text)['html'];
    }
}
