<?php

namespace App\Console\Commands;

use App\Http\Api\OpenApi;
use Illuminate\Console\Command;

/** Writes the OpenAPI document of /api/v1 (resources/api/openapi.json) from the code that serves it. */
class OpenApiCommand extends Command
{
    protected $signature = 'arkon:openapi';

    protected $description = 'Regenerate resources/api/openapi.json from the API registries';

    public function handle(): int
    {
        $path = resource_path('api/openapi.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, self::encode(OpenApi::build()));
        $this->info('Wrote '.$path);

        return self::SUCCESS;
    }

    public static function encode(array $spec): string
    {
        return json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
