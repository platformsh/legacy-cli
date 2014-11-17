<?php
namespace CommerceGuys\Platform\Cli\Local;

use CommerceGuys\Platform\Cli\Local\Toolstack\ToolstackInterface;
use Symfony\Component\Yaml\Parser;

class LocalBuild {

    /**
     * @return ToolstackInterface[]
     */
    public static function getToolstacks()
    {
        return array(
            new Toolstack\Drupal(),
            new Toolstack\Symfony(),
        );
    }

    /**
     * Get a list of applications in the repository.
     *
     * @param string $repositoryRoot   The absolute path to the repository.
     *
     * @return string[]    A list of directories containing applications.
     */
    public static function getApplications($repositoryRoot)
    {
        // @todo: Determine multiple project roots, perhaps using Finder again
        return array($repositoryRoot);
    }

    /**
     * Get the application's configuration, parsed from its YAML definition.
     *
     * @param string $appRoot   The absolute path to the application.
     *
     * @return array|false  The configuration, or false if not found.
     */
    public static function getAppConfig($appRoot)
    {
        if (file_exists($appRoot . '/.platform.app.yaml')) {
            $parser = new Parser();
            return $parser->parse(file_get_contents($appRoot . '/.platform.app.yaml'));
        }
        return false;
    }

    /**
     * Get the toolstack for a particular application.
     *
     * @param string $appRoot   The absolute path to the application.
     * @param mixed $appConfig  The application's configuration.
     *
     * @throws \Exception   If a specified toolstack is not found.
     *
     * @return ToolstackInterface|false
     */
    public static function getToolstack($appRoot, $appConfig = false)
    {
        $toolstackChoice = false;
        if ($appConfig && isset($appConfig['toolstack'])) {
            $toolstackChoice = $appConfig['toolstack'];
        }
        foreach (self::getToolstacks() as $toolstack) {
            if (($toolstackChoice && $toolstack->getKey() == $toolstackChoice)
                || $toolstack->detect($appRoot)) {
                return $toolstack;
            }
        }
        if ($toolstackChoice) {
            throw new \Exception("Toolstack not found: $toolstackChoice");
        }
        return false;
    }

}
