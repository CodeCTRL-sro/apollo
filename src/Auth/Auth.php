<?php
namespace Metapp\Apollo\Auth;

use Metapp\Apollo\Config\Config;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;

class Auth
{
    const JWT = 'JWT';
    const Session = 'Session';

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * Auth constructor.
     * @param EntityManagerInterface $entityManager
     * @param Config $config
     */
    public function __construct(EntityManagerInterface $entityManager,Config $config)
    {
        $this->config = $config;
        $this->entityManager = $entityManager;
    }

    public function generateJWT($data = array()){
        $tokenData = $this->config->get(array('jwt','payload'));
        $tokenData["data"] = $data;
        return JWT::encode($tokenData, $this->config->get(array('jwt','key')));
    }

    public function validateJWT($token){
        try {
            $decodedData = JWT::decode($token, $this->config->get(array('jwt','key')), array('HS256'));

            if (is_object($decodedData)) {
                $fetchData = $decodedData->data;
                $table = $this->config->get(array('route', 'modules', 'Session', 'entity', 'user'), 'Session:Users');
                $where = $this->config->get(array('route', 'modules', 'Session', 'entity', 'user_auth_key'), 'email');
                $a = $this->config->get(array('route', 'modules', 'Session', 'entity', 'user_auth_data'), 'email');
                $data = $fetchData->{$a};
                $getUser = $this->entityManager->getRepository($table)->findOneBy(array($where => $data));
                if ($getUser && method_exists($getUser, 'setLastUserInteraction')) {
					$getUser->setLastUserInteraction(date_create());
					$this->entityManager->persist($getUser);
					$this->entityManager->flush();
					return true;
				}
            }
        } catch (\Exception $e) {
        }
        return false;
    }

    public function getUserByJWT($token){
        try {
            $decodedData = JWT::decode($token, $this->config->get(array('jwt','key')), array('HS256'));
            if (is_object($decodedData)) {
                $fetchData = $decodedData->data;
                $table = $this->config->get(array('route', 'modules', 'Session', 'entity', 'user'), 'Session:Users');
                $where = $this->config->get(array('route', 'modules', 'Session', 'entity', 'user_auth_key'), 'email');
                $a = $this->config->get(array('route', 'modules', 'Session', 'entity', 'user_auth_data'), 'email');
                $data = $fetchData->{$a};
                $getUser = $this->entityManager->getRepository($table)->findOneBy(array($where => $data));
                if ($getUser) {
                    return $getUser;
                }
            }
        } catch (\Exception $e) {
        }
        return false;
    }
}
