<?php

namespace CommerceGuys\Platform\Cli\Local\Toolstack;

use CommerceGuys\Platform\Cli\Helper\FilesystemHelper;
use CommerceGuys\Platform\Cli\Helper\ShellHelper;
use CommerceGuys\Platform\Cli\Helper\ShellHelperInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ToolstackBase implements ToolstackInterface
{

    /**
     * Files from the app root to ignore during install.
     *
     * @var string[]
     */
    protected $ignoredFiles = array();

    /**
     * Special destinations for installation.
     *
     * @var array
     *   An array of filenames in the app root, mapped to destinations. The
     *   destinations are filenames supporting the replacements:
     *     "{webroot}" - www for the CLI, usually /app/public on Platform.sh
     *     "{approot}" - ignored by the CLI, /app on Platform.sh
     */
    protected $specialDestinations = array();

    protected $settings = array();
    protected $appRoot;
    protected $projectRoot;
    protected $buildDir;
    protected $absoluteLinks = false;

    /** @var OutputInterface */
    protected $output;

    /** @var FilesystemHelper */
    protected $fsHelper;

    /** @var ShellHelperInterface */
    protected $shellHelper;

    public function __construct(FilesystemHelper $fsHelper = null, ShellHelperInterface $shellHelper = null)
    {
        $this->fsHelper = $fsHelper ?: new FilesystemHelper();
        $this->shellHelper = $shellHelper ?: new ShellHelper();

        $this->specialDestinations = array(
          "favicon.ico" => "{webroot}",
          "robots.txt" => "{webroot}",
        );

        // Platform.sh has '.platform.app.yaml', but we need to be stricter.
        $this->ignoredFiles = array('.*');
    }

    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
        $this->shellHelper->setOutput($output);
    }

    public function prepareBuild($appRoot, $projectRoot, array $settings)
    {
        $this->appRoot = $appRoot;
        $this->projectRoot = $projectRoot;
        $this->settings = $settings;

        $buildName = date('Y-m-d--H-i-s') . '--' . $settings['environmentId'];
        $this->buildDir = $projectRoot . '/builds/' . $buildName;

        $this->absoluteLinks = !empty($settings['absoluteLinks']);
        $this->fsHelper->setRelativeLinks(!$this->absoluteLinks);
    }

    /**
     * Process the defined special destinations.
     */
    protected function symLinkSpecialDestinations()
    {
        foreach ($this->specialDestinations as $sourcePattern => $relDestination) {
            $matched = glob($this->appRoot . '/' . $sourcePattern, GLOB_NOSORT);
            if (!$matched) {
                continue;
            }
            $this->output->writeln("Symlinking $sourcePattern to $relDestination");

            // On Platform these replacements would be a bit different.
            $absDestination = str_replace(array('{webroot}', '{approot}'), $this->buildDir, $relDestination);

            foreach ($matched as $source) {
                // Ignore the source if it's in ignoredFiles.
                $relSource = str_replace($this->appRoot . '/', '', $source);
                if (in_array($relSource, $this->ignoredFiles)) {
                    continue;
                }
                $destination = $absDestination;
                // Do not overwrite directories with files.
                if (!is_dir($source) && is_dir($destination)) {
                    $destination = $destination . '/' . basename($source);
                }
                // Delete existing files, emitting a warning.
                if (file_exists($destination)) {
                    $this->output->writeln(sprintf(
                        "Overriding existing path '%s' in destination",
                        str_replace($this->buildDir . '/', '', $destination)
                      ));
                    $this->fsHelper->remove($destination);
                }
                $this->fsHelper->symlink($source, $destination);
            }
        }
    }

}
