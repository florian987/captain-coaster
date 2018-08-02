<?php

namespace BddBundle\Service;

use BddBundle\Entity\Image;
use Doctrine\DBAL\DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Imagine\Filter\Basic\Resize;
use Imagine\Filter\Transformation;
use Imagine\Imagick\Imagine;
use Imagine\Image\Box;
use Imagine\Image\Metadata\ExifMetadataReader;
use Imagine\Image\Point;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ImageManager
{
    /**
     * Image max size
     */
    CONST MAX_SIZE = 1440;

    /**
     * Watermark name
     */
    CONST WATERMARK_CC = 'cc';

    /**
     * @var string
     */
    private $basePath;

    /**
     * @var string
     */
    private $watermarkPath;

    /**
     * @var string
     */
    private $jpegoptimPath;

    /**
     * @var Imagine
     */
    private $imagine;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * ImageUploader constructor.
     * @param string $basePath
     * @param string $watermarkPath
     * @param string $jpegoptimPath
     * @param Imagine $imagine
     * @param EntityManagerInterface $em
     * @param LoggerInterface $logger
     */
    public function __construct(
        string $basePath,
        string $watermarkPath,
        string $jpegoptimPath,
        Imagine $imagine,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ) {
        $this->basePath = $basePath;
        $this->watermarkPath = $watermarkPath;
        $this->jpegoptimPath = $jpegoptimPath;
        $this->imagine = $imagine;
        $this->em = $em;
        $this->logger = $logger;
    }

    /**
     * Create file on disk
     *
     * @param UploadedFile $file
     * @return string
     */
    public function upload(UploadedFile $file)
    {
        $filename = $this->generateFilename($file);

        $file->move(
            $this->getFullPath($filename),
            $filename
        );

        return $filename;
    }

    /**
     * Remove file from disk
     *
     * @param string $filename
     * @return bool
     */
    public function remove(string $filename)
    {
        $file = sprintf('%s/%s', $this->getFullPath($filename), $filename);
        $fs = new Filesystem();

        if ($fs->exists($file)) {
            $fs->remove($file);

            return true;
        }

        return false;
    }

    /**
     * @param Image $image
     * @return bool
     */
    public function process(Image $image)
    {
        try {
            $this->resize($image);
            $this->watermark($image);
            $this->optimize($image);
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage());

            return false;
        }

        $image->setOptimized(true);
        $this->em->persist($image);
        $this->em->flush();

        return true;
    }

    /**
     * Reset main image shortcut
     * @todo faire mieux :)
     */
    public function setMainImages()
    {
        $conn = $this->em->getConnection();

        $sql = 'update coaster c
          inner join
          (
            select id, coaster_id from image i 
            where enabled = 1 
            order by updated_at asc, id asc
          ) as i2 on i2.coaster_id = c.id
          set c.main_image_id = i2.id;';

        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute();
        } catch (DBALException $e) {
        }
    }

    /**
     * @param Image $image
     */
    public function enableImage(Image $image)
    {
        $image->setEnabled(true);
        $this->em->persist($image);
        $this->em->flush();

        $this->setMainImages();
    }

    /**
     * @param Image $image
     * @param int $maxSize
     * @return bool
     */
    private function resize(Image $image, int $maxSize = self::MAX_SIZE)
    {
        $fullPath = $this->getFullPath($image->getFilename(), true);
        $file = $this->imagine->setMetadataReader(new ExifMetadataReader())->open($fullPath);

        $transformation = new Transformation();
        $transformation->add(new \Imagine\Filter\Basic\Autorotate());

        $height = $file->getSize()->getHeight();
        $width = $file->getSize()->getWidth();

        if ($width > $maxSize || $height > $maxSize) {
            if ($width > $height) {
                $ratio = $maxSize / $width;
                $box = new Box($maxSize, $height * $ratio);
            } else {
                $ratio = $maxSize / $height;
                $box = new Box($width * $ratio, $height);
            }

            $transformation->add(new Resize($box));
        }

        $transformation->apply($file)->save($fullPath);

        return true;
    }

    /**
     * @param Image $image
     * @return bool
     */
    private function watermark(Image $image)
    {
        if ($image->getWatermark() !== self::WATERMARK_CC) {
            return false;
        }

        $watermark = $this->imagine->open($this->watermarkPath);
        $fullPath = $this->getFullPath($image->getFilename(), true);
        $file = $this->imagine->open($fullPath);
        // do a backup before watermarking
        $file->save($this->getFullBackupPath($image->getFilename(), true));

        $file = $this->imagine->open($fullPath);
        $size = $file->getSize();
        $wSize = $watermark->getSize();

        $bottomLeft = new Point(30, $size->getHeight() - $wSize->getHeight() - 30);

        $file->paste($watermark, $bottomLeft);
        $file->save($fullPath);

        return true;
    }

    /**
     * @param Image $image
     * @return bool
     */
    private function optimize(Image $image)
    {
        $process = new Process([$this->jpegoptimPath, '--help']);
        $process->run();

        if (!$process->isSuccessful()) {
            return false;
        }

        $fullPath = $this->getFullPath($image->getFilename(), true);
        $process = new Process("$this->jpegoptimPath -s $fullPath");
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return true;
    }

    /**
     * Get full path like /var/www/image/8f52b371-1c2d-4a08-95f7-48cff34a1fc6.jpeg
     *
     * @param string $filename
     * @param bool $includeFilename
     * @return string
     */
    private function getFullPath(string $filename, bool $includeFilename = false): string
    {
        $path = sprintf('%s/%s', $this->basePath, substr($filename, 0, 1));

        if ($includeFilename) {
            return sprintf('%s/%s', $path, $filename);
        }

        return $path;
    }

    /**
     * Get full backup path like /var/www/image/8f52b371-1c2d-4a08-95f7-48cff34a1fc6.jpeg
     *
     * @param string $filename
     * @param bool $includeFilename
     * @return string
     *
     * @todo faire mieux
     */
    private function getFullBackupPath(string $filename, bool $includeFilename = false): string
    {
        $path = sprintf('%s/backup/%s', $this->basePath, substr($filename, 0, 1));

        if ($includeFilename) {
            return sprintf('%s/%s', $path, $filename);
        }

        return $path;
    }

    /**
     * Generates a filename like 8f52b371-1c2d-4a08-95f7-48cff34a1fc6.jpeg
     *
     * @param UploadedFile $file
     * @return string
     */
    private function generateFilename(UploadedFile $file): string
    {
        return sprintf('%s.%s', Uuid::uuid4()->toString(), $file->guessExtension());
    }
}
