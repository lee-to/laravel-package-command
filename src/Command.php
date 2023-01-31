<?php

declare(strict_types=1);

namespace Leeto\PackageCommand;

use Illuminate\Console\Command as BaseCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class Command extends BaseCommand
{
    protected string $stubsDir;

    public function flushNodeModules(): void
    {
        tap(new Filesystem, function (Filesystem $files) {
            $files->deleteDirectory(base_path('node_modules'));

            $files->delete(base_path('pnpm-lock.yaml'));
            $files->delete(base_path('yarn.lock'));
            $files->delete(base_path('package-lock.json'));
        });
    }

    protected function installServiceProviderAfter(
        string $after,
        string $name,
        string $namespaceAfter = 'App\\Providers\\',
        string $namespaceName = 'App\\Providers\\'
    ): void {
        if (!Str::contains(
            $appConfig = file_get_contents(config_path('app.php')),
            $namespaceName.$name.'::class'
        )) {
            file_put_contents(
                config_path('app.php'),
                str_replace(
                    $namespaceAfter.$after.'::class,',
                    $namespaceAfter.$after.'::class,'.PHP_EOL.'        '.$namespaceName.$name.'::class,',
                    $appConfig
                )
            );
        }
    }

    protected function installMiddlewareAfter(string $after, string $name, string $group = 'web'): void
    {
        $httpKernel = file_get_contents(app_path('Http/Kernel.php'));

        $middlewareGroups = Str::before(Str::after($httpKernel, '$middlewareGroups = ['), '];');
        $middlewareGroup = Str::before(Str::after($middlewareGroups, "'$group' => ["), '],');

        if (!Str::contains($middlewareGroup, $name)) {
            $modifiedMiddlewareGroup = str_replace(
                $after.',',
                $after.','.PHP_EOL.'            '.$name.',',
                $middlewareGroup,
            );

            file_put_contents(
                app_path('Http/Kernel.php'),
                str_replace(
                    $middlewareGroups,
                    str_replace($middlewareGroup, $modifiedMiddlewareGroup, $middlewareGroups),
                    $httpKernel
                )
            );
        }
    }

    /**
     * @throws Throwable
     */
    protected function getStubsPath(): string
    {
        throw_if(!isset($this->stubsDir), new InvalidArgumentException('Set stubsDir'));

        return $this->stubsDir;
    }

    /**
     * @throws FileNotFoundException
     */
    protected function getStub(string $name): string
    {
        return (new Filesystem)->get($this->getStubsPath()."/$name.stub");
    }

    /**
     * @throws FileNotFoundException
     */
    protected function copyStub(string $stub, string $destination, array $replace = []): void
    {
        (new Filesystem)->put(
            $destination,
            !empty($replace)
                ? $this->replaceInStub($stub, $replace)
                : $this->getStub($stub)
        );
    }

    protected function copyStubsDir(string $dir, string $destination): void
    {
        (new Filesystem)->copyDirectory($this->getStubsPath().$dir, $destination);
    }

    /**
     * @throws FileNotFoundException
     */
    protected function replaceInStub(string $stub, array $replace): string
    {
        $stub = $this->getStub($stub);

        return str($stub)
            ->replace(array_keys($replace), array_values($replace))
            ->value();
    }

    protected function makeDir(string $path = ''): void
    {
        (new Filesystem)->makeDirectory($path, 0755, true, true);
    }

    protected function qualifyModel(string $model): array|string
    {
        $model = ltrim($model, '\\/');

        $model = str_replace('/', '\\', $model);

        $rootNamespace = $this->laravel->getNamespace();

        if (Str::startsWith($model, $rootNamespace)) {
            return $model;
        }

        return is_dir(app_path('Models'))
            ? $rootNamespace.'Models\\'.$model
            : $rootNamespace.$model;
    }

    protected function replaceInFile(string $search, string $replace, string $path): void
    {
        file_put_contents($path, str_replace($search, $replace, file_get_contents($path)));
    }

    protected function runCommands($commands): void
    {
        $process = Process::fromShellCommandline(implode(' && ', $commands), null, null, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $this->output->writeln('  <bg=yellow;fg=black> WARN </> '.$e->getMessage().PHP_EOL);
            }
        }

        $process->run(function ($type, $line) {
            $this->output->write('    '.$line);
        });
    }

    protected function requireComposerPackages(array $packages, bool $dev = false): bool
    {
        $command = array_merge(
                $command ?? $dev ? ['composer', 'require', '--dev'] : ['composer', 'require'],
            $packages
        );

        return !(new Process($command, base_path(), ['COMPOSER_MEMORY_LIMIT' => '-1']))
            ->setTimeout(null)
            ->run(function ($type, $output) {
                $this->output->write($output);
            });
    }

    protected static function updateNodePackages(callable $callback, bool $dev = true): void
    {
        if (!file_exists(base_path('package.json'))) {
            return;
        }

        $configurationKey = $dev ? 'devDependencies' : 'dependencies';

        $packages = json_decode(file_get_contents(base_path('package.json')), true);

        $packages[$configurationKey] = $callback(
            array_key_exists($configurationKey, $packages) ? $packages[$configurationKey] : [],
            $configurationKey
        );

        ksort($packages[$configurationKey]);

        file_put_contents(
            base_path('package.json'),
            json_encode($packages, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL
        );
    }

    protected function installNodePackages(): void
    {
        if (file_exists(base_path('pnpm-lock.yaml'))) {
            $this->runCommands(['pnpm install', 'pnpm run build']);
        } elseif (file_exists(base_path('yarn.lock'))) {
            $this->runCommands(['yarn install', 'yarn run build']);
        } else {
            $this->runCommands(['npm install', 'npm run build']);
        }
    }

    protected function phpBinary(): false|string
    {
        return (new PhpExecutableFinder())->find(false) ?: 'php';
    }
}
