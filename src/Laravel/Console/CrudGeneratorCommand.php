<?php

namespace AutoSwagger\Laravel\Console;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Doctrine\DBAL\Schema\Column;
use Illuminate\Support\Facades\DB;
use Ibex\CrudGenerator\Commands\GeneratorCommand;
use AutoSwagger\Laravel\CrudGenerator\ModelGenerator;

class CrudGeneratorCommand extends GeneratorCommand
{
    protected $signature = 'crud:generate-api {name : Table name} {--api} {--route= : Custom route name}';

    public function handle()
    {
        $this->info('Running Crud Generator ...');

        $this->table = $this->getNameInput();

        // If table not exist in DB return
        if (!$this->tableExists()) {
            $this->error("`{$this->table}` table not exist");

            return false;
        }

        if ($this->option('api')) {
            $this->controllerNamespace  = $this->controllerNamespace . "\\Api";
        }

            // Build the class name from table name
        $this->name = $this->_buildClassName();

        // Generate the crud
        $this->buildOptions()
            ->buildController()
            ->buildModel();

        $this->info('Please add route below:');

        $this->info('');
        $this->info("Route::resource('" . $this->_getRoute() . "', {$this->name}Controller::class);");
        $this->info('');
        $this->info('Created Successfully.');

        return true;
    }

    protected function _getControllerPath($name): string
    {
        if ($this->option('api')) {
            return app_path(path: $this->_getNamespacePath($this->controllerNamespace) . "{$name}ApiController.php");
        } else {
            return app_path(path: $this->_getNamespacePath($this->controllerNamespace) . "{$name}Controller.php");
        }
    }

    private function _getNamespacePath($namespace): array|string
    {
        $str = Str::start(Str::finish(Str::after($namespace, 'App'), '\\'), '\\');

        return str_replace('\\', '/', $str);
    }

    protected function buildController(): static
    {
        $controllerPath = $this->_getControllerPath($this->name);

        if ($this->files->exists($controllerPath) && $this->ask('Already exist Controller. Do you want overwrite (y/n)?', 'y') == 'n') {
            return $this;
        }

        $this->info('Creating Controller ...');

        $replace = $this->buildReplacements();

        $controllerTemplate = str_replace(
            array_keys($replace), array_values($replace), $this->getStub(config('crud.stubs.controller') ?? 'Controller')
        );

        $this->write($controllerPath, $controllerTemplate);

        return $this;
    }

    protected function buildModel(): static
    {
        $modelPath = $this->_getModelPath($this->name);

        if ($this->files->exists($modelPath) && $this->ask('Already exist Model. Do you want overwrite (y/n)?', 'y') == 'n') {
            return $this;
        }

        $this->info('Creating Model ...');

        // Make the models attributes and replacement
        $replace = array_merge($this->buildReplacements(), $this->modelReplacements());

        $modelTemplate = str_replace(
            array_keys($replace), array_values($replace), $this->getStub('Model')
        );

        $this->write($modelPath, $modelTemplate);

        // Make Request Class
        $requestPath = $this->_getRequestPath($this->name);

        $this->info('Creating Request Class ...');

        $requestTemplate = str_replace(
            array_keys($replace), array_values($replace), $this->getStub('Request')
        );

        $this->write($requestPath, $requestTemplate);

        return $this;
    }

    protected function buildViews(): static
    {
        return $this;
    }

    /**
     * Make the class name from table name.
     *
     * @return string
     */
    private function _buildClassName(): string
    {
        return Str::studly(Str::singular($this->table));
    }

    protected function modelReplacements(): array
    {
        $properties = '*';
        $rulesArray = [];
        $softDeletesNamespace = $softDeletes = '';

        /** @var Column $column */
        foreach ($this->getColumns() as $column) {
            $type = $column->getType()->getName();

            $isNotNull = $column->getNotnull();

            $propertyIsNull = $isNotNull ? '' : '|null';

            $properties .= "\n * @property {$this->mapDatabaseTypeToPhpType($type)}{$propertyIsNull} \${$column->getName()}";

            $rulesArray[$column->getName()]['name'] = $this->mapDatabaseTypeToPhpType($type);

            $rulesArray[$column->getName()]['required'] = $isNotNull;

            if ($column->getName() == 'deleted_at') {
                $softDeletesNamespace = "use Illuminate\Database\Eloquent\SoftDeletes;\n";
                $softDeletes = "use SoftDeletes;\n";
            }
        }

        $rules = function () use ($rulesArray) {
            $rules = '';
            // Exclude the unwanted rulesArray
            $rulesArray = Arr::except($rulesArray, $this->unwantedColumns);
            // Make rulesArray
            foreach ($rulesArray as $col => $rule) {
                $rules .= "\n\t\t\t'{$col}' => '" . implode('|', $rule) . "',";
            }

            return $rules;
        };

        $fillable = function () {

            /** @var array $filterColumns Exclude the unwanted columns */
            $filterColumns = $this->getFilteredColumns();

            // Add quotes to the unwanted columns for fillable
            array_walk($filterColumns, function (&$value) {
                $value = "'" . $value . "'";
            });

            // CSV format
            return implode(', ', $filterColumns);
        };

        $properties .= "\n *";

        [$relations, $properties] = (new ModelGenerator($this->table, $properties, $this->modelNamespace))->getEloquentRelations();

        return [
            '{{fillable}}' => $fillable(),
            '{{rules}}' => $rules(),
            '{{relations}}' => $relations,
            '{{properties}}' => $properties,
            '{{softDeletesNamespace}}' => $softDeletesNamespace,
            '{{softDeletes}}' => $softDeletes,
        ];
    }

    protected function getFilteredColumns(): array
    {
        $columns = [];

        foreach ($this->getColumns() as $column) {
            $columns[] = $column->getName();
        }

        return $columns;
    }

    private function mapDatabaseTypeToPhpType(string $columnType): string
    {
        // Remove length, precision, or scale info from column types (e.g., VARCHAR(255) becomes VARCHAR)
        $type = strtolower(preg_replace('/\(.*/', '', $columnType));

        // Map database types to PHP types using match
        return match ($type) {
            // Integer types
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'serial', 'bigint' => 'int',

            // Floating-point types
            'float', 'double' => 'float',
            'decimal', 'numeric' => 'string', // DECIMAL and NUMERIC as string to avoid precision loss

            // String types
            'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set' => 'string',

            // Binary types
            'binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob' => 'string',

            // Date and time types
            'date', 'time', 'datetime', 'timestamp', 'year' => 'string',

            // Boolean types
            'bool', 'boolean', 'bit' => 'bool',

            // JSON type
            'json' => 'array', // JSON can be decoded into an array or object, use array as default

            // Geometry types (optional: if you work with spatial data)
            'geometry', 'point', 'linestring', 'polygon' => 'string',
            'string' => 'string',

            // Default case for unmapped types
            default => 'mixed',
        };
    }

    protected function getColumns(): array
    {
        if (empty($this->tableColumns)) {
            $this->tableColumns = DB::getDoctrineSchemaManager()->listTableColumns($this->table);
        }

        return $this->tableColumns;
    }
}
