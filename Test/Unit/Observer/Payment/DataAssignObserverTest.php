<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Unit\Observer\Payment;

use Magento\Framework\App\State as AppState;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Mondu\Mondu\Model\Payment\AsyncOrderFields;
use Mondu\Mondu\Observer\Payment\DataAssignObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DataAssignObserverTest extends TestCase
{
    /** @var AppState&MockObject */
    private AppState $appState;

    protected function setUp(): void
    {
        $this->appState = $this->createMock(AppState::class);
    }

    public function testCopiesFieldsIntoAdditionalInformationForMondu(): void
    {
        $this->appState->method('getAreaCode')->willReturn('adminhtml');
        $observer = new DataAssignObserver($this->appState);

        $payment = $this->makePaymentMock('mondu');
        $event = $this->makeEvent($payment, [
            AsyncOrderFields::FIELD_LEGAL_FORM => 'GmbH',
            AsyncOrderFields::FIELD_NET_TERM => '30',
        ]);

        $observer->execute($event);

        $this->assertSame('GmbH', $payment->getAdditionalInformation(AsyncOrderFields::FIELD_LEGAL_FORM));
        $this->assertSame('30', $payment->getAdditionalInformation(AsyncOrderFields::FIELD_NET_TERM));
    }

    public function testIgnoresEmptyStringsAndWhitespace(): void
    {
        $this->appState->method('getAreaCode')->willReturn('frontend'); // skip validation
        $observer = new DataAssignObserver($this->appState);

        $payment = $this->makePaymentMock('mondu');
        $event = $this->makeEvent($payment, [
            AsyncOrderFields::FIELD_LEGAL_FORM => '   ',
            AsyncOrderFields::FIELD_NET_TERM => '',
        ]);

        $observer->execute($event);

        $this->assertNull($payment->getAdditionalInformation(AsyncOrderFields::FIELD_LEGAL_FORM));
        $this->assertNull($payment->getAdditionalInformation(AsyncOrderFields::FIELD_NET_TERM));
    }

    public function testThrowsWhenRequiredFieldMissingInAdmin(): void
    {
        $this->appState->method('getAreaCode')->willReturn('adminhtml');
        $observer = new DataAssignObserver($this->appState);

        $payment = $this->makePaymentMock('mondusepa');
        // Only legal_form provided; mondusepa also needs net_term, iban, account_holder.
        $event = $this->makeEvent($payment, [
            AsyncOrderFields::FIELD_LEGAL_FORM => 'GmbH',
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Net term.*IBAN.*Account holder/');
        $observer->execute($event);
    }

    public function testSkipsValidationOutsideAdminArea(): void
    {
        $this->appState->method('getAreaCode')->willReturn('frontend');
        $observer = new DataAssignObserver($this->appState);

        $payment = $this->makePaymentMock('mondusepa');
        $event = $this->makeEvent($payment, []); // nothing — should NOT throw on frontend

        $observer->execute($event);
        $this->addToAssertionCount(1);
    }

    public function testAcceptsDirectDataObjectWhenNoAdditionalDataKey(): void
    {
        $this->appState->method('getAreaCode')->willReturn('frontend');
        $observer = new DataAssignObserver($this->appState);

        $payment = $this->makePaymentMock('mondu');

        // No nested 'additional_data' key — fields are top-level on DataObject.
        $data = new DataObject([
            AsyncOrderFields::FIELD_LEGAL_FORM => 'AG',
        ]);
        $event = new Observer([
            'event' => new Event([
                'data' => $data,
                AbstractDataAssignObserver::MODEL_CODE => $payment,
            ]),
        ]);

        $observer->execute($event);

        $this->assertSame('AG', $payment->getAdditionalInformation(AsyncOrderFields::FIELD_LEGAL_FORM));
    }

    private function makePaymentMock(string $methodCode): QuotePayment
    {
        $payment = $this->getMockBuilder(QuotePayment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMethod'])
            ->getMock();
        $payment->method('getMethod')->willReturn($methodCode);

        return $payment;
    }

    private function makeEvent(QuotePayment $payment, array $additionalData): Observer
    {
        $data = new DataObject([
            PaymentInterface::KEY_ADDITIONAL_DATA => $additionalData,
        ]);

        return new Observer([
            'event' => new Event([
                'data' => $data,
                AbstractDataAssignObserver::MODEL_CODE => $payment,
            ]),
        ]);
    }
}
