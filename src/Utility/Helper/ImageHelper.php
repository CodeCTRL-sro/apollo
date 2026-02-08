<?php

namespace CodeCTRL\Apollo\Utility\Helper;

use Intervention\Image\ImageManager;
use CodeCTRL\Apollo\Utility\Utils\StringUtils;

class ImageHelper
{
    /**
     * @param $path
     * @param $prefix
     * @param $file
     * @param $smallWidth
     * @param $highWidth
     * @param $removeOriginal
     * @param $saveSmall
     * @param $outputExt
     * @param $rotateCheck
     * @return array
     */
    public function uploadFile($path, $prefix, $file, $smallWidth = 500, $highWidth = 1000, $removeOriginal = false, $saveSmall = false, $outputExt = 'jpg', $rotateCheck = true): array
    {
        ini_set('memory_limit', -1);
        ini_set('gd.jpeg_ignore_warning', 1);

        $fileNameG = implode("_", array($prefix, StringUtils::generateRandomString(), time()));
        $fileName = $fileNameG . '.' . $outputExt;
        $reducedFileName = $fileNameG . '_r.' . $outputExt;

        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }

        $originalFileSizePath = $path . '/' . $fileName;
        $reducedFileSizePath = $path . '/' . $reducedFileName;

        $manager = new ImageManager(new \Intervention\Image\Drivers\Imagick\Driver());

        //------------------------ SAVE ORIGINAL IMAGE ------------------------
        $saveOriginalImage = $manager->read($file["tmp_name"]);
        if ($outputExt == "jpg") {
            $saveOriginalImage->toJpeg();
        } else {
            $saveOriginalImage->toPng();
        }
        $saveOriginalImage->save($originalFileSizePath);

//        //------------------------ ROTATE IMAGE FOR THE GOOD POSITION ------------------------
        if($rotateCheck) {
            if ($outputExt == "jpg") {
                $img = imagecreatefromjpeg($originalFileSizePath);
                $img = $this->exifRotationCheck($img, $originalFileSizePath);
                imagejpeg($img, $originalFileSizePath, 98);
            } else {
                $img = imagecreatefrompng($originalFileSizePath);
                $img = $this->exifRotationCheck($img, $originalFileSizePath);
                imagepng($img, $originalFileSizePath, 98);
            }
            imagedestroy($img);
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
     * @param $img
     * @param $filePath
     * @return false|\GdImage|mixed|resource
     */
    public function exifRotationCheck($img, $filePath)
    {
        if (function_exists('exif_read_data')) {
            $exif = exif_read_data($filePath);
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