<?php

namespace CodeCTRL\Apollo\Database\Doctrine\Interfaces;

use Doctrine\ORM\EntityManagerInterface;

interface EntityManagerAwareInterface
{
    /**
     * @return EntityManagerInterface
     */
    public function getEntityManager(): EntityManagerInterface;

    /**
     * @param EntityManagerInterface $entityManager
     */
    public function setEntityManager(EntityManagerInterface $entityManager): void;
}
