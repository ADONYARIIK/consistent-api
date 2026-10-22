<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CommandDescriptionTest extends TestCase
{
    /**
     * Commands are built on the first Artisan call, so the folder is configured before the test body runs.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('consistentapi.modules_folder', '/Domain/Modules/');
    }

    public function test_descriptions_use_the_configured_modules_folder(): void
    {
        $commands = Artisan::all();

        $this->assertSame('Create an API module under app/Domain/Modules', $commands['consistent:create']->getDescription());
        $this->assertSame('Rebuild conventional API classes into modules under app/Domain/Modules', $commands['consistent:rebuild']->getDescription());
    }
}
