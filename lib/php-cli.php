<?php
/*
 * Front controller of the "php" command of the FrankenPHP buildpack.
 *
 * bin/php runs: frankenphp php-cli php-cli.php [options] [script] [args...]
 *
 * "frankenphp php-cli" does not parse the options of the php binary, and
 * FrankenPHP v1.13.0 puts the binary name in $argv[0] and the script in $argv[1]
 * (https://github.com/php/frankenphp/issues/2690). Composer, Symfony Console
 * and Laravel Artisan then fail with 'Command "..." is not defined'.
 *
 * This file parses the options, gives the script the $argv that the php binary
 * gives, and runs the script (or the -r code) in the global scope.
 */

namespace HerokuFrankenPHP;

final class Cli
{
    public const STDIN_NAME = 'Standard input code';

    /** @var string|null File to run, or null for -r code */
    public static $file;

    /** @var string|null Code given with -r */
    public static $code;

    public static function setUp(array $rawArgv): void
    {
        $args = self::argsAfterSelf($rawArgv);
        $count = \count($args);
        $mode = 'file';
        $script = null;
        $fromStdin = false;
        $i = 0;

        for (; $i < $count; $i++) {
            $arg = $args[$i];

            if ($arg === '--') {
                $fromStdin = true;
                $i++;
                break;
            }
            if ($arg === '' || $arg === '-' || $arg[0] !== '-') {
                break;
            }

            switch ($arg) {
                case '-d':
                    self::setIni(self::value($args, ++$i, $arg));
                    continue 2;
                case '-r':
                    $mode = 'eval';
                    self::$code = self::value($args, ++$i, $arg);
                    $i++;
                    if (($args[$i] ?? null) === '--') {
                        $i++;
                    }
                    break 2;
                case '-f':
                    $script = self::value($args, ++$i, $arg);
                    $i++;
                    if (($args[$i] ?? null) === '--') {
                        $i++;
                    }
                    break 2;
                case '-n':
                case '-q':
                case '-e':
                case '-H':
                case '-C':
                    continue 2;
                case '-c':
                    self::value($args, ++$i, $arg);
                    fwrite(STDERR, "php: option -c is ignored; put .ini files in .php-extensions/conf.d/ instead\n");
                    continue 2;
                case '-v':
                case '--version':
                    self::printVersion();
                    exit(0);
                case '-m':
                case '--modules':
                    self::printModules();
                    exit(0);
                case '-i':
                case '--info':
                    phpinfo();
                    exit(0);
                case '--ini':
                    self::printIni();
                    exit(0);
                case '-h':
                case '-?':
                case '--help':
                    self::printHelp();
                    exit(0);
            }

            if (strncmp($arg, '-d', 2) === 0) {
                self::setIni(substr($arg, 2));
                continue;
            }

            self::fail("option $arg is not supported by \"frankenphp php-cli\"");
        }

        if ($mode === 'eval') {
            self::setArgv(self::STDIN_NAME, \array_slice($args, $i));

            return;
        }

        if ($script === null && !$fromStdin && $i < $count) {
            if ($args[$i] === '-') {
                $i++; // "-" means standard input
            } else {
                $script = $args[$i++];
            }
        }

        if ($script === null) {
            self::$file = self::readStdin();
            self::setArgv(self::STDIN_NAME, \array_slice($args, $i));

            return;
        }

        if (!is_file($script)) {
            self::fail("Could not open input file: $script");
        }

        self::$file = $script;
        self::setArgv($script, \array_slice($args, $i));
    }

    /**
     * Returns the arguments after this file. Before FrankenPHP v1.13.0, this
     * file is $argv[0]; in v1.13.0, it is $argv[1].
     */
    private static function argsAfterSelf(array $rawArgv): array
    {
        foreach (\array_slice($rawArgv, 0, 2, true) as $index => $arg) {
            if (@realpath($arg) === __FILE__) {
                return \array_slice($rawArgv, $index + 1);
            }
        }

        return \array_slice($rawArgv, 1);
    }

    private static function value(array $args, int $index, string $option): string
    {
        if (!isset($args[$index])) {
            self::fail("option $option needs a value");
        }

        return $args[$index];
    }

    private static function setIni(string $setting): void
    {
        [$name, $value] = array_pad(explode('=', $setting, 2), 2, '1');
        // Settings that can only be set in php.ini (allow_url_fopen, ...) are ignored
        @ini_set(trim($name), trim($value, " \t\"'"));
    }

    private static function setArgv(string $script, array $scriptArgs): void
    {
        $argv = array_merge([$script], array_values($scriptArgs));

        $GLOBALS['argv'] = $_SERVER['argv'] = $argv;
        $GLOBALS['argc'] = $_SERVER['argc'] = \count($argv);
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = $script;
        $_SERVER['SCRIPT_FILENAME'] = $_SERVER['PATH_TRANSLATED'] = $script;
    }

    private static function readStdin(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'php-stdin-');
        file_put_contents($file, stream_get_contents(STDIN));
        register_shutdown_function(static function () use ($file): void {
            @unlink($file);
        });

        return $file;
    }

    private static function printVersion(): void
    {
        printf(
            "PHP %s (cli) (%s)\nCopyright (c) The PHP Group\nZend Engine v%s\n",
            PHP_VERSION,
            PHP_ZTS ? 'ZTS' : 'NTS',
            zend_version()
        );
    }

    private static function printModules(): void
    {
        $modules = get_loaded_extensions();
        natcasesort($modules);
        $zendModules = get_loaded_extensions(true);
        natcasesort($zendModules);

        echo "[PHP Modules]\n", implode("\n", $modules), "\n\n";
        echo "[Zend Modules]\n", implode("\n", $zendModules), "\n\n";
    }

    private static function printIni(): void
    {
        echo 'Loaded Configuration File:         ', php_ini_loaded_file() ?: '(none)', "\n";
        echo 'Scan for additional .ini files in: ', getenv('PHP_INI_SCAN_DIR') ?: '(none)', "\n";
        echo 'Additional .ini files parsed:      ', trim((string) php_ini_scanned_files()) ?: '(none)', "\n";
    }

    private static function printHelp(): void
    {
        echo <<<'HELP'
Usage: php [options] [-f] <file> [--] [args...]
       php [options] -r <code> [--] [args...]
       php [options] [--] [args...]  (reads the script from standard input)

  -d key[=value]  Set an ini setting (settings for php.ini only are ignored)
  -f <file>       Run <file>
  -r <code>       Run PHP <code> without <?php tags
  -i, --info      Show PHP information
  -m, --modules   Show the compiled-in modules
  -v, --version   Show the version number
  --ini           Show the configuration file names
  -h, --help      Show this help

This "php" command runs "frankenphp php-cli". Other php options are not supported.

HELP;
    }

    private static function fail(string $message): void
    {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

Cli::setUp($_SERVER['argv']);

// Run the script in the global scope, like the php binary does
if (Cli::$file === null) {
    eval(Cli::$code);
} else {
    require Cli::$file;
}
