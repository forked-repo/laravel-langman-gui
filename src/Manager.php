<?php

namespace Themsaid\LangmanGUI;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Manager
{
    /**
     * The Filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    private $disk;

    /**
     * Path to the language files.
     *
     * @var string
     */
    private $languageFilesPath;

    /**
     * Paths we will look inside to find translations.
     *
     * @var array
     */
    private $lookupPaths;

    /**
     * Available translations.
     *
     * @var array
     */
    private $translations = [];

    /**
     * Manager constructor.
     *
     * @param \Illuminate\Filesystem\Filesystem $disk
     * @param string $languageFilesPath
     * @param array $lookupPaths
     */
    public function __construct(Filesystem $disk, string $languageFilesPath, array $lookupPaths)
    {
        $this->disk = $disk;
        $this->languageFilesPath = $languageFilesPath;
        $this->lookupPaths = $lookupPaths;
    }

    /**
     * Get all the available lines.
     *
     * @param bool $reload
     * @return array
     */
    public function getTranslations($reload = false)
    {
        if ($this->translations && $reload) {
            return $this->translations;
        }

        collect($this->disk->allFiles($this->languageFilesPath))
            ->filter(function ($file) {
                return $this->disk->extension($file) == 'json';
            })
            ->each(function ($file) {
                $this->translations[str_replace('.json', '', $file->getFilename())]
                    = json_decode($file->getContents());
            });

        return $this->translations;
    }

    /**
     * Synchronize the language keys from files.
     *
     * @return void
     */
    public function sync()
    {
        $this->backup();

        $existingKeys = $this->getTranslations();

        $keysFromFiles = array_collapse($this->getTranslationsFromFiles());

        foreach (array_unique($keysFromFiles) as $fileName => $key) {
            foreach ($existingKeys as $lang => $keys) {
                if (! array_key_exists($key, $keys)) {
                    $this->setLanguageKey($lang, $key);
                }
            }
        }
    }

    /**
     * @param $translations
     */
    public function saveTranslations($translations)
    {
        $this->backup();

        foreach ($translations as $lang => $lines) {
            $filename = $this->languageFilesPath.DIRECTORY_SEPARATOR."$lang.json";

            ksort($lines);

            file_put_contents($filename, json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }

    /**
     * Get found translation lines found per file.
     *
     * @return array
     */
    private function getTranslationsFromFiles()
    {
        /*
         * This pattern is derived from Barryvdh\TranslationManager by Barry vd. Heuvel <barryvdh@gmail.com>
         *
         * https://github.com/barryvdh/laravel-translation-manager/blob/master/src/Manager.php
         */
        $functions = ['__'];

        $pattern =
            // See https://regex101.com/r/jS5fX0/3
            '[^\w]'. // Must not start with any alphanum or _
            '(?<!->)'. // Must not start with ->
            '('.implode('|', $functions).')'.// Must start with one of the functions
            "\(".// Match opening parentheses
            "[\'\"]".// Match " or '
            '('.// Start a new group to match:
            '.+'.// Must start with group
            ')'.// Close group
            "[\'\"]".// Closing quote
            "[\),]"  // Close parentheses or new parameter
        ;

        $allMatches = [];

        foreach ($this->disk->allFiles($this->lookupPaths) as $file) {
            if (preg_match_all("/$pattern/siU", $file->getContents(), $matches)) {
                $allMatches[$file->getRelativePathname()] = $matches[2];
            }
        }

        return $allMatches;
    }

    /**
     * Backup the existing translation files
     */
    private function backup()
    {
        if (! $this->disk->exists(storage_path('langmanGUI'))) {
            $this->disk->makeDirectory(storage_path('langmanGUI'));

            $this->disk->put(storage_path('langmanGUI'.'/.gitignore'), "*\n!.gitignore");
        }

        $this->disk->copyDirectory(resource_path('lang'), storage_path('langmanGUI/'.time()));
    }
}
