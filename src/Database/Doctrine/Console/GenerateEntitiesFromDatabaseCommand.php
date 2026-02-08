<?php

namespace CodeCTRL\Apollo\Database\Doctrine\Console;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Console\Command\AbstractEntityManagerCommand;
use Doctrine\ORM\Tools\Console\EntityManagerProvider;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateEntitiesFromDatabaseCommand extends AbstractEntityManagerCommand
{
    protected static $defaultName = 'apollo:generate:entities-from-db';

    private EntityManagerInterface $entityManager;
    private EntityManagerProvider $entityManagerProvider;
    private array $generatedEntities = [];
    private array $validFetchStrategies = ['LAZY', 'EAGER', 'EXTRA_LAZY'];
    private string $namespace = 'App\\Entity';
    private string $entityPath = 'src/Entity';

    /**
     * @param EntityManagerProvider $entityManagerProvider
     * @param string $namespace
     * @param string $entityPath
     */
    public function __construct(
        EntityManagerProvider $entityManagerProvider,
        string $namespace = 'App\\Entity',
        string $entityPath = 'src/Entity'
    ) {
        parent::__construct($entityManagerProvider);
        $this->entityManagerProvider = $entityManagerProvider;
        $this->entityManager = $this->entityManagerProvider->getDefaultManager();
        $this->namespace = $namespace;
        $this->entityPath = $entityPath;
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Generate entity classes from existing database tables')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'The namespace to use for generated entities', null)
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Destination path for generated entities', null)
            ->addOption('filter', null, InputOption::VALUE_OPTIONAL, 'Filter to generate specific table only')
            ->addOption('fetch-strategy', null, InputOption::VALUE_OPTIONAL, 'Default fetch strategy ('.implode(",",$this->validFetchStrategies).')', 'LAZY')
            ->setHelp('This command generates entity classes from your existing database schema');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->entityManager;
        $namespace = $input->getOption('namespace') ?? $this->namespace;
        $destPath = $input->getOption('path') ?? $this->entityPath;
        $filter = $input->getOption('filter');
        $fetchStrategy = $input->getOption('fetch-strategy');

        $fetchStrategy = in_array(strtoupper($fetchStrategy), $this->validFetchStrategies) ? strtoupper($fetchStrategy): 'LAZY';

        if (!file_exists($destPath)) {
            mkdir($destPath, 0777, true);
        }

        $connection = $em->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $tables = $schemaManager->listTables();

        if ($filter) {
            $tables = array_filter($tables, function($table) use ($filter) {
                return stripos($table->getName(), $filter) !== false;
            });
            if (empty($tables)) {
                $output->writeln(sprintf('<error>No table found with name: %s</error>', $filter));
                return 1;
            }
        }

        $output->writeln(sprintf('Generating entities for %d tables...', count($tables)));

        $this->generatedEntities = [];
        foreach ($tables as $table) {
            $className = $this->convertToClassName($table->getName());
            $this->generatedEntities[$table->getName()] = $className;
        }

        /** @var Table $table */
        foreach ($tables as $table) {
            $className = $this->generatedEntities[$table->getName()];
            $fullClassName = sprintf('%s\\%s', $namespace, $className);

            $fileContent = $this->generateEntityClass($table, $namespace, $className, $output, $fetchStrategy);
            $filePath = sprintf('%s/%s.php', $destPath, $className);

            file_put_contents($filePath, $fileContent);
            $output->writeln(sprintf('Generated entity: %s', $className));
        }

        $output->writeln('<info>Entity generation completed successfully!</info>');
        return 0;
    }

    /**
     * @param Table $table
     * @param string $namespace
     * @param string $className
     * @return string
     */
    private function generateEntityClass(Table $table, string $namespace, string $className, $output, string $fetchStrategy = 'LAZY'): string
    {
        $properties = [];
        $gettersSetters = [];
        $relationshipProperties = [];
        $relationshipGettersSetters = [];

        foreach ($table->getColumns() as $column) {
            $originalType = $this->getColumnType($column);
            $columnType = $this->convertDbTypeToDoctrineType($originalType);
            $phpType = $this->convertDbTypeToPHPType($originalType);

            $propertyName = $column->getName();
            $isNullable = !$column->getNotnull();
            $typeHint = $isNullable ? '?' . $phpType : $phpType;

            if ($column->getAutoincrement() || $column->getName() == 'id') {
                $propertyAnnotations[] = "#[ORM\\Id]";
            }

            if ($column->getAutoincrement()) {
                $propertyAnnotations[] = "#[ORM\\GeneratedValue(strategy: \"AUTO\")]";
            }

            $propertyAnnotations = [
                "#[ORM\\Column(name: \"{$propertyName}\", type: \"{$columnType}\"" .
                ($column->getLength() ? ", length: {$column->getLength()}" : "") .
                ($column->getPrecision() ? ", precision: {$column->getPrecision()}" : "") .
                ($column->getScale() ? ", scale: {$column->getScale()}" : "") .
                ($isNullable ? ", nullable: true" : "") .
                ($column->getDefault() !== null && !$column->getUnsigned() ? ", options: ['default' => '{$column->getDefault()}']" : "") .
                (!$column->getDefault() && $column->getUnsigned() ? ", options: ['unsigned' => true]" : "") .
                ($column->getDefault() !== null && $column->getUnsigned() ? ", options: ['default' => '{$column->getDefault()}', 'unsigned' => true]" : "") .
                ")]"
            ];

            $propertyNameUpper = $this->covertToPascalCase($propertyName);

            $properties[] = sprintf(
                "    %s\n    private %s $%s;\n",
                implode("\n    ", $propertyAnnotations),
                $typeHint,
                $propertyNameUpper
            );

            $gettersSetters[] = sprintf(
                "    public function get%s(): %s\n    {\n        return \$this->%s;\n    }\n",
                ucfirst($propertyNameUpper),
                $typeHint,
                $propertyNameUpper
            );

            $gettersSetters[] = sprintf(
                "    public function set%s(%s $%s): self\n    {\n        \$this->%s = \$%s;\n        return \$this;\n    }\n",
                ucfirst($propertyNameUpper),
                $typeHint,
                $propertyNameUpper,
                $propertyNameUpper,
                $propertyNameUpper
            );
        }

        $foreignKeys = $table->getForeignKeys();
        foreach ($foreignKeys as $foreignKey) {
            $relatedTable = $foreignKey->getForeignTableName();
            $localColumns = $foreignKey->getLocalColumns();
            $foreignColumns = $foreignKey->getForeignColumns();

            if (!isset($this->generatedEntities[$relatedTable])) {
                $output->writeln(sprintf('<comment>Skipping relationship for unknown table: %s</comment>', $relatedTable));
                continue;
            }

            $relatedClassName = $this->generatedEntities[$relatedTable];
            $localColumn = $localColumns[0];
            $propertyName = $this->covertToPascalCase(str_replace('_id', '', $localColumn));

            $indexes = $table->getIndexes();
            $isOneToOne = false;
            foreach ($indexes as $index) {
                if ($index->isUnique() && in_array($localColumn, $index->getColumns())) {
                    $isOneToOne = true;
                    break;
                }
            }
            $primaryKey = $table->getPrimaryKey();
            if ($primaryKey !== null && in_array($localColumn, $primaryKey->getColumns())) {
                $isOneToOne = true;
            }

            $relationshipType = $isOneToOne ? 'OneToOne' : 'ManyToOne';

            $relationshipProperties[] = sprintf(
                "    #[ORM\\%s(targetEntity: %s::class, fetch: \"%s\")]\n" .
                "    #[ORM\\JoinColumn(name: \"%s\", referencedColumnName: \"%s\", nullable: %s)]\n" .
                "    private ?%s $%s = null;\n",
                $relationshipType,
                '\\' . $namespace . '\\' . $relatedClassName,
                strtoupper($fetchStrategy),
                $localColumn,
                $foreignColumns[0],
                $isOneToOne ? 'false' : 'true',
                $relatedClassName,
                $propertyName
            );

            $relationshipGettersSetters[] = sprintf(
                "    public function get%s(): ?%s\n    {\n        return \$this->%s;\n    }\n",
                ucfirst($propertyName),
                $relatedClassName,
                $propertyName
            );

            $relationshipGettersSetters[] = sprintf(
                "    public function set%s(?%s $%s): self\n    {\n        \$this->%s = \$%s;\n        return \$this;\n    }\n",
                ucfirst($propertyName),
                $relatedClassName,
                $propertyName,
                $propertyName,
                $propertyName
            );
        }

        $allTables = $this->entityManager->getConnection()->createSchemaManager()->listTables();
        foreach ($allTables as $otherTable) {
            foreach ($otherTable->getForeignKeys() as $fk) {
                if ($fk->getForeignTableName() === $table->getName()) {
                    $relatedTable = $otherTable->getName();
                    if (!isset($this->generatedEntities[$relatedTable])) {
                        continue;
                    }

                    $relatedClassName = $this->generatedEntities[$relatedTable];
                    $propertyName = $this->covertToPascalCase($relatedTable);
                    $mappedBy = $this->covertToPascalCase(str_replace('_id', '', $fk->getLocalColumns()[0]));

                    $indexes = $otherTable->getIndexes();
                    $isOneToOne = false;
                    foreach ($indexes as $index) {
                        if ($index->isUnique() && in_array($fk->getLocalColumns()[0], $index->getColumns())) {
                            $isOneToOne = true;
                            break;
                        }
                    }
                    $otherPrimaryKey = $otherTable->getPrimaryKey();
                    if ($otherPrimaryKey !== null && in_array($fk->getLocalColumns()[0], $otherPrimaryKey->getColumns())) {
                        $isOneToOne = true;
                    }

                    if ($isOneToOne) {
                        $relationshipProperties[] = sprintf(
                            "    #[ORM\\OneToOne(targetEntity: %s::class, mappedBy: \"%s\", fetch: \"%s\")]\n" .
                            "    private ?%s $%s = null;\n",
                            '\\' . $namespace . '\\' . $relatedClassName,
                            $mappedBy,
                            strtoupper($fetchStrategy),
                            $relatedClassName,
                            $propertyName
                        );

                        $relationshipGettersSetters[] = sprintf(
                            "    public function get%s(): ?%s\n    {\n        return \$this->%s;\n    }\n",
                            ucfirst($propertyName),
                            $relatedClassName,
                            $propertyName
                        );

                        $relationshipGettersSetters[] = sprintf(
                            "    public function set%s(?%s $%s): self\n    {\n        \$this->%s = \$%s;\n" .
                            "        if (\$%s !== null) {\n" .
                            "            \$%s->set%s(\$this);\n" .
                            "        }\n" .
                            "        return \$this;\n    }\n",
                            ucfirst($propertyName),
                            $relatedClassName,
                            $propertyName,
                            $propertyName,
                            $propertyName,
                            $propertyName,
                            $propertyName,
                            ucfirst($mappedBy)
                        );
                    } else {
                        $relationshipProperties[] = sprintf(
                            "    #[ORM\\OneToMany(targetEntity: %s::class, mappedBy: \"%s\", fetch: \"%s\")]\n" .
                            "    private \Doctrine\Common\Collections\Collection $%s;\n",
                            '\\' . $namespace . '\\' . $relatedClassName,
                            $mappedBy,
                            strtoupper($fetchStrategy),
                            $propertyName
                        );

                        $relationshipGettersSetters[] = sprintf(
                            "    public function get%s(): \Doctrine\Common\Collections\Collection\n    {\n        return \$this->%s;\n    }\n",
                            ucfirst($propertyName),
                            $propertyName
                        );

                        $relationshipGettersSetters[] = sprintf(
                            "    public function add%s(%s $%s): self\n    {\n" .
                            "        if (!\$this->%s->contains(\$%s)) {\n" .
                            "            \$this->%s->add(\$%s);\n" .
                            "            \$%s->set%s(\$this);\n" .
                            "        }\n        return \$this;\n    }\n",
                            ucfirst($propertyName),
                            $relatedClassName,
                            $propertyName,
                            $propertyName,
                            $propertyName,
                            $propertyName,
                            $propertyName,
                            $propertyName,
                            ucfirst($mappedBy)
                        );

                        $relationshipGettersSetters[] = sprintf(
                            "    public function remove%s(%s $%s): self\n    {\n" .
                            "        if (\$this->%s->removeElement(\$%s)) {\n" .
                            "            \$%s->set%s(null);\n" .
                            "        }\n        return \$this;\n    }\n",
                            ucfirst($propertyName),
                            $relatedClassName,
                            $propertyName,
                            $propertyName,
                            $propertyName,
                            $propertyName,
                            ucfirst($mappedBy)
                        );
                    }
                }
            }
        }

        $constructor = '';
        $collectionProperties = array_filter($relationshipProperties, function($prop) {
            return strpos($prop, 'Collection') !== false;
        });

        if (!empty($collectionProperties)) {
            $constructor = "    public function __construct()\n    {\n";
            foreach ($collectionProperties as $prop) {
                preg_match('/private .+ \$(\w+)/', $prop, $matches);
                $constructor .= "        \$this->{$matches[1]} = new \Doctrine\Common\Collections\ArrayCollection();\n";
            }
            $constructor .= "    }\n";
        }

        $allProperties = array_merge($properties, $relationshipProperties);
        $allGettersSetters = array_merge($gettersSetters, $relationshipGettersSetters);

        return sprintf(
            "<?php\n\nnamespace %s;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n#[ORM\\Entity]\n#[ORM\\Table(name: \"%s\")]\nclass %s\n{\n%s\n%s}\n",
            $namespace,
            $table->getName(),
            $className,
            implode("\n", $allProperties),
            implode("\n", $allGettersSetters)
        );
    }

    /**
     * @param string $tableName
     * @return string
     */
    private function convertToClassName(string $tableName): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $tableName)));
    }

    /**
     * @param $string
     * @return string
     */
    private function covertToPascalCase($string): string
    {
        $string = str_replace('_', ' ', $string);
        $string = ucwords($string);
        return lcfirst(str_replace(' ', '', $string));
    }

    /**
     * @param string $dbType
     * @return string
     */
    private function convertDbTypeToDoctrineType(string $dbType): string
    {
        $typeMap = [
            'integer' => 'integer',
            'int' => 'integer',
            'varchar' => 'string',
            'text' => 'text',
            'datetime' => 'datetime',
            'date' => 'date',
            'time' => 'time',
            'decimal' => 'float',
            'float' => 'float',
            'boolean' => 'boolean',
        ];

        return $typeMap[strtolower($dbType)] ?? 'string';
    }

    /**
     * @param string $dbType
     * @return string
     */
    private function convertDbTypeToPHPType(string $dbType): string
    {
        $typeMap = [
            'integer' => 'int',
            'int' => 'int',
            'varchar' => 'string',
            'text' => 'string',
            'datetime' => '\DateTime',
            'date' => '\DateTime',
            'time' => 'time',
            'decimal' => 'float',
            'float' => 'float',
            'boolean' => 'bool',
        ];

        return $typeMap[strtolower($dbType)] ?? 'string';
    }

    /**
     * @param $column
     * @return string
     */
    private function getColumnType($column): string
    {
        $type = $column->getType();

        switch (true) {
            case $type instanceof \Doctrine\DBAL\Types\DateTimeType:
                return 'datetime';
            case $type instanceof \Doctrine\DBAL\Types\DateType:
                return 'date';
            case $type instanceof \Doctrine\DBAL\Types\TimeType:
                return 'time';
            case $type instanceof \Doctrine\DBAL\Types\IntegerType:
                return 'integer';
            case $type instanceof \Doctrine\DBAL\Types\StringType:
                return 'string';
            case $type instanceof \Doctrine\DBAL\Types\TextType:
                return 'text';
            case $type instanceof \Doctrine\DBAL\Types\DecimalType:
                return 'decimal';
            case $type instanceof \Doctrine\DBAL\Types\FloatType:
                return 'float';
            case $type instanceof \Doctrine\DBAL\Types\BooleanType:
                return 'boolean';
            default:
                return strtolower(substr(get_class($type), strrpos(get_class($type), '\\') + 1, -4));
        }
    }
}