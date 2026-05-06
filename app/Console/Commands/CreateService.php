<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('make:service {name}')]
#[Description('Create a new service class')]
class CreateService extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->argument('name');
        $path = app_path('Services'); // Automatically sets path to app/Enums

        // Create directory if it doesn't exist
        if (!File::exists($path)) {
            File::makeDirectory($path, 0755, true);
        }

        // Generate the service file
        $filePath = $path . '/' . $name . '.php';
        $stub = <<<EOD
        <?php

        namespace App\Services;

        class {$name}
        {
           public function __construct()
           {
                // Code here
           }
        }
        EOD;

        File::put($filePath, $stub);

        $this->info("Service {$name} created successfully at {$filePath}");
    }
}
