<?php

namespace CodeCTRL\Apollo\Utility\Helper;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use CodeCTRL\Apollo\Core\Env;
use CodeCTRL\Apollo\Utility\Utils\StringUtils;
use RuntimeException;

class ImageHelper
{
    /**
     * Handle an uploaded image: normalise its orientation, write it, and optionally
     * write a scaled down copy.
     *
     * Two things changed in 3.3.0.
     *
     * The image used to be encoded three times: written by Intervention, read back and
     * rotated with GD, written again, then read a third time to scale. Intervention can
     * apply the EXIF orientation itself, so the GD round trip is gone — one decode, one
     * encode. It also handles the mirrored orientations (2, 4, 5, 7) that the old switch
     * silently ignored.
     *
     * memory_limit is no longer set to -1. An unbounded limit means one oversized upload
     * can exhaust the worker process; the budget is now IMAGE_MEMORY_LIMIT, and images
     * larger than IMAGE_MAX_PIXELS are rejected before they are decoded at all.
     *
     * @param string $path
     * @param string $prefix
     * @param array<string, mixed>|string $file The $_FILES entry, or a path.
     * @param int $smallWidth
     * @param int $highWidth
     * @param bool $removeOriginal
     * @param bool $saveSmall
     * @param string $outputExt
     * @param bool $rotateCheck
     * @return array{hash: string, file: string}
     */
    public function uploadFile($path, $prefix, $file, $smallWidth = 500, $highWidth = 1000, $removeOriginal = false, $saveSmall = false, $outputExt = 'jpg', $rotateCheck = true): array
    {
        ini_set('memory_limit', Env::string('IMAGE_MEMORY_LIMIT', '512M'));
        ini_set('gd.jpeg_ignore_warning', 1);

        $source = is_array($file) ? (string)($file['tmp_name'] ?? '') : (string)$file;
        if ($source === '' || !is_file($source)) {
            throw new RuntimeException('Uploaded image is missing or unreadable.');
        }
        $this->assertWithinPixelBudget($source);

        $fileNameG = implode("_", array($prefix, StringUtils::generateRandomString(), time()));
        $fileName = $fileNameG . '.' . $outputExt;
        $reducedFileName = $fileNameG . '_r.' . $outputExt;

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Could not create upload directory "%s".', $path));
        }

        $originalFileSizePath = $path . '/' . $fileName;
        $reducedFileSizePath = $path . '/' . $reducedFileName;

        $manager = new ImageManager($this->driver());

        //------------------------ SAVE ORIGINAL IMAGE ------------------------
        $image = $manager->read($source);

        //------------------------ ROTATE IMAGE FOR THE GOOD POSITION ------------------------
        if ($rotateCheck) {
            $image = $image->orient();
        }

        $quality = (int)Env::int('IMAGE_JPEG_QUALITY', 98);
        if ($outputExt == "jpg") {
            $image->toJpeg($quality)->save($originalFileSizePath);
        } else {
            $image->toPng()->save($originalFileSizePath);
        }

        //------------------------ READ AND RESIZE TO HI WIDTH ------------------------
        $getOriginalImage = $manager->read($originalFileSizePath);
        $getOriginalImage->scale($highWidth);

        if ($saveSmall) {
            //------------------------ RESIZE TO LO WIDTH ------------------------
            $getOriginalImage->scale($smallWidth);
            $getOriginalImage->save($reducedFileSizePath);
        } else {
            $reducedFileName = $fileName;
        }

        if ($removeOriginal) {
            unlink($originalFileSizePath);
        }

        return array('hash' => $fileNameG, 'file' => $reducedFileName);
    }

    /**
     * Reject an image whose pixel count would blow the memory budget once decoded. A
     * JPEG compresses to a few hundred kilobytes at sizes that need gigabytes in memory,
     * so a file size check alone does not protect anything.
     *
     * @param string $source
     */
    protected function assertWithinPixelBudget(string $source): void
    {
        $maxPixels = (int)Env::int('IMAGE_MAX_PIXELS', 40000000);
        if ($maxPixels <= 0) {
            return;
        }

        $info = @getimagesize($source);
        if ($info === false) {
            throw new RuntimeException('Uploaded file is not a readable image.');
        }

        $pixels = (int)$info[0] * (int)$info[1];
        if ($pixels > $maxPixels) {
            throw new RuntimeException(sprintf(
                'Image is too large: %dx%d is %d pixels, the limit is %d (IMAGE_MAX_PIXELS).',
                $info[0],
                $info[1],
                $pixels,
                $maxPixels
            ));
        }
    }

    /**
     * Imagick where available, GD otherwise. Previously Imagick was hardcoded, so a
     * server without the extension failed at the constructor with nothing to explain it.
     *
     * @return DriverInterface
     */
    protected function driver(): DriverInterface
    {
        if (extension_loaded('imagick')) {
            return new ImagickDriver();
        }

        if (extension_loaded('gd')) {
            return new GdDriver();
        }

        throw new RuntimeException('Image processing needs either ext-imagick or ext-gd.');
    }

    /**
     * @param \GdImage|resource $img
     * @param string $filePath
     * @return \GdImage|resource|false|mixed
     * @deprecated 3.3.0 uploadFile() applies the EXIF orientation through Intervention's
     *             orient(), which also covers the mirrored orientations this method
     *             ignores. Kept for applications calling it directly; removed in 4.0.
     */
    public function exifRotationCheck($img, $filePath)
    {
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($filePath);
            if ($exif && isset($exif['Orientation'])) {
                $orientation = $exif['Orientation'];
                if ($orientation != 1) {
                    $deg = 0;
                    switch ($orientation) {
                        case 3:
                            $deg = 180;
                            break;
                        case 6:
                            $deg = 270;
                            break;
                        case 8:
                            $deg = 90;
                            break;
                    }
                    if ($deg) {
                        $img = imagerotate($img, $deg, 0);
                    }
                }
            }
        }
        return $img;
    }

    public static function getImageSizes($path): ?array
    {
        try {
            if (!filter_var($path, FILTER_VALIDATE_URL)) {
                return null;
            }

            $imageInfo = getimagesize($path);

            if ($imageInfo === false) {
                return null;
            }

            return [
                'width' => $imageInfo[0],
                'height' => $imageInfo[1]
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}
