<?php

declare(strict_types=1);

namespace Mygra\CLI;

use Composer\InstalledVersions;
use Mygra\CLI\Commands\CompareCommand;
use Mygra\CLI\Commands\FinishCommand;
use Mygra\CLI\Commands\InitCommand;
use Mygra\CLI\Commands\InspectCommand;
use Mygra\CLI\Commands\MigrateCommand;
use Mygra\CLI\Commands\SuggestCommand;
use Mygra\CLI\Commands\ValidateCommand;
use Mygra\CLI\Commands\VerifyCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Mygra CLI Application
 *
 * Main entry point for the command-line interface.
 */
class Application extends SymfonyApplication
{
  public const NAME = 'Mygra';
  public const PACKAGE = 'kodegrenade/mygra';

  public function __construct()
  {
    parent::__construct(self::NAME, self::version());

    $this->registerCommands();
  }

  /**
   * The installed version, from the git tag Composer installed (or 'dev-main' from a clone),
   * so it never needs bumping by hand.
   */
  public static function version(): string
  {
    if (InstalledVersions::isInstalled(self::PACKAGE)) {
      return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'unknown';
    }
    return 'unknown';
  }

  /**
   * Register all available commands.
   */
  private function registerCommands(): void
  {
    $this->addCommands([
      new MigrateCommand(),
      new InspectCommand(),
      new ValidateCommand(),
      new InitCommand(),
      new CompareCommand(),
      new SuggestCommand(),
      new VerifyCommand(),
      new FinishCommand(),
    ]);
  }
}
