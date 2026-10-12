<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\ViewHelpers\Form;

use Mittwald\Typo3Forum\RichText\Html;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Fluid\ViewHelpers\Form\AbstractFormFieldViewHelper;
use TYPO3\CMS\Fluid\ViewHelpers\Form\TextareaViewHelper;

final class RichTextEditorViewHelper extends AbstractFormFieldViewHelper
{
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('id', 'string', 'Editor field ID', false, 'forum-editor');
    }

    public function render(): string
    {
        $collector = GeneralUtility::makeInstance(AssetCollector::class);
        $collector->addJavaScript('typo3forum-editor', 'EXT:typo3_forum/Resources/Public/Editor/editor.js', ['type' => 'module']);
        $collector->addStyleSheet('typo3forum-editor', 'EXT:typo3_forum/Resources/Public/CSS/editor.css');
        $label = static fn (string $key): string => Html::escape(LocalizationUtility::translate('editor.' . $key, 'Typo3Forum') ?? $key);
        $controls = '<div class="forum-editor-controls"></div>';
        $textarea = $this->renderingContext->getViewHelperInvoker()->invoke(TextareaViewHelper::class, array_merge($this->arguments, $this->additionalArguments), $this->renderingContext);
        return '<div class="forum-rich-editor" data-loading="' . $label('loading') . '" data-ready="' . $label('ready') . '" data-error="' . $label('error') . '">'
            . $controls . $textarea . '<div class="forum-editor-host"></div><p role="status" aria-live="polite"></p></div>';
    }
}
