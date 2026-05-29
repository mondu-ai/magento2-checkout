<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Unit\Model\Payment\Source;

use Mondu\Mondu\Model\Payment\Source\Installments;
use PHPUnit\Framework\TestCase;

class InstallmentsTest extends TestCase
{
    public function testReturnsSupportedTerms(): void
    {
        $options = (new Installments())->toOptionArray();
        $values = array_column($options, 'value');

        $this->assertSame([3, 6, 12], $values);
    }
}
