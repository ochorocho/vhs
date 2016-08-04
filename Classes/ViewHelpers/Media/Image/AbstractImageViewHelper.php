<?php
namespace FluidTYPO3\Vhs\ViewHelpers\Media\Image;

/*
 * This file is part of the FluidTYPO3/Vhs project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Vhs\ViewHelpers\Media\AbstractMediaViewHelper;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Utility\CommandUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Fluid\Core\ViewHelper\Exception;

/**
 * Base class for image related view helpers adapted from FLUID
 * original image viewhelper.
 */

abstract class AbstractImageViewHelper extends AbstractMediaViewHelper
{

    /**
     * @var \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController contains a backup of the current
     * $GLOBALS['TSFE'] if used in BE mode
     */
    protected $tsfeBackup;

    /**
     * @var string
     */
    protected $workingDirectoryBackup;

    /**
     * @var \TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer
     */
    protected $contentObject;

    /**
     * @var ConfigurationManagerInterface
     */
    protected $configurationManager;

    /**
     * Result of \TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer::getImgResource()
     * @var array
     */
    protected $imageInfo;

    /**
     * @param ConfigurationManagerInterface $configurationManager
     * @return void
     */
    public function injectConfigurationManager(ConfigurationManagerInterface $configurationManager)
    {
        $this->configurationManager = $configurationManager;
        $this->contentObject = $this->configurationManager->getContentObject();
    }

    /**
     * Initialize arguments.
     *
     * @return void
     * @api
     */
    public function initializeArguments()
    {
        parent::initializeArguments();
        $this->registerArgument(
            'width',
            'string',
            'Width of the image. This can be a numeric value representing the fixed width of the image in pixels. ' .
            'But you can also perform simple calculations by adding "m" or "c" to the value. See imgResource.width ' .
            'for possible options.'
        );
        $this->registerArgument(
            'height',
            'string',
            'Height of the image. This can be a numeric value representing the fixed height of the image in pixels. ' .
            'But you can also perform simple calculations by adding "m" or "c" to the value. See imgResource.width ' .
            'for possible options.'
        );
        $this->registerArgument('maxW', 'integer', 'Maximum Width of the image. (no upscaling)');
        $this->registerArgument('maxH', 'integer', 'Maximum Height of the image. (no upscaling)');
        $this->registerArgument('minW', 'integer', 'Minimum Width of the image.');
        $this->registerArgument('minH', 'integer', 'Minimum Height of the image.');
        $this->registerArgument(
            'format',
            'string',
            'Format of the processed file - also determines the target file format. If blank, TYPO3/IM/GM default is ' .
            'taken into account.'
        );
        $this->registerArgument(
            'quality',
            'integer',
            'Quality of the processed image. If blank/not present falls back to the default quality defined in ' .
            'install tool.'
        );
        $this->registerArgument(
            'treatIdAsReference',
            'boolean',
            'When TRUE treat given src argument as sys_file_reference record. Applies only to TYPO3 6.x and above.',
            false,
            false
        );
        $this->registerArgument('canvasWidth', 'integer', 'Width of an optional canvas to place the image on.');
        $this->registerArgument('canvasHeight', 'integer', 'Height of an optional canvas to place the image on.');
        $this->registerArgument(
            'canvasColor',
            'string',
            'Background color of an optional canvas to place the image on (hex triplet).'
        );
    }

    /**
     * @param string|null $imageSource
     * @throws Exception
     */
    public function preprocessImage($imageSource = null)
    {
        $src = (null === $imageSource) ? $this->arguments['src'] : $imageSource;
        $width = $this->arguments['width'];
        $height = $this->arguments['height'];
        $minW = $this->arguments['minW'];
        $minH = $this->arguments['minH'];
        $maxW = $this->arguments['maxW'];
        $maxH = $this->arguments['maxH'];
        $format = $this->arguments['format'];
        $quality = $this->arguments['quality'];
        $treatIdAsReference = (boolean) $this->arguments['treatIdAsReference'];

        if (is_object($src) && $src instanceof FileReference) {
            $src = $src->getUid();
            $treatIdAsReference = true;
        }

        if ('BE' === TYPO3_MODE) {
            $this->simulateFrontendEnvironment();
        }
        $setup = [
            'width' => $width,
            'height' => $height,
            'minW' => $minW,
            'minH' => $minH,
            'maxW' => $maxW,
            'maxH' => $maxH,
            'treatIdAsReference' => $treatIdAsReference,
        ];
        if (false === empty($format)) {
            $setup['ext'] = $format;
        }
        if (0 < (integer) $quality) {
            $quality = MathUtility::forceIntegerInRange($quality, 10, 100, 75);
            $setup['params'] = '-quality ' . $quality;
        }

        if (TYPO3_MODE === 'BE' && strpos($src, '../') === 0) {
            $src = substr($src, 3);
        }
        $this->imageInfo = $this->contentObject->getImgResource($src, $setup);
        $GLOBALS['TSFE']->lastImageInfo = $this->imageInfo;

        if (false === is_array($this->imageInfo)) {
            throw new Exception('Could not get image resource for "' . htmlspecialchars($src) . '".', 1253191060);
        }
        $this->imageInfo[3] = GraphicalFunctions::pngToGifByImagemagick($this->imageInfo[3]);
        if ($this->hasArgument('canvasWidth') && $this->hasArgument('canvasHeight')) {
            $canvasWidth = (integer) $this->arguments['canvasWidth'];
            $canvasHeight = (integer) $this->arguments['canvasHeight'];
            $canvasColor = str_replace('#', '', $this->arguments['canvasColor']);
            $originalFilename = $this->imageInfo[3];
            $originalExtension = substr($originalFilename, -3);
            $destinationFilename = 'typo3temp/vhs-canvas-' .
                md5($originalFilename.$canvasColor.$canvasWidth.$canvasHeight) . '.' . $originalExtension;
            $destinationFilepath = GeneralUtility::getFileAbsFileName($destinationFilename);
            if (!file_exists($destinationFilepath)) {
                $arguments = sprintf(
                    '%s -background \'#%s\' -gravity center -extent %dx%d %s',
                    $originalFilename,
                    $canvasColor,
                    $canvasWidth,
                    $canvasHeight,
                    $destinationFilepath
                );
                $command = CommandUtility::imageMagickCommand('convert', $arguments);
                CommandUtility::exec($command);
            }
            $this->imageInfo[3] = $destinationFilename;
        }

        $GLOBALS['TSFE']->imagesOnPage[] = $this->imageInfo[3];
        $GLOBALS['TSFE']->imagesOnPage[] = $this->imageInfo[3];
        $publicUrl = rawurldecode($this->imageInfo[3]);
        $this->mediaSource = GeneralUtility::rawUrlEncodeFP($publicUrl);
        if (TYPO3_MODE === 'BE') {
            $this->resetFrontendEnvironment();
        }
    }

    /**
     * Prepares $GLOBALS['TSFE'] for Backend mode
     * This somewhat hacky work around is currently needed because the
     * getImgResource() function of \TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer
     * relies on those variables to be set
     *
     * @return void
     */
    protected function simulateFrontendEnvironment()
    {
        $this->tsfeBackup = true === isset($GLOBALS['TSFE']) ? $GLOBALS['TSFE'] : null;
        $this->workingDirectoryBackup = getcwd();
        chdir(constant('PATH_site'));
        $typoScriptSetup = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        );
        $GLOBALS['TSFE'] = new \stdClass();
        $template = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\TypoScript\\TemplateService');
        $template->tt_track = 0;
        $template->init();
        $template->getFileName_backPath = constant('PATH_site');
        $GLOBALS['TSFE']->tmpl = $template;
        $GLOBALS['TSFE']->tmpl->setup = $typoScriptSetup;
        $GLOBALS['TSFE']->config = $typoScriptSetup;
    }

    /**
     * Resets $GLOBALS['TSFE'] if it was previously changed
     * by simulateFrontendEnvironment()
     *
     * @return void
     * @see simulateFrontendEnvironment()
     */
    protected function resetFrontendEnvironment()
    {
        $GLOBALS['TSFE'] = $this->tsfeBackup;
        chdir($this->workingDirectoryBackup);
    }
}
