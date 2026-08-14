<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\AsyncOrder;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State as AppState;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote\Payment;
use Mondu\Mondu\Model\Payment\AsyncOrderFields as F;
use Mondu\Mondu\Observer\Payment\DataAssignObserver;
use PHPUnit\Framework\TestCase;

/**
 * Covers the admin-side validation of the Mondu order-create fields.
 *
 * The observer copies the POSTed fields into payment.additional_information and
 * rejects incomplete input before the order reaches POST /orders/create_async.
 */
class AsyncOrderFieldValidationTest extends TestCase
{
    private $om;
    private DataAssignObserver $observer;

    protected function setUp(): void
    {
        $this->om = ObjectManager::getInstance();

        try {
            $this->om->get(AppState::class)->setAreaCode('adminhtml');
        } catch (\Throwable $e) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            // area code already set by another test
        }

        $this->observer = $this->om->create(DataAssignObserver::class);
    }

    public function testInvoiceStoresRegistrationIdAndNetTerm(): void
    {
        $payment = $this->assign('mondu', [
            F::FIELD_REGISTRATION_ID => '86653938',
            F::FIELD_NET_TERM        => '30',
        ]);

        $this->assertSame('86653938', $payment->getAdditionalInformation(F::FIELD_REGISTRATION_ID));
        $this->assertSame('30', $payment->getAdditionalInformation(F::FIELD_NET_TERM));
    }

    public function testInvoiceRejectsMissingRegistrationId(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Registration ID/');

        $this->assign('mondu', [F::FIELD_NET_TERM => '30']);
    }

    public function testSoleTraderRequiresOwnerFields(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Owner first name, Owner last name, Owner date of birth/');

        $this->assign('mondu', [
            F::FIELD_REGISTRATION_ID     => '86653938',
            F::FIELD_NET_TERM            => '30',
            F::FIELD_LEGAL_FORM_CATEGORY => F::LEGAL_FORM_CATEGORY_SOLE_TRADER,
        ]);
    }

    public function testSoleTraderWithOwnerFieldsPasses(): void
    {
        $payment = $this->assign('mondu', [
            F::FIELD_REGISTRATION_ID     => '86653938',
            F::FIELD_NET_TERM            => '30',
            F::FIELD_LEGAL_FORM_CATEGORY => F::LEGAL_FORM_CATEGORY_SOLE_TRADER,
            F::FIELD_OWNER_FIRST_NAME    => 'Erika',
            F::FIELD_OWNER_LAST_NAME     => 'Musterfrau',
            F::FIELD_OWNER_BIRTH_DATE    => '1980-05-14',
        ]);

        $this->assertSame('1980-05-14', $payment->getAdditionalInformation(F::FIELD_OWNER_BIRTH_DATE));
    }

    public function testOtherCategoryDoesNotRequireOwnerFields(): void
    {
        $payment = $this->assign('mondu', [
            F::FIELD_REGISTRATION_ID     => '86653938',
            F::FIELD_NET_TERM            => '30',
            F::FIELD_LEGAL_FORM_CATEGORY => 'kapital_und_personen_gesellschaft',
        ]);

        $this->assertNull($payment->getAdditionalInformation(F::FIELD_OWNER_FIRST_NAME));
    }

    public function testUnknownCategoryIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/unknown legal form category/');

        $this->assign('mondu', [
            F::FIELD_REGISTRATION_ID     => '86653938',
            F::FIELD_NET_TERM            => '30',
            F::FIELD_LEGAL_FORM_CATEGORY => 'GmbH',
        ]);
    }

    public function testBirthDateFormatIsEnforced(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/YYYY-MM-DD/');

        $this->assign('mondu', [
            F::FIELD_REGISTRATION_ID     => '86653938',
            F::FIELD_NET_TERM            => '30',
            F::FIELD_LEGAL_FORM_CATEGORY => F::LEGAL_FORM_CATEGORY_SOLE_TRADER,
            F::FIELD_OWNER_FIRST_NAME    => 'Erika',
            F::FIELD_OWNER_LAST_NAME     => 'Musterfrau',
            F::FIELD_OWNER_BIRTH_DATE    => '14.05.1980',
        ]);
    }

    public function testBirthDateInTheFutureIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/cannot be in the future/');

        $this->assign('mondu', [
            F::FIELD_REGISTRATION_ID     => '86653938',
            F::FIELD_NET_TERM            => '30',
            F::FIELD_LEGAL_FORM_CATEGORY => F::LEGAL_FORM_CATEGORY_SOLE_TRADER,
            F::FIELD_OWNER_FIRST_NAME    => 'Erika',
            F::FIELD_OWNER_LAST_NAME     => 'Musterfrau',
            F::FIELD_OWNER_BIRTH_DATE    => '2099-01-01',
        ]);
    }

    public function testSepaDoesNotRequireRegistrationIdAndNormalisesIban(): void
    {
        $payment = $this->assign('mondusepa', [
            F::FIELD_NET_TERM       => '30',
            F::FIELD_IBAN           => 'de02 1203 0000 0000 2020 51',
            F::FIELD_ACCOUNT_HOLDER => 'Mondu GmbH',
        ]);

        $this->assertSame('DE02120300000000202051', $payment->getAdditionalInformation(F::FIELD_IBAN));
        $this->assertNull($payment->getAdditionalInformation(F::FIELD_REGISTRATION_ID));
    }

    public function testPayNowHasNoRequiredFields(): void
    {
        $payment = $this->assign('mondupaynow', []);

        $this->assertNull($payment->getAdditionalInformation(F::FIELD_NET_TERM));
    }

    /**
     * Runs the observer for one POSTed form state and returns the payment it filled.
     *
     * @param array<string,mixed> $postedFields
     * @throws LocalizedException
     */
    private function assign(string $methodCode, array $postedFields): Payment
    {
        /** @var Payment $payment */
        $payment = $this->om->create(Payment::class);
        $payment->setMethod($methodCode);

        $data = new DataObject([PaymentInterface::KEY_ADDITIONAL_DATA => $postedFields]);

        $observer = new Observer();
        $observer->setEvent(new Event(['data' => $data, 'payment_model' => $payment]));
        $observer->setData('data', $data);
        $observer->setData('payment_model', $payment);

        $this->observer->execute($observer);

        return $payment;
    }
}
