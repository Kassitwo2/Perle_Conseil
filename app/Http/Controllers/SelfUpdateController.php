<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2023. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Http\Controllers;

use App\Exceptions\FilePermissionsFailure;
use App\Models\Client;
use App\Utils\Ninja;
use App\Utils\Traits\AppSetup;
use App\Utils\Traits\ClientGroupSettingsSaver;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

class SelfUpdateController extends BaseController
{
    use DispatchesJobs;
    use ClientGroupSettingsSaver;
    use AppSetup;

    private array $purge_file_list = [
        'bootstrap/cache/compiled.php',
        'bootstrap/cache/config.php',
        'bootstrap/cache/packages.php',
        'bootstrap/cache/services.php',
        'bootstrap/cache/routes-v7.php',
        'bootstrap/cache/livewire-components.php',
    ];

    public function __construct()
    {
    }

    /**
     * @OA\Post(
     *      path="/api/v1/self-update",
     *      operationId="selfUpdate",
     *      tags={"update"},
     *      summary="Performs a system update",
     *      description="Performs a system update",
     *      @OA\Parameter(ref="#/components/parameters/X-API-TOKEN"),
     *      @OA\Parameter(ref="#/components/parameters/X-API-PASSWORD"),
     *      @OA\Parameter(ref="#/components/parameters/X-Requested-With"),
     *      @OA\Parameter(ref="#/components/parameters/include"),
     *      @OA\Response(
     *          response=200,
     *          description="Success/failure response"
     *       ),
     *       @OA\Response(
     *          response=422,
     *          description="Validation error",
     *          @OA\JsonContent(ref="#/components/schemas/ValidationError"),
     *
     *       ),
     *       @OA\Response(
     *           response="default",
     *           description="Unexpected Error",
     *           @OA\JsonContent(ref="#/components/schemas/Error"),
     *       ),
     *     )
     */
 
    public function update()
    {
        set_time_limit(0);
        define('STDIN', fopen('php://stdin', 'r'));

        if (Ninja::isHosted()) {
            return response()->json(['message' => ctrans('texts.self_update_not_available')], 403);
        }

        nlog('Test filesystem is writable');

        $this->testWritable();

        nlog('Clear cache directory');

        $this->clearCacheDir();

        nlog('copying release file');

        if (copy($this->getDownloadUrl(), storage_path('app/invoiceninja.zip'))) {
            nlog('Copied file from URL');
        } else {
            return response()->json(['message' => 'Download not yet available. Please try again shortly.'], 410);
        }

        nlog('Finished copying');

        $file = Storage::disk('local')->path('invoiceninja.zip');

        nlog('Extracting zip');

        $zipFile = new \PhpZip\ZipFile();

        $zipFile->openFile($file);

        $zipFile->deleteFromName(".htaccess");
        
        $zipFile->rewrite();

        $zipFile->extractTo(base_path());

        $zipFile->close();

        $zipFile = null;
        
        nlog('Finished extracting files');

        unlink($file);

        nlog('Deleted release zip file');

        foreach ($this->purge_file_list as $purge_file_path) {
            $purge_file = base_path($purge_file_path);
            if (file_exists($purge_file)) {
                unlink($purge_file);
            }
        }

        nlog('Removing cache files');

        Artisan::call('clear-compiled');
        Artisan::call('route:clear');
        Artisan::call('view:clear');
        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('config:clear');

        $this->buildCache(true);

        nlog('Called Artisan commands');

        return response()->json(['message' => 'Update completed'], 200);
    }

    private function deleteDirectory($dir)
    {
        if (! file_exists($dir)) {
            return true;
        }

        if (! is_dir($dir) || is_link($dir)) {
            return unlink($dir);
        }
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            if (! $this->deleteDirectory($dir.'/'.$item)) {
                if (! $this->deleteDirectory($dir.'/'.$item)) {
                    return false;
                }
            }
        }

        return rmdir($dir);
    }

    private function postHookUpdate()
    {
        if (config('ninja.app_version') == '5.3.82') {
            Client::withTrashed()->cursor()->each(function ($client) {
                $entity_settings = $this->checkSettingType($client->settings);
                $entity_settings->md5 = md5(time());
                $client->settings = $entity_settings;
                $client->save();
            });
        }
    }

    private function clearCacheDir()
    {
        $directoryIterator = new \RecursiveDirectoryIterator(base_path('bootstrap/cache'), \RecursiveDirectoryIterator::SKIP_DOTS);

        foreach (new \RecursiveIteratorIterator($directoryIterator) as $file) {
            unlink(base_path('bootstrap/cache/').$file->getFileName());
        }

        $directoryIterator = null;
    }

    private function testWritable()
    {
        $directoryIterator = new \RecursiveDirectoryIterator(base_path(), \RecursiveDirectoryIterator::SKIP_DOTS);

        foreach (new \RecursiveIteratorIterator($directoryIterator) as $file) {
            if (strpos($file->getPathname(), '.git') !== false) {
                continue;
            }

            if ($file->isFile() && ! $file->isWritable()) {
                nlog("Cannot update system because {$file->getFileName()} is not writable");
                throw new FilePermissionsFailure("Cannot update system because {$file->getFileName()} is not writable");

                return false;
            }
        }

        $directoryIterator = null;
        
        return true;
    }

    public function checkVersion()
    {
        return trim(file_get_contents(config('ninja.version_url')));
    }

    private function getDownloadUrl()
    {
        $version = $this->checkVersion();

        return "https://github.com/invoiceninja/invoiceninja/releases/download/v{$version}/invoiceninja.zip";
    }
}
