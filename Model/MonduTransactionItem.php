<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Mondu\Mondu\Model\ResourceModel\MonduTransactionItem as MonduTransactionItemResource;

class MonduTransactionItem extends AbstractModel implements IdentityInterface
{
    public const CACHE_TAG = 'mondu_mondu_mondu_transaction_item';

    /**
     * Model cache tag for clear cache in after save and after delete.
     *
     * @var string
     */
    protected $_cacheTag = self::CACHE_TAG;

    /**
     * Prefix of model events names.
     *
     * @var string
     */
    protected $_eventPrefix = 'mondu_transaction_item';

    /**
     * Initialize resource model.
     *
     * @throws LocalizedException
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(MonduTransactionItemResource::class);
    }

    /**
     * Return a unique id for the model.
     *
     * @return array
     */
    public function getIdentities(): array
    {
        return [self::CACHE_TAG . '_' . $this->getId()];
    }

    /**
     * DeleteRecordsForTransaction.
     *
     * @param int $transactionId
     * @return void
     */
    public function deleteRecordsForTransaction($transactionId): void
    {
        $this->getResource()->deleteRecords($transactionId);
    }
}
