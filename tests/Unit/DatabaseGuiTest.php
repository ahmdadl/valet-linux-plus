<?php

namespace Valet\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\DatabaseGui;
use Valet\Tests\TestCase;

use function Valet\swap;

class DatabaseGuiTest extends TestCase
{
    private MockInterface $config;
    private MockInterface $cli;
    private DatabaseGui $gui;

    public function setUp(): void
    {
        parent::setUp();

        $this->config = Mockery::mock(Configuration::class);
        $this->cli = Mockery::mock(CommandLine::class);

        /** @var Configuration $config */
        $config = $this->config;
        /** @var CommandLine $cli */
        $cli = $this->cli;

        $this->gui = new DatabaseGui($config, $cli);
    }

    /**
     * @test
     */
    public function it_returns_database_url_from_environment(): void
    {
        $environment = Mockery::mock();
        $environment->shouldReceive('databaseUrl')->once()->with(false)->andReturn('mysql://valet:secret@127.0.0.1:3306/app');
        swap('Valet\Environment', $environment);

        $this->assertSame('mysql://valet:secret@127.0.0.1:3306/app', $this->gui->url());
    }

    /**
     * @test
     */
    public function it_opens_adminer_without_password_in_url(): void
    {
        $addon = Mockery::mock();
        $addon->shouldReceive('list')->andReturn([['name' => 'adminer', 'enabled' => true]]);
        $addon->shouldReceive('adminerUrl')->andReturn('https://adminer.test');
        swap('Valet\Addon', $addon);

        $environment = Mockery::mock();
        $environment->shouldReceive('gather')->andReturn([
            'DB_HOST' => '127.0.0.1',
            'DB_USERNAME' => 'valet',
            'DB_DATABASE' => 'my_app',
            'DB_CONNECTION' => 'mysql',
            'DB_PASSWORD' => 'secret',
        ]);
        swap('Valet\Environment', $environment);

        $this->cli->shouldReceive('quietly')->once()->withArgs(function (string $command) {
            return str_contains($command, 'https://adminer.test')
                && str_contains($command, 'my_app')
                && !str_contains($command, 'secret');
        });

        \ConsoleComponents\Writer::fake();
        $this->gui->open('adminer');
    }
}
