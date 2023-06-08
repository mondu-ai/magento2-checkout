<?php
namespace Mondu\Mondu\Model;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Model\AbstractModel;

class MonduTransactionItem extends AbstractModel implements IdentityInterface
{
    public const CACHE_TAG = 'mondu_mondu_mondu_transaction_item';

    /**
     * Model cache tag for clear cache in after save and after delete
     *
     * @var string
     */
    protected $_cacheTag = self::CACHE_TAG;

    /**
     * Prefix of model events names
     *
     * @var string
     */
    protected $_eventPrefix = 'mondu_transaction_item';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(\Mondu\Mondu\Model\ResourceModel\MonduTransactionItem::class);
    }

    /**
     * Return a unique id for the model.
     *
     * @return array
     */
    public function getIdentities()
    {
        return [self::CACHE_TAG . '_' . $this->getId()];
    }

    /**
     * DeleteRecordsForTransaction
     *
     * @param int $transactionId
     * @return void
     */
    public function deleteRecordsForTransaction($transactionId)
    {
        $this->getResource()->deleteRecords($transactionId);
    }
}
