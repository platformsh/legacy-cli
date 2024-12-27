<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Shell;
use GuzzleHttp\Client;
use GuzzleHttp\Utils;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\VersionUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;

#[AsCommand(name: 'self:release', description: 'Build and release a new version')]
class SelfReleaseCommand extends CommandBase
{
    protected bool $hiddenInList = true;
    public function __construct(private readonly Config $config, private readonly Git $git, private readonly QuestionHelper $questionHelper, private readonly Shell $shell, private readonly SubCommandRunner $subCommandRunner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $defaultRepo = $this->config->getStr('application.github_repo');
        $defaultReleaseBranch = $this->config->getStr('application.release_branch');

        $this
            ->addArgument('version', InputArgument::OPTIONAL, 'The new version number')
            ->addOption('phar', null, InputOption::VALUE_REQUIRED, 'The path to a newly built Phar file')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'The GitHub repository', $defaultRepo)
            ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'The manifest file to update')
            ->addOption('manifest-mode', null, InputOption::VALUE_REQUIRED, 'How to update the manifest file', 'update-latest-matching')
            ->addOption('release-branch', null, InputOption::VALUE_REQUIRED, 'Override the release branch', $defaultReleaseBranch)
            ->addOption('last-version', null, InputOption::VALUE_REQUIRED, 'The last version number')
            ->addOption('no-check-changes', null, InputOption::VALUE_NONE, 'Skip check for uncommitted changes, or no change since the last version')
            ->addOption('allow-lower', null, InputOption::VALUE_NONE, 'Allow releasing with a lower version number than the last');
    }

    public function isEnabled(): bool
    {
        return $this->config->has('application.github_repo')
            && (!extension_loaded('Phar') || !\Phar::running(false));
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->git->setDefaultRepositoryDir(CLI_ROOT);

        $releaseBranch = $input->getOption('release-branch');
        if ($this->git->getCurrentBranch(CLI_ROOT, true) !== $releaseBranch) {
            $this->stdErr->writeln('You must be on the ' . $releaseBranch . ' branch to make a release.');

            return 1;
        }

        if (!$input->getOption('no-check-changes')) {
            $gitStatus = $this->git->execute(['status', '--porcelain'], CLI_ROOT, true);
            if (is_string($gitStatus) && !empty($gitStatus)) {
                foreach (explode("\n", $gitStatus) as $statusLine) {
                    if (!str_contains($statusLine, ' config.yaml')) {
                        $this->stdErr->writeln('There are uncommitted changes in Git. Cannot proceed.');
                        $this->stdErr->writeln('Use the --no-check-changes option to override this.');

                        return 1;
                    }
                }
            }
        }

        if ($this->shell->commandExists('gh')) {
            $process = $this->shell->executeCaptureProcess('gh auth status --show-token', null, true);
            if (!preg_match('/Token: (\S+)/', $process->getOutput(), $matches)) {
                $this->stdErr->writeln('Unable to obtain a GitHub token.');
                $this->stdErr->writeln('Log in to the GitHub CLI with: <info>gh auth login</info>');
                return 1;
            }
            $gitHubToken = $matches[1];
        } elseif (getenv('GITHUB_TOKEN')) {
            $gitHubToken = getenv('GITHUB_TOKEN');
        } else {
            $this->stdErr->writeln('The GITHUB_TOKEN environment variable should be set, or the gh CLI should be installed.');
            return 1;
        }

        // Find the previous version number.
        if ($input->getOption('last-version')) {
            $lastVersion = ltrim((string) $input->getOption('last-version'), 'v');
            $lastTag = 'v' . $lastVersion;

            $this->stdErr->writeln('Last version number: <info>' . $lastVersion . '</info>');
        } else {
            $lastTag = $this->shell->mustExecute(['git', 'describe', '--tags', '--abbrev=0'], dir: CLI_ROOT);
            $lastVersion = ltrim($lastTag, 'v');
            $this->stdErr->writeln('Last version number (from latest Git tag): <info>' . $lastVersion . '</info>');
        }

        if (!$input->getOption('no-check-changes') && !$this->hasGitDifferences($lastTag)) {
            $this->stdErr->writeln('There are no changes since the last version.');

            return 1;
        }

        $allowLower = (bool) $input->getOption('allow-lower');
        $validateNewVersion = function ($next) use ($lastVersion, $allowLower) {
            if ($next === null) {
                throw new \InvalidArgumentException('The new version is required.');
            }
            if (!$allowLower && version_compare($next, $lastVersion, '<=')) {
                throw new \InvalidArgumentException(
                    'The new version number must be greater than ' . $lastVersion
                    . "\n" . 'Use --allow-lower to skip this check.',
                );
            }

            return $next;
        };

        $versionUtil = new VersionUtil();

        $newVersion = $input->getArgument('version');
        if ($newVersion !== null) {
            $validateNewVersion($newVersion);
        } else {
            if (!$input->isInteractive()) {
                $this->stdErr->writeln('The version number is required in non-interactive mode.');

                return 1;
            }

            // Find a good default new version number.
            $default = null;
            $autoComplete = [];
            if ($nextVersions = $versionUtil->nextVersions($lastVersion)) {
                $default = reset($nextVersions);
                $autoComplete = $nextVersions;
            }
            $newVersion = $this->questionHelper->askInput('New version number', $default, $autoComplete, $validateNewVersion);
        }

        // Set up GitHub API connection details.
        $http = new Client();
        $repo = $input->getOption('repo') ?: $this->config->getStr('application.github_repo');
        $repoUrl = implode('/', array_map('rawurlencode', explode('/', (string) $repo)));
        $repoApiUrl = 'https://api.github.com/repos/' . $repoUrl;
        $repoGitUrl = 'git@github.com:' . $repo . '.git';

        // Check if the chosen version number already exists as a release.
        $tagName = 'v' . ltrim((string) $newVersion, 'v');
        $existsResponse = $http->get($repoApiUrl . '/releases/tags/' . $tagName, [
            'headers' => [
                'Authorization' => 'token ' . $gitHubToken,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
            'http_errors' => false,
            'debug' => $output->isDebug(),
        ]);
        if ($existsResponse->getStatusCode() !== 404) {
            if ($existsResponse->getStatusCode() >= 300) {
                $this->stdErr->writeln('Failed to check for an existing release on GitHub.');

                return 1;
            }
            $this->stdErr->writeln('A release tagged ' . $tagName . ' already exists on GitHub.');

            return 1;
        }

        // Validate the --phar option.
        $pharFilename = $input->getOption('phar');
        if ($pharFilename && !file_exists($pharFilename)) {
            $this->stdErr->writeln('File not found: <error>' . $pharFilename . '</error>');

            return 1;
        }

        // Check the manifest file for the right item to update.
        $manifestFile = $input->getOption('manifest') ?: CLI_ROOT . '/dist/manifest.json';
        $contents = file_get_contents($manifestFile);
        if ($contents === false) {
            throw new \RuntimeException('Manifest file not readable: ' . $manifestFile);
        }
        if (!is_writable($manifestFile)) {
            throw new \RuntimeException('Manifest file not writable: ' . $manifestFile);
        }
        $this->stdErr->writeln('Checking manifest file: ' . $manifestFile);
        $manifest = json_decode($contents, true);
        if ($manifest === null && json_last_error()) {
            throw new \RuntimeException('Failed to decode manifest file: ' . $manifestFile);
        }
        $latestItem = null;
        $latestSameMajorItem = null;
        foreach ($manifest as $key => $item) {
            if ($latestItem === null || version_compare($item['version'], $latestItem['version'], '>')) {
                $latestItem = &$manifest[$key];
            }
            if ($versionUtil->majorVersion($item['version']) === $versionUtil->majorVersion($newVersion)) {
                if ($latestSameMajorItem === null || version_compare($item['version'], $latestSameMajorItem['version'], '>')) {
                    $latestSameMajorItem = &$manifest[$key];
                }
            }
        }
        $manifestItem = null;
        switch ($input->getOption('manifest-mode')) {
            case 'update-latest':
                $manifestItem = &$latestItem;
                break;

            case 'update-latest-matching':
                $manifestItem = &$latestSameMajorItem;
                break;

            case 'add':
                break;

            default:
                throw new \RuntimeException('Unrecognised --manifest-mode: ' . $input->getOption('manifest-mode'));
        }
        if ($manifestItem === null) {
            array_unshift($manifest, []);
            $manifestItem = &$manifest[0];
        }

        // Confirm the release changelog.
        [$changelogFilename, $changelog] = $this->getReleaseChangelog($lastTag, $tagName);
        $questionText = "\nChangelog:\n\n" . $changelog . "\n\nIs this changelog correct?";
        if (!$this->questionHelper->confirm($questionText)) {
            $this->stdErr->writeln('Update or delete the file <comment>' . $changelogFilename . '</comment> and re-run this command.');

            return 1;
        }

        // Build a Phar file, if one doesn't already exist.
        if (!$pharFilename) {
            $pharFilename = sys_get_temp_dir() . '/' . $this->config->getStr('application.executable') . '.phar';
            $result = $this->subCommandRunner->run('self:build', [
                '--output' => $pharFilename,
                '--yes' => true,
                '--replace-version' => $newVersion,
            ]);
            if ($result !== 0) {
                $this->stdErr->writeln('The build failed');

                return $result;
            }
        }

        // Validate that the Phar file has the right version number.
        if ($pharFilename) {
            $versionInPhar = $this->shell->mustExecute([
                (new PhpExecutableFinder())->find() ?: PHP_BINARY,
                $pharFilename,
                '--version',
            ]);
            if (!str_contains($versionInPhar, (string) $newVersion)) {
                $this->stdErr->writeln('The file ' . $pharFilename . ' reports a different version: "' . $versionInPhar . '"');

                return 1;
            }
        }

        // Construct the download URL (the public location of the Phar file).
        $pharPublicFilename = $this->config->getStr('application.executable') . '.phar';
        $download_url = str_replace('{tag}', $tagName, $this->config->getWithDefault(
            'application.download_url',
            'https://github.com/' . $repoUrl . '/releases/download/{tag}/' . $pharPublicFilename,
        ));

        // Construct the new manifest item details.
        $manifestItem['version'] = $newVersion;
        $manifestItem['sha1'] = sha1_file($pharFilename);
        $manifestItem['sha256'] = hash_file('sha256', $pharFilename);
        $manifestItem['name'] = basename($pharPublicFilename);
        $manifestItem['url'] = $download_url;
        $manifestItem['php']['min'] = '8.2';
        if (!empty($changelog)) {
            // This is the newer release notes format.
            $manifestItem['notes'][$newVersion] = $changelog;

            $this->stdErr->writeln('<info>Changes:</info>');
            $this->stdErr->writeln($changelog);
            $this->stdErr->writeln('');
        }
        $result = file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($result !== false) {
            $this->stdErr->writeln('Updated manifest file: ' . $manifestFile);
        } else {
            $this->stdErr->writeln('Failed to update manifest file: ' . $manifestFile);

            return 1;
        }

        // Commit any changes to Git.
        $gitStatus = $this->git->execute(['status', '--porcelain'], CLI_ROOT, true);
        if (is_string($gitStatus) && !empty($gitStatus)) {
            $this->stdErr->writeln('Committing changes to Git');

            $result = $this->shell->executeSimple('git commit --patch config.yaml dist/manifest.json --message ' . escapeshellarg('Release v' . $newVersion) . ' --edit', CLI_ROOT);
            if ($result !== 0) {
                return $result;
            }
        }

        // Tag the current commit.
        $this->stdErr->writeln('Creating tag <info>' . $tagName . '</info>');
        $this->git->execute(['tag', '--force', $tagName], CLI_ROOT, true);

        // Push to GitHub.
        if (!$this->questionHelper->confirm('Push changes to <comment>' . $releaseBranch . '</comment> branch on ' . $repoGitUrl . '?')) {
            return 1;
        }
        $this->shell->execute(['git', 'push', $repoGitUrl, 'HEAD:' . $releaseBranch], CLI_ROOT, true);
        $this->shell->execute(['git', 'push', '--force', $repoGitUrl, $tagName], CLI_ROOT, true);

        // Upload a release to GitHub.
        $lastReleasePublicUrl = 'https://github.com/' . $repoUrl . '/releases/' . $lastTag;
        $releaseDescription = sprintf('Changes since [%s](%s):', $lastTag, $lastReleasePublicUrl);
        if (!empty($changelog)) {
            $releaseDescription .= "\n\n" . $changelog;
        }
        $releaseDescription .= "\n\n" . 'https://github.com/' . $repoUrl . '/compare/' . $lastTag . '...' . $tagName;
        $releaseDescription .= "\n\n" . sprintf('SHA-256 checksum for `%s`:', $pharPublicFilename)
            . "\n" . sprintf('`%s`', hash_file('sha256', $pharFilename));
        $this->stdErr->writeln('');
        $this->stdErr->writeln('Creating new release ' . $tagName . ' on GitHub');
        $this->stdErr->writeln('Release description:');
        $this->stdErr->writeln(preg_replace('/^/m', '  ', $releaseDescription));
        $this->stdErr->writeln('');
        $http = new Client();
        $response = $http->post($repoApiUrl . '/releases', [
            'headers' => [
                'Authorization' => 'token ' . $gitHubToken,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'tag_name' => $tagName,
                'name' => $tagName,
                'body' => $releaseDescription,
                'draft' => true,
                'prerelease' => $versionUtil->isPreRelease($newVersion),
            ],
            'debug' => $output->isDebug(),
        ]);
        $release = (array) Utils::jsonDecode((string) $response->getBody(), true);
        $releaseUrl = $repoApiUrl . '/releases/' . $release['id'];
        $uploadUrl = preg_replace('/\{.+?}/', '', (string) $release['upload_url']);

        // Upload the Phar to the GitHub release.
        $this->stdErr->writeln('Uploading the Phar file to the release');
        $fileResource = fopen($pharFilename, 'r');
        if (!$fileResource) {
            throw new \RuntimeException('Failed to open file for reading: ' . $fileResource);
        }
        $http->post($uploadUrl . '?name=' . rawurldecode($pharPublicFilename), [
            'headers' => [
                'Authorization' => 'token ' . $gitHubToken,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/octet-stream',
            ],
            'body' => $fileResource,
            'debug' => $output->isDebug(),
        ]);

        // Mark the GitHub release as published.
        $this->stdErr->writeln('Publishing the release');
        $http->patch($releaseUrl, [
            'headers' => [
                'Authorization' => 'token ' . $gitHubToken,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
            'json' => ['draft' => false],
            'debug' => $output->isDebug(),
        ]);
        $this->stdErr->writeln('');
        $this->stdErr->writeln('Release successfully published');
        $this->stdErr->writeln('https://github.com/' . $repoUrl . '/releases/latest');

        return 0;
    }

    /**
     * @param string $lastVersionTag The tag corresponding to the last version.
     * @param string $newVersionTag The tag corresponding to the new version being released.
     *
     * @return string[] The filename and the current changelog.
     */
    private function getReleaseChangelog(string $lastVersionTag, string $newVersionTag): array
    {
        $filename = CLI_ROOT . '/release-changelog-' . $newVersionTag . '.md';
        if (file_exists($filename)) {
            $mTime = filemtime($filename);
            $lastVersionDate = $this->getTagDate($lastVersionTag);
            if (!$lastVersionDate || !$mTime || $mTime > $lastVersionDate) {
                $contents = file_get_contents($filename);
                if ($contents === false) {
                    throw new \RuntimeException('Failed to read file: ' . $filename);
                }
                $changelog = trim($contents);
            }
        }
        if (empty($changelog)) {
            $changelog = $this->getGitChangelog($lastVersionTag);
            (new Filesystem())->dumpFile($filename, $changelog);
        }

        return [$filename, $changelog];
    }

    /**
     * Returns the commit date associated with a tag.
     *
     * @param string $tagName
     *
     * @return int|false
     */
    private function getTagDate(string $tagName): int|false
    {
        $date = $this->git->execute(['log', '-1', '--format=%aI', 'refs/tags/' . $tagName]);

        return is_string($date) ? strtotime(trim($date)) : false;
    }

    /**
     * Checks if there are relevant Git differences since the last version.
     *
     * @param string      $since
     *
     * @return bool
     */
    private function hasGitDifferences(string $since): bool
    {
        $stat = $this->git->execute(['diff', '--numstat', $since . '...HEAD'], CLI_ROOT, true);
        if (!is_string($stat)) {
            return false;
        }

        foreach (explode("\n", trim($stat)) as $line) {
            // Exclude config.yaml and dist/manifest.json from the check.
            if (!str_contains($line, ' config.yaml') && !str_contains($line, ' dist/manifest.json')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $since
     *
     * @return string
     */
    private function getGitChangelog(string $since): string
    {
        $changelog = $this->git->execute([
            'log',
            '--pretty=tformat:* %s%n%b',
            '--no-merges',
            '--invert-grep',
            '--grep=(Release v|\[skip changelog\])',
            '--perl-regexp',
            '--regexp-ignore-case',
            $since . '...HEAD',
        ], CLI_ROOT);
        if (!is_string($changelog)) {
            return '';
        }

        $changelog = preg_replace('/^[^*\n]/m', '    $0', $changelog);
        $changelog = preg_replace('/\n+\*/', "\n*", (string) $changelog);
        $changelog = trim((string) $changelog);

        return $changelog;
    }
}
