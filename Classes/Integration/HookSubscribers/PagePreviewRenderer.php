<?php
namespace FluidTYPO3\Flux\Integration\HookSubscribers;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Integration\PreviewView;
use FluidTYPO3\Flux\Provider\PageProvider;
use TYPO3\CMS\Backend\Controller\PageLayoutController;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Object\ObjectManagerInterface;

/**
 * Class PagePreviewRenderer
 */
class PagePreviewRenderer
{
    /**
     * @param array $params
     * @param PageLayoutController $pageLayoutController
     * @return string
     */
    public function render(array $params, PageLayoutController $pageLayoutController)
    {
        $pageProvider = $this->getPageProvider();
        $previewContent = '';

        $row = $this->getRecord($pageLayoutController->id);
        if (!$row) {
            return '';
        }

        $form = $pageProvider->getForm($row);

        if ($form && $form->getEnabled()) {
            // Force the preview to *not* generate content column HTML in preview
            $form->setOption(PreviewView::OPTION_PREVIEW, [
                PreviewView::OPTION_MODE => PreviewView::MODE_NONE
            ]);

            list(, $previewContent, ) = $pageProvider->getPreview($row);
        }

        return $previewContent;
    }

    /**
     * @param integer $uid
     * @return array|null
     * @codeCoverageIgnore
     */
    protected function getRecord($uid)
    {
        return BackendUtility::getRecord('pages', $uid);
    }

    /**
     * @return PageProvider
     * @codeCoverageIgnore
     */
    protected function getPageProvider()
    {
        /** @var ObjectManagerInterface $objectManager */
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        /** @var PageProvider $pageProvider */
        $pageProvider = $objectManager->get(PageProvider::class);
        return $pageProvider;
    }
}
