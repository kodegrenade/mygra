<?php

declare(strict_types=1);

namespace Mygra\CLI\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Init Command
 * 
 * Interactive wizard to create configuration file.
 */
#[AsCommand(
  name: 'init',
  description: 'Initialize a new migration configuration'
)]
class InitCommand extends Command
{

  protected function configure(): void
  {
    $this
      ->addOption(
        'output',
        'o',
        InputOption::VALUE_REQUIRED,
        'Output path for configuration file',
        'config/mygra.yaml'
      )
      ->addOption(
        'format',
        'f',
        InputOption::VALUE_REQUIRED,
        'Configuration format (yaml, json, php)',
        'yaml'
      )
      ->setHelp(
        <<<HELP
The <info>init</info> command creates a new migration configuration file interactively.

<comment>Examples:</comment>

  Create default configuration:
    <info>php mygra init</info>

  Create configuration in JSON format:
    <info>php mygra init --format=json</info>

  Custom output path:
    <info>php mygra init --output=my-migration.yaml</info>
HELP
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);

    $io->title('Mygra Configuration Wizard');

    $helper = $this->getHelper('question');

    // Source database
    $io->section('Source Database');

    $sourceDriver = $io->choice('Source database driver', ['mysql', 'postgresql', 'sqlite', 'sqlserver'], 'mysql');
    $sourceHost = $io->ask('Source host', 'localhost');
    $sourcePort = $io->ask('Source port', $this->getDefaultPort($sourceDriver));
    $sourceDatabase = $io->ask('Source database name');
    $sourceUser = $io->ask('Source username', 'root');
    $sourcePassword = $io->askHidden('Source password (hidden)') ?? '';

    // Target database
    $io->section('Target Database');

    $targetDriver = $io->choice('Target database driver', ['mysql', 'postgresql', 'sqlite', 'sqlserver'], 'mysql');
    $targetHost = $io->ask('Target host', 'localhost');
    $targetPort = $io->ask('Target port', $this->getDefaultPort($targetDriver));
    $targetDatabase = $io->ask('Target database name');
    $targetUser = $io->ask('Target username', 'root');
    $targetPassword = $io->askHidden('Target password (hidden)') ?? '';

    // Schema mapping
    $io->section('Schema Mapping');

    $sourceTable = $io->ask('Source table name');
    $targetTable = $io->ask('Target table name');
    $schemaName = $io->ask('Schema name (identifier)', $sourceTable);

    // Build configuration
    $config = [
      'version' => '1.0',
      'connections' => [
        'source' => [
          'driver' => $sourceDriver,
          'host' => $sourceHost,
          'port' => (int) $sourcePort,
          'database' => $sourceDatabase,
          'user' => $sourceUser,
          'password' => $sourcePassword ?: '${SOURCE_DB_PASSWORD}',
        ],
        'target' => [
          'driver' => $targetDriver,
          'host' => $targetHost,
          'port' => (int) $targetPort,
          'database' => $targetDatabase,
          'user' => $targetUser,
          'password' => $targetPassword ?: '${TARGET_DB_PASSWORD}',
        ],
      ],
      'settings' => [
        'batch_size' => 1000,
        'log_level' => 'info',
        'log_file' => 'logs/migration.log',
        'transaction_mode' => 'per_batch',
        'on_error' => 'rollback',
      ],
      'schemas' => [
        $schemaName => [
          'source_table' => $sourceTable,
          'target_table' => $targetTable,
          'fields' => [
            'id' => 'id',
            // More fields to be added
          ],
          'transforms' => [],
          'validations' => [],
        ],
      ],
    ];

    // Write configuration
    $outputPath = $input->getOption('output');
    $format = $input->getOption('format');

    // Ensure directory exists
    $dir = dirname($outputPath);
    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }

    $content = match ($format) {
      'yaml', 'yml' => \Symfony\Component\Yaml\Yaml::dump($config, 6, 2),
      'json' => json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
      'php' => "<?php\n\nreturn " . var_export($config, true) . ";\n",
      default => throw new \InvalidArgumentException("Unknown format: {$format}"),
    };

    file_put_contents($outputPath, $content);

    $io->success("Configuration created: {$outputPath}");

    $io->note([
      "Next steps:",
      "1. Edit the configuration file to add field mappings",
      "2. Run 'php mygra validate' to check your configuration",
      "3. Run 'php mygra migrate --dry-run' to preview the migration",
      "4. Run 'php mygra migrate' to execute the migration",
    ]);

    return Command::SUCCESS;
  }

  private function getDefaultPort(string $driver): string
  {
    return match ($driver) {
      'mysql' => '3306',
      'postgresql', 'pgsql' => '5432',
      'sqlserver', 'sqlsrv' => '1433',
      'sqlite' => '',
      default => '',
    };
  }
}
