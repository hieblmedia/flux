<?php
namespace FluidTYPO3\Flux\Tests\Unit\Provider;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Provider\SubPageProvider;
use FluidTYPO3\Flux\Service\PageService;
use FluidTYPO3\Flux\Tests\Unit\AbstractTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Fluid\View\TemplatePaths;

/**
 * Class SubPageProviderTest
 */
class SubPageProviderTest extends AbstractTestCase
{

    /**
     * @dataProvider getControllerActionFromRecordTestValues
     * @param array $record
     * @param string $fieldName
     * @param string $expected
     */
    public function testGetControllerActionFromRecord(array $record, $fieldName, $expected)
    {
        $instance = new SubPageProvider();
        $service = $this->getMockBuilder(PageService::class)->setMethods(['getPageTemplateConfiguration'])->getMock();
        $service->expects($this->any())->method('getPageTemplateConfiguration')->willReturn($record);
        $instance->injectPageService($service);
        // make sure PageProvider is now using the right field name
        $instance->trigger($record, null, $fieldName);
        $result = $instance->getControllerActionFromRecord($record);
        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function getControllerActionFromRecordTestValues()
    {
        return array(
            array(array('uid' => 123, 'tx_fed_page_controller_action_sub' => ''), 'tx_fed_page_flexform_sub', 'default'),
            array(array('uid' => 123, 'tx_fed_page_controller_action_sub' => 'flux->action'), 'tx_fed_page_flexform_sub', 'action'),
        );
    }

    public function testGetTemplatePathAndFilename()
    {
        $expected = 'Tests/Fixtures/Templates/Page/Dummy.html';
        $dataFieldName = 'tx_fed_page_flexform_sub';
        $fieldName = 'tx_fed_page_controller_action_sub';
        /** @var PageService|MockObject $service */
        $service = $this->getMockBuilder(PageService::class)->setMethods(['getPageTemplateConfiguration'])->getMock();
        $templatePaths = $this->getMockBuilder(TemplatePaths::class)->setMethods(['resolveTemplateFileForControllerAndActionAndFormat'])->getMock();
        $templatePaths->method('resolveTemplateFileForControllerAndActionAndFormat')->willReturn($expected);
        $instance = $this->getMockBuilder(SubPageProvider::class)->setMethods(['createTemplatePaths'])->getMock();
        $instance->method('createTemplatePaths')->willReturn($templatePaths);
        $instance->injectPageService($service);
        $record = array(
            'uid' => 123,
            $fieldName => 'Flux->dummy',
        );
        $service->expects($this->any())->method('getPageTemplateConfiguration')->willReturn($record);
        $instance->trigger($record, null, $dataFieldName);
        $result = $instance->getTemplatePathAndFilename($record);
        $this->assertEquals($expected, $result);
    }
}
