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

    public function skillAction()
    {
        header('Content-Type: text/markdown; charset=utf-8');
        echo $this->generateSkillMd();
        return $this->noview();
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
            return "/api/{$resource}";
        case 'item':
            return "/api/{$resource}/{$id_fields_string}";
        case 'relation':
            return "/api/{$resource}/{$id_fields_string}/{$relation_name}";
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

    /**
     * 產生給 AI Agent 讀的完整 API 說明（Markdown），內容從 CCAPI_Type/*.php
     * 自動掃描產生，跟 swagger.yaml 用同一份 type 定義，兩者不會脫節。
     */
    /**
     * 格式依循 ~/work/openfun-data-portal/docs/api-skill-standard.md 這份跨服務標準
     * （data.openfun.tw portal 要能直接 proxy 各服務自己的 /skill.md，不能依賴外部
     * knowledge repo 補資訊），必要段落：開頭（含 slug）／開始之前（Base URL、認證、
     * Device Authorization Grant 固定範本、禁止 WebFetch 警告）／業務警告／端點與查詢
     * 說明／快速參考表。認證測 Bearer Token 的部分是 data.openfun.tw 統一核發、由
     * nginx gateway 驗證（見 OpenFunAPIHelper 那次整合），不是 ccapi 自己的認證系統。
     */
    protected function generateSkillMd(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'all.cc.govapi.tw';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $postfix = getenv('CCAPI_DOMAIN_POSTFIX') ?: '.cc.govapi.tw';
        $slug = 'tw.openfun~api~tw.ccapi';

        $md = "# 地方議會開放 API（CCAPI）— `{$slug}`\n\n";
        $md .= "給 AI 閱讀的使用指引。人類可在 https://data.openfun.tw/datasets/{$slug} 看到資料集說明。\n\n";

        $md .= "## ⚠️ 開始之前（AI agent 必讀）\n\n";
        $md .= "**Base URL**：`{$scheme}://{$host}/api`（依子網域決定查詢範圍，見下方「子網域決定查詢範圍」一節）\n";
        $md .= "**認證**：使用 data.openfun.tw 核發的 Bearer Token 可解除流量限制；不帶 Token 仍可呼叫，但可能因超過流量門檻被擋（門檻隨時調整，不保證特定數字）。\n";
        $md .= "**回應格式**：一律 `application/json`，CORS 完全開放（前端可直接呼叫）。\n\n";
        $md .= "最簡查詢範例：\n";
        $md .= "```bash\ncurl -H \"Authorization: Bearer YOUR_TOKEN\" \\\n  \"{$scheme}://{$host}/api/councilors?limit=5\"\n```\n\n";
        $md .= "**取得 Token 方式（Device Authorization Grant）：**\n";
        $md .= "```bash\n# 步驟一：取得驗證連結\ncurl -X POST https://data.openfun.tw/api/v1/auth/device\n\n"
             . "# 步驟二：在瀏覽器開啟回應中的 verification_uri_complete，用 Google 帳號登入授權\n\n"
             . "# 步驟三：輪詢取得 Token（約 10-30 秒後成功）\ncurl -X POST https://data.openfun.tw/api/v1/auth/token \\\n  -d \"device_code=DEVICE_CODE_FROM_STEP1\"\n```\n";
        $md .= "若無 Token，也可在 https://data.openfun.tw/user 登入後從 Dashboard 取得長效 API 金鑰。\n\n";
        $md .= "禁止用 WebFetch 抓 HTML 頁面，請直接呼叫 API。\n\n";
        $md .= "呼叫前建議先讀 `{$scheme}://{$host}/knowledge.md`——裡面說明「議會」「議員」等詞在這個 API 裡的精確定義，"
             . "避免用一般政治制度的既有知識（例如跟國會、其他國家的地方議會搞混）誤判資料意義。\n\n";

        $md .= "## ⚠️ 不是立法院（國會）\n\n";
        $md .= "這是台灣「地方制度法」規範的地方議會（直轄市議會、縣（市）議會）開放資料，**不是立法院**。"
             . "立法委員、國會的資料在另一個姊妹服務 `ly.govapi.tw`，資料完全不重疊、也不能互相查詢。"
             . "看到「議員」不要當成「立法委員」，看到「議會」不要當成「國會」「Parliament」。"
             . "完整背景知識（含「議會」定義範圍、跟鄉鎮市民代表會的差異等）見 `{$scheme}://{$host}/knowledge.md`。\n\n";

        $md .= "## 子網域決定查詢範圍\n\n";
        $md .= "- `https://{議會代碼}{$postfix}/api/...` — 只查詢該議會的資料（自動加上議會代碼 filter，不需要自己帶）\n";
        $md .= "- `https://all{$postfix}/api/...` — 跨議會查詢，不限單一議會\n";
        $md .= "- 目前主機：`{$scheme}://{$host}`\n";
        $md .= "- 未知的議會代碼子網域會回傳 HTTP 404\n";
        $md .= "- CORS 全開（`Access-Control-Allow-Origin: *`），前端可直接呼叫\n\n";

        $md .= "### 議會代碼清單\n\n";
        foreach (CouncilHelper::getAll() as $code => $name) {
            if ($code === 'all') {
                continue;
            }
            $md .= "- `{$code}` — {$name}\n";
        }
        $md .= "\n";

        $md .= "## 共用查詢參數（適用於下方所有型別的「列表」endpoint）\n\n";
        $md .= "- `q={關鍵字}`：全文搜尋，可搜尋欄位見各型別「查詢欄位」。中文查詢會自動依空白斷詞，"
             . "每個詞各自做片語比對後以 AND 串接——例如 `q=黃國昌 土地` 等同「內容同時包含『黃國昌』與『土地』」，"
             . "不會被拆成單字各自比對。搜尋命中時，回傳資料會多一個 `{欄位}:highlight` 欄位（陣列），"
             . "內容是命中前後文片段，比對到的詞會用 `<em>` 包起來。\n";
        $md .= "- `{filter欄位}={值}`：依欄位篩選，同一欄位出現多次視為 OR（例：`黨籍=中國國民黨&黨籍=民主進步黨`）\n";
        $md .= "- `{filter欄位}:{起},{訖}`：範圍篩選，用冒號＋逗號（不是 `=`），起訖任一端可留空表示不限"
             . "（例：`日期:2024-01-01,2024-12-31`、`日期:2024-01-01,` 代表 2024-01-01 以後）\n";
        $md .= "- `agg={filter欄位}`：依欄位分群統計筆數；可重複帶多個 `agg=`；同一個 `agg=` 裡用逗號可做多層分群"
             . "（例：`agg=議會代碼,黨籍`）。回傳結果在 `aggs` 陣列裡，每個 bucket 有該欄位值與 `count`。\n";
        $md .= "- `sort={filter欄位}<` 或 `>`：排序，`<` 升冪、`>` 降冪，可重複帶多個欄位\n";
        $md .= "- `output_fields={欄位}`：限制回傳欄位，可重複帶；不帶則回傳全部欄位\n";
        $md .= "- `page=`、`limit=`：分頁（`limit=0` 只回傳統計/aggs，不回傳實際資料列，適合只要 `agg=` 結果時用）\n\n";

        $md .= "## 列表 API 回應格式\n\n";
        $md .= "```\n{\n"
             . "  \"total\": 總筆數,\n"
             . "  \"total_page\": 總頁數,\n"
             . "  \"page\": 目前頁數,\n"
             . "  \"limit\": 每頁筆數,\n"
             . "  \"id_fields\": [組成單筆 ID 的欄位名稱],\n"
             . "  \"supported_filter_fields\": [這個型別支援的 filter 欄位],\n"
             . "  \"{回傳key}\": [資料列, ...]\n"
             . "}\n```\n\n";
        $md .= "## 單筆 API 回應格式\n\n";
        $md .= "找到：`{\"error\": false, \"data\": {...}}`；找不到：`{\"error\": true, \"message\": \"找不到資料\"}`（HTTP 200，用 `error` 欄位判斷，不是用 HTTP status）\n\n";

        $md .= "## 範例查詢\n\n";
        $md .= "```bash\n# 台北市議會第14屆議員名單\ncurl -H \"Authorization: Bearer YOUR_TOKEN\" \\\n"
             . "  \"https://tpe{$postfix}/api/councilors?屆次=14\"\n```\n\n";
        $md .= "```bash\n# 全國民主進步黨議員，依議會分群統計\ncurl -H \"Authorization: Bearer YOUR_TOKEN\" \\\n"
             . "  \"https://all{$postfix}/api/councilors?黨籍=民主進步黨&agg=議會代碼\"\n```\n\n";
        $md .= "```bash\n# 全文搜尋議案案由，依類別分群統計\ncurl -H \"Authorization: Bearer YOUR_TOKEN\" \\\n"
             . "  \"https://all{$postfix}/api/bills?q=公共托育&agg=類別\"\n```\n\n";

        $md .= "## 型別一覽\n\n";

        $auto_gen_files = MINI_ENGINE_ROOT . '/libraries/CCAPI/Type/*.php';
        foreach (glob($auto_gen_files) as $f) {
            $entity = basename($f, '.php');
            $class_name = $this->getClassNameByEntity($entity);
            if (!class_exists($class_name)) {
                include_once $f;
            }
            $md .= $this->generateSkillSection($entity, $class_name);
        }

        $md .= "## 快速參考\n\n";
        $md .= "| 項目 | 說明 |\n|------|------|\n";
        $md .= "| Base URL | `{$scheme}://{$host}/api` |\n";
        $md .= "| 認證 | `Authorization: Bearer {token}` 可解除流量限制（不帶也能呼叫） |\n";
        $md .= "| 取得 Token | https://data.openfun.tw/user |\n";
        $md .= "| 子網域 | `{議會代碼}{$postfix}` 只查該議會；`all{$postfix}` 跨議會查詢，未知議會代碼回傳 404 |\n";
        $md .= "| 背景知識 | `{$scheme}://{$host}/knowledge.md`（議會/議員等詞的精確定義，避免跟國會搞混） |\n";
        $md .= "| 全文搜尋 | `q=` 參數，中文自動片語比對；命中時多回傳 `{欄位}:highlight` |\n";

        return $md;
    }

    protected function generateSkillSection(string $entity, string $class_name): string
    {
        $subject = $class_name::getTypeSubject();
        $resource_list = $class_name::getReturnKey();
        $resource_item = strtolower($entity);
        $id_fields_info = $class_name::getIdFieldsInfo();
        $id_example = implode('/', array_map(fn($info) => (string)$info['example'], $id_fields_info));

        $md = "### {$subject}（{$entity}）\n\n";
        $md .= "- 列表：`GET /api/{$resource_list}`\n";
        if ($id_fields_info) {
            $md .= "- 單筆：`GET /api/{$resource_item}/{$id_example}`（ID 依序為："
                 . implode('、', array_keys($id_fields_info)) . "）\n";
        }

        $query_fields = $class_name::queryFields();
        if ($query_fields) {
            $md .= "- 查詢欄位（`q=` 搜尋範圍）：" . implode('、', $query_fields) . "\n";
        }

        $filter_fields = $class_name::getFilterFieldsInfo();
        if ($filter_fields) {
            $md .= "- 篩選／排序／分群欄位：\n";
            foreach ($filter_fields as $field => $info) {
                $enum = !empty($info['enum']) ? '，可選值：' . implode('、', $info['enum']) : '';
                $md .= "  - `{$field}`（{$info['type']}）：{$info['description']}{$enum}\n";
            }
        }

        $sort_fields = $class_name::sortFields();
        if ($sort_fields) {
            $md .= "- 預設排序：" . implode('、', $sort_fields) . "\n";
        }
        $md .= "- 預設每頁筆數：{$class_name::defaultLimit()}\n";
        $md .= "- 列表資料放在回應的 `{$resource_list}` 欄位\n\n";

        return $md;
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
        $postfix = getenv('CCAPI_DOMAIN_POSTFIX') ?: '.cc.govapi.tw';

        $data = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => '地方議會 API (CCAPI)',
                'description' => '地方議會開放資料 API。使用 {city-code}.cc.govapi.tw 存取特定縣市議會，all.cc.govapi.tw 進行跨議會查詢。',
                'version' => '1.0.0',
            ],
            'servers' => [
                ['url' => "{$scheme}://{$host}", 'description' => '目前主機'],
                ['url' => "{$scheme}://all{$postfix}", 'description' => '全國查詢'],
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
