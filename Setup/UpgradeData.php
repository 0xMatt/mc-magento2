<?php
/**
 * mc-magento2 Magento Component
 *
 * @category Ebizmarts
 * @package mc-magento2
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @date: 10/31/16 3:28 PM
 * @file: UpgradeData.php
 */
namespace Ebizmarts\MailChimp\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\DeploymentConfig;
use Magento\Sales\Model\OrderFactory;
use Ebizmarts\MailChimp\Model\ResourceModel\MailChimpSyncEcommerce\CollectionFactory as SyncCollectionFactory;

class UpgradeData implements UpgradeDataInterface
{
    /**
     * @var ResourceConnection
     */
    protected $_resource;
    /**
     * @var DeploymentConfig
     */
    protected $_deploymentConfig;
    /**
     * @var \Ebizmarts\MailChimp\Model\ResourceModel\MailChimpInterestGroup\CollectionFactory
     */
    protected $_insterestGroupCollectionFactory;
    /**
     * @var \Ebizmarts\MailChimp\Model\ResourceModel\MailChimpWebhookRequest\CollectionFactory
     */
    protected $_webhookCollectionFactory;
    /**
     * @var \Ebizmarts\MailChimp\Helper\Data
     */
    protected $_helper;
    /**
     * @var \Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory
     */
    protected $configFactory;
    /**
     * @var SyncCollectionFactory
     */
    private $syncCollectionFactory;
    /**
     * @var OrderFactory
     */
    private $orderFactory;
    /**
     * @param ResourceConnection $resource
     * @param DeploymentConfig $deploymentConfig
     * @param \Ebizmarts\MailChimp\Model\ResourceModel\MailChimpInterestGroup\CollectionFactory $interestGroupCollectionFactory
     * @param \Ebizmarts\MailChimp\Model\ResourceModel\MailChimpWebhookRequest\CollectionFactory $webhookCollectionFactory
     * @param \Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory $configFactory
     * @param SyncCollectionFactory $syncCollectionFactory
     * @param OrderFactory $orderFactory
     * @param \Ebizmarts\MailChimp\Helper\Data $helper
     */
    public function __construct(
        ResourceConnection $resource,
        DeploymentConfig $deploymentConfig,
        \Ebizmarts\MailChimp\Model\ResourceModel\MailChimpInterestGroup\CollectionFactory $interestGroupCollectionFactory,
        \Ebizmarts\MailChimp\Model\ResourceModel\MailChimpWebhookRequest\CollectionFactory $webhookCollectionFactory,
        \Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory $configFactory,
        SyncCollectionFactory $syncCollectionFactory,
        OrderFactory $orderFactory,
        \Ebizmarts\MailChimp\Helper\Data $helper
    ) {
        $this->_resource            = $resource;
        $this->_deploymentConfig    = $deploymentConfig;
        $this->_insterestGroupCollectionFactory = $interestGroupCollectionFactory;
        $this->_webhookCollectionFactory        = $webhookCollectionFactory;
        $this->configFactory                    = $configFactory;
        $this->syncCollectionFactory            = $syncCollectionFactory;
        $this->orderFactory                     = $orderFactory;
        $this->_helper              = $helper;
    }

    /**
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface $context
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        if (version_compare($context->getVersion(), '1.0.24') < 0) {
            $setup->startSetup();
            $connection = $this->_resource->getConnectionByName('default');
            if ($this->_deploymentConfig->get(
                \Magento\Framework\Config\ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTIONS . '/sales'
            )
            ) {
                    $salesConnection = $this->_resource->getConnectionByName('sales');
            } else {
                    $salesConnection = $connection;
            }
            $table = $setup->getTable('sales_order');
            $select = $salesConnection->select()
                ->from(
                    false,
                    ['mailchimp_flag' => new \Zend_Db_Expr('IF(mailchimp_abandonedcart_flag OR mailchimp_campaign_id OR mailchimp_landing_page, 1, 0)')]
                )->join(['O'=>$table], 'O.entity_id = G.entity_id', []);

            $query = $salesConnection->updateFromSelect($select, ['G' => $setup->getTable('sales_order_grid')]);

            $salesConnection->query($query);
            $setup->endSetup();
        }
        if (version_compare($context->getVersion(), '1.2.32') < 0) {
            // delete the old serialized data from core_config_data
            $setup->startSetup();
            $connection = $this->_resource->getConnectionByName('default');
            $table = $setup->getTable('core_config_data');
            try {
                $connection->delete($table, ['path = ?'=> \Ebizmarts\MailChimp\Helper\Data::XML_MERGEVARS]);
            } catch (\Exception $e) {
                $this->_helper->log($e->getMessage());
            }

            // empty table mailchimp_interest_group
            /**
             * @var \Ebizmarts\MailChimp\Model\ResourceModel\MailChimpInterestGroup $item
             */
            $table = $setup->getTable('mailchimp_interest_group');

            try {
                $connection->delete($table);
            } catch (\Exception $e) {
                $this->_helper->log($e->getMessage());
            }
            // convert table mailchimp_webhook_request
            /**
             * @var \Ebizmarts\MailChimp\Model\ResourceModel\MailChimpWebhookRequest $webhookItem
             */
            $lastId = 0;
            $done = false;
            while (!$done) {
                $webhookCollection = $this->_webhookCollectionFactory->create();
                $webhookCollection->addFieldToFilter('processed', ['neq' => 1]);
                $webhookCollection->addFieldToFilter('id', ['gt' => $lastId]);
                $webhookCollection->getSelect()->limit(500);
                if (!$webhookCollection->getSize()) {
                    $done = true;
                } else {
                    foreach ($webhookCollection as $webhookItem) {
                        try {
                            $webhookItem->setProcessed(\Ebizmarts\MailChimp\Cron\Webhook::DATA_NOT_CONVERTED);
                            $webhookItem->getResource()->save($webhookItem);
                        } catch (\Exception $e) {
                            $this->_helper->log($e->getMessage());
                            $webhookItem->setProcesed(\Ebizmarts\MailChimp\Cron\Webhook::DATA_WITH_ERROR);
                            $webhookItem->getResource()->save($webhookItem);
                        }
                        $lastId = $webhookItem->getId();
                    }
                }
            }
        }
        if (version_compare($context->getVersion(), '102.3.35') < 0) {
            $configCollection = $this->configFactory->create();
            $configCollection->addFieldToFilter('path', ['eq' => \Ebizmarts\MailChimp\Helper\Data::XML_PATH_APIKEY]);
            /**
             * @var $config \Magento\Config\Model\ResourceModel\Config
             */
            foreach ($configCollection as $config) {
                try {
                    $config->setValue($this->_helper->encrypt($config->getvalue()));
                    $config->getResource()->save($config);
                } catch (\Exception $e) {
                    $this->_helper->log($e->getMessage());
                }
            }
            $configCollection = $this->configFactory->create();
            $configCollection->addFieldToFilter(
                'path',
                ['eq' => \Ebizmarts\MailChimp\Helper\Data::XML_PATH_APIKEY_LIST]
            );
            foreach ($configCollection as $config) {
                $config->getResource()->delete($config);
            }
        }
        if (version_compare($context->getVersion(), '102.3.52') < 0) {
            $setup->startSetup();
            $connection = $this->_resource->getConnectionByName('default');
            $syncCollection = $this->syncCollectionFactory->create();
            $syncCollection->addFieldToFilter('type', ['eq'=>'ORD']);
            foreach($syncCollection as $item) {
                try {
                    $order = $this->orderFactory->create()->loadByAttribute('entity_id', $item->getRelatedId());
                    $order->setMailchimpSent($item->getMailchimpSent());
                    $order->setMailchimpSyncError($item->getMailchimpSyncError());
                    $order->save();
                } catch (\Exception $e) {
                    $this->_helper->log($e->getMessage());
                }
            }
            $tableProducts = $setup->getTable('catalog_product_entity');
            $tableEcommerce = $setup->getTable('mailchimp_sync_ecommerce');
            $query = "UPDATE `$tableProducts` as A ";
            $query.= "INNER JOIN `$tableEcommerce` as B ON A.`entity_id` = B.`related_id` ";
            $query.= "SET A.`mailchimp_sync_error` = B.`mailchimp_sync_error`, A.`mailchimp_sent` = B.`mailchimp_sent` ";
            $query.= "WHERE B.`type` = 'PRO'";
            $connection->query($query);
        }
    }
}
