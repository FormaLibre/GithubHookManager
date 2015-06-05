<?php

namespace Claroline\Manager;

use Claroline\Exception\BundleNotFoundException;
use Claroline\Exception\VendorNotFoundException;
use Claroline\Handler\ParametersHandler;
use Claroline\Model\Bundle;
use Claroline\FileSystem\FileSystem;

class PackageManager
{
    private $outputDir;
    private $fs;
    private $logger;

    public function __construct($outputDir, $logger = null)
    {
        $this->outputDir = $outputDir;
        $this->fs = new Filesystem();
        $this->logger = $logger;
    }

    /**
     * Creates an new package in the output directory.
     */
    public function create($repository, $tag = null)
    {
        if (!$tag) $tag = $this->getLatestRepositoryTag($repository);
        $bundleName = $this->getBundleFromRepository($repository);
        $outputTag = str_replace('v', '', $tag);
        $output = $this->outputDir . '/' . $bundleName . '/' . $outputTag;
        $this->fs->mkdir($output);
        $url = sprintf(
            "https://github.com/{$repository}/archive/%s.zip",
            $tag
        );

        if ($this->logger) $this->logger->writeln("cloning $repository $outputTag...");
        //1st step, download and store the archive

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        $zipFile = $output . '/package.zip';
        $file = fopen($zipFile, "w+");;
        curl_setopt($ch, CURLOPT_FILE, $file);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Claroline');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec ($ch);
        curl_close ($ch);
        fclose($file);
        //2nd step, unzip everything and rename the root directory before we pack everything again!
        $archive = new \ZipArchive();

        if ($this->logger) $this->logger->writeln("first extraction...");
        //first we unzip
        if ($archive->open($zipFile) === true) {
            $archive->extractTo($output . '/');
            $archive->close();
        }

        if ($this->logger) $this->logger->writeln("renaming root directory...");

        $this->fs->rename(
            $output . '/' . $this->getRepositoryUrlBundleName($repository) . '-' . $outputTag,
            $output . '/' . $bundleName . '-' . $outputTag
        );

        if ($this->logger) $this->logger->writeln("injecting version file...");
        file_put_contents($output . '/' . $bundleName . '-' . $outputTag . '/VERSION.txt', $outputTag);
        if ($this->logger) $this->logger->writeln("removing old zip file...");
        $this->fs->remove($zipFile);
        if ($this->logger) $this->logger->writeln("generating new archive...");
        $tmp = $this->fs->zipDir($output . '/' . $bundleName . '-' . $outputTag);
        if ($this->logger) $this->logger->writeln("moving new archive from temporary directory...");
        $this->fs->rename($tmp, $zipFile);
        if ($this->logger) $this->logger->writeln("Repository $repository cloned !");
        $this->logAccess("Repository $repository cloned !");
        $scripts = ParametersHandler::getParameter('hook_scripts');

        foreach ($scripts as $script) {
            exec("$script '" . escapeshellcmd($bundleName) . "' '" . escapeshellcmd($tag) . "' '" . escapeshellcmd(ParametersHandler::getParameter('hook_log')) . "'");
        }
        //3rd generate readme for each pkg
        //generate readme here because it should be great !*/
    }

    /**
     * Get the last available tag of a repository from github.
     */
    public function getLatestRepositoryTag($repository)
    {
        $tags = $this->getRepositoryTags($repository);

        return $tags[0]->name;
    }

    /**
     * Get the list of available tags from github.
     */
    public function getRepositoryTags($repository)
    {
        $options = array(
            'http' => array(
                'method' => 'GET',
                'user_agent' => 'Claroline',
                'timeout' => 5
            )
        );

        return json_decode(file_get_contents("https://api.github.com/repos/$repository/tags", false,
            stream_context_create($options)
        ));
    }

    /**
     * Get the bundle name from a repository.
     */
    public function getBundleFromRepository($repository)
    {
        $options = array(
            'http' => array(
                'method' => 'GET',
                'user_agent' => 'Claroline',
                'timeout' => 5
            )
        );

        $url = "https://raw.githubusercontent.com/{$repository}/master/composer.json";
        $data = json_decode(file_get_contents($url, false,
            stream_context_create($options)
        ));

        $prop = 'target-dir';
        $parts = explode('/', $data->$prop);
        $name = end($parts);

        return $name;
    }

    /**
     * Get the bundle name from a repository url.
     */
    public function getRepositoryUrlBundleName($repository)
    {
        return substr($repository, strpos($repository, "/") + 1);
    }

    /**
     * Returns the directory where the bundles of this type are stored
     */
    public function getBundleOutputDirectory($bundle)
    {
        $dir = $this->outputDir . "/{$bundle}";

        if (!is_dir($dir)) {
            throw new BundleNotFoundException("The directory $dir was not found");
        }

        return $dir;
    }

    /**
     * Returns the directory where a tag is stored
     */
    public function getTagOutputDirectory($bundle, $tag)
    {
        $dir = $this->getBundleOutputDirectory($bundle) . "/{$tag}";

        if (!is_dir($dir)) {
            throw new TagNotFoundException("The directory $dir was not found");
        }

        return $dir;
    }

    /**
     * Returns the latest tag of an uploaded bundle.
     */
    public function getLatestUploadedTag($bundle)
    {
        $dir = $this->getBundleOutputDirectory($bundle);
        $iterator = new \DirectoryIterator($dir);
        $maxVersion = '0.0.0';

        foreach ($iterator as $el) {
            if (version_compare($maxVersion, $el->getBaseName(), '<')) {
                $maxVersion = $el->getBaseName();
            }
        }

        return $maxVersion;
    }

    /**
     * Returns the list of available tags for a bundle.
     */
    public function getUploadedTags($bundle)
    {
        $dir = $this->getBundleOutputDirectory($bundle);
        $iterator = new \DirectoryIterator($dir);
        $tags = array();

        foreach ($iterator as $el) {
            if (!$el->isDot() && $el->isDir()) $tags[] = $el->getBaseName();
        }

        return $tags;
    }

    /**
     * Returns the last installable bundle tag for a version of the core bundle
     */
    public function getLastInstallableTag($bundle, $coreVersion)
    {
        $arr = explode(".", $coreVersion);
        $majorV = $arr[0];
        $nextMajor = ((integer) $majorV) + 1;
        $prevMajor = ((integer) $majorV) - 1;
        $dir = $this->getBundleOutputDirectory($bundle);
        $iterator = new \DirectoryIterator($dir);
        $maxVersion = "$prevMajor.999.999.999.999";
        $nextMajorVersion = "$nextMajor.0.0.0.0.0";

        foreach ($iterator as $el) {
            if (
                version_compare($maxVersion, $el->getBaseName(), '<') &&
                version_compare($el->getBaseName(), $nextMajor, '<')
            ) {
                $maxVersion = $el->getBaseName();
            }
        }

        return $maxVersion === "$prevMajor.999.999.999.999" ? null: $maxVersion;
    }

    public function getAvailableBundles()
    {
        $bundles = array();
        $iterator = new \DirectoryIterator($this->outputDir);

        foreach ($iterator as $el) {
            if (!$el->isDot() && $el->isDir()) $bundles[] = $el->getBaseName();
        }

        return $bundles;
    }

    /**
     * Returns a list of installable bundle tag for a version of the core bundle
     */
    public function getLastInstallableTags($coreVersion)
    {
        if ($coreVersion === 'dev-master') {
            $coreVersion = $this->getLatestUploadedTag('CoreBundle');
        }

        $bundles = $this->getAvailableBundles();
        $tags = array();

        foreach ($bundles as $bundle) {
            $version = $this->getLastInstallableTag($bundle, $coreVersion);
            if ($version) $tags[$bundle] = $version;
        }

        return $tags;
    }

    public function logError($msg)
    {
        file_put_contents(ParametersHandler::getParameter('error_log'), $this->prepareLog($msg), FILE_APPEND);
    }

    public function logAccess($msg)
    {
        file_put_contents(ParametersHandler::getParameter('access_log'), $this->prepareLog($msg), FILE_APPEND);
    }

    public function prepareLog($msg)
    {
        $msg = date('d-m-Y H:i:s') . ': ' . $msg . "\n";

        return $msg;
    }

    public function validateGithubPayload($payload, $hash, $repository)
    {
        $pwd = ParametersHandler::getRepositorySecret($repository);
        //$this->logAccess("Validating access for $repository with secret $pwd and token $hash");
        $str = hash_hmac('sha1', $payload, $pwd);
        //$this->logAccess("Computed hash is $str");

        return 'sha1=' . $str === $hash;
    }

    public function getBundle($bundle, $version)
    {
        $composerPath = $this->outputDir . "/$bundle/$version/$bundle-$version/composer.json";
        $json = file_get_contents($composerPath);
        $data = json_decode($json);
        $bundle = new Bundle(
            $this->getComposerClarolineName($data),
            $this->getComposerAuthors($data),
            $this->getComposerDescription($data),
            $version,
            $this->getComposerType($data),
            $this->getComposerLicense($data),
            $this->getComposerTargetDir($data),
            $this->getComposerBasePath($data),
            $this->getComposerRequirements($data)
        );

        return $bundle;
    }

    private function getComposerVersion($data)
    {
        if (property_exists($data, 'version')) return $data->version;

        return "0.0.0.0";
    }

    private function getComposerClarolineName($data)
    {
        $prop = 'target-dir';
        $parts = explode('/', $data->$prop);

        return end($parts);
    }

    private function getComposerType($data)
    {
        return $data->type;
    }

    private function getComposerAuthors($data)
    {
        if (property_exists($data, 'authors')) return $data->authors;

        return array();
    }

    private function getComposerLicense($data)
    {
        if (property_exists($data, 'license')) return $data->license;

        return 'unknown';
    }

    private function getComposerTargetDir($data)
    {
        $prop = 'target-dir';

        return $data->$prop;
    }

    private function getComposerDescription($data)
    {
        if (property_exists($data, 'description')) return $data->description;

        return null;
    }

    private function getComposerBasePath($data)
    {
        if (property_exists($data, 'name')) return $data->name;
    }

    private function getComposerRequirements($data)
    {
        if (property_exists($data, 'require')) return $data->require;
    }
}
