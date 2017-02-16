<?php
namespace Platformsh\Cli\Local;

use Platformsh\Cli\Service\Shell;
use Symfony\Component\Console\Output\OutputInterface;

class DependencyInstaller
{
    protected $output;
    protected $shell;

    public function __construct(OutputInterface $output, Shell $shell)
    {
        $this->output = $output;
        $this->shell = $shell;
    }

    /**
     * Modify the environment to make the installed dependencies available.
     *
     * @param string $destination
     * @param array  $dependencies
     */
    public function putEnv($destination, array $dependencies)
    {
        $env = [];
        $paths = [];
        foreach ($dependencies as $stack => $stackDependencies) {
            $manager = $this->getManager($stack);
            $path = $destination . '/' . $stack;
            $paths = array_merge($paths, $manager->getBinPaths($path));
            $env = array_merge($env, $manager->getEnvVars($path));
        }
        $paths = array_filter(array_map('realpath', $paths));
        if (!empty($paths)) {
            $pathVariable = stripos(PHP_OS, 'WIN') === 0 ? 'Path' : 'PATH';
            $env[$pathVariable] = implode(':', $paths);
            if (getenv($pathVariable)) {
                $env[$pathVariable] .= ':' . getenv($pathVariable);
            }
        }
        foreach ($env as $name => $value) {
            putenv($name . '=' . $value);
        }
    }

    /**
     * @param string $destination
     * @param array $dependencies
     */
    public function installDependencies($destination, array $dependencies)
    {
        foreach ($dependencies as $stack => $stackDependencies) {
            $manager = $this->getManager($stack);
            $this->output->writeln(sprintf(
                "Installing <info>%s</info> dependencies with '%s': %s",
                $stack,
                $manager->getCommandName(),
                implode(', ', array_keys($stackDependencies))
            ));
            if (!$manager->isAvailable()) {
                throw new \RuntimeException(rtrim(sprintf(
                    "Cannot install %s dependencies: '%s' is not installed\n%s",
                    $stack,
                    $manager->getCommandName(),
                    $manager->getInstallHelp()
                )));
            }
            $path = $destination . '/' . $stack;
            $this->ensureDirectory($path);
            $manager->install($path, $stackDependencies);
        }
    }

    /**
     * @param string $path
     */
    protected function ensureDirectory($path)
    {
        if (!is_dir($path) && !mkdir($path, 0755, true)) {
            throw new \RuntimeException('Failed to create directory: ' . $path);
        }
    }

    /**
     * @param string $name
     *
     * @return \Platformsh\Cli\Local\DependencyManager\DependencyManagerInterface
     */
    protected function getManager($name)
    {
        $stacks = [
            'nodejs' => new DependencyManager\Yarn($this->shell),
            'python' => new DependencyManager\Pip($this->shell),
            'ruby' => new DependencyManager\Bundler($this->shell),
            'php' => new DependencyManager\Composer($this->shell),
        ];

        if (!isset($stacks[$name])) {
            throw new \InvalidArgumentException(sprintf('Unknown dependencies stack: %s', $name));
        }

        return $stacks[$name];
    }
}
