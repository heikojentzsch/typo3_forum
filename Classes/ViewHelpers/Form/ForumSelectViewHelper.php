<?php
namespace Mittwald\Typo3Forum\ViewHelpers\Form;

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

use Mittwald\Typo3Forum\Domain\Model\Forum\Forum;
use Mittwald\Typo3Forum\Domain\Repository\Forum\ForumRepository;
use TYPO3\CMS\Fluid\ViewHelpers\Form\AbstractFormFieldViewHelper;
use TYPO3\CMS\Fluid\ViewHelpers\Form\SelectViewHelper;
use TYPO3\CMS\Fluid\ViewHelpers\Form\Select\OptionViewHelper;

/**
 * ViewHelper that renders a selectbox with a hierarchical list of all forums.
 */
class ForumSelectViewHelper extends AbstractFormFieldViewHelper
{
    protected ForumRepository $forumRepository;

    public function __construct(
        ForumRepository $forumRepository
    ) {
        parent::__construct();

        $this->forumRepository = $forumRepository;
    }

    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('multiple', 'bool', 'Allow multiple selected forums', false, false);
        $this->registerArgument('errorClass', 'string', 'Validation error CSS class', false, 'f3-form-error');
    }

    public function render(): string
    {
        return $this->renderingContext->getViewHelperInvoker()->invoke(
            SelectViewHelper::class,
            array_merge($this->arguments, $this->additionalArguments),
            $this->renderingContext,
            fn() => $this->renderOptionTags($this->getOptions())
        );
    }

    protected function getOptions(): array
    {
        $rootForums = $this->forumRepository->findRootForums();
        $values = [];

        foreach ($rootForums as $rootForum) {
            $values[] = $this->getForumOptionRow($rootForum, true);
        }
        return $values;
    }

    /**
     * Recursively generates option rows for a forum and each subforum of this forum.
     */
    protected function getForumOptionRow(Forum $forum, bool $isRoot = false): array
    {
        $result = [
            'name' => $forum->getTitle(),
            'uid' => $forum->getUid(),
            '_isRoot' => $isRoot,
            '_children' => []
        ];
        foreach ($forum->getChildren() as $childForum) {
            $result['_children'][] = $this->getForumOptionRow($childForum, false);
        }
        return $result;
    }

    /**
     * Recursively renders all option tags.
     *
     * @param   array $options All option rows.
     * @param int $nestingLevel The current nesting level. Required for correct formatting.
     * @return string
     */
    protected function renderOptionTags($options, $nestingLevel = 1)
    {
        $content = '';
        foreach ($options as $option) {
            if ($option['_isRoot']) {
                $content .= '<optgroup label="' . htmlspecialchars($option['name']) . '">' . chr(10);
                $content .= $this->renderOptionTags($option['_children'], $nestingLevel + 1);
                $content .= '</optgroup>';
            } else {
                $indent = ($nestingLevel - 1) * 20;
                $style = 'padding-left: ' . $indent . 'px;';
                $content .= $this->renderingContext->getViewHelperInvoker()->invoke(
                    OptionViewHelper::class,
                    ['value' => (string)$option['uid'], 'style' => $style],
                    $this->renderingContext,
                    static fn() => htmlspecialchars($option['name'])
                ) . chr(10);
                $content .= $this->renderOptionTags($option['_children'], $nestingLevel + 1);
            }
        }
        return $content;
    }
}
