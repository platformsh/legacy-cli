<?php
namespace Platformsh\Cli\Command\Self;

use GuzzleHttp\Client;
use function GuzzleHttp\json_decode;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;

class SelfReleaseCommand extends CommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $defaultRepo = $this->config()->getWithDefault('application.github_repo', null);
        $defaultReleaseBranch = $this->config()->getWithDefault('application.release_branch', 'master');

        $this
            ->setName('self:release')
            ->setDescription('Build and release a new version')
            ->addOption('phar', null, InputOption::VALUE_REQUIRED, 'The path to a newly built Phar file')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'The GitHub repository', $defaultRepo)
            ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'The manifest file to update')
            ->addOption('manifest-mode', null, InputOption::VALUE_REQUIRED, 'How to update the manifest file', 'update-latest')
            ->addOption('release-branch', null, InputOption::VALUE_REQUIRED, 'Override the release branch', $defaultReleaseBranch)
            ->addOption('last-version', null, InputOption::VALUE_REQUIRED, 'Specify the last version number')
            ->addOption('no-check-changes', null, InputOption::VALUE_NONE, 'Skip check for uncommitted changes')
            ->addOption('allow-lower', null, InputOption::VALUE_NONE, 'Allow releasing with a lower version number than the last');
    }

    public function isEnabled()
    {
        return $this->config()->has('application.github_repo')
            && (!extension_loaded('Phar') || !\Phar::running(false));
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $git->setDefaultRepositoryDir(CLI_ROOT);

        $releaseBranch = $input->getOption('release-branch');
        if ($git->getCurrentBranch(CLI_ROOT, true) !== $releaseBranch) {
            $this->stdErr->writeln('You must be on the ' . $releaseBranch . ' branch to make a release.');

            return 1;
        }

        $developmentDiffStat = $git->execute(['diff', '--numstat', $releaseBranch . '...development'], CLI_ROOT);
        if (is_string($developmentDiffStat) && strlen(trim($developmentDiffStat)) && $questionHelper->confirm('Merge changes from development?')) {
            $git->execute(['merge', 'development'], CLI_ROOT, true);
        }

        if (!$input->getOption('no-check-changes')) {
            $gitStatus = $git->execute(['status', '--porcelain'], CLI_ROOT, true);
            if (is_string($gitStatus) && !empty($gitStatus)) {
                foreach (explode("\n", $gitStatus) as $statusLine) {
                    if (strpos($statusLine, ' config.yaml') === false) {
                        $this->stdErr->writeln('There are uncommitted changes in Git. Cannot proceed.');

                        return 1;
                    }
                }
            }
        }

        if (getenv('GITHUB_TOKEN')) {
            $gitHubToken = getenv('GITHUB_TOKEN');
        } else {
            $this->stdErr->writeln('The GITHUB_TOKEN environment variable must be set');

            return 1;
        }

        $newVersion = $this->config()->get('application.version');
        $this->stdErr->writeln('The version number defined in the config.yaml file is: <comment>' . $newVersion . '</comment>');

        if (substr($newVersion, 0, 1) === 'v') {
            $this->stdErr->writeln('The version number should not be prefixed by `v`.');

            return 1;
        }
        if (!$questionHelper->confirm('Is <comment>' . $newVersion . '</comment> the correct new version number?')) {
            $this->stdErr->writeln('Update the version number in config.yaml and re-run this command.');

            return 1;
        }

        $tagName = 'v' . $newVersion;
        $http = new Client();
        $repo = $input->getOption('repo') ?: $this->config()->get('application.github_repo');
        $repoUrl = implode('/', array_map('rawurlencode', explode('/', $repo)));
        $repoApiUrl = 'https://api.github.com/repos/' . $repoUrl;
        $repoGitUrl = 'git@github.com:' . $repo . '.git';

        $existsResponse = $http->get($repoApiUrl . '/releases/tags/' . $tagName, [
            'headers' => [
                'Authorization' => 'token ' . $gitHubToken,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
            'exceptions' => false,
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

        $pharFilename = $input->getOption('phar');
        if ($pharFilename && !file_exists($pharFilename)) {
            $this->stdErr->writeln('File not found: <error>' . $pharFilename . '</error>');

            return 1;
        }
        if (!$pharFilename) {
            $pharFilename = CLI_ROOT . '/' . $this->config()->get('application.executable') . '.phar';
            $result = $this->runOtherCommand('self:build', [
                '--output' => $pharFilename,
            ]);
            if ($result !== 0) {
                $this->stdErr->writeln('The build failed');

                return $result;
            }
        } else {
            $versionInPhar = $shell->execute([
                (new PhpExecutableFinder())->find() ?: PHP_BINARY,
                $pharFilename,
                '--version'
            ], null, true);
            if (strpos($versionInPhar, $newVersion) === false) {
                $this->stdErr->writeln('The file ' . $pharFilename . ' reports a different version: "' . $versionInPhar . '"');

                return 1;
            }
        }

        // Write to the manifest file.
        $manifestFile = $input->getOption('manifest') ?: CLI_ROOT . '/dist/manifest.json';
        $contents = file_get_contents($manifestFile);
        if ($contents === false) {
            throw new \RuntimeException('Manifest file not readable: ' . $manifestFile);
        }
        if (!is_writable($manifestFile)) {
            throw new \RuntimeException('Manifest file not writable: ' . $manifestFile);
        }
        $this->stdErr->writeln('Updating manifest file: ' . $manifestFile);
        $manifest = json_decode($contents, true);
        $latestItem = null;
        foreach ($manifest as $key => $item) {
            if ($latestItem === null || version_compare($item['version'], $latestItem['version'], '>')) {
                $latestItem = &$manifest[$key];
            }
        }

        switch ($input->getOption('manifest-mode')) {
            case 'update-latest':
                $manifestItem = &$latestItem;
                break;

            case 'add':
                array_unshift($manifest, []);
                $manifestItem = &$manifest[0];
                break;

            default:
                throw new \RuntimeException('Unrecognised --manifest-mode: ' . $input->getOption('manifest-mode'));
        }

        if ($input->getOption('last-version')) {
            $lastVersion = ltrim($input->getOption('last-version'), 'v');
            $lastTag = 'v' . $lastVersion;
        } else {
            $response = $http->get($repoApiUrl . '/releases/latest', [
                'headers' => [
                    'Authorization' => 'token ' . $gitHubToken,
                    'Accept' => 'application/vnd.github.v3+json',
                    'Content-Type' => 'application/json',
                ],
                'debug' => $output->isDebug(),
            ]);
            $latestRelease = json_decode($response->getBody()->getContents(), true);
            $lastTag = $latestRelease['tag_name'];
            $lastVersion = ltrim($lastTag, 'v');
            $this->stdErr->writeln('  Found latest version: v' . $lastVersion);
        }

        if (version_compare($newVersion, $lastVersion, '<') && !$input->getOption('allow-lower')) {
            $this->stdErr->writeln(sprintf('The new version number <error>%s</error> is lower than the last version number <error>%s</error>.', $newVersion, $lastVersion));
            $this->stdErr->writeln('Use --allow-lower to skip this check.');

            return 1;
        }

        $pharPublicFilename = $this->config()->get('application.executable') . '.phar';

        $changelog = $this->getReleaseChangelog($lastVersion, $repoApiUrl);
        $questionText = "\nChangelog:\n\n" . $changelog . "\n\nIs this changelog correct?";
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm($questionText)) {
            $this->stdErr->writeln('Update or delete the file <comment>' . CLI_ROOT . '/release-changelog.md</comment> and re-run this command.');

            return 1;
        }

        $manifestItem['version'] = $newVersion;
        $manifestItem['sha1'] = sha1_file($pharFilename);
        $manifestItem['sha256'] = hash_file('sha256', $pharFilename);
        $manifestItem['name'] = basename($pharPublicFilename);
        $manifestItem['url'] = 'https://github.com/' . $repoUrl . '/releases/download/' . $tagName . '/' . $pharPublicFilename;
        $manifestItem['php']['min'] = '5.5.9';
        if (!empty($changelog)) {
            $manifestItem['updating'][] = [
                'notes' => $changelog,
                'show from' => $lastVersion,
                'hide from' => $newVersion,
            ];
            $this->stdErr->writeln('<info>Changes:</info>');
            $this->stdErr->writeln($changelog);
            $this->stdErr->writeln('');
        }
        $result = file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($result !== false) {
            $this->stdErr->writeln('Updated manifest file: ' . $manifestFile);
        }
        else {
            $this->stdErr->writeln('Failed to update manifest file: ' . $manifestFile);

            return 1;
        }

        $gitStatus = $git->execute(['status', '--porcelain'], CLI_ROOT, true);
        if (is_string($gitStatus) && !empty($gitStatus)) {
            $this->stdErr->writeln('Committing changes to Git');

            $result = $shell->executeSimple('git commit --patch config.yaml dist/manifest.json --message ' . escapeshellarg('Release v' . $newVersion) . ' --edit', CLI_ROOT);
            if ($result !== 0) {
                return $result;
            }
        }

        $this->stdErr->writeln('Creating tag <info>' . $tagName . '</info>');
        $git->execute(['tag', '--force', $tagName], CLI_ROOT, true);

        if (!$questionHelper->confirm('Push changes and tag to <comment>' . $releaseBranch . '</comment> branch on ' . $repoGitUrl . '?')) {
            return 1;
        }
        $shell->execute(['git', 'push', $repoGitUrl, 'HEAD:' . $releaseBranch], CLI_ROOT, true);
        $shell->execute(['git', 'push', '--force', $repoGitUrl, $tagName], CLI_ROOT, true);

        $lastReleasePublicUrl = 'https://github.com/' . $repoUrl . '/releases/' . $lastTag;
        $releaseDescription = sprintf('Changes since [%s](%s):', $lastTag, $lastReleasePublicUrl);
        if (!empty($changelog)) {
            $releaseDescription .= "\n\n" . $changelog;
        } else {
            $releaseDescription .= "\n\n" . 'https://github.com/' . $repoUrl . '/compare/' . $lastTag . '...' . $tagName;
        }

        $releaseDescription .= "\n\n" . sprintf('SHA-256 checksum for `%s`:', $pharPublicFilename)
            . "\n" . sprintf('`%s`', hash_file('sha256', $pharFilename));

        $this->stdErr->writeln('');
        $this->stdErr->writeln('Creating new release ' . $tagName . ' on GitHub');
        $this->stdErr->writeln('Release description:');
        $this->stdErr->writeln(preg_replace('/^/m', '  ', $releaseDescription));
        $this->stdErr->writeln('');

        if (!$questionHelper->confirm('Is this OK?')) {
            return 1;
        }

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
            ],
            'debug' => $output->isDebug(),
        ]);
        $release = json_decode($response->getBody()->getContents(), true);
        $releaseUrl = $repoApiUrl . '/releases/' . $release['id'];
        $uploadUrl = preg_replace('/\{.+?\}/', '', $release['upload_url']);

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
     * @param string $lastVersion The last version number.
     * @param string $repoApiUrl
     *
     * @return string
     */
    private function getReleaseChangelog($lastVersion, $repoApiUrl)
    {
        $lastVersionTag = 'v' . ltrim($lastVersion, 'v');
        $filename = CLI_ROOT . '/release-changelog.md';
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
            $changelog = $this->getGitChangelog($lastVersionTag, $repoApiUrl);
            (new Filesystem())->dumpFile($filename, $changelog);
        }

        return $changelog;
    }

    /**
     * Returns the commit date associated with a tag.
     *
     * @param string $tagName
     *
     * @return int|false
     */
    private function getTagDate($tagName)
    {
        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $date = $git->execute(['log', '-1', '--format=%aI', 'refs/tags/' . $tagName]);

        return is_string($date) ? strtotime(trim($date)) : false;
    }

    /**
     * @param string $since
     * @param string $repoApiUrl
     *
     * @return string
     */
    private function getGitChangelog($since, $repoApiUrl)
    {
        $http = new Client();
        $sha = $http->get($repoApiUrl . '/commits/' . rawurlencode($since), [
            'headers' => [
                'Authorization' => 'token ' . getenv('GITHUB_TOKEN'),
                'Accept' => 'application/vnd.github.v3.sha',
            ],
            'debug' => $this->stdErr->isDebug(),
        ])->getBody();

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $changelog = $git->execute([
            'log',
            '--pretty=tformat:* %s%n%b',
            '--no-merges',
            '--invert-grep',
            '--grep=(Release v|\[skip changelog\])',
            '--perl-regexp',
            '--regexp-ignore-case',
            $sha . '...HEAD'
        ], CLI_ROOT);
        if (!is_string($changelog)) {
            return '';
        }

        $changelog = preg_replace('/^[^\*\n]/m', '    $0', $changelog);
        $changelog = preg_replace('/\n+\*/', "\n*", $changelog);
        $changelog = trim($changelog);

        return $changelog;
    }
}
