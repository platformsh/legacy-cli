<?php

namespace CommerceGuys\Platform\Cli\Local\Toolstack;

interface ToolstackInterface
{

    /**
     * Get the configuration key for the toolstack.
     *
     * @return string
     */
    public function getKey();

    /**
     * Detect if the files in a given "application root" folder path belong to 
     * this toolstack.
     * 
     * @param   string  $appRoot The absolute path to the application folder
     *
     * @return  bool    Whether this toolstack is a valid choice or not
     */
    public function detect($appRoot);
    
    /**
     * Prepare this application to be built. This function should be isometric
     * and not affect the file system.
     *
     * @param string $appRoot
     * @param string $projectRoot
     * @param array $settings
     *
     * @return self
     */
    public function prepareBuild($appRoot, $projectRoot, array $settings);
    
    /**
     * Build this application. Acquire dependencies, plugins, libraries, and
     * submodules.
     *
     * @return bool
     */
    public function build();

    /**
     * Move files into place and symlink appropriate locations
     * from the local shared/ folder into the application's web root.
     */
    public function install();
    
}
