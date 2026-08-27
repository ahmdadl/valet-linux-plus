<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Output\BufferedOutput;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Log;
use Valet\Tests\TestCase;

class LogTest extends TestCase
{
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Configuration|MockObject $config;
    private Log $log;

    public function setUp(): void
    {
        parent::setUp();

        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(Configuration::class);

        $this->log = new Log(
            $this->commandLine,
            $this->filesystem,
            $this->config
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
                $this->assertSame('journalctl --no-pager -n 50 -u php*-fpm', $command);

                return "[php-fpm] worker started\n[php-fpm] request served\n";
            });

        Writer::fake();

        ob_start();
        $this->log->tail('php', 50);
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
                $this->assertSame('journalctl --no-pager -n 50 -u unknownservice', $command);
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
}
