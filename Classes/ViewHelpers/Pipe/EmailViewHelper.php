<?php
namespace FluidTYPO3\Flux\ViewHelpers\Pipe;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Outlet\Pipe\EmailPipe;
use FluidTYPO3\Flux\Outlet\Pipe\PipeInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;

/**
 * Email Outlet Pipe ViewHelper
 *
 * Adds an EmailPipe to the Form's Outlet
 */
class EmailViewHelper extends AbstractPipeViewHelper
{
    /**
     * @var boolean
     */
    protected $escapeChildren = false;

    /**
     * @return void
     */
    public function initializeArguments()
    {
        parent::initializeArguments();
        $this->registerArgument('body', 'string', 'Message body. Can also be inserted as tag content');
        $this->registerArgument('bodySection', 'string', 'Section to use for the body');
        $this->registerArgument('subject', 'string', 'Message subject', true);
        $this->registerArgument(
            'recipient',
            'string',
            'Message recipient address or name+address as "Name <add@ress>"',
            true
        );
        $this->registerArgument(
            'sender',
            'string',
            'Message sender address or name+address as "Name <add@ress>"',
            true
        );
    }

    /**
     * @param RenderingContextInterface $renderingContext
     * @param iterable $arguments
     * @param \Closure $renderChildrenClosure
     * @return PipeInterface
     */
    protected static function preparePipeInstance(
        RenderingContextInterface $renderingContext,
        iterable $arguments,
        \Closure $renderChildrenClosure = null
    ) {
        /** @var array $arguments */
        $body = $arguments['body'];
        if (true === empty($body) && $renderChildrenClosure instanceof \Closure) {
            $body = $renderChildrenClosure();
        }
        /** @var EmailPipe $pipe */
        $pipe = GeneralUtility::makeInstance(EmailPipe::class);
        $pipe->setSubject($arguments['subject']);
        $pipe->setSender($arguments['sender']);
        $pipe->setRecipient($arguments['recipient']);
        $pipe->setBody($body);
        $pipe->setBodySection($arguments['bodySection']);

        return $pipe;
    }
}
