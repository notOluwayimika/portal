<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('make:repository {name}')]
#[Description('Create a new repository class')]
class CreateRepository extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->argument('name');
        $path = app_path('Repositories');

        // Create directory if it doesn't exist
        if (!File::exists($path)) {
            File::makeDirectory($path, 0755, true);
        }

        // Generate the repository file
        $filePath = $path . '/' . $name . '.php';
        $stub = <<<EOD
        <?php

        namespace App\Repositories;

        class {$name}
        {
           public function __construct()
           {
                // Code here
           }
        }
        EOD;

        File::put($filePath, $stub);

        $this->info("Repository {$name} created successfully at {$filePath}");
    }
}
