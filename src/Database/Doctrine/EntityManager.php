<?php
namespace CodeCTRL\Apollo\Database\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use CodeCTRL\Apollo\Utility\Logger\Interfaces\LoggerHelperInterface;
use CodeCTRL\Apollo\Utility\Logger\Traits\LoggerHelperTrait;

class EntityManager extends \Doctrine\ORM\EntityManager implements LoggerHelperInterface
{
    use LoggerHelperTrait;

    /**
     * @param Connection $conn
     * @param Configuration $config
     */
    public function __construct(Connection $conn, Configuration $config, EventManager $eventManager)
    {
        parent::__construct($conn, $config, $eventManager);
    }
}
