<?php

namespace CommerceGuys\Platform\Cli\Toolstack;

use CommerceGuys\Platform\Cli;
use Symfony\Component\Console;

interface LocalBuildInterface
{
  
    /**
     * Detect if the files in a given "application root" folder path belong to 
     * this toolstack.
     * 
     * @param   string  $appRoot The absolute path to the application folder
     *
     * @param   array   $settings The settings parsed from the 
     *                  .platform.app.yaml file, if any.
     *
     * @return  bool    Whether this application layer is a valid choice or not
     */
    public static function detect($appRoot, $settings);
    
    /**
     * Prepare this application to be built. This function should be isometric
     * and not affect the file system.
     * 
     * @return  void
     */
    public function prepareBuild();
    
    /**
     * Build this application. Acquire dependencies, plugins, libraries, and
     * submodules. Move files into place and symlink appropriate locations
     * from the local shared/ folder into the application's webroot.
     *
     * @return  void
     */
    public function build();
    
}
