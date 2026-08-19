<?php

namespace Emaia\MediaMan\ResponsiveImages;

use Emaia\MediaMan\Models\Media;
use Illuminate\Database\Eloquent\Builder;

final class ResponsiveMetadataQuery
{
    public static function whereHasGenerationDiskMetadata(Builder $query): Builder
    {
        $qualifiedColumn = $query->getModel()->qualifyColumn('custom_properties');
        $column = $query->getQuery()->getGrammar()->wrap($qualifiedColumn);

        return match ($query->getModel()->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => $query->whereRaw(
                "(JSON_TYPE(JSON_EXTRACT($column, '$.responsive_generation_disk')) = 'STRING' "
                ."OR JSON_TYPE(JSON_EXTRACT($column, '$.responsive_generation_disks')) = 'ARRAY')"
            ),
            'pgsql' => $query->whereRaw(
                "(json_typeof(($column)::json -> 'responsive_generation_disk') = 'string' "
                ."OR json_typeof(($column)::json -> 'responsive_generation_disks') = 'array')"
            ),
            'sqlite' => $query->whereRaw(
                "(json_type($column, '$.responsive_generation_disk') = 'text' "
                ."OR json_type($column, '$.responsive_generation_disks') = 'array')"
            ),
            'sqlsrv' => $query->whereRaw(
                "EXISTS (SELECT 1 FROM OPENJSON($column) WHERE "
                ."([key] = 'responsive_generation_disk' AND [type] = 1) OR "
                ."([key] = 'responsive_generation_disks' AND [type] = 4))"
            ),
            default => $query->where(function (Builder $query) use ($qualifiedColumn): void {
                $query
                    ->whereNotNull($qualifiedColumn.'->'.Media::PROPERTY_RESPONSIVE_GENERATION_DISK)
                    ->orWhereNotNull($qualifiedColumn.'->'.Media::PROPERTY_RESPONSIVE_GENERATION_DISKS);
            }),
        };
    }

    public static function whereHasManifest(Builder $query): Builder
    {
        $qualifiedColumn = $query->getModel()->qualifyColumn('custom_properties');
        $column = $query->getQuery()->getGrammar()->wrap($qualifiedColumn);

        return match ($query->getModel()->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => $query->whereRaw(
                "JSON_TYPE(JSON_EXTRACT($column, '$.responsive_images')) = 'ARRAY' "
                ."AND JSON_LENGTH(JSON_EXTRACT($column, '$.responsive_images')) > 0"
            ),
            'pgsql' => $query->whereRaw(
                "CASE WHEN json_typeof(($column)::json -> 'responsive_images') = 'array' "
                ."THEN json_array_length(($column)::json -> 'responsive_images') ELSE 0 END > 0"
            ),
            'sqlite' => $query->whereRaw(
                "json_type($column, '$.responsive_images') = 'array' "
                ."AND json_array_length($column, '$.responsive_images') > 0"
            ),
            'sqlsrv' => $query->whereRaw(
                "EXISTS (SELECT 1 FROM OPENJSON(CASE WHEN LEFT(LTRIM(JSON_QUERY($column, '$.responsive_images')), 1) = '[' "
                ."THEN JSON_QUERY($column, '$.responsive_images') ELSE '[]' END))"
            ),
            default => $query->whereJsonLength(
                $qualifiedColumn.'->'.Media::PROPERTY_RESPONSIVE_IMAGES,
                '>',
                0,
            ),
        };
    }

    public static function whereHasManagedGeneration(Builder $query): Builder
    {
        $qualifiedColumn = $query->getModel()->qualifyColumn('custom_properties');
        $column = $query->getQuery()->getGrammar()->wrap($qualifiedColumn);
        $nil = '00000000000000000000000000';
        $max = '7ZZZZZZZZZZZZZZZZZZZZZZZZZ';

        return match ($query->getModel()->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => $query->whereRaw(
                "JSON_TYPE(JSON_EXTRACT($column, '$.responsive_generation')) = 'STRING' "
                ."AND JSON_UNQUOTE(JSON_EXTRACT($column, '$.responsive_generation')) COLLATE utf8mb4_bin "
                ."REGEXP '^[0-7][0-9A-HJKMNP-TV-Z]{25}$' "
                ."AND JSON_UNQUOTE(JSON_EXTRACT($column, '$.responsive_generation')) NOT IN (?, ?)",
                [$nil, $max],
            ),
            'pgsql' => $query->whereRaw(
                "json_typeof(($column)::json -> 'responsive_generation') = 'string' "
                ."AND (($column)::json ->> 'responsive_generation') ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$' "
                ."AND (($column)::json ->> 'responsive_generation') NOT IN (?, ?)",
                [$nil, $max],
            ),
            'sqlite' => $query->whereRaw(
                "json_type($column, '$.responsive_generation') = 'text' "
                ."AND length(json_extract($column, '$.responsive_generation')) = 26 "
                ."AND substr(json_extract($column, '$.responsive_generation'), 1, 1) GLOB '[0-7]' "
                ."AND json_extract($column, '$.responsive_generation') NOT GLOB '*[^0-9A-HJKMNP-TV-Z]*' "
                ."AND json_extract($column, '$.responsive_generation') NOT IN (?, ?)",
                [$nil, $max],
            ),
            'sqlsrv' => $query->whereRaw(
                "EXISTS (SELECT 1 FROM OPENJSON($column) "
                ."WHERE [key] = 'responsive_generation' AND [type] = 1 "
                ."AND LEN([value]) = 26 AND LEFT([value], 1) LIKE '[0-7]' "
                ."AND [value] COLLATE Latin1_General_100_BIN2 NOT LIKE '%[^0-9A-HJKMNP-TV-Z]%' "
                .'AND [value] NOT IN (?, ?))',
                [$nil, $max],
            ),
            default => $query
                ->whereNotNull($qualifiedColumn.'->'.Media::PROPERTY_RESPONSIVE_GENERATION)
                ->where($qualifiedColumn.'->'.Media::PROPERTY_RESPONSIVE_GENERATION, '!=', ''),
        };
    }
}
