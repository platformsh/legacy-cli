<?php

namespace CommerceGuys\Platform\Cli\Local\Toolstack;

use Symfony\Component\Console\Output\OutputInterface;

interface ToolstackInterface
{

    /**
     * Get the configuration key for the toolstack.
     *
     * @return string
     */
    public function getKey();

    /**
     * Set the output stream for the toolstack.
     *
     * @param OutputInterface $output
     */
    public function setOutput(OutputInterface $output);

    /**
     * Detect if the files in a given directory belong to this toolstack.
     *
     * @param   string  $appRoot The absolute path to the application folder
     *
     * @return  bool    Whether this toolstack is a valid choice or not
     */
    public function detect($appRoot);

    /**
     * Prepare this application to be built.
     *
     * This function should be isometric and not affect the file system.
     *
     * @param string $buildDir
     * @param string $appRoot
     * @param string $projectRoot
     * @param array $settings
     */
    public function prepare($buildDir, $appRoot, $projectRoot, array $settings);

    /**
     * Build this application. Acquire dependencies, plugins, libraries, and
     * submodules.
     */
    public function build();

    /**
     * Move files into place. This could happen straight after the build, or
     * after an old build archive has been extracted.
     */
    public function install();

    /**
     * @return string
     */
    public function getBuildDir();

}
