<?php
/**
 * WindowsAzure DistributionBundle
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace WindowsAzure\DistributionBundle\HttpFoundation\Session\Storage\Handler;

use WindowsAzure\Common\ServiceException;
use WindowsAzure\Table\Models\BatchOperations;
use WindowsAzure\Table\Models\EdmType;
use WindowsAzure\Table\Models\Entity;
use WindowsAzure\Table\TableRestProxy;

/**
 * Azure Table session handler
 *
 * @author Gordon Franke <info@nevalon.de>
 */
class AzureTableSessionHandler implements \SessionHandlerInterface
{
    /**
     * @var TableRestProxy
     */
    private $tableProxy;

    /**
     * @var array
     */
    private $options;

    /**
     * Constructor.
     *
     * List of available options:
     * * table: The name of the table [required]
     * * partition_key: The name of the partition key [required]
     * * data_field: The field name for storing the session data [default: data]
     *
     * @param TableRestProxy $tableProxy A TableRestProxy instance
     * @param array $options An associative array of table options
     *
     * @throws \InvalidArgumentException When TableRestProxy instance not provided
     * @throws \InvalidArgumentException When "table" or "partition_key" not provided
     */
    public function __construct($tableProxy, array $options)
    {
        if (!($tableProxy instanceof TableRestProxy)) {
            throw new \InvalidArgumentException('WindowsAzure\Table\TableRestProxy instance required');
        }

        if (!isset($options['table']) || !isset($options['partition_key'])) {
            throw new \InvalidArgumentException('You must provide the "table" and "partition_key" option for AzureTableSessionHandler');
        }

        $this->tableProxy = $tableProxy;

        $this->options = array_merge(array(
            'data_field' => 'data',
        ), $options);
    }

    /**
     * {@inheritDoc}
     */
    public function open($savePath, $sessionName)
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function close()
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function destroy($sessionId)
    {
        $this->getTableProxy()
            ->deleteEntity(
                $this->options['table'],
                $this->options['partition_key'],
                $sessionId
            );

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function gc($lifetime)
    {
        $date = gmdate('Y-m-d\TH:i:s', time() - $lifetime);

        $result   = $this->getTableProxy()->queryEntities(
            $this->options['table'],
            sprintf('PartitionKey eq \'%s\' and Timestamp lt datetime\'%s\'', $this->options['partition_key'], $date)
        );
        $entities = $result->getEntities();

        $operations = new BatchOperations();
        foreach ($entities as $entity) {
            $operations->addDeleteEntity(
                $this->options['table'],
                $entity->getPartitionKey(),
                $entity->getRowKey()
            );
        }
        $this->getTableProxy()->batch($operations);

        return true;
    }

    /**
     * {@inheritDoc]
     */
    public function write($sessionId, $data)
    {
        $entity = new Entity();
        $entity->setPartitionKey($this->options['partition_key']);
        $entity->setRowKey($sessionId);
        $entity->addProperty($this->options['data_field'], EdmType::STRING, $data);

        $this->getTableProxy()->insertOrReplaceEntity(
            $this->options['table'],
            $entity
        );

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function read($sessionId)
    {
        try {
            $result = $this->getTableProxy()->getEntity(
                $this->options['table'],
                $this->options['partition_key'],
                $sessionId
            );
        } catch(ServiceException $e) {
            return null;
        }

        return $result->getEntity()->getPropertyValue($this->options['data_field']);
    }

    /**
     * Return a TableRestProxy instance
     *
     * @return TableRestProxy
     */
    protected function getTableProxy()
    {
        return $this->tableProxy;
    }
}
