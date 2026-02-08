<?php

namespace CodeCTRL\Apollo\Utility\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Intervention\Image\ImageManager;
use CodeCTRL\Apollo\Utility\Utils\StringUtils;
use Psr\Log\LoggerInterface;

class BunnyCDNHelper
{
    /**
     * @var Client
     */
    private Client $client;

    /**
     * @var string
     */
    private string $apiKey;

    /**
     * @var string
     */
    private string $storageZone;

    /**
     * @var string
     */
    private string $pullZoneHostname;

    /**
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger;

    /**
     * @param LoggerInterface|null $logger
     * @throws \Exception
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->apiKey = $_ENV["BUNNY_CDN_SECRET"];
        $this->storageZone = $_ENV["BUNNY_CDN_STORAGE_ZONE"];
        $this->pullZoneHostname = $_ENV["BUNNY_CDN_PULL_ZONE_HOSTNAME"];
        $this->logger = $logger;

        if (empty($this->apiKey) || empty($this->storageZone) || empty($this->pullZoneHostname)) {
            throw new \Exception("BunnyCDN API key, storage zone or pull zone hostname is not set");
        }

        $this->client = new Client([
            'base_uri' => $this->getStorageUrl(),
            'headers' => [
                'AccessKey' => $this->apiKey,
                'Accept' => '*/*'
            ]
        ]);
    }

    /**
     *
     * @return string
     */
    private function getStorageUrl(): string
    {
        return "https://storage.bunnycdn.com/{$this->storageZone}/";
    }

    /**
     * @param $file
     * @param $remoteFolder
     * @param null $hash
     * @param bool $isChunkUpload
     * @return array
     */
    public function storeAndUploadFile($file, $remoteFolder, $tmpFolder, $hash = null, bool $isChunkUpload = false): array
    {
        if (!$hash) {
            $hash = StringUtils::generateRandomString();
        }

        $tmpFilePath = $tmpFolder . '/' . $hash . '.jpg';
        if (extension_loaded('imagick') && class_exists('Imagick')) {
            $manager = new ImageManager(new \Intervention\Image\Drivers\Imagick\Driver());
            $saveTmpFile = $manager->read($file["tmp_name"]);
            $saveTmpFile->toJpeg(quality: 100);
            $saveTmpFile->save($tmpFilePath);
        } else {
            if ($isChunkUpload) {
                copy($file["tmp_name"], $tmpFilePath);
            } else {
                move_uploaded_file($file["tmp_name"], $tmpFilePath);
            }
        }
        if ($this->uploadFile($tmpFilePath, "{$remoteFolder}/{$hash}.jpg")) {
            unlink($tmpFilePath);
            return array('status' => 200, 'hash' => $hash, 'url' => "https://" . $this->pullZoneHostname . "/{$remoteFolder}/{$hash}.jpg");
        }

        return array('status' => 400);
    }

    /**
     * @param string $localFilePath
     * @param string $cdnPath
     * @return bool
     */
    public function uploadFile(string $localFilePath, string $cdnPath): bool
    {
        if (!file_exists($localFilePath)) {
            $this->logError("File not found: {$localFilePath}");
            return false;
        }

        try {
            $fileContents = file_get_contents($localFilePath);
            $cdnPath = ltrim($cdnPath, '/');

            $response = $this->client->put($cdnPath, [
                'body' => $fileContents,
                'headers' => [
                    'Content-Type' => $this->getMimeType($localFilePath)
                ]
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logInfo("File uploaded successfully: {$cdnPath}");
                return true;
            }

            $this->logError("Failed to upload file. Status code: {$statusCode}");
            return false;

        } catch (GuzzleException $e) {
            $this->logError("Upload error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @param string $content
     * @param string $cdnPath
     * @param string $mimeType
     * @return bool
     */
    public function uploadContent(string $content, string $cdnPath, string $mimeType = 'application/octet-stream'): bool
    {
        try {
            $cdnPath = ltrim($cdnPath, '/');

            $response = $this->client->put($cdnPath, [
                'body' => $content,
                'headers' => [
                    'Content-Type' => $mimeType
                ]
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logInfo("Content uploaded successfully: {$cdnPath}");
                return true;
            }

            $this->logError("Failed to upload content. Status code: {$statusCode}");
            return false;

        } catch (GuzzleException $e) {
            $this->logError("Upload error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @param string $cdnPath
     * @return bool
     */
    public function deleteFile(string $cdnPath): bool
    {
        try {
            $cdnPath = ltrim($cdnPath, '/');

            $response = $this->client->delete($cdnPath);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logInfo("File deleted successfully: {$cdnPath}");
                return true;
            }

            $this->logError("Failed to delete file. Status code: {$statusCode}");
            return false;

        } catch (GuzzleException $e) {
            $this->logError("Delete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @param string $cdnPath
     * @return string
     */
    public function getAssetUrl(string $cdnPath): string
    {
        $cdnPath = ltrim($cdnPath, '/');
        return "https://{$this->pullZoneHostname}/{$cdnPath}";
    }

    /**
     * @param string $cdnPath
     * @return bool
     */
    public function fileExists(string $cdnPath): bool
    {
        try {
            $cdnPath = ltrim($cdnPath, '/');

            $response = $this->client->head($cdnPath);
            $statusCode = $response->getStatusCode();

            return $statusCode >= 200 && $statusCode < 300;

        } catch (GuzzleException $e) {
            return false;
        }
    }

    /**
     * @param string $directoryPath
     * @return array|null
     */
    public function listFiles(string $directoryPath = ''): ?array
    {
        try {
            $directoryPath = rtrim(ltrim($directoryPath, '/'), '/');
            $path = $directoryPath ? $directoryPath . '/' : '';

            $response = $this->client->get($path);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $content = $response->getBody()->getContents();
                return json_decode($content, true);
            }

            $this->logError("Failed to list files. Status code: {$statusCode}");
            return null;

        } catch (GuzzleException $e) {
            $this->logError("List files error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * @param string $filePath
     * @return string
     */
    private function getMimeType(string $filePath): string
    {
        if (function_exists('mime_content_type')) {
            return mime_content_type($filePath);
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'txt' => 'text/plain',
            'html' => 'text/html',
            'htm' => 'text/html',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'otf' => 'font/otf',
        ];

        $extension = strtolower($extension);
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * @param string $message
     */
    private function logInfo(string $message): void
    {
        if ($this->logger) {
            $this->logger->info("[BunnyCDN] {$message}");
        }
    }

    /**
     * @param string $message
     */
    private function logError(string $message): void
    {
        if ($this->logger) {
            $this->logger->error("[BunnyCDN] {$message}");
        }
    }
}