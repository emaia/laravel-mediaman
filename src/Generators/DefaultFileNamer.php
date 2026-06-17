<?php

namespace Emaia\MediaMan\Generators;

class DefaultFileNamer implements FileNamer
{
    public function getBaseName(string $originalName): string
    {
        return $this->sanitizeName($originalName);
    }

    public function getConversionFileName(string $originalName, string $conversion, string $extension): string
    {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);

        return $baseName.'.'.$extension;
    }

    public function getResponsiveFileName(string $originalName, int $width, string $format): string
    {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);

        return "{$baseName}_{$width}w.{$format}";
    }

    protected function sanitizeName(string $fileName): string
    {
        $fileName = preg_replace('/[\x00\p{C}]/u', '', $fileName);

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $name = pathinfo($fileName, PATHINFO_FILENAME);

        $name = str_replace(
            ['..', '#', '/', '\\', ' ', '?', '%', '*', ':', '|', '"', "'", '<', '>'],
            '-',
            $name
        );

        $name = str_replace('.', '-', $name);

        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        if ($name === '') {
            $name = 'unnamed';
        }

        return $extension !== '' ? "{$name}.{$extension}" : $name;
    }
}
