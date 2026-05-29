<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Unit\Model\Payment\Source;

use Mondu\Mondu\Model\Payment\Source\LegalForm;
use PHPUnit\Framework\TestCase;

class LegalFormTest extends TestCase
{
    public function testToOptionArrayShapeAndNonEmpty(): void
    {
        $options = (new LegalForm())->toOptionArray();
        $this->assertNotEmpty($options);

        foreach ($options as $opt) {
            $this->assertArrayHasKey('value', $opt);
            $this->assertArrayHasKey('label', $opt);
            $this->assertIsString($opt['value']);
            $this->assertNotSame('', $opt['value']);
        }
    }

    public function testCommonGermanFormsPresent(): void
    {
        $options = (new LegalForm())->toOptionArray();
        $values = array_column($options, 'value');

        $this->assertContains('GmbH', $values);
        $this->assertContains('sole_trader', $values);
        $this->assertContains('freelancer', $values);
    }
}
