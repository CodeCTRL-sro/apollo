<?php

namespace CodeCTRL\Apollo\Utility\Utils;

use Psr\Http\Message\ResponseInterface;

class APIResponseBuilder
{

    /**
     * @var int
     */
    private int $status;

    /**
     * @var string|null
     */
    private string|null $message;

    /**
     * @var array
     */
    private array $data;

    /**
     * APIResponseBuilder constructor.
     * @param int $status
     * @param string|null $message
     * @param array $data
     */
    public function __construct(int $status = 200, string $message = null, array $data = array())
    {
        $this->status = $status;
        $this->message = $message;
        $this->data = $data;
    }


    /**
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @param int $status
     * @return APIResponseBuilder
     */
    public function setStatus($status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * @param string $message
     * @param null $status
     * @return APIResponseBuilder
     */
    public function setMessage(string $message, $status = null): static
    {
        if ($status != null) {
            $this->status = $status;
        }
        $this->message = $message;
        return $this;
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): static
    {
        $this->data = $data;
        return $this;
    }

    public function addData($data): static
    {
        $this->data[] = $data;
        return $this;
    }

    /**
     * @return string
     */
    public function build(): string
    {
        $response = array(
            "status" => $this->getStatus()
        );

        if ($this->getMessage() != "") {
            $response["message"] = $this->getMessage();
        }

        if (!empty($this->getData())) {
            $response["data"] = $this->getData();
        }

        return json_encode($response);
    }

    /**
     * @param ResponseInterface $response
     * @param int $status
     * @param string|null $message
     * @param array $data
     * @return ResponseInterface
     */
    public static function staticBuild(ResponseInterface $response, int $status = 200, string $message = null, array $data = array()): ResponseInterface
    {
        $builder = new self($status, $message, $data);
        $response->getBody()->write($builder->build());
        return $response->withStatus($status);
    }
}