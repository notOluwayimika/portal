<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('make:dto {name}')]
#[Description('Create a new DTO class')]
class CreateDto extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->argument('name');
        $path = app_path('DTOs');

        // Create directory if it doesn't exist
        if (!File::exists($path)) {
            File::makeDirectory($path, 0755, true);
        }

        // Generate the DTO file
        $filePath = $path . '/' . $name . '.php';
        $stub = <<<EOD
        <?php

        namespace App\DTOs;

        use Illuminate\Http\Request;

        readonly class {$name}
        {
            public function __construct() {}

            public static function fromRequest(Request \$request): self
            {
                return new self(\$request->validated());
            }

            public static function fromArray(array \$data): self
            {
                return new self(\$data);
            }

            public function toArray(): array
            {
                return \$this->toArray();
            }
        }
        EOD;

        File::put($filePath, $stub);

        $this->info("DTO {$name} created successfully at {$filePath}");
    }
}
