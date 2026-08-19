<?php

namespace Emaia\MediaMan\Conversions;

use Emaia\MediaMan\Models\Media;
use Illuminate\Database\Eloquent\Builder;

final class ConversionMetadataQuery
{
    public static function whereHasManifest(Builder $query): Builder
    {
        $qualifiedColumn = $query->getModel()->qualifyColumn('custom_properties');
        $column = $query->getQuery()->getGrammar()->wrap($qualifiedColumn);

        return match ($query->getModel()->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => $query->whereRaw(
                "JSON_TYPE(JSON_EXTRACT($column, '$.conversion_files')) = 'OBJECT' "
                ."AND JSON_LENGTH(JSON_EXTRACT($column, '$.conversion_files')) > 0"
            ),
            'pgsql' => $query->whereRaw(
                "CASE WHEN json_typeof(($column)::json -> 'conversion_files') = 'object' "
                ."THEN EXISTS (SELECT 1 FROM json_object_keys(($column)::json -> 'conversion_files')) ELSE false END"
            ),
            'sqlite' => $query->whereRaw(
                "json_type($column, '$.conversion_files') = 'object' "
                ."AND EXISTS (SELECT 1 FROM json_each($column, '$.conversion_files'))"
            ),
            'sqlsrv' => $query->whereRaw(
                "EXISTS (SELECT 1 FROM OPENJSON(CASE WHEN LEFT(LTRIM(JSON_QUERY($column, '$.conversion_files')), 1) = '{' "
                ."THEN JSON_QUERY($column, '$.conversion_files') ELSE '{}' END))"
            ),
            default => $query->whereNotNull($qualifiedColumn.'->'.Media::PROPERTY_CONVERSION_FILES),
        };
    }
}
