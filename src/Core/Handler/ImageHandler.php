<?php

namespace Core\Handler;

use Core\Entity\AppParameters;
use Core\Entity\Image\InputImage;
use Core\Entity\Image\OutputImage;
use Core\Entity\OptionsBag;
use Core\Processor\ExtractProcessor;
use Core\Processor\FaceDetectProcessor;
use Core\Processor\ImageProcessor;
use Core\Processor\OverlayProcessor;
use Core\Processor\SmartCropProcessor;
use League\Flysystem\Filesystem;

/**
 * Class ImageHandler
 * @package Core\Service
 */
class ImageHandler
{
    /** @var ImageProcessor */
    protected $imageProcessor;

    /** @var FaceDetectProcessor */
    protected $faceDetectProcessor;

    /** @var ExtractProcessor */
    protected $extractProcessor;

    /** @var SmartCropProcessor */
    protected $smartCropProcessor;

    /** @var OverlayProcessor */
    protected $overlayProcessor;

    /** @var SecurityHandler */
    protected $securityHandler;

    /** @var Filesystem */
    protected $filesystem;

    /** @var AppParameters */
    protected $appParameters;

    /** @var OptionsBag */
    protected $optionsBag;

    /**
     * ImageHandler constructor.
     *
     * @param Filesystem $filesystem
     * @param AppParameters $appParameters
     */
    public function __construct(Filesystem $filesystem, AppParameters $appParameters)
    {
        $this->filesystem = $filesystem;
        $this->appParameters = $appParameters;

        $this->imageProcessor = new ImageProcessor();
        $this->faceDetectProcessor = new FaceDetectProcessor();
        $this->extractProcessor = new ExtractProcessor();
        $this->smartCropProcessor = new SmartCropProcessor();
        $this->overlayProcessor = new OverlayProcessor();
        $this->securityHandler = new SecurityHandler($appParameters);
    }

    /**
     * @return ImageProcessor
     */
    public function imageProcessor(): ImageProcessor
    {
        return $this->imageProcessor;
    }

    /**
     * @return AppParameters
     */
    public function appParameters(): AppParameters
    {
        return $this->appParameters;
    }

    /**
     * @return SecurityHandler
     */
    public function securityHandler(): SecurityHandler
    {
        return $this->securityHandler;
    }


    /**
     * @param string $options
     * @param string $imageSrc
     *
     * @return OutputImage
     * @throws \Exception
     */
    public function processImage(string $options, string $imageSrc): OutputImage
    {
        [$options, $imageSrc] = $this->securityHandler->checkSecurityHash($options, $imageSrc);

        $imageSrc = $this->parseAndValidateImageSource($imageSrc);

        $optionsBag = new OptionsBag($this->appParameters, $options);

        $this->optionsBag = clone $optionsBag;

        $inputImage = new InputImage($optionsBag, $imageSrc);
        $outputImage = new OutputImage($inputImage);

        return $this->processOutputImage($outputImage);
    }

    private function parseAndValidateImageSource(string $imageSrc): string
    {
        $imageSrc = $this->parseDirectories($imageSrc);
        $imageSrc = $this->securityHandler->checkSingleDomain($imageSrc);

        $this->securityHandler->checkRestrictedDomains($imageSrc);

        return $imageSrc;
    }


    public function parseDirectories(string $imageSrc): string
    {
        $directoriesArray = $this->appParameters->parameterByKey('directories');
        foreach ($directoriesArray as $value) {
            $alias = $value['alias'];
            $directory = $value['directory'];
            $imageSrc = str_replace($alias . "/", $directory . "/", $imageSrc);
            $imageSrc = str_replace($alias . ":", $directory . "/", $imageSrc);
        }

        return $imageSrc;
    }

    private function processOutputImage(OutputImage $outputImage): OutputImage
    {
        try {
            if ($this->filesystem->has($outputImage->getOutputImageName()) && $outputImage->getInputImage()->optionsBag()->get(key: 'refresh')) {
                $this->filesystem->delete($outputImage->getOutputImageName());
            }

            if (!$this->filesystem->has($outputImage->getOutputImageName())) {
                $outputImage = $this->processNewImage($outputImage);
            }

            $outputImage->attachOutputContent($this->filesystem->read($outputImage->getOutputImageName()));
        } catch (\Exception $e) {
            $outputImage->removeOutputImage();
            throw $e;
        }

        return $outputImage;
    }

    /**
     * @param OutputImage $outputImage
     *
     * @throws \Exception
     */
    protected function smartCropProcess(OutputImage $outputImage): void
    {
        $smartCrop = $outputImage->extractKey('smart-crop');

        if ($smartCrop && !$outputImage->isOutputGif()) {
            $this->smartCropProcessor->smartCrop($outputImage);
        }
    }

    /**
     * @param OutputImage $outputImage
     *
     * @throws \Exception
     */
    protected function faceDetectionProcess(OutputImage $outputImage): void
    {
        $faceCrop = $outputImage->extractKey('face-crop');
        $faceCropPosition = $outputImage->extractKey('face-crop-position');
        $faceBlur = $outputImage->extractKey('face-blur');

        if ($faceBlur && !$outputImage->isOutputGif()) {
            $this->faceDetectProcessor->blurFaces($outputImage);
        }

        if ($faceCrop && !$outputImage->isOutputGif()) {
            $this->faceDetectProcessor->cropFaces($outputImage, $faceCropPosition);
        }
    }

    /**
     * @param OutputImage $outputImage
     *
     * @throws \Exception
     */
    protected function overlayProcess(OutputImage $outputImage): void
    {
        # If there's an overlay image, things will happen
        $overlayImage = base64_decode($outputImage->extractKey('overlay-image'));

        if (empty($overlayImage)) {
            return;
        }

        $tempBag = $this->optionsBag;
        $tempBag->remove("overlay-image"); # We remove the overlay-image because we want the if above to return
        $tempBag->setOption("output", "input"); # We want the overlay to be generated in its original format

        $inputImage = new InputImage($tempBag, $overlayImage);
        $outputOverlayImage = new OutputImage($inputImage);

        $outputOverlayImage = $this->processOutputImage($outputOverlayImage);

        $this->overlayProcessor->overlayImage($outputImage, $outputOverlayImage);
    }

    /**
     * @param OutputImage $outputImage
     *
     * @return OutputImage
     * @throws \Exception
     */
    protected function processNewImage(OutputImage $outputImage): OutputImage
    {
        //Check Extract options
        if ($outputImage->extractKey('extract')) {
            $this->extractProcess($outputImage);
        }

        $outputImage = $this->imageProcessor()->processNewImage(outputImage: $outputImage);

        // Check if Smart Crop enabled
        $this->smartCropProcess($outputImage);

        //Check Face Detection options
        $this->faceDetectionProcess($outputImage);

        //Check Overlay options
        $this->overlayProcess($outputImage);

        $this->filesystem->write(
            $outputImage->getOutputImageName(),
            stream_get_contents(fopen($outputImage->getOutputTmpPath(), 'r'))
        );

        return $outputImage;
    }

    /**
     * @param OutputImage $outputImage
     *
     * @throws \Exception
     */
    protected function extractProcess(OutputImage $outputImage): void
    {
        $this->extractProcessor->extract($outputImage);
    }

    /**
     * @param OutputImage $outputImage
     *
     * @return string
     */
    public function responseContentType(OutputImage $outputImage): string
    {
        if ($outputImage->getOutputImageExtension() == OutputImage::EXT_AVIF) {
            return InputImage::AVIF_MIME_TYPE;
        }
        if ($outputImage->getOutputImageExtension() == OutputImage::EXT_WEBP) {
            return InputImage::WEBP_MIME_TYPE;
        }
        if ($outputImage->getOutputImageExtension() == OutputImage::EXT_PNG) {
            return InputImage::PNG_MIME_TYPE;
        }
        if ($outputImage->getOutputImageExtension() == OutputImage::EXT_GIF) {
            return InputImage::GIF_MIME_TYPE;
        }

        return InputImage::JPEG_MIME_TYPE;
    }
}
