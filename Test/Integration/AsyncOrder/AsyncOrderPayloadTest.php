<?php

declare(strict_types=1);

namespace Mondu\Mondu\Test\Integration\AsyncOrder;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State as AppState;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\Order\Payment;
use Mondu\Mondu\Model\Payment\AsyncOrderFields as F;
use Mondu\Mondu\Model\Request\CreateAsyncOrder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the buyer/owners part of the POST /orders/create_async payload.
 *
 * Rules enforced by the API (verified against the sandbox):
 *  - buyer.registration_id is mandatory for `invoice`
 *  - buyer.legal_form_category is optional
 *  - `owners` (with birth_date) is mandatory only for `einzelunternehmen`,
 *    and an owner without birth_date is rejected — so we never send one.
 */
class AsyncOrderPayloadTest extends TestCase
{
    private $om;
    private ReflectionMethod $buildPayload;

    protected function setUp(): void
    {
        $this->om = ObjectManager::getInstance();

        try {
            $this->om->get(AppState::class)->setAreaCode('adminhtml');
        } catch (\Throwable $e) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
            // area code already set by another test
        }

        $this->buildPayload = new ReflectionMethod(CreateAsyncOrder::class, 'buildPayload');
    }

    public function testInvoicePayloadCarriesRegistrationIdAndNoOwners(): void
    {
        $payload = $this->buildPayloadFor([
            F::FIELD_REGISTRATION_ID => '86653938',
            F::FIELD_NET_TERM        => 30,
        ]);

        $this->assertSame('86653938', $payload['buyer']['registration_id']);
        $this->assertArrayNotHasKey('legal_form_category', $payload['buyer']);
        $this->assertArrayNotHasKey('owners', $payload, 'owners must be omitted without a sole trader category');
        $this->assertSame(30, $payload['net_term']);
    }

    public function testSoleTraderPayloadCarriesOwnersWithBirthDate(): void
    {
        $payload = $this->buildPayloadFor([
            F::FIELD_REGISTRATION_ID     => '86653938',
            F::FIELD_NET_TERM            => 30,
            F::FIELD_LEGAL_FORM_CATEGORY => F::LEGAL_FORM_CATEGORY_SOLE_TRADER,
            F::FIELD_OWNER_FIRST_NAME    => 'Erika',
            F::FIELD_OWNER_LAST_NAME     => 'Musterfrau',
            F::FIELD_OWNER_BIRTH_DATE    => '1980-05-14',
        ]);

        $this->assertSame('einzelunternehmen', $payload['buyer']['legal_form_category']);
        $this->assertSame(
            [['first_name' => 'Erika', 'last_name' => 'Musterfrau', 'birth_date' => '1980-05-14']],
            $payload['owners']
        );
    }

    public function testOtherCategoriesDoNotSendOwners(): void
    {
        $payload = $this->buildPayloadFor([
            F::FIELD_REGISTRATION_ID     => '86653938',
            F::FIELD_NET_TERM            => 30,
            F::FIELD_LEGAL_FORM_CATEGORY => 'kapital_und_personen_gesellschaft',
        ]);

        $this->assertSame('kapital_und_personen_gesellschaft', $payload['buyer']['legal_form_category']);
        $this->assertArrayNotHasKey('owners', $payload);
    }

    public function testSoleTraderWithoutBirthDateSendsNoOwners(): void
    {
        $payload = $this->buildPayloadFor([
            F::FIELD_REGISTRATION_ID     => '86653938',
            F::FIELD_NET_TERM            => 30,
            F::FIELD_LEGAL_FORM_CATEGORY => F::LEGAL_FORM_CATEGORY_SOLE_TRADER,
            F::FIELD_OWNER_FIRST_NAME    => 'Erika',
            F::FIELD_OWNER_LAST_NAME     => 'Musterfrau',
        ]);

        $this->assertArrayNotHasKey(
            'owners',
            $payload,
            'an owner without birth_date would be rejected by the API'
        );
    }

    public function testLegalFormIsNoLongerSent(): void
    {
        $payload = $this->buildPayloadFor([
            F::FIELD_REGISTRATION_ID => '86653938',
            F::FIELD_NET_TERM        => 30,
        ]);

        $this->assertArrayNotHasKey('legal_form', $payload['buyer']);
    }

    public function testRegistrationIdFallsBackToBillingAddress(): void
    {
        $payload = $this->buildPayloadFor([F::FIELD_NET_TERM => 30], '12345678');

        $this->assertSame('12345678', $payload['buyer']['registration_id']);
    }

    /**
     * @param array<string,mixed> $additionalInformation
     */
    private function buildPayloadFor(
        array $additionalInformation,
        ?string $addressRegistrationId = null,
        string $methodCode = 'mondu'
    ): array {
        $request = $this->om->create(CreateAsyncOrder::class);

        return $this->buildPayload->invoke(
            $request,
            $this->createOrder($additionalInformation, $addressRegistrationId, $methodCode)
        );
    }

    /**
     * @param array<string,mixed> $additionalInformation
     */
    private function createOrder(
        array $additionalInformation,
        ?string $addressRegistrationId,
        string $methodCode
    ): Order {
        /** @var Order $order */
        $order = $this->om->create(Order::class);
        $order->setIncrementId('TEST-' . bin2hex(random_bytes(4)))
            ->setBaseCurrencyCode('EUR')
            ->setBaseGrandTotal(119.00)
            ->setBaseTaxAmount(19.00)
            ->setBaseShippingAmount(0.00)
            ->setCustomerEmail('accepted.test@example.com')
            ->setCustomerFirstname('Max')
            ->setCustomerLastname('Mustermann');

        /** @var Payment $payment */
        $payment = $this->om->create(Payment::class);
        $payment->setMethod($methodCode);
        foreach ($additionalInformation as $field => $value) {
            $payment->setAdditionalInformation($field, $value);
        }
        $order->setPayment($payment);

        $order->setBillingAddress($this->createAddress(Address::TYPE_BILLING, $addressRegistrationId));
        $order->setShippingAddress($this->createAddress(Address::TYPE_SHIPPING, null));

        /** @var Item $item */
        $item = $this->om->create(Item::class);
        $item->setName('My Product')
            ->setBasePrice(100.00)
            ->setProductId(1)
            ->setItemId(1)
            ->setQtyOrdered(1)
            ->setSku('SKU-1')
            ->setProductType('simple');
        $order->setItems([$item]);

        return $order;
    }

    private function createAddress(string $type, ?string $registrationId): Address
    {
        /** @var Address $address */
        $address = $this->om->create(Address::class);
        $address->setAddressType($type)
            ->setFirstname('Max')
            ->setLastname('Mustermann')
            ->setCompany('Mondu GmbH')
            ->setStreet(['Alexanderplatz 1'])
            ->setCity('Berlin')
            ->setPostcode('10115')
            ->setCountryId('DE')
            ->setTelephone('+49300000000')
            ->setEmail('accepted.test@example.com');

        if ($registrationId !== null) {
            $address->setData('registration_id', $registrationId);
        }

        return $address;
    }
}
