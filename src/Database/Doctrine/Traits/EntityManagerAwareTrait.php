<?php

namespace CodeCTRL\Apollo\Database\Doctrine\Traits;

use Doctrine\ORM\EntityManagerInterface;
use CodeCTRL\Apollo\Database\Doctrine\EntityManagerProvider;

trait EntityManagerAwareTrait
{
    /**
     * @var EntityManagerInterface|null
     */
    protected ?EntityManagerInterface $entityManager = null;

    /**
     * @return EntityManagerInterface
     */
    public function getEntityManager(): EntityManagerInterface
    {
        if ($this->entityManager === null) {
            $this->entityManager = EntityManagerProvider::getEntityManager();
        }

        return $this->entityManager;
    }

    /**
     * @param EntityManagerInterface $entityManager
     */
    public function setEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->entityManager = $entityManager;
    }
}
