<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Mygra\Adapters\AdapterFactory;
use Mygra\Adapters\AdapterInterface;
use Mygra\CLI\Ui;
use Mygra\Config\ConfigLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * What every Mygra command shares: the --config option, loading it, connecting, and reporting failure.
 */
abstract class MygraCommand extends Command
{
  protected Ui $ui;

  protected function addConfigOption(): static
  {
    return $this->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to the configuration file', 'config/mygra.yaml');
  }

  /**
   * --format=json: one JSON document on stdout instead of the human-readable output.
   */
  protected function addFormatOption(): static
  {
    return $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
  }

  protected function initialize(InputInterface $input, OutputInterface $output): void
  {
    $this->ui = new Ui($output);
  }

  protected function wantsJson(InputInterface $input): bool
  {
    return $input->hasOption('format') && $input->getOption('format') === 'json';
  }

  protected function loadConfig(InputInterface $input): ConfigLoader
  {
    return ConfigLoader::load($input->getOption('config'));
  }

  /**
   * @param string $which source or target
   */
  protected function connect(ConfigLoader $config, string $which): AdapterInterface
  {
    $connection = $which === 'source' ? $config->getSourceConnection() : $config->getTargetConnection();
    try {
      return AdapterFactory::create($connection);
    } catch (\Exception $e) {
      throw new \RuntimeException("Cannot connect to the {$which} database (" . Ui::connection($connection) . "): {$e->getMessage()}", 0, $e);
    }
  }

  /**
   * Report a failure that stops the command, as text or JSON, and return the failure exit code.
   */
  protected function fail(InputInterface $input, OutputInterface $output, string $message): int
  {
    if ($this->wantsJson($input)) {
      $output->writeln(json_encode(['status' => 'error', 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    } else {
      $this->ui->error($message);
    }
    return Command::FAILURE;
  }

  protected function askQuestion(InputInterface $input, OutputInterface $output, Question $question): mixed
  {
    $helper = $this->getHelper('question');
    assert($helper instanceof QuestionHelper);
    return $helper->ask($input, $output, $question);
  }

  protected function writeJson(OutputInterface $output, array $document): void
  {
    $output->writeln(json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }
}
