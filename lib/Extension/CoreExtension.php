<?php

/*
 * This file is part of the PHPBench package
 *
 * (c) Daniel Leech <daniel@dantleech.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

namespace PhpBench\Extension;

use Humbug\SelfUpdate\Updater;
use PhpBench\Assertion\AssertionProcessor;
use PhpBench\Assertion\ParameterProvider;
use PhpBench\Benchmark\BaselineManager;
use PhpBench\Benchmark\BenchmarkFinder;
use PhpBench\Benchmark\Metadata\AnnotationReader;
use PhpBench\Benchmark\Metadata\Driver\AnnotationDriver;
use PhpBench\Benchmark\Metadata\Driver\AttributeDriver;
use PhpBench\Benchmark\Metadata\Driver\ChainDriver;
use PhpBench\Benchmark\Metadata\Driver\ConfigDriver;
use PhpBench\Benchmark\Metadata\MetadataFactory;
use PhpBench\Benchmark\Runner;
use PhpBench\Console\Application;
use PhpBench\Console\Command\Handler\DumpHandler;
use PhpBench\Console\Command\Handler\ReportHandler;
use PhpBench\Console\Command\Handler\RunnerHandler;
use PhpBench\Console\Command\Handler\SuiteCollectionHandler;
use PhpBench\Console\Command\Handler\TimeUnitHandler;
use PhpBench\Console\Command\LogCommand;
use PhpBench\Console\Command\ReportCommand;
use PhpBench\Console\Command\RunCommand;
use PhpBench\Console\Command\SelfUpdateCommand;
use PhpBench\Console\Command\ShowCommand;
use PhpBench\DependencyInjection\Container;
use PhpBench\DependencyInjection\ExtensionInterface;
use PhpBench\Environment\Provider;
use PhpBench\Environment\Supplier;
use PhpBench\Executor\Benchmark\DebugExecutor;
use PhpBench\Executor\Benchmark\LocalExecutor;
use PhpBench\Executor\Benchmark\MemoryCentricMicrotimeExecutor;
use PhpBench\Executor\Benchmark\RemoteExecutor;
use PhpBench\Executor\CompositeExecutor;
use PhpBench\Executor\Method\ErrorHandlingExecutorDecorator;
use PhpBench\Executor\Method\LocalMethodExecutor;
use PhpBench\Executor\Method\RemoteMethodExecutor;
use PhpBench\Expression\Evaluator;
use PhpBench\Expression\ExpressionLanguage;
use PhpBench\Expression\Printer;
use PhpBench\Expression\Printer\EvaluatingPrinter;
use PhpBench\Json\JsonDecoder;
use PhpBench\Logger\ConsoleLogger;
use PhpBench\Progress\Logger\BlinkenLogger;
use PhpBench\Progress\Logger\DotsLogger;
use PhpBench\Progress\Logger\HistogramLogger;
use PhpBench\Progress\Logger\NullLogger;
use PhpBench\Progress\Logger\TravisLogger;
use PhpBench\Progress\Logger\VerboseLogger;
use PhpBench\Progress\LoggerRegistry;
use PhpBench\Progress\VariantFormatter;
use PhpBench\Progress\VariantSummaryFormatter;
use PhpBench\Reflection\RemoteReflector;
use PhpBench\Registry\ConfigurableRegistry;
use PhpBench\Remote\Launcher;
use PhpBench\Remote\PayloadFactory;
use PhpBench\Remote\ProcessFactory;
use PhpBench\Report\Generator\BareGenerator;
use PhpBench\Report\Generator\CompositeGenerator;
use PhpBench\Report\Generator\EnvGenerator;
use PhpBench\Report\Generator\ExpressionGenerator;
use PhpBench\Report\Generator\OutputTestGenerator;
use PhpBench\Report\Renderer\ConsoleRenderer;
use PhpBench\Report\Renderer\DelimitedRenderer;
use PhpBench\Report\ReportManager;
use PhpBench\Report\Transform\SuiteCollectionTransformer;
use PhpBench\Serializer\XmlDecoder;
use PhpBench\Serializer\XmlEncoder;
use PhpBench\Storage\Driver\Xml\XmlDriver;
use PhpBench\Storage\StorageRegistry;
use PhpBench\Storage\UuidResolver;
use PhpBench\Storage\UuidResolver\ChainResolver;
use PhpBench\Storage\UuidResolver\LatestResolver;
use PhpBench\Storage\UuidResolver\TagResolver;
use PhpBench\Util\TimeUnit;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Finder\Finder;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Process\ExecutableFinder;
use Webmozart\PathUtil\Path;

class CoreExtension implements ExtensionInterface
{
    public const PARAM_ANNOTATION_IMPORT_USE = 'annotation_import_use';
    public const PARAM_BOOTSTRAP = 'bootstrap';
    public const PARAM_CONFIG_PATH = 'config_path';
    public const PARAM_ENV_BASELINES = 'env_baselines';
    public const PARAM_ENV_BASELINE_CALLABLES = 'env_baseline_callables';
    public const PARAM_ENABLED_PROVIDERS = 'env.enabled_providers';
    public const PARAM_EXECUTORS = 'executors';
    public const PARAM_OUTPUTS = 'outputs';
    public const PARAM_OUTPUT_MODE = 'output_mode';
    public const PARAM_PATH = 'path';
    public const PARAM_PHP_BINARY = 'php_binary';
    public const PARAM_PHP_CONFIG = 'php_config';
    public const PARAM_PHP_DISABLE_INI = 'php_disable_ini';
    public const PARAM_PHP_WRAPPER = 'php_wrapper';
    public const PARAM_PROGRESS = 'progress';
    public const PARAM_REPORTS = 'reports';

    /**
     * @deprecated Use PARAM_RUNNER_RETRY_THRESHOLD
     */
    public const PARAM_RETRY_THRESHOLD = 'retry_threshold';
    public const PARAM_STORAGE = 'storage';
    public const PARAM_SUBJECT_PATTERN = 'subject_pattern';
    public const PARAM_TIME_UNIT = 'time_unit';
    public const PARAM_XML_STORAGE_PATH = 'xml_storage_path';
    public const PARAM_REMOTE_SCRIPT_PATH = 'remote_script_path';
    public const PARAM_REMOTE_SCRIPT_REMOVE = 'remote_script_remove';
    public const PARAM_DISABLE_OUTPUT = 'console.disable_output';
    public const PARAM_CONSOLE_ANSI = 'console.ansi';
    public const PARAM_CONSOLE_OUTPUT_STREAM = 'console.output_stream';
    public const PARAM_CONSOLE_ERROR_STREAM = 'console.error_stream';
    public const PARAM_PROGRESS_SUMMARY_FORMAT = 'progress_summary_variant_format';
    public const PARAM_PROGRESS_SUMMARY_BASELINE_FORMAT = 'progress_summary_baseline_format';
    public const PARAM_ANNOTATIONS = 'annotations';
    public const PARAM_ATTRIBUTES = 'attributes';
    public const PARAM_DEBUG = 'debug';
    public const PARAM_RUNNER_ASSERT = 'runner.assert';
    public const PARAM_RUNNER_EXECUTOR = 'runner.executor';
    public const PARAM_RUNNER_FORMAT = 'runner.format';
    public const PARAM_RUNNER_ITERATIONS = 'runner.iterations';
    public const PARAM_RUNNER_OUTPUT_MODE = 'runner.output_mode';
    public const PARAM_RUNNER_OUTPUT_TIME_UNIT = 'runner.time_unit';
    public const PARAM_RUNNER_REVS = 'runner.revs';
    public const PARAM_RUNNER_TIMEOUT = 'runner.timeout';
    public const PARAM_RUNNER_WARMUP = 'runner.warmup';
    public const PARAM_RUNNER_RETRY_THRESHOLD = 'runner.retry_threshold';

    public const TAG_EXECUTOR = 'benchmark_executor';
    public const TAG_CONSOLE_COMMAND = 'console.command';
    public const TAG_ENV_PROVIDER = 'environment_provider';
    public const TAG_PROGRESS_LOGGER = 'progress_logger';
    public const TAG_REPORT_GENERATOR = 'report_generator';
    public const TAG_REPORT_RENDERER = 'report_renderer';
    public const TAG_STORAGE_DRIVER = 'storage_driver';
    public const TAG_UUID_RESOLVER = 'uuid_resolver';

    public const SERVICE_REGISTRY_DRIVER = 'storage.driver_registry';
    public const SERVICE_REGISTRY_EXECUTOR = 'benchmark.registry.executor';
    public const SERVICE_REGISTRY_GENERATOR = 'report.registry.generator';
    public const SERVICE_REGISTRY_LOGGER = 'progress_logger.registry';
    public const SERVICE_REGISTRY_RENDERER = 'report.registry.renderer';
    public const SERVICE_VARIANT_SUMMARY_FORMATTER = 'progress_logger.variant_summary_formatter';
    public const SERVICE_OUTPUT_STD = 'console.stream.std';
    public const SERVICE_OUTPUT_ERR = 'console.stream.err';

    public const ENV_PROVIDER_UNAME = 'uname';
    public const ENV_PROVIDER_PHP = 'php';
    public const ENV_PROVIDER_OPCACHE = 'opcache';
    public const ENV_PROVIDER_UNIX_SYSLOAD = 'unix_sysload';
    public const ENV_PROVIDER_GIT = 'git';
    public const ENV_PROVIDER_BASELINE = 'baseline';
    const ENV_PROVIDER_TEST = 'test';

    public function configure(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            self::PARAM_BOOTSTRAP => null,
            self::PARAM_PATH => null,
            self::PARAM_REPORTS => [],
            self::PARAM_OUTPUTS => [],
            self::PARAM_EXECUTORS => [],
            self::PARAM_CONFIG_PATH => null,
            self::PARAM_PROGRESS => getenv('CONTINUOUS_INTEGRATION') ? 'travis' : 'verbose',
            self::PARAM_RETRY_THRESHOLD => null,
            self::PARAM_TIME_UNIT => TimeUnit::MICROSECONDS,
            self::PARAM_OUTPUT_MODE => TimeUnit::MODE_TIME,
            self::PARAM_STORAGE => 'xml',
            self::PARAM_SUBJECT_PATTERN => '^bench',
            self::PARAM_ENABLED_PROVIDERS => [
                self::ENV_PROVIDER_UNAME,
                self::ENV_PROVIDER_PHP,
                self::ENV_PROVIDER_OPCACHE,
                self::ENV_PROVIDER_UNIX_SYSLOAD,
                self::ENV_PROVIDER_GIT,
                self::ENV_PROVIDER_BASELINE,
            ],
            self::PARAM_ENV_BASELINES => ['nothing', 'md5', 'file_rw'],
            self::PARAM_ENV_BASELINE_CALLABLES => [],
            self::PARAM_XML_STORAGE_PATH => getcwd() . '/.phpbench/storage', // use cwd because PHARs
            self::PARAM_PHP_CONFIG => [],
            self::PARAM_PHP_BINARY => null,
            self::PARAM_PHP_WRAPPER => null,
            self::PARAM_PHP_DISABLE_INI => false,
            self::PARAM_ANNOTATION_IMPORT_USE => false,
            self::PARAM_REMOTE_SCRIPT_PATH => null,
            self::PARAM_REMOTE_SCRIPT_REMOVE => true,
            self::PARAM_DISABLE_OUTPUT => false,
            self::PARAM_CONSOLE_ANSI => true,
            self::PARAM_PROGRESS_SUMMARY_FORMAT => VariantSummaryFormatter::DEFAULT_FORMAT,
            self::PARAM_PROGRESS_SUMMARY_BASELINE_FORMAT => VariantSummaryFormatter::BASELINE_FORMAT,
            self::PARAM_ANNOTATIONS => true,
            self::PARAM_ATTRIBUTES => true,
            self::PARAM_DEBUG => false,
            self::PARAM_CONSOLE_OUTPUT_STREAM => 'php://stdout',
            self::PARAM_CONSOLE_ERROR_STREAM => 'php://stderr',

            self::PARAM_RUNNER_ASSERT => null,
            self::PARAM_RUNNER_EXECUTOR => null,
            self::PARAM_RUNNER_FORMAT => null,
            self::PARAM_RUNNER_ITERATIONS => null,
            self::PARAM_RUNNER_OUTPUT_MODE => null,
            self::PARAM_RUNNER_OUTPUT_TIME_UNIT => null,
            self::PARAM_RUNNER_REVS => null,
            self::PARAM_RUNNER_TIMEOUT => null,
            self::PARAM_RUNNER_WARMUP => null,
            self::PARAM_RUNNER_RETRY_THRESHOLD => null,

        ]);

        $resolver->setAllowedTypes(self::PARAM_DEBUG, ['bool']);
        $resolver->setAllowedTypes(self::PARAM_ANNOTATIONS, ['bool']);
        $resolver->setAllowedTypes(self::PARAM_ATTRIBUTES, ['bool']);
        $resolver->setAllowedTypes(self::PARAM_BOOTSTRAP, ['string', 'null']);
        $resolver->setAllowedTypes(self::PARAM_PATH, ['string', 'array', 'null']);
        $resolver->setAllowedTypes(self::PARAM_REPORTS, ['array']);
        $resolver->setAllowedTypes(self::PARAM_OUTPUTS, ['array']);
        $resolver->setAllowedTypes(self::PARAM_EXECUTORS, ['array']);
        $resolver->setAllowedTypes(self::PARAM_PROGRESS, ['string']);
        $resolver->setAllowedTypes(self::PARAM_RETRY_THRESHOLD, ['null', 'int', 'float']);
        $resolver->setAllowedTypes(self::PARAM_TIME_UNIT, ['string']);
        $resolver->setAllowedTypes(self::PARAM_OUTPUT_MODE, ['string']);
        $resolver->setAllowedTypes(self::PARAM_STORAGE, ['string']);
        $resolver->setAllowedTypes(self::PARAM_SUBJECT_PATTERN, ['string']);
        $resolver->setAllowedTypes(self::PARAM_ENV_BASELINES, ['array']);
        $resolver->setAllowedTypes(self::PARAM_ENV_BASELINE_CALLABLES, ['array']);
        $resolver->setAllowedTypes(self::PARAM_XML_STORAGE_PATH, ['string']);
        $resolver->setAllowedTypes(self::PARAM_PHP_CONFIG, ['array']);
        $resolver->setAllowedTypes(self::PARAM_PHP_BINARY, ['string', 'null']);
        $resolver->setAllowedTypes(self::PARAM_PHP_WRAPPER, ['string', 'null']);
        $resolver->setAllowedTypes(self::PARAM_PHP_DISABLE_INI, ['bool']);
        $resolver->setAllowedTypes(self::PARAM_ANNOTATION_IMPORT_USE, ['bool']);
        $resolver->setAllowedTypes(self::PARAM_REMOTE_SCRIPT_REMOVE, ['bool']);
        $resolver->setAllowedTypes(self::PARAM_REMOTE_SCRIPT_PATH, ['string', 'null']);
        $resolver->setAllowedTypes(self::PARAM_DISABLE_OUTPUT, ['bool']);
        $resolver->setAllowedTypes(self::PARAM_PROGRESS_SUMMARY_FORMAT, ['string']);
        $resolver->setAllowedTypes(self::PARAM_PROGRESS_SUMMARY_BASELINE_FORMAT, ['string']);
        $resolver->setAllowedTypes(self::PARAM_CONSOLE_OUTPUT_STREAM, ['string']);
        $resolver->setAllowedTypes(self::PARAM_RUNNER_ASSERT, ['null', 'string', 'array']);
        $resolver->setAllowedTypes(self::PARAM_RUNNER_EXECUTOR, ['null', 'string']);
        $resolver->setAllowedTypes(self::PARAM_RUNNER_FORMAT, ['null', 'string']);
        $resolver->setAllowedTypes(self::PARAM_RUNNER_ITERATIONS, ['null', 'int', 'array']);
        $resolver->setAllowedTypes(self::PARAM_RUNNER_OUTPUT_MODE, ['null', 'string']);
        $resolver->setAllowedTypes(self::PARAM_RUNNER_OUTPUT_TIME_UNIT, ['null', 'string']);
        $resolver->setAllowedTypes(self::PARAM_RUNNER_REVS, ['null', 'int', 'array']);
        $resolver->setAllowedTypes(self::PARAM_RUNNER_TIMEOUT, ['null', 'float', 'int']);
        $resolver->setAllowedTypes(self::PARAM_RUNNER_WARMUP, ['null', 'int', 'array']);
        $resolver->setAllowedTypes(self::PARAM_RUNNER_RETRY_THRESHOLD, ['null', 'int', 'float']);
        $resolver->setAllowedTypes(self::PARAM_ENABLED_PROVIDERS, ['array']);
    }

    public function load(Container $container): void
    {
        $this->relativizeConfigPath($container);

        $container->register(self::SERVICE_OUTPUT_STD, function (Container $container) {
            return $this->createOutput($container, self::PARAM_CONSOLE_OUTPUT_STREAM);
        });

        $container->register(self::SERVICE_OUTPUT_ERR, function (Container $container) {
            return $this->createOutput($container, self::PARAM_CONSOLE_ERROR_STREAM);
        });

        $container->register(InputInterface::class, function (Container $container) {
            return new ArgvInput();
        });

        $container->register(Application::class, function (Container $container) {
            $application = new Application();

            foreach (array_keys($container->getServiceIdsForTag(self::TAG_CONSOLE_COMMAND)) as $serviceId) {
                $command = $container->get($serviceId);
                $application->add($command);
            }

            return $application;
        });
        $container->register(ReportManager::class, function (Container $container) {
            return new ReportManager(
                $container->get(self::SERVICE_REGISTRY_GENERATOR),
                $container->get(self::SERVICE_REGISTRY_RENDERER)
            );
        });

        $container->register(LoggerInterface::class, function (Container $container) {
            return new ConsoleLogger(
                $container->getParameter(self::PARAM_DEBUG)
            );
        });

        $this->registerBenchmark($container);
        $this->registerJson($container);
        $this->registerCommands($container);
        $this->registerRegistries($container);
        $this->registerProgressLoggers($container);
        $this->registerReportGenerators($container);
        $this->registerReportRenderers($container);
        $this->registerEnvironment($container);
        $this->registerSerializer($container);
        $this->registerStorage($container);
        $this->registerAsserters($container);
    }

    private function registerBenchmark(Container $container): void
    {
        $container->register(Runner::class, function (Container $container) {
            return new Runner(
                $container->get(self::SERVICE_REGISTRY_EXECUTOR),
                $container->get(Supplier::class),
                $container->get(AssertionProcessor::class),
                $container->getParameter(self::PARAM_RETRY_THRESHOLD),
                $container->getParameter(self::PARAM_CONFIG_PATH)
            );
        });

        $container->register(RemoteExecutor::class . '.composite', function (Container $container) {
            return new CompositeExecutor(
                $container->get(RemoteExecutor::class),
                new ErrorHandlingExecutorDecorator($container->get(RemoteMethodExecutor::class))
            );
        }, [self::TAG_EXECUTOR => ['name' => 'remote']]);

        $container->register(LocalExecutor::class . '.composite', function (Container $container) {
            return new CompositeExecutor(
                $container->get(LocalExecutor::class),
                new ErrorHandlingExecutorDecorator($container->get(LocalMethodExecutor::class))
            );
        }, [self::TAG_EXECUTOR => ['name' => 'local']]);

        $container->register(MemoryCentricMicrotimeExecutor::class, function (Container $container) {
            return new CompositeExecutor(
                new MemoryCentricMicrotimeExecutor($container->get(Launcher::class)),
                $container->get(RemoteMethodExecutor::class)
            );
        }, [self::TAG_EXECUTOR => ['name' => 'memory_centric_microtime']]);

        $container->register(RemoteExecutor::class, function (Container $container) {
            return new RemoteExecutor(
                $container->get(Launcher::class)
            );
        });

        $container->register(LocalExecutor::class, function (Container $container) {
            return new LocalExecutor();
        });

        $container->register(RemoteMethodExecutor::class, function (Container $container) {
            return new RemoteMethodExecutor(
                $container->get(Launcher::class)
            );
        });

        $container->register(LocalMethodExecutor::class, function (Container $container) {
            return new LocalMethodExecutor();
        });

        $container->register(DebugExecutor::class, function (Container $container) {
            return new DebugExecutor();
        }, [
            self::TAG_EXECUTOR => ['name' => self::PARAM_DEBUG]
        ]);

        $container->register(Finder::class, function (Container $container) {
            return new Finder();
        });

        $container->register(ProcessFactory::class, function (Container $container) {
            return new ProcessFactory($container->get(LoggerInterface::class));
        });

        $container->register(Launcher::class, function (Container $container) {
            return new Launcher(
                new PayloadFactory(
                    $container->get(ProcessFactory::class),
                    $container->getParameter(self::PARAM_REMOTE_SCRIPT_PATH),
                    $container->getParameter(self::PARAM_REMOTE_SCRIPT_REMOVE)
                ),
                new ExecutableFinder(),
                $container->hasParameter(self::PARAM_BOOTSTRAP) ? $container->getParameter(self::PARAM_BOOTSTRAP) : null,
                $container->hasParameter(self::PARAM_PHP_BINARY) ? $container->getParameter(self::PARAM_PHP_BINARY) : null,
                $container->hasParameter(self::PARAM_PHP_CONFIG) ? $container->getParameter(self::PARAM_PHP_CONFIG) : null,
                $container->hasParameter(self::PARAM_PHP_WRAPPER) ? $container->getParameter(self::PARAM_PHP_WRAPPER) : null,
                $container->hasParameter(self::PARAM_PHP_DISABLE_INI) ? $container->getParameter(self::PARAM_PHP_DISABLE_INI) : false
            );
        });

        $container->register(RemoteReflector::class, function (Container $container) {
            return new RemoteReflector($container->get(Launcher::class));
        });

        $container->register(AnnotationReader::class, function (Container $container) {
            return new AnnotationReader($container->getParameter(self::PARAM_ANNOTATION_IMPORT_USE));
        });

        $container->register(AnnotationDriver::class, function (Container $container) {
            return new AnnotationDriver(
                $container->get(RemoteReflector::class),
                $container->getParameter(self::PARAM_SUBJECT_PATTERN),
                $container->get(AnnotationReader::class)
            );
        });

        $container->register(AttributeDriver::class, function (Container $container) {
            return new AttributeDriver(
                $container->getParameter(self::PARAM_SUBJECT_PATTERN)
            );
        });

        $container->register(ChainDriver::class, function (Container $container) {
            $drivers = [];

            if ($container->getParameter(self::PARAM_ANNOTATIONS)) {
                $drivers[] = $container->get(AnnotationDriver::class);
            }

            if ($container->getParameter(self::PARAM_ATTRIBUTES)) {
                $drivers[] = $container->get(AttributeDriver::class);
            }

            return new ChainDriver($drivers);
        });

        $container->register(MetadataFactory::class, function (Container $container) {
            return new MetadataFactory(
                $container->get(RemoteReflector::class),
                $container->get(ConfigDriver::class)
            );
        });

        $container->register(ConfigDriver::class, function (Container $container) {
            return new ConfigDriver(
                $container->get(ChainDriver::class),
                (array)$container->getParameter(self::PARAM_RUNNER_ASSERT),
                $container->getParameter(self::PARAM_RUNNER_EXECUTOR),
                $container->getParameter(self::PARAM_RUNNER_FORMAT),
                (array)$container->getParameter(self::PARAM_RUNNER_ITERATIONS),
                $container->getParameter(self::PARAM_RUNNER_OUTPUT_MODE),
                $container->getParameter(self::PARAM_RUNNER_OUTPUT_TIME_UNIT),
                (array)$container->getParameter(self::PARAM_RUNNER_REVS),
                $container->getParameter(self::PARAM_RUNNER_TIMEOUT),
                (array)$container->getParameter(self::PARAM_RUNNER_WARMUP),
                (float)($container->getParameter(self::PARAM_RUNNER_RETRY_THRESHOLD) ?: $container->getParameter(self::PARAM_RETRY_THRESHOLD)),
            );
        });

        $container->register(BenchmarkFinder::class, function (Container $container) {
            return new BenchmarkFinder(
                $container->get(MetadataFactory::class)
            );
        });

        $container->register(BaselineManager::class, function (Container $container) {
            $manager = new BaselineManager();
            $callables = array_merge([
                'nothing' => '\PhpBench\Benchmark\Baseline\Baselines::nothing',
                'md5' => '\PhpBench\Benchmark\Baseline\Baselines::md5',
                'file_rw' => '\PhpBench\Benchmark\Baseline\Baselines::fwriteFread',
            ], $container->getParameter(self::PARAM_ENV_BASELINE_CALLABLES));

            foreach ($callables as $name => $callable) {
                $manager->addBaselineCallable($name, $callable);
            }

            return $manager;
        });

        $container->register(TimeUnit::class, function (Container $container) {
            return new TimeUnit(TimeUnit::MICROSECONDS, $container->getParameter(self::PARAM_TIME_UNIT));
        });
    }

    private function registerJson(Container $container): void
    {
        $container->register(JsonDecoder::class, function (Container $container) {
            return new JsonDecoder();
        });
    }

    private function registerCommands(Container $container): void
    {
        $container->register(RunnerHandler::class, function (Container $container) {
            return new RunnerHandler(
                $container->get(Runner::class),
                $container->get(self::SERVICE_REGISTRY_LOGGER),
                $container->get(BenchmarkFinder::class),
                $container->getParameter(self::PARAM_PROGRESS),
                $container->getParameter(self::PARAM_PATH)
            );
        });

        $container->register(ReportHandler::class, function (Container $container) {
            return new ReportHandler(
                $container->get(ReportManager::class)
            );
        });

        $container->register(TimeUnitHandler::class, function (Container $container) {
            return new TimeUnitHandler(
                $container->get(TimeUnit::class)
            );
        });

        $container->register(SuiteCollectionHandler::class, function (Container $container) {
            return new SuiteCollectionHandler(
                $container->get(XmlDecoder::class),
                $container->get(self::SERVICE_REGISTRY_DRIVER),
                $container->get(UuidResolver::class)
            );
        });

        $container->register(DumpHandler::class, function (Container $container) {
            return new DumpHandler(
                $container->get(XmlEncoder::class)
            );
        });

        $container->register(RunCommand::class, function (Container $container) {
            return new RunCommand(
                $container->get(RunnerHandler::class),
                $container->get(ReportHandler::class),
                $container->get(SuiteCollectionHandler::class),
                $container->get(TimeUnitHandler::class),
                $container->get(DumpHandler::class),
                $container->get(self::SERVICE_REGISTRY_DRIVER)
            );
        }, [
            self::TAG_CONSOLE_COMMAND => []
        ]);

        $container->register(ReportCommand::class, function (Container $container) {
            return new ReportCommand(
                $container->get(ReportHandler::class),
                $container->get(TimeUnitHandler::class),
                $container->get(SuiteCollectionHandler::class),
                $container->get(DumpHandler::class)
            );
        }, [
            self::TAG_CONSOLE_COMMAND => []
        ]);

        $container->register(LogCommand::class, function (Container $container) {
            return new LogCommand(
                $container->get(self::SERVICE_REGISTRY_DRIVER),
                $container->get(TimeUnit::class),
                $container->get(TimeUnitHandler::class)
            );
        }, [
            self::TAG_CONSOLE_COMMAND => []
        ]);

        $container->register(ShowCommand::class, function (Container $container) {
            return new ShowCommand(
                $container->get(self::SERVICE_REGISTRY_DRIVER),
                $container->get(ReportHandler::class),
                $container->get(TimeUnitHandler::class),
                $container->get(DumpHandler::class),
                $container->get(UuidResolver::class)
            );
        }, [
            self::TAG_CONSOLE_COMMAND => []
        ]);

        if (class_exists(Updater::class) && class_exists(\Phar::class) && \Phar::running()) {
            $container->register(SelfUpdateCommand::class, function (Container $container) {
                return new SelfUpdateCommand();
            }, [
                self::TAG_CONSOLE_COMMAND => []
            ]);
        }
    }

    private function registerProgressLoggers(Container $container): void
    {
        $container->register(VariantFormatter::class, function (Container $container) {
            return new VariantSummaryFormatter(
                $container->get(ExpressionLanguage::class),
                $container->get(EvaluatingPrinter::class),
                $container->get(ParameterProvider::class),
                $container->getParameter(self::PARAM_PROGRESS_SUMMARY_FORMAT),
                $container->getParameter(self::PARAM_PROGRESS_SUMMARY_BASELINE_FORMAT)
            );
        });
        $container->register(self::SERVICE_REGISTRY_LOGGER, function (Container $container) {
            $registry = new LoggerRegistry();

            foreach ($container->getServiceIdsForTag(self::TAG_PROGRESS_LOGGER) as $serviceId => $attributes) {
                $registry->addProgressLogger(
                    $attributes['name'],
                    $container->get($serviceId)
                );
            }

            return $registry;
        });

        $container->register(DotsLogger::class, function (Container $container) {
            return new DotsLogger(
                $container->get(self::SERVICE_OUTPUT_ERR),
                $container->get(VariantFormatter::class),
                $container->get(TimeUnit::class)
            );
        }, [self::TAG_PROGRESS_LOGGER => ['name' => 'dots']]);

        $container->register(DotsLogger::class .'.show', function (Container $container) {
            return new DotsLogger(
                $container->get(self::SERVICE_OUTPUT_ERR),
                $container->get(VariantFormatter::class),
                $container->get(TimeUnit::class),
                true
            );
        }, [self::TAG_PROGRESS_LOGGER => ['name' => 'classdots']]);

        $container->register(VerboseLogger::class, function (Container $container) {
            return new VerboseLogger(
                $container->get(self::SERVICE_OUTPUT_ERR),
                $container->get(VariantFormatter::class),
                $container->get(TimeUnit::class)
            );
        }, [self::TAG_PROGRESS_LOGGER => ['name' => 'verbose']]);

        $container->register(TravisLogger::class, function (Container $container) {
            return new TravisLogger(
                $container->get(self::SERVICE_OUTPUT_ERR),
                $container->get(VariantFormatter::class),
                $container->get(TimeUnit::class)
            );
        }, [self::TAG_PROGRESS_LOGGER => ['name' => 'travis']]);

        $container->register(NullLogger::class, function (Container $container) {
            return new NullLogger();
        }, [self::TAG_PROGRESS_LOGGER => ['name' => 'none']]);

        $container->register(BlinkenLogger::class, function (Container $container) {
            return new BlinkenLogger(
                $container->get(self::SERVICE_OUTPUT_ERR),
                $container->get(VariantFormatter::class),
                $container->get(TimeUnit::class)
            );
        }, [self::TAG_PROGRESS_LOGGER => ['name' => 'blinken']]);

        $container->register(HistogramLogger::class, function (Container $container) {
            return new HistogramLogger(
                $container->get(self::SERVICE_OUTPUT_ERR),
                $container->get(VariantFormatter::class),
                $container->get(TimeUnit::class)
            );
        }, [self::TAG_PROGRESS_LOGGER => ['name' => 'histogram']]);
    }

    private function registerReportGenerators(Container $container): void
    {
        $container->register(ExpressionGenerator::class, function (Container $container) {
            return new ExpressionGenerator(
                $container->get(ExpressionLanguage::class),
                $container->get(Evaluator::class),
                $container->get(EvaluatingPrinter::class),
                new SuiteCollectionTransformer(),
                $container->get(LoggerInterface::class)
            );
        }, [self::TAG_REPORT_GENERATOR => ['name' => 'expression']]);
        $container->register(EnvGenerator::class, function (Container $container) {
            return new EnvGenerator();
        }, [self::TAG_REPORT_GENERATOR => ['name' => 'env']]);
        $container->register(BareGenerator::class, function (Container $container) {
            return new BareGenerator(new SuiteCollectionTransformer());
        }, [self::TAG_REPORT_GENERATOR => ['name' => 'bare']]);
        $container->register(OutputTestGenerator::class, function (Container $container) {
            return new OutputTestGenerator();
        }, [self::TAG_REPORT_GENERATOR => ['name' => 'output_test']]);
        $container->register(CompositeGenerator::class, function (Container $container) {
            return new CompositeGenerator(
                $container->get(ReportManager::class)
            );
        }, [
            self::TAG_REPORT_GENERATOR => ['name' => 'composite']
        ]);
    }

    private function registerReportRenderers(Container $container): void
    {
        $container->register(ConsoleRenderer::class, function (Container $container) {
            return new ConsoleRenderer(
                $container->get(self::SERVICE_OUTPUT_STD),
                $container->get(Printer::class)
            );
        }, [self::TAG_REPORT_RENDERER => ['name' => 'console']]);
        $container->register(DelimitedRenderer::class, function (Container $container) {
            return new DelimitedRenderer(
                $container->get(self::SERVICE_OUTPUT_STD),
                $container->get(ExpressionExtension::SERVICE_BARE_PRINTER)
            );
        }, [self::TAG_REPORT_RENDERER => ['name' => 'delimited']]);
    }

    private function registerAsserters(Container $container): void
    {
        $container->register(AssertionProcessor::class, function (Container $container) {
            return new AssertionProcessor(
                $container->get(ExpressionLanguage::class),
                $container->get(Evaluator::class),
                $container->get(Printer::class),
                $container->get(EvaluatingPrinter::class),
                $container->get(ParameterProvider::class)
            );
        });

        $container->register(ParameterProvider::class, function () {
            return new ParameterProvider();
        });
    }

    private function registerRegistries(Container $container): void
    {
        foreach (['generator' => self::PARAM_REPORTS, 'renderer' => self::PARAM_OUTPUTS] as $registryType => $optionName) {
            $container->register('report.registry.' . $registryType, function (Container $container) use ($registryType, $optionName) {
                $registry = new ConfigurableRegistry(
                    $registryType,
                    $container,
                    $container->get(JsonDecoder::class)
                );

                foreach ($container->getServiceIdsForTag('report_' . $registryType) as $serviceId => $attributes) {
                    $registry->registerService($attributes['name'], $serviceId);
                }

                $configs = array_merge(
                    require(__DIR__ . '/config/report/' . $registryType . 's.php'),
                    $container->getParameter($optionName)
                );

                foreach ($configs as $name => $config) {
                    $registry->setConfig($name, $config);
                }

                return $registry;
            });
        }

        $container->register(self::SERVICE_REGISTRY_EXECUTOR, function (Container $container) {
            $registry = new ConfigurableRegistry(
                'executor',
                $container,
                $container->get(JsonDecoder::class)
            );

            foreach ($container->getServiceIdsForTag(self::TAG_EXECUTOR) as $serviceId => $attributes) {
                $registry->registerService($attributes['name'], $serviceId);
            }

            $executorConfigs = array_merge(
                require(__DIR__ . '/config/benchmark/executors.php'),
                $container->getParameter(self::PARAM_EXECUTORS)
            );

            foreach ($executorConfigs as $name => $config) {
                $registry->setConfig($name, $config);
            }

            return $registry;
        });
    }

    public function registerEnvironment(Container $container): void
    {
        $container->register(Provider\Uname::class, function (Container $container) {
            return new Provider\Uname();
        }, [self::TAG_ENV_PROVIDER => [
            'name' => self::ENV_PROVIDER_UNAME,
        ]]);

        $container->register(Provider\Php::class, function (Container $container) {
            return new Provider\Php(
                $container->get(Launcher::class)
            );
        }, [self::TAG_ENV_PROVIDER => [
            'name' => self::ENV_PROVIDER_PHP,
        ]]);

        $container->register(Provider\Opcache::class, function (Container $container) {
            return new Provider\Opcache(
                $container->get(Launcher::class)
            );
        }, [self::TAG_ENV_PROVIDER => [
            'name' => self::ENV_PROVIDER_OPCACHE,
        ]]);

        $container->register(Provider\UnixSysload::class, function (Container $container) {
            return new Provider\UnixSysload();
        }, [self::TAG_ENV_PROVIDER => [
            'name' => self::ENV_PROVIDER_UNIX_SYSLOAD,
        ]]);

        $container->register(Provider\Git::class, function (Container $container) {
            return new Provider\Git();
        }, [self::TAG_ENV_PROVIDER => [
            'name' => self::ENV_PROVIDER_GIT,
        ]]);

        $container->register(Provider\Baseline::class, function (Container $container) {
            return new Provider\Baseline(
                $container->get(BaselineManager::class),
                $container->getParameter(self::PARAM_ENV_BASELINES)
            );
        }, [self::TAG_ENV_PROVIDER => [
            'name' => self::ENV_PROVIDER_BASELINE,
        ]]);

        $container->register(Provider\TestProvider::class, function (Container $container) {
            return new Provider\TestProvider();
        }, [self::TAG_ENV_PROVIDER => [
            'name' => self::ENV_PROVIDER_TEST,
        ]]);

        $container->register(Supplier::class, function (Container $container) {
            $supplier = new Supplier();
            $enabledProviders = $container->getParameter(self::PARAM_ENABLED_PROVIDERS);

            foreach ($container->getServiceIdsForTag(self::TAG_ENV_PROVIDER) as $serviceId => $attributes) {
                if (!isset($attributes['name'])) {
                    throw new RuntimeException(sprintf(
                        'Env provider "%s" has no `name` attribute', $serviceId
                    ));
                }

                if (!in_array($attributes['name'], $enabledProviders)) {
                    continue;
                }

                $provider = $container->get($serviceId);
                $supplier->addProvider($provider);
            }

            return $supplier;
        });
    }

    private function registerSerializer(Container $container): void
    {
        $container->register(XmlEncoder::class, function (Container $container) {
            return new XmlEncoder();
        });
        $container->register(XmlDecoder::class, function (Container $container) {
            return new XmlDecoder();
        });
    }

    private function registerStorage(Container $container): void
    {
        $container->register(self::SERVICE_REGISTRY_DRIVER, function (Container $container) {
            $registry = new StorageRegistry($container, $container->getParameter(self::PARAM_STORAGE));

            foreach ($container->getServiceIdsForTag(self::TAG_STORAGE_DRIVER) as $serviceId => $attributes) {
                $registry->registerService($attributes['name'], $serviceId);
            }

            return $registry;
        });
        $container->register(XmlDriver::class, function (Container $container) {
            return new XmlDriver(
                $container->getParameter(self::PARAM_XML_STORAGE_PATH),
                $container->get(XmlEncoder::class),
                $container->get(XmlDecoder::class)
            );
        }, [self::TAG_STORAGE_DRIVER => ['name' => 'xml']]);

        $container->register(UuidResolver::class, function (Container $container) {
            $resolvers = [];

            foreach (array_keys($container->getServiceIdsForTag(self::TAG_UUID_RESOLVER)) as $serviceId) {
                $resolvers[] = $container->get($serviceId);
            }

            return new UuidResolver(new ChainResolver($resolvers));
        });

        $container->register(LatestResolver::class, function (Container $container) {
            return new LatestResolver(
                $container->get(self::SERVICE_REGISTRY_DRIVER)
            );
        }, [self::TAG_UUID_RESOLVER => []]);

        $container->register(TagResolver::class, function (Container $container) {
            return new TagResolver(
                $container->get(self::SERVICE_REGISTRY_DRIVER)
            );
        }, [self::TAG_UUID_RESOLVER => []]);
    }

    private function relativizeConfigPath(Container $container): void
    {
        $paths = (array)$container->getParameter(self::PARAM_PATH);

        if (empty($paths)) {
            return;
        }

        $container->setParameter(self::PARAM_PATH, array_map(function (string $path) use ($container) {
            if (Path::isAbsolute($path)) {
                return $path;
            }

            return Path::join([
                dirname($container->getParameter(self::PARAM_CONFIG_PATH)),
                $path
            ]);
        }, $paths));
    }

    private function createOutput(Container $container, string $type): OutputInterface
    {
        if ($container->getParameter(self::PARAM_DISABLE_OUTPUT)) {
            return new NullOutput();
        }

        $output = (function (string $name): OutputInterface {
            $resource = fopen($name, 'w');

            if (false === $resource) {
                throw new RuntimeException(sprintf(
                    'Could not open stream "%s"',
                    $name
                ));
            }

            return new StreamOutput($resource);
        })($container->getParameter($type));

        if (false === $container->getParameter(self::PARAM_CONSOLE_ANSI)) {
            $output->setDecorated(false);
        }

        $output->getFormatter()->setStyle('success', new OutputFormatterStyle('black', 'green', []));
        $output->getFormatter()->setStyle(self::ENV_PROVIDER_BASELINE, new OutputFormatterStyle('cyan', null, []));
        $output->getFormatter()->setStyle('result-neutral', new OutputFormatterStyle('cyan', null, []));
        $output->getFormatter()->setStyle('result-good', new OutputFormatterStyle('green', null, []));
        $output->getFormatter()->setStyle('result-none', new OutputFormatterStyle(null, null, []));
        $output->getFormatter()->setStyle('result-failure', new OutputFormatterStyle('white', 'red', []));
        $output->getFormatter()->setStyle('title', new OutputFormatterStyle('white', null, ['bold']));
        $output->getFormatter()->setStyle('subtitle', new OutputFormatterStyle('white', null, []));
        $output->getFormatter()->setStyle('description', new OutputFormatterStyle(null, null, []));

        return $output;
    }
}
