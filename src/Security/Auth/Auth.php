<?php
namespace CodeCTRL\Apollo\Security\Auth;

use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use CodeCTRL\Apollo\Core\Config\Config;

class Auth
{
    const JWT = 'JWT';
    const Session = 'Session';
    const Cookie = 'Cookie';

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var EntityManagerInterface|null
     */
    protected EntityManagerInterface|null $entityManager;

    /**
     * Auth constructor.
     * @param EntityManagerInterface|null $entityManager
     * @param Config $config
     */
    public function __construct(Config $config, EntityManagerInterface $entityManager = null)
    {
        $this->config = $config;
        $this->entityManager = $entityManager;
    }

	/**
	 * @param $data
	 * @return string
	 */
	public function generateJWT($data = array()): string
	{
        $tokenData = $this->config->get(array('jwt','payload'));
        $tokenData["data"] = $data;
        return JWT::encode($tokenData, $this->config->get(array('jwt','key')),'HS256');
    }

	/**
	 * @param $token
	 * @return bool
	 */
	public function validateJWT($token): bool
	{
		$table = $this->config->get(array('route', 'modules', 'Session', 'entity', 'user'));
		$where = $this->config->get(array('route', 'modules', 'Session', 'entity', 'user_auth_key'), 'email');
		$a = $this->config->get(array('route', 'modules', 'Session', 'entity', 'user_auth_data'), 'email');
        try {
            $decodedData = JWT::decode($token, new Key($this->config->get(array('jwt','key')), 'HS256'));
            $fetchData = $decodedData->data;

            $data = $fetchData->{$a};
            $getUser = $this->entityManager->getRepository($table)->findOneBy(array($where => $data));
            if ($getUser) {
                return true;
            }
        } catch (\Exception $e) {
        }
        return false;
    }

	/**
	 * @param $token
	 * @return mixed
	 */
	public function getUserByJWT($token): mixed
	{
		$table = $this->config->get(array('route', 'modules', 'Session', 'entity', 'user'));
		$where = $this->config->get(array('route', 'modules', 'Session', 'entity', 'user_auth_key'), 'email');
		$a = $this->config->get(array('route', 'modules', 'Session', 'entity', 'user_auth_data'), 'email');
		try {
			$decodedData = JWT::decode($token, new Key($this->config->get(array('jwt','key')), 'HS256'));
            $fetchData = $decodedData->data;

            $data = $fetchData->{$a};
            $getUser = $this->entityManager->getRepository($table)->findOneBy(array($where => $data));
            if ($getUser) {
                return $getUser;
            }
		} catch (\Exception $e) {
		}
		return false;
    }
}
