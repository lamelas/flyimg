<?php

namespace Core\Processor;

use Core\Entity\Command;
use Core\Entity\Image\OutputImage;

/**
 * Class OverlayProcessor
 * @package Core\Processor
 */
class OverlayProcessor extends Processor
{

    const IM_COMPOSE_OPTIONS = ["multiply", "screen", "plus", "add", "minus", "subtract", "difference", "exclusion", "darken", "lighten", "negate", "reflect", "freeze", "stamp", "interpolate"];

    const IM_GRAVITY_OPTIONS = ["none", "center", "east", "forget", "northeast", "north", "northwest", "southeast", "south", "southwest", "west"];

    /**
     * Smart cropping
     *
     * @param OutputImage $outputImage
     * @param OutputImage $overlayImage
     * @param array $overlayOptions
     *
     * @throws \Exception
     */
    public function overlayImage(OutputImage $outputImage, OutputImage $overlayImage)
    {
        $overlayCmd = new Command(self::IM_CONVERT_COMMAND);
        $overlayCmd->addArgument($outputImage->getOutputTmpPath());

        $overlayOptions = $overlayImage->getInputImage()->optionsBag();

        $this->logger->info('sourceImageUrl: ' . $outputImage->getInputImage()->sourceImageUrl());
        $this->logger->info('overlayImageUrl: ' . $overlayImage->getInputImage()->sourceImageUrl());

        $resizeString = "";
        if (filter_var($overlayOptions->get('overlay-width'), FILTER_VALIDATE_FLOAT) > 0 && filter_var($overlayOptions->get('overlay-width'), FILTER_VALIDATE_FLOAT) <= 1) {
            $overlayWidth = filter_var($overlayOptions->get('overlay-width'), FILTER_VALIDATE_FLOAT);
            $overlayCmd->addArgument("-set option:overlaywidth \"%[fx:int(w*" . $overlayWidth . ")]\"");
            $resizeString .= "%[overlaywidth]";
        }
        $resizeString .= "x";
        if (filter_var($overlayOptions->get('overlay-height'), FILTER_VALIDATE_FLOAT) > 0 && filter_var($overlayOptions->get('overlay-width'), FILTER_VALIDATE_FLOAT) <= 1) {
            $overlayHeight = filter_var($overlayOptions->get('overlay-height'), FILTER_VALIDATE_FLOAT);
            $overlayCmd->addArgument("-set option:overlayheight \"%[fx:int(h*" . $overlayHeight . ")]\"");
            $resizeString .= "%[overlayheight]";
        }
        $resizeString .= "!";

        if (filter_var($overlayOptions->get('overlay-opacity'), FILTER_VALIDATE_FLOAT) >= 0 && filter_var($overlayOptions->get('overlay-opacity'), FILTER_VALIDATE_FLOAT) <= 1) {
            $opacityValue = filter_var($overlayOptions->get('overlay-opacity'), FILTER_VALIDATE_FLOAT);
            $opacityString = "-alpha set -channel A -evaluate multiply " . $opacityValue . " +channel";
        }

        $overlayCmd->addArgument("\( " . $overlayImage->getOutputTmpPath() . " -resize \"" . $resizeString . "\" " . $opacityString . " \)");

        if (in_array(strtolower($overlayOptions->get('overlay-blend')), self::IM_COMPOSE_OPTIONS)) {
            $overlayCmd->addArgument("-compose", $overlayOptions->get('overlay-blend'));
        }

        if (in_array(strtolower($overlayOptions->get('overlay-position')), self::IM_GRAVITY_OPTIONS)) {
            $overlayCmd->addArgument("-gravity", $overlayOptions->get('overlay-position'));
        }

        $overlayCmd->addArgument("-composite");
        $overlayCmd->addArgument($outputImage->getOutputTmpPath());

        $this->logger->info('OverlayCommand: ' . $overlayCmd);

        $this->execute($overlayCmd);
    }
}
