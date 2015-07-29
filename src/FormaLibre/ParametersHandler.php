<?php

namespace FormaLibre;

class ParametersHandler
{

    public static function getPackageFile()
    {
        return  __DIR__ . '/../../config/packages.ini';
    }

    public static function getHandledPackages()
    {
        return array_keys(parse_ini_file(self::getPackageFile()));
    }

    public static function getRepositorySecret($repository)
    {
        $ini = parse_ini_file(self::getPackageFile());

        return $ini[$repository];
    }
}
