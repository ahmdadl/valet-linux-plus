<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Log;
use Valet\Tests\TestCase;

class LogTest extends TestCase
{
    private MockInterface $commandLine;
    private MockInterface $filesystem;
    private MockInterface $config;
    private Log $log;

    public function setUp(): void
    {
        parent::setUp();

        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(Configuration::class);

        /** @var CommandLine $commandLine */
        $commandLine = $this->commandLine;
        /** @var Filesystem $filesystem */
        $filesystem = $this->filesystem;
        /** @var Configuration $config */
        $config = $this->config;

        $this->log = new Log(
            $commandLine,
            $filesystem,
            $config
        );
    }

    /**
     * @test
     */
    public function itWillTailNginxLogFileWhenItExists(): void
    {
        $path = VALET_HOME_PATH . '/Log/nginx-error.log';

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with($path)
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with($path)
            ->andReturn("line one\nline two\nline three\n");

        Writer::fake();

        ob_start();
        $this->log->tail('nginx', 50);
        /** @var string $output */
        $output = ob_get_clean();

        $this->assertStringContainsString('line one', $output);
        $this->assertStringContainsString('line three', $output);

        /** @var BufferedOutput $writerOutput */
        $writerOutput = Writer::output();
        $this->assertStringNotContainsString('No logs found', $writerOutput->fetch());
    }

    /**
     * @test
     */
    public function itWillWarnWhenNginxLogFileDoesNotExist(): void
    {
        $path = VALET_HOME_PATH . '/Log/nginx-error.log';

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with($path)
            ->andReturnFalse();

        Writer::fake();

        $this->log->tail('nginx');

        /** @var BufferedOutput $output */
        $output = Writer::output();
        $this->assertStringContainsString('No logs found for nginx', $output->fetch());
    }

    /**
     * @test
     */
    public function itWillTailJournalctlForPhpService(): void
    {
        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($command, $onError) {
                $this->assertSame('journalctl --no-pager -n 50 -u '.escapeshellarg('php*-fpm'), $command);

                return "[php-fpm] worker started\n[php-fpm] request served\n";
            });

        Writer::fake();

        ob_start();
        $this->log->tail('php', 50);
        /** @var string $output */
        $output = ob_get_clean();

        $this->assertStringContainsString('worker started', $output);
        $this->assertStringContainsString('request served', $output);
    }

    /**
     * @test
     */
    public function itWillWarnWhenJournalctlFailsAndNoFallbackFile(): void
    {
        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($command, $onError) {
                $this->assertSame('journalctl --no-pager -n 50 -u '.escapeshellarg('unknownservice'), $command);
                // Simulate journalctl failure so the caller falls back / warns.
                $onError(1, '');

                return '';
            });

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with('/var/log/unknownservice.log')
            ->andReturnFalse();

        Writer::fake();

        $this->log->tail('unknownservice');

        /** @var BufferedOutput $output */
        $output = Writer::output();
        $this->assertStringContainsString('No logs found for unknownservice', $output->fetch());
    }

    /**
     * @test
     */
    public function itWillOpenMailpitUiForConfiguredDomain(): void
    {
        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('domain', 'test')
            ->andReturn('test');

        $this->commandLine
            ->shouldReceive('quietly')
            ->once()
            ->with('xdg-open ' . escapeshellarg('https://mails.test'));

        $this->log->openMail();
    }

    /**
     * @test
     */
    public function itAggregatesPrefixedLinesFromMultipleServices(): void
    {
        $nginxPath = VALET_HOME_PATH . '/Log/nginx-error.log';

        $this->filesystem
            ->shouldReceive('exists')
            ->with($nginxPath)
            ->andReturnTrue();
        $this->filesystem
            ->shouldReceive('get')
            ->with($nginxPath)
            ->andReturn("nginx boom\nnginx ok\n");

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($command) {
                $this->assertStringContainsString('php*-fpm', $command);

                return "php worker\nphp done\n";
            });

        $lines = $this->log->collect(['nginx', 'php'], 50);

        $this->assertContains('[nginx] nginx boom', $lines);
        $this->assertContains('[php] php worker', $lines);
    }

    /**
     * @test
     */
    public function itFiltersAggregatedLinesWithGrep(): void
    {
        $nginxPath = VALET_HOME_PATH . '/Log/nginx-error.log';

        $this->filesystem
            ->shouldReceive('exists')
            ->with($nginxPath)
            ->andReturnTrue();
        $this->filesystem
            ->shouldReceive('get')
            ->with($nginxPath)
            ->andReturn("error one\ninfo two\nerror three\n");

        $lines = $this->log->collect(['nginx'], 50, 'error');

        $this->assertSame([
            '[nginx] error one',
            '[nginx] error three',
        ], $lines);
    }
}
