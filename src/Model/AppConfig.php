<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model;

use Platformsh\Cli\Exception\InvalidConfigException;
use Platformsh\Client\Model\Deployment\WebApp;

/**
 * A class to help reading and normalizing an application's configuration.
 */
final class AppConfig
{
    /** @var array<string, mixed> */
    private array $normalizedConfig;

    /**
     * AppConfig constructor.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
        $this->normalizedConfig = $this->normalizeConfig($this->config);
    }

    /**
     * @param WebApp $app
     *
     * @return self
     */
    public static function fromWebApp(WebApp $app): self
    {
        return new self($app->getProperties());
    }

    /**
     * Get normalized configuration.
     *
     * @return array<string, mixed>
     */
    public function getNormalized(): array
    {
        if (!isset($this->normalizedConfig)) {
            $this->normalizedConfig = $this->normalizeConfig($this->config);
        }

        return $this->normalizedConfig;
    }

    /**
     * Get the (normalized) document root as a relative path.
     *
     * @return string
     */
    public function getDocumentRoot(): string
    {
        $documentRoot = '';
        $normalized = $this->getNormalized();
        if (empty($normalized['web']['locations'])) {
            return $documentRoot;
        }
        foreach ($this->getNormalized()['web']['locations'] as $path => $location) {
            if (isset($location['root'])) {
                $documentRoot = $location['root'];
            }
            if ($path === '/') {
                break;
            }
        }

        return ltrim((string) $documentRoot, '/');
    }

    /**
     * Normalize the application config.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function normalizeConfig(array $config): array
    {
        // Backwards compatibility with old config format: `toolstack` is
        // changed to application `type` and `build`.`flavor`.
        if (isset($config['toolstack'])) {
            if (!strpos((string) $config['toolstack'], ':')) {
                throw new InvalidConfigException("Invalid value for 'toolstack'");
            }
            [$config['type'], $config['build']['flavor']] = explode(':', (string) $config['toolstack'], 2);
        }

        // The `web` section has changed to `web`.`locations`.
        if (isset($config['web']['document_root']) && empty($config['web']['locations'])) {
            $oldConfig = $config['web'] + $this->getOldWebDefaults();

            $location = &$config['web']['locations']['/'];

            $location['root'] = $oldConfig['document_root'];
            $location['expires'] = $oldConfig['expires'];
            $location['passthru'] = $oldConfig['passthru'];
            $location['allow'] = true;

            foreach ($oldConfig['whitelist'] as $pattern) {
                $location['allow'] = false;
                $location['rules'][$pattern]['allow'] = true;
            }

            foreach ($oldConfig['blacklist'] as $pattern) {
                $location['rules'][$pattern]['allow'] = false;
            }
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function getOldWebDefaults(): array
    {
        return [
            'document_root' => '/public',
            'expires' => 0,
            'passthru' => '/index.php',
            'blacklist' => [],
            'whitelist' => [
                // CSS and Javascript.
                '\.css$',
                '\.js$',

                // image/* types.
                '\.gif$',
                '\.jpe?g$',
                '\.png$',
                '\.tiff?$',
                '\.wbmp$',
                '\.ico$',
                '\.jng$',
                '\.bmp$',
                '\.svgz?$',

                // audio/* types.
                '\.midi?$',
                '\.mpe?ga$',
                '\.mp2$',
                '\.mp3$',
                '\.m4a$',
                '\.ra$',
                '\.weba$',

                // video/* types.
                '\.3gpp?$',
                '\.mp4$',
                '\.mpe?g$',
                '\.mpe$',
                '\.ogv$',
                '\.mov$',
                '\.webm$',
                '\.flv$',
                '\.mng$',
                '\.asx$',
                '\.asf$',
                '\.wmv$',
                '\.avi$',

                // application/ogg.
                '\.ogx$',

                // application/x-shockwave-flash.
                '\.swf$',

                // application/java-archive.
                '\.jar$',

                // fonts types.
                '\.ttf$',
                '\.eot$',
                '\.woff$',
                '\.otf$',

                // robots.txt.
                '/robots\.txt$',
            ],
        ];
    }
}
