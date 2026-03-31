<?php

class SwaggerController extends MiniEngine_Controller
{
    public function indexAction()
    {
        header('Content-Type: text/yaml');
        echo $this->generate();
        return $this->noview();
    }

    public function uiAction()
    {
        //
    }

    protected function pascal2Underscore(string $pascal): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $pascal));
    }

    protected function underscore2Pascal(string $underscore): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $underscore)));
    }

    protected function getEndPointPath(string $entity, string $class_name, string $endpoint_type, ?string $relation_name = null): string
    {
        $resource = $this->pascal2Underscore($entity) . 's';
        $id_fields = array_column($class_name::getIdFieldsInfo(), 'path_name');
        $id_fields_string = implode('/', array_map(fn($field) => '{' . $field . '}', $id_fields));
        switch ($endpoint_type) {
        case 'list':
            return "/{$resource}";
        case 'item':
            return "/{$resource}/{$id_fields_string}";
        case 'relation':
            return "/{$resource}/{$id_fields_string}/{$relation_name}";
        }
    }

    protected function getOperationId(string $entity, string $endpoint_type, ?string $relation_name = null): string
    {
        switch ($endpoint_type) {
        case 'list':
            return "list{$entity}s";
        case 'item':
            return "get{$entity}";
        case 'relation':
            return "get{$entity}" . $this->underscore2Pascal($relation_name);
        }
    }

    protected function getEndpointSummary(string $type_subject, string $endpoint_type): string
    {
        switch ($endpoint_type) {
        case 'list':
            return "取得{$type_subject}列表";
        case 'item':
            return "取得特定{$type_subject}資訊";
        }
    }

    protected function getFilterParameters(string $class_name): array
    {
        $parameters = [];
        foreach ($class_name::getFilterFieldsInfo() as $field => $info) {
            $param = [
                'name' => $field,
                'in' => 'query',
                'description' => $info['description'],
                'required' => false,
                'schema' => [
                    'type' => $info['type'],
                ],
            ];
            if (!empty($info['enum'])) {
                $param['schema']['enum'] = $info['enum'];
            }
            $parameters[] = $param;
        }
        $parameters[] = [
            'name' => 'agg',
            'in' => 'query',
            'description' => '統計欄位（可用欄位：' . implode('、', array_keys($class_name::getFilterFieldsInfo())) . '）',
            'required' => false,
            'schema' => ['type' => 'string'],
        ];
        $parameters[] = [
            'name' => 'sort',
            'in' => 'query',
            'description' => '排序欄位，欄位名後加 > 為降冪、< 為升冪',
            'required' => false,
            'schema' => ['type' => 'string'],
        ];
        $parameters[] = [
            'name' => 'q',
            'in' => 'query',
            'description' => '全文搜尋關鍵字',
            'required' => false,
            'schema' => ['type' => 'string'],
        ];
        $parameters[] = [
            'name' => 'page',
            'in' => 'query',
            'description' => '頁數',
            'required' => false,
            'schema' => ['type' => 'integer'],
            'example' => 1,
        ];
        $parameters[] = [
            'name' => 'limit',
            'in' => 'query',
            'description' => '每頁筆數',
            'required' => false,
            'schema' => ['type' => 'integer'],
            'example' => 20,
        ];
        return $parameters;
    }

    protected function getOutputFieldsParameters(string $class_name): array
    {
        return [[
            'name' => 'output_fields',
            'in' => 'query',
            'description' => '輸出欄位',
            'required' => false,
            'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
            'example' => array_keys($class_name::getIdFieldsInfo()),
        ]];
    }

    protected function getIdParameters(string $class_name): array
    {
        $parameters = [];
        foreach ($class_name::getIdFieldsInfo() as $name => $info) {
            $parameters[] = [
                'name' => $info['path_name'],
                'description' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => $info['type']],
                'example' => $info['example'],
            ];
        }
        return $parameters;
    }

    protected function getParameters(string $class_name, string $endpoint_type, ?string $relation_type = null): array
    {
        switch ($endpoint_type) {
        case 'list':
            return array_merge(
                $this->getFilterParameters($class_name),
                $this->getOutputFieldsParameters($class_name),
            );
        case 'item':
            return $this->getIdParameters($class_name);
        case 'relation':
            $relation_entity = $this->underscore2Pascal($relation_type);
            $relation_class_name = $this->getClassNameByEntity($relation_entity);
            if (class_exists($relation_class_name)) {
                return array_merge(
                    $this->getIdParameters($class_name),
                    $this->getFilterParameters($relation_class_name),
                    $this->getOutputFieldsParameters($class_name),
                );
            } else {
                return $this->getIdParameters($class_name);
            }
        }
    }

    protected function getResponses(string $subject, ?string $schema_ref = null): stdClass
    {
        $response_200 = [
            'description' => sprintf('%s資料', $subject),
        ];
        if ($schema_ref) {
            $response_200['content'] = [
                'application/json' => [
                    'schema' => ['$ref' => $schema_ref],
                ],
            ];
        }
        return (object)[
            '200' => $response_200,
            '404' => [
                'description' => sprintf('找不到%s資料', $subject),
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/Error'],
                    ],
                ],
            ],
        ];
    }

    protected function getClassNameByEntity(string $entity): string
    {
        return 'CCAPI_Type_' . $entity;
    }

    protected function generatePathsFromFile(string $file): array
    {
        $paths = [];
        $entity = basename($file, '.php');
        $class_name = $this->getClassNameByEntity($entity);
        if (!class_exists($class_name)) {
            return [];
        }
        $endpoint_types = $class_name::getEndpointTypes();
        if (empty($endpoint_types)) {
            return [];
        }
        $group = $entity;

        foreach ($endpoint_types as $endpoint_type) {
            $base_path = $this->getEndPointPath($entity, $class_name, $endpoint_type);
            $paths[$base_path] = [
                'get' => [
                    'tags' => [$group],
                    'summary' => $this->getEndpointSummary($class_name::getTypeSubject(), $endpoint_type),
                    'operationId' => $this->getOperationId($entity, $endpoint_type),
                    'parameters' => $this->getParameters($class_name, $endpoint_type),
                    'responses' => $this->getResponses($class_name::getTypeSubject(), $this->getSchemaRef($entity, $endpoint_type)),
                ],
            ];
        }

        foreach ($class_name::getRelations() as $relation_name => $info) {
            $base_path = $this->getEndPointPath($entity, $class_name, 'relation', $relation_name);
            $paths[$base_path] = [
                'get' => [
                    'tags' => [$group],
                    'summary' => $this->getEndpointSummary($info['subject'], 'list'),
                    'operationId' => $this->getOperationId($entity, 'relation', $relation_name),
                    'parameters' => $this->getParameters($class_name, 'relation', $info['type']),
                    'responses' => $this->getResponses($info['subject'] ?? '', $this->getSchemaRef($info['type'], 'relation')),
                ],
            ];
        }

        return $paths;
    }

    protected function getSchemaRef(string $entity, string $endpoint_type): ?string
    {
        $class_name = $this->getClassNameByEntity($entity);
        switch ($endpoint_type) {
        case 'item':
            if (!empty($class_name::getItemProperties())) {
                return "#/components/schemas/{$entity}";
            }
            break;
        case 'list':
            if (!empty($class_name::getEntryProperties())) {
                return "#/components/schemas/{$entity}List";
            }
            break;
        }
        return null;
    }

    protected function generateSchemasFromFile(string $file): array
    {
        $entity = basename($file, '.php');
        $class_name = $this->getClassNameByEntity($entity);
        if (!class_exists($class_name)) {
            return [];
        }
        $schemas = [];

        if (!empty($class_name::getItemProperties())) {
            $schemas[$entity] = [
                'type' => 'object',
                'properties' => $class_name::getItemProperties(),
            ];
        }

        if (!empty($class_name::getEntryProperties())) {
            $items_key = sprintf('%ss', strtolower($entity));
            $schemas["{$entity}List"] = [
                'type' => 'object',
                'properties' => [
                    'total'        => ['type' => 'integer'],
                    'total_pages'  => ['type' => 'integer'],
                    'page'         => ['type' => 'integer'],
                    'limit'        => ['type' => 'integer'],
                    'filter'       => ['type' => 'object'],
                    'id_fields'    => ['type' => 'array', 'items' => ['type' => 'string']],
                    'sort'         => ['type' => 'array', 'items' => ['type' => 'string']],
                    'output_fields'=> ['type' => 'array', 'items' => ['type' => 'string']],
                    $items_key     => ['type' => 'array', 'items' => ['$ref' => "#/components/schemas/{$entity}Entry"]],
                ],
            ];
            $schemas["{$entity}Entry"] = [
                'type' => 'object',
                'properties' => $class_name::getEntryProperties(),
            ];
        }

        return $schemas;
    }

    protected function parseToYaml($data, $indent = ''): string
    {
        $yaml = '';
        foreach ($data as $key => $value) {
            if (is_array($value) || $value instanceof stdClass) {
                if (is_int($key)) {
                    $yaml .= "{$indent}-\n";
                    $yaml .= $this->parseToYaml($value, $indent . '  ');
                } else {
                    $yaml .= "{$indent}{$key}:\n";
                    $yaml .= $this->parseToYaml($value, $indent . '  ');
                }
            } elseif (is_bool($value)) {
                $yaml .= "{$indent}{$key}: " . ($value ? 'true' : 'false') . "\n";
            } elseif (is_int($key)) {
                $yaml .= "{$indent}- {$value}\n";
            } elseif (is_string($value)) {
                // 逸脫單引號
                $escaped = str_replace("'", "''", $value);
                $yaml .= "{$indent}{$key}: '{$escaped}'\n";
            } elseif (!is_null($value)) {
                $yaml .= "{$indent}{$key}: {$value}\n";
            }
        }
        return $yaml;
    }

    protected function generate(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'all.cc.govapi.tw';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        $data = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => '地方議會 API (CCAPI)',
                'description' => '地方議會開放資料 API。使用 {city-code}.cc.govapi.tw 存取特定縣市議會，all.cc.govapi.tw 進行跨議會查詢。',
                'version' => '1.0.0',
            ],
            'servers' => [
                ['url' => "{$scheme}://{$host}", 'description' => '目前主機'],
                ['url' => "{$scheme}://all.cc.govapi.tw", 'description' => '全國查詢'],
            ],
        ];

        $auto_gen_files = MINI_ENGINE_ROOT . '/libraries/CCAPI/Type/*.php';
        $data['paths'] = [];
        foreach (glob($auto_gen_files) as $f) {
            // 確保 class 已載入
            $entity = basename($f, '.php');
            $class_name = $this->getClassNameByEntity($entity);
            if (!class_exists($class_name)) {
                include_once $f;
            }
            $paths = $this->generatePathsFromFile($f);
            $data['paths'] = array_merge($data['paths'], $paths);
        }

        $data['components'] = [
            'schemas' => [
                'Error' => [
                    'type' => 'object',
                    'required' => ['error'],
                    'properties' => [
                        'error'   => ['type' => 'boolean'],
                        'message' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
        foreach (glob($auto_gen_files) as $f) {
            foreach ($this->generateSchemasFromFile($f) as $name => $schema) {
                $data['components']['schemas'][$name] = $schema;
            }
        }

        return $this->parseToYaml($data);
    }
}
