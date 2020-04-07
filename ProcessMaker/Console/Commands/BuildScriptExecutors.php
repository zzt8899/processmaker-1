<?php

namespace ProcessMaker\Console\Commands;

use Illuminate\Console\Command;
use ProcessMaker\Events\BuildScriptExecutor;
use ProcessMaker\BuildSdk;
use ProcessMaker\Models\ScriptExecutor;
use \Exception;

class BuildScriptExecutors extends Command
{
    /**
     * The name and signature of the console command.
     *
     *
     * @var string
     */
    protected $signature = 'processmaker:build-script-executor {lang} {user?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '';
    
    /**
     * The user ID to send the broadcast event to.
     *
     * @var int
     */
    protected $userId = null;
    
    /**
     * The path to save the current running process id
     *
     * @var string
     */
    protected $pidFilePath = null;
    
    /**
     * The path to the executor package
     *
     * @var string
     */
    protected $packagePath = null;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->userId = $this->argument('user');
        try {
            $this->buildExecutor();
        } catch (\Exception $e) {
            if ($this->userId) {
                event(new BuildScriptExecutor($e->getMessage(), $this->userId, 'error'));
            }
            throw new \Exception($e->getMessage());
        } finally {
            if ($this->packagePath && file_exists($this->packagePath . '/Dockerfile.custom')) {
                unlink($this->packagePath . '/Dockerfile.custom');
            }
            if ($this->pidFilePath) {
                unlink($this->pidFilePath);
            }
        }
    }

    public function buildExecutor()
    {
        $this->savePid();
        $this->sendEvent($this->pidFilePath, 'starting');
        
        $langArg = $this->argument('lang');
        if (is_numeric($langArg)) {
            $scriptExecutor = ScriptExecutor::findOrFail($langArg);
        } else {
            $scriptExecutor = ScriptExecutor::initialExecutor($langArg);
        }
        $lang = $scriptExecutor->language;

        $this->info("Building for language: $lang");
        $this->info("Generating SDK json document");
        $this->artisan('l5-swagger:generate');

        $this->packagePath = $packagePath =
            ScriptExecutor::packagePath($lang);

        $sdkDir = $packagePath . "/sdk";

        if (!is_dir($sdkDir)) {
            mkdir($sdkDir, 0755, true);
        }

        $this->info("Building the SDK");
        $this->artisan("processmaker:sdk $lang $sdkDir --clean");
        $this->info("SDK is at ${sdkDir}");

        $dockerfile = ScriptExecutor::initDockerfile($lang) . "\n" . $scriptExecutor->config;

        $this->info("Dockerfile:\n  " . implode("\n  ", explode("\n", $dockerfile)));
        file_put_contents($packagePath . '/Dockerfile.custom', $dockerfile);

        $this->info("Building the docker executor");

        $image = $scriptExecutor->dockerImageName();
        $command = "docker build --build-arg SDK_DIR=/sdk -t ${image} -f ${packagePath}/Dockerfile.custom ${packagePath}";

        if ($this->userId) {
            $this->runProc(
                $command,
                function() {
                    // Command starting
                },
                function($output) {
                    // Command output callback
                    $this->sendEvent($output, 'running');
                },
                function($exitCode) {
                    // Command finished callback
                    $this->sendEvent($exitCode, 'done');
                }
            );
        } else {
            system($command);
        }
    }

    public function info($text, $verbosity = null) {
        if ($this->userId) {
            $this->sendEvent($text . "\n", 'running');
        }
        parent::info($text, $verbosity);
    }

    private function sendEvent($output, $status)
    {
        event(new BuildScriptExecutor($output, $this->userId, $status));
    }
    
    private function artisan($cmd)
    {
        \Artisan::call($cmd);
    }

    private function savePid()
    {
        $pid = getmypid();
        $this->pidFilePath = tempnam('/tmp', 'build_script_executor_');
        file_put_contents($this->pidFilePath, $pid);
    }

    private function runProc($cmd, $start, $callback, $done)
    {
        $dsc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $process = proc_open("($cmd) 2>&1", $dsc, $pipes);

        $start();

        while(!feof($pipes[1])) {
            $callback(fgets($pipes[1]));
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        $exitCode = proc_close($process);
        $done($exitCode);
    }
}