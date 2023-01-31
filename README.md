### The base class of the console command with a set of methods for package developers

Applies to console commands to install a package

It is convenient if your package adds stubs during installation, service providers

### Installation
```shell
composer require lee-to/laravel-package-command
```
### Usage

- Change `Illuminate\Console\Command ` to `Leeto\PackageCommand\Command`
- Set `protected string $stubsDir = 'Path to stubs dir''`
