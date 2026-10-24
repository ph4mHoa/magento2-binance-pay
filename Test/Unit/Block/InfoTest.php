<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Test\Unit\Block;

use JoePham\BinancePay\Block\Info;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Gateway\ConfigInterface;
use PHPUnit\Framework\TestCase;

class InfoTest extends TestCase
{
    public function testBinanceIdsAreShownInTheAdmin(): void
    {
        $this->assertFalse($this->block(Area::AREA_ADMINHTML)->getIsSecureMode());
    }

    public function testBinanceIdsAreHiddenFromShoppers(): void
    {
        $this->assertTrue($this->block(Area::AREA_FRONTEND)->getIsSecureMode());
    }

    public function testEmailsAndPdfsPrintedFromTheAdminStayInSecureMode(): void
    {
        $block = $this->block(Area::AREA_ADMINHTML);
        $block->setIsSecureMode(true);

        $this->assertTrue($block->getIsSecureMode());
    }

    /**
     * @param string $area
     * @return Info
     */
    private function block(string $area): Info
    {
        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willReturn($area);
        $context = $this->createMock(Context::class);
        $context->method('getAppState')->willReturn($appState);

        return new Info($context, $this->createMock(ConfigInterface::class));
    }
}
