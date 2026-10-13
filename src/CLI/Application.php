<?php

declare(strict_types=1);

namespace Mygra\CLI;

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
  public const VERSION = '1.0.0';
  public const NAME = 'Mygra';

  public function __construct()
  {
    parent::__construct(self::NAME, self::VERSION);

    $this->registerCommands();
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
