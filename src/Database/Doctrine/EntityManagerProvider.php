<?php

namespace CodeCTRL\Apollo\Database\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;

final class EntityManagerProvider
{
    /**
     * @var ContainerInterface|null
     */
    private static ?ContainerInterface $container = null;

    /**
     * @var EntityManagerInterface|null
     */
    private static ?EntityManagerInterface $entityManager = null;

    /**
     * @param ContainerInterface $container
     */
    public static function setContainer(ContainerInterface $container): void
    {
        self::$container = $container;
    }

    /**
     * @param EntityManagerInterface|null $entityManager
     */
    public static function setEntityManager(?EntityManagerInterface $entityManager): void
    {
        self::$entityManager = $entityManager;
    }

    /**
     * @return EntityManagerInterface
     */
    public static function getEntityManager(): EntityManagerInterface
    {
        if (self::$entityManager instanceof EntityManagerInterface) {
            return self::$entityManager;
        }

        if (self::$container === null) {
            throw new \RuntimeException(
                'EntityManagerProvider nincs bootstrap-elve. Hívd meg a '
                . 'EntityManagerProvider::setContainer($container)-t a boot során.'
            );
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::$container->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
