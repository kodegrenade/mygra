<?php

declare(strict_types=1);

namespace Mygra\CLI;

use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Mygra\CLI\Commands\MigrateCommand;
use Mygra\CLI\Commands\InspectCommand;
use Mygra\CLI\Commands\ValidateCommand;
use Mygra\CLI\Commands\InitCommand;
use Mygra\CLI\Commands\CompareCommand;
use Mygra\CLI\Commands\SuggestCommand;

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
    ]);
  }

  /**
   * Get the application logo/banner.
   */
  public function getLongVersion(): string
  {
    return sprintf(
      '<info>%s</info> <comment>v%s</comment> - Universal Database Migration Tool',
      self::NAME,
      self::VERSION
    );
  }

  /**
   * Override to add custom banner.
   */
  public function doRun(InputInterface $input, OutputInterface $output): int
  {
    if ($input->hasParameterOption(['--version', '-V'], true)) {
      $output->writeln($this->getBanner());
    }

    return parent::doRun($input, $output);
  }

  /**
   * Get ASCII banner.
   */
  private function getBanner(): string
  {
    return <<<BANNER
<fg=cyan>
  __  __                       
 |  \/  |_   _  __ _ _ __ __ _ 
 | |\/| | | | |/ _` | '__/ _` |
 | |  | | |_| | (_| | | | (_| |
 |_|  |_|\__, |\__, |_|  \__,_|
         |___/ |___/           
</>
<info>Universal Database Migration Tool</info> <comment>v1.0.0</comment>
BANNER;
  }
}
