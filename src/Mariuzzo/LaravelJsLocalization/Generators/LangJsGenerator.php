<?php

namespace Mariuzzo\LaravelJsLocalization\Generators;

use Cartalyst\Dependencies\DependencySorter;
use Illuminate\Filesystem\Filesystem as File;
use JShrink\Minifier;

/**
 * The LangJsGenerator class.
 *
 * @author  Rubens Mariuzzo <rubens@mariuzzo.com>
 */
class LangJsGenerator
{
    /**
     * The file service.
     *
     * @var File
     */
    protected $file;

    /**
     * The source path of the language files.
     *
     * @var string
     */
    protected $sourcePath;

    /**
     * List of messages should be included in build.
     *
     * @var array
     */
    protected $messagesIncluded = [];

    protected $modulesPath;

    /**
     * Construct a new LangJsGenerator instance.
     *
     * @param File   $file       The file service instance.
     * @param string $sourcePath The source path of the language files.
     */
    public function __construct(File $file, $sourcePath, $messagesIncluded = [])
    {
        $this->file = $file;
        $this->sourcePath = $sourcePath;
        $this->messagesIncluded = $messagesIncluded;
    }

    /**
     * Generate a JS lang file from all language files.
     *
     * @param string $target  The target directory.
     * @param array  $options Array of options.
     *
     * @return int
     */

    public function generate($target, $options)
    {
        if ($options['source']) {
            $this->sourcePath = $options['source'];
        }
        $messages = $this->getMessages();
        $this->prepareTarget($target);
        if ($options['no-lib']) {
            $template = $this->file->get(__DIR__.'/Templates/messages.js');
        } else if ($options['json']) {
            $template = $this->file->get(__DIR__.'/Templates/messages.json');
        } else {
            $template = $this->file->get(__DIR__.'/Templates/langjs_with_messages.js');
            $langjs = $this->file->get(__DIR__.'/../../../../lib/lang.min.js');
            $template = str_replace('\'{ langjs }\';', $langjs, $template);
        }
        $template = str_replace('\'{ messages }\'', json_encode($messages), $template);
        if ($options['compress']) {
            $template = Minifier::minify($template);
        }
        return $this->file->put($target, $template);
    }

    /**
     * Recursively sorts all messages by key.
     *
     * @param array $messages The messages to sort by key.
     */
    protected function sortMessages(&$messages)
    {
        if (is_array($messages)) {
            ksort($messages);

            foreach ($messages as $key => &$value) {
                $this->sortMessages($value);
            }
        }
    }

    /**
     * Return all language messages.
     *
     * @return array
     *
     * @throws \Exception
     */
    protected function getMessages()
    {
        $messages = [];
        $path = $this->sourcePath;

        if (!$this->file->exists($path)) {
            throw new \Exception("{$path} doesn't exists!");
        }

        foreach ($this->file->allFiles($path) as $file) {
            $pathName = $file->getRelativePathName();

            if ($this->file->extension($pathName) !== 'php') {
                continue;
            }

            if ($this->isMessagesExcluded($pathName)) {
                continue;
            }

            $key = substr($pathName, 0, -4);
            $key = str_replace('\\', '.', $key);
            $key = str_replace('/', '.', $key);

            if (starts_with($key, 'vendor')) {
                $key = $this->getVendorKey($key);
            }

            $messages[$key] = include $path . DIRECTORY_SEPARATOR . $pathName;
        }

        $this->sortMessages($messages);

        return $messages;
    }


    /**
     * Prepare the target directory.
     *
     * @param string $target The target directory.
     */
    protected function prepareTarget($target)
    {
        $dirname = dirname($target);

        if (!$this->file->exists($dirname)) {
            $this->file->makeDirectory($dirname, 0755, true);
        }
    }

    /**
     * If messages should be excluded from build.
     *
     * @param string $filePath
     *
     * @return bool
     */
    protected function isMessagesExcluded($filePath)
    {
        if (empty($this->messagesIncluded)) {
            return false;
        }

        $filePath = str_replace(DIRECTORY_SEPARATOR, '/', $filePath);

        $localeDirSeparatorPosition = strpos($filePath, '/');
        $filePath = substr($filePath, $localeDirSeparatorPosition);
        $filePath = ltrim($filePath, '/');
        $filePath = substr($filePath, 0, -4);

        if (in_array($filePath, $this->messagesIncluded)) {
            return false;
        }

        return true;
    }

    private function getVendorKey($key)
    {
        $keyParts = explode('.', $key, 4);
        unset($keyParts[0]);

        return $keyParts[2] .'.'. $keyParts[1] . '::' . $keyParts[3];
    }



    //GetCodes
    protected function getExtensions()
    {
        $sorter = new DependencySorter();

        foreach (app('extensions')->getBag()->allInstalled() as $extension) {
            $sorter->add($extension->getSlug(), $extension->getDependencies());
        }

        $extensions = array();

        foreach ($sorter->sort() as $slug) {
            if(strpos($slug, 'bct/') !== false) {
                $extensions[$slug] = $slug;
            }

        }

        return $extensions;
    }

    protected function getDependentsFromConfig()
    {
        return collect(config('bct.extensions.js.trans.dependents'));
    }

    public function generateFromExtension($target, $options) {
        if ($options['modules']) {
            $this->modulesPath = $options['modules'];
        }

        if ($options['source']) {
            $this->sourcePath = $options['source'];
        }

        $extensions = $this->getExtensions();
        $extensionDependents = $this->getDependentsFromConfig();

        $messages = [];
        $dependents = [];
        if ($extensions) {
            foreach($extensions as $extension) {

                $messages[$extension] = $this->getMessagesFromExtension($this->modulesPath.DIRECTORY_SEPARATOR.$extension.DIRECTORY_SEPARATOR.$this->sourcePath);

                if($extensionDependents) {
                    $dependents[$extension] = $this->getCurrentDependents($extensions, data_get($extensionDependents, $extension));
                }
            }
        }

        $this->prepareTarget($target);

        if ($options['no-lib']) {
            $template = $this->file->get(__DIR__.'/Templates/messages.js');
        } else if ($options['json']) {
            $template = $this->file->get(__DIR__.'/Templates/messages.json');
        } else {
            $template = $this->file->get(__DIR__.'/Templates/langjs_with_messages.js');
            $langjs = $this->file->get(__DIR__.'/../../../../lib/lang.min.js');
            $template = str_replace('\'{ langjs }\';', $langjs, $template);
        }

        if ($options['dependents'] === true && $dependents && $options['json']) {
            $template = str_replace('\'{ messages }\'', json_encode($dependents), $template);
        } else {
            $template = str_replace('\'{ messages }\'', json_encode($messages), $template);
            if($dependents) {
                $template = str_replace('\'{ dependents }\'', json_encode($dependents), $template);
            }
        }


        if ($options['compress']) {
            $template = Minifier::minify($template);
        }

        return $this->file->put($target, $template);
    }

    protected function getMessagesFromExtension($path)
    {
        $messages = [];

        if (!$this->file->exists($path)) {
            throw new \Exception("{$path} doesn't exists!");
        }

        foreach ($this->file->allFiles($path) as $file) {
            $pathName = $file->getRelativePathName();

            if ($this->file->extension($pathName) !== 'php') {
                continue;
            }

            if ($this->isMessagesExcluded($pathName)) {
                continue;
            }

            $key = substr($pathName, 0, -4);
            $key = str_replace('\\', '.', $key);
            $key = str_replace('/', '.', $key);

            if (starts_with($key, 'vendor')) {
                $key = $this->getVendorKey($key);
            }

            $messages[$key] = include $path . DIRECTORY_SEPARATOR . $pathName;
        }

        $this->sortMessages($messages);

        return $messages;
    }

    public function getCurrentDependents($extensions, $dependents) {
        $currentDependents = null;
        if($dependents) {
            foreach($extensions as $extension) {
                if(array_search($extension, $dependents) !== false) {
                    $currentDependents = $extension;
                }
            }
        }
        return $currentDependents;
    }
}
