<?php

namespace Mittwald\Typo3Forum\ViewHelpers\User;

/*                                                                    - *
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

use Mittwald\Typo3Forum\Domain\Model\User\FrontendUser;
use Mittwald\Typo3Forum\Domain\Model\User\FrontendUserGroup;
use TYPO3\CMS\Fluid\ViewHelpers\Uri\ActionViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

class LinkViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    /**
     * @var array
     */
    protected $settings;

    /**
     * Initialize viewHelper and add given settings
     *
     * @throws \TYPO3\CMS\Fluid\Core\ViewHelper\Exception\InvalidVariableException
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->settings = $this->renderingContext->getVariableProvider()->get('settings') ?? [];
    }

    /**
     * Initialize required arguments
     *
     * @throws \TYPO3\CMS\Fluid\Core\ViewHelper\Exception
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('class', 'string', 'CSS class.');
        $this->registerArgument('style', 'string', 'CSS inline styles.');
        $this->registerArgument('user', FrontendUser::class, 'User', true);
        $this->registerArgument('showOnlineStatus', 'boolean', 'Show if user is online', false, true);
        $this->registerArgument('showOnline', 'boolean', 'Is user online?', false, false);
    }

    /**
     * render.
     * @return string
     */
    public function render()
    {
        $user = $this->arguments['user'];
        $showOnlineStatus = $this->arguments['showOnlineStatus'];
        $showOnline = $this->arguments['showOnline'];

        // if user anonymous: show only the username
        if ($user->isAnonymous()) {
            return htmlspecialchars($user->getUsername());
        }

        $uri = $this->renderingContext->getViewHelperInvoker()->invoke(
            ActionViewHelper::class,
            ['pageUid' => (int)($this->settings['pids']['UserShow'] ?? 0),
                'extensionName' => 'Typo3Forum', 'pluginName' => 'UserProfile',
                'controller' => 'User', 'action' => 'show', 'arguments' => ['user' => $user->getUid()]],
            $this->renderingContext,
            static fn() => ''
        );
        $uri = htmlspecialchars($uri);

        $class = '';

        if ($this->hasArgument('class')) {
            $class = htmlspecialchars($this->arguments['class']);
        }

        $fullUsername = htmlspecialchars($user->getUsername());
        $limit = (int)($this->settings['cutUsernameOnChar'] ?? 0);
        if ($limit == 0 || mb_strlen($user->getUsername()) <= $limit) {
            $username = $fullUsername;
        } else {
            $username = htmlspecialchars(mb_substr($user->getUsername(), 0, $limit)) . '...';
        }
        $moderatorMark = '';
        if (isset($this->settings['moderatorMark']['image'])) {
            /** @var FrontendUserGroup $group */
            foreach ($user->getUsergroup() as $group) {
                if ($group->getUserMod()) {
                    $moderatorMark = '<img src="' . htmlspecialchars($this->settings['moderatorMark']['image']) . '" title="' . htmlspecialchars($this->settings['moderatorMark']['title'] ?? '') . '" />';
                    break;
                }
            }
        }

        if ($showOnlineStatus) {
            if ($showOnline) {
                $onlineStatus = 'user_onlinepoint iconset-8-user-online';
            } else {
                $onlineStatus = 'user_onlinepoint iconset-8-user-offline';
            }
            $link = '<a href="' . $uri . '" class="' . $class . '" title="' . $fullUsername . '">' . $username . ' <i class="' . $onlineStatus . '" data-uid="' . $user->getUid() . '"></i> ' . $moderatorMark . '</a>';
        } else {
            $link = '<a href="' . $uri . '" class="' . $class . '" title="' . $fullUsername . '">' . $username . ' ' . $moderatorMark . '</a>';
        }

        return $link;
    }

}
