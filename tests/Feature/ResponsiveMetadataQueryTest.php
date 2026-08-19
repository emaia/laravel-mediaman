<?php

use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveMetadataQuery;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\SqlServerConnection;

function responsiveMetadataBuilder(string $driver): Builder
{
    $connectionClass = match ($driver) {
        'mysql' => MySqlConnection::class,
        'mariadb' => MariaDbConnection::class,
        'pgsql' => PostgresConnection::class,
        'sqlite' => SQLiteConnection::class,
        'sqlsrv' => SqlServerConnection::class,
        default => Connection::class,
    };
    $connection = new $connectionClass(null, '', '', ['driver' => $driver]);

    if ($driver === 'other') {
        $connection->setQueryGrammar(new SQLiteGrammar($connection));
    }

    $model = new class($connection) extends Media
    {
        public function __construct(private ?Connection $testConnection = null)
        {
            parent::__construct();
        }

        public function getConnection(): Connection
        {
            return $this->testConnection ?? app('db')->connection();
        }

        public function getTable(): string
        {
            return 'mediaman_media';
        }
    };

    return $model->newQuery();
}

it('builds responsive metadata predicates for every native database grammar', function (
    string $driver,
    string $wrappedColumn,
    string $diskFragment,
    string $manifestFragment,
    string $generationFragment,
) {
    $cases = [
        'whereHasGenerationDiskMetadata' => [$diskFragment, 'responsive_generation_disk', 'responsive_generation_disks'],
        'whereHasManifest' => [$manifestFragment, 'responsive_images'],
        'whereHasManagedGeneration' => [$generationFragment, 'responsive_generation'],
    ];

    foreach ($cases as $method => $fragments) {
        $query = responsiveMetadataBuilder($driver);
        $result = ResponsiveMetadataQuery::$method($query);
        $sql = $query->toSql();

        expect($result)->toBe($query)
            ->and($sql)->toContain($wrappedColumn, ...$fragments);
    }
})->with([
    'mysql' => [
        'mysql',
        '`mediaman_media`.`custom_properties`',
        'JSON_TYPE(JSON_EXTRACT',
        'JSON_LENGTH(JSON_EXTRACT',
        'COLLATE utf8mb4_bin',
    ],
    'mariadb' => [
        'mariadb',
        '`mediaman_media`.`custom_properties`',
        'JSON_TYPE(JSON_EXTRACT',
        'JSON_LENGTH(JSON_EXTRACT',
        'COLLATE utf8mb4_bin',
    ],
    'pgsql' => [
        'pgsql',
        '"mediaman_media"."custom_properties"',
        'json_typeof((',
        'json_array_length((',
        ") ~ '^[0-7]",
    ],
    'sqlite' => [
        'sqlite',
        '"mediaman_media"."custom_properties"',
        'json_type(',
        'json_array_length(',
        "GLOB '[0-7]'",
    ],
    'sqlsrv' => [
        'sqlsrv',
        '[mediaman_media].[custom_properties]',
        'FROM OPENJSON(',
        'JSON_QUERY(',
        'Latin1_General_100_BIN2',
    ],
]);

it('builds portable fallback predicates for unknown database drivers', function () {
    $disk = responsiveMetadataBuilder('other');
    ResponsiveMetadataQuery::whereHasGenerationDiskMetadata($disk);

    $manifest = responsiveMetadataBuilder('other');
    ResponsiveMetadataQuery::whereHasManifest($manifest);

    $managed = responsiveMetadataBuilder('other');
    ResponsiveMetadataQuery::whereHasManagedGeneration($managed);

    expect($disk->toSql())->toContain(
        'responsive_generation_disk',
        'responsive_generation_disks',
    )->and($manifest->toSql())->toContain(
        'json_array_length',
        'responsive_images',
    )->and($managed->toSql())->toContain(
        'responsive_generation',
        'is not null',
    );
});
