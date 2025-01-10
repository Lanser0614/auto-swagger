<?php

namespace AutoSwagger\Laravel\CrudGenerator;

use Doctrine\DBAL\Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;

class ModelGenerator
{
    private $functions = null;

    private $table;
    private $properties;
    private $modelNamespace;

    /**
     * ModelGenerator constructor.
     */
    public function __construct(string $table, string $properties, string $modelNamespace)
    {
        $this->table = $table;
        $this->properties = $properties;
        $this->modelNamespace = $modelNamespace;
        $this->_init();
    }

    /**
     * Get all the eloquent relations.
     *
     * @return array
     */
    public function getEloquentRelations()
    {
        return [$this->functions, $this->properties];
    }

    private function _init()
    {
        foreach ($this->_getTableRelations() as $relation) {
            $this->functions .= $this->_getFunction($relation);
        }
    }

    private function _getFunction(array $relation)
    {
        switch ($relation['name']) {
            case 'hasOne':
            case 'belongsTo':
                $this->properties .= "\n * @property {$relation['class']} \${$relation['relation_name']}";
                break;
            case 'hasMany':
                $this->properties .= "\n * @property " . $relation['class'] . "[] \${$relation['relation_name']}";
                break;
        }

        return '
    /**
     * @return \Illuminate\Database\Eloquent\Relations\\' . ucfirst($relation['name']) . '
     */
    public function ' . $relation['relation_name'] . '()
    {
        return $this->' . $relation['name'] . '(\\' . $this->modelNamespace . '\\' . $relation['class'] . '::class, \'' . $relation['foreign_key'] . '\', \'' . $relation['owner_key'] . '\');
    }
    ';
    }

    /**
     * Get all relations from Table.
     *
     * @return array
     */
    private function _getTableRelations()
    {
        return [
            ...$this->getBelongsTo(),
//            ...$this->getOtherRelations(),
        ];
    }

    /**
     * @return array
     * @throws Exception
     */
    protected function getBelongsTo(): array
    {
        $relations = DB::getDoctrineSchemaManager()->listTableForeignKeys($this->table);

        $eloquent = [];

        /** @var ForeignKeyConstraint $relation */
        foreach ($relations as $relation) {

            $eloquent[] = [
                'name' => 'belongsTo',
                'relation_name' => Str::camel(Str::singular($relation->getForeignTableName())),
                'class' => Str::studly(Str::singular($relation->getForeignTableName())),
                'foreign_key' => $relation->getForeignColumns()[0],
                'owner_key' => $relation->getLocalColumns()[0],
            ];
        }

        return $eloquent;
    }

    /**
     * @throws Exception
     */
    protected function getOtherRelations()
    {
        $tables = DB::getDoctrineSchemaManager()->listTableColumns($this->table);
        $eloquent = [];

        foreach ($tables as $table) {
            $relations = Schema::getForeignKeys($table);
            $indexes = collect(Schema::getIndexes($table));

            foreach ($relations as $relation) {
                if ($relation['foreign_table'] != $this->table) {
                    continue;
                }

                if (count($relation['foreign_columns']) != 1 || count($relation['columns']) != 1) {
                    continue;
                }

                $isUniqueColumn = $this->getUniqueIndex($indexes, $relation['columns'][0]);

                $eloquent[] = [
                    'name' => $isUniqueColumn ? 'hasOne' : 'hasMany',
                    'relation_name' => Str::camel($isUniqueColumn ? Str::singular($table) : Str::plural($table)),
                    'class' => Str::studly(Str::singular($table)),
                    'foreign_key' => $relation['foreign_columns'][0],
                    'owner_key' => $relation['columns'][0],
                ];
            }
        }

        return $eloquent;
    }

    private function getUniqueIndex($indexes, $column)
    {
        $isUnique = false;

        foreach ($indexes as $index) {
            if (
                (count($index['columns']) == 1)
                && ($index['columns'][0] == $column)
                && $index['unique']
            ) {
                $isUnique = true;
                break;
            }
        }

        return $isUnique;
    }
}
