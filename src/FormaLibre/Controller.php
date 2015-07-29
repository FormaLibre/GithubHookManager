<?php

namespace FormaLibre;

class Controller
{
    private $errorLog;
    private $scriptLog;
    private $accessLog;
    private $logDir;
    private $scriptDir;

    public function __construct()
    {
        $this->logDir    = realpath(__DIR__ . '/../../logs');
        $this->scriptDir = realpath(__DIR__ . '/../../scripts');
        $this->errorLog  = $this->logDir . '/error.log';
        $this->accessLog = $this->logDir . '/access.log';
        $this->scriptLog = $this->logDir . '/script.log';
    }

    /**
     * github hook
     */
    public function execute($script)
    {
        $scriptFile = $this->scriptDir . '/' . $script;
        $headers = getallheaders();

        if (!isset($headers['X-Hub-Signature'])) {
            $this->logError('X-Hub-Signature missing.');
            return;
        }

        $this->logAccess('Github hook activated...');

        if (!isset($_POST['payload'])) {
            $this->logError('Payload missing.');
            return;
        }

        $json = $_POST['payload'];
        $payload = json_decode($json);
        $repository = $payload->repository->full_name;

        if (!$this->validateGithubPayload(file_get_contents('php://input'), $headers['X-Hub-Signature'], $repository)) {
            return;
        }

        exec("$script '". escapeshellcmd($this->scriptLog) . "'");
    }

    public function validateGithubPayload($payload, $hash, $repository)
    {
        $pwd = ParametersHandler::getRepositorySecret($repository);
        //$this->logAccess("Validating access for $repository with secret $pwd and token $hash");
        $str = hash_hmac('sha1', $payload, $pwd);
        //$this->logAccess("Computed hash is $str");
        return 'sha1=' . $str === $hash;
    }

    private function logError($message)
    {
        $this->log($message, $this->errorLog);
    }

    private function logAccess($message)
    {
        $this->log($message, $this->accessLog);
    }

    private function log($message, $file)
    {
         $msg = date('d-m-Y H:i:s') . ': ' . $message . "\n";
         file_put_contents($file, $message, FILE_APPEND);
    }
}
