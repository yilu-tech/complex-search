<?php

namespace YiluTech\ComplexSearch;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class ComplexSearch
{
    public $root;
    /**
     * @var \Illuminate\Database\Eloquent\Builder
     */
    public $query;

    public $action;

    protected $range = 2;

    protected $lang = 'fields';

    protected $display = 'default';

    protected $joins = array();

    protected $hidden = array();

    protected $orderBy;

    protected $groupBy = array();

    protected $joinDef = array();

    protected $fieldDef = array();

    protected $whereDef = array();

    protected $groupDef = array();

    protected $headers = array();

    protected $conditions = array();

    protected $filterPreg = array();

    protected $exportLinkTime = 120;

    /**
     * @var RelationNode
     */
    private $relations;

    private $joinsRelation = array();

    private $loop = array();

    private $request = array();

    private $sqlOperators = [
        'string' => ['=', '<>', 'like', 'not like'],
        'numeric' => ['=', '<>', '>', '>=', '<', '<=', 'in'],
        'boolean' => ['=', '<>'],
        'only' => ['=', '<>', 'in'],
        'json' => ['like', 'not like'],
        'date' => ['=', '<>', '>', '>=', '<', '<=', 'in']
    ];

    private $fun = ['add', 'mul', 'sub', 'div', 'sum', 'max', 'min', 'count', 'avg', 'if', 'date_format', 'round', 'cast', 'concat', 'abs', 'group_concat'];

    public function make($request)
    {
        $this->request = $request;

        $this->action = $this->input('action');

        if ($this->root) {
            if (is_string($this->root)) {
                $this->root = new $this->root;
            }
            $this->makeRelation($this->root, $this->range);
        }

        if ($this->action !== 'prepare') {
            return $this;
        }

        $this->prepare();

        if ($this->root) {
            $this->bindCondition();
        }

        return $this;
    }

    public function prepare()
    {
        // do
    }

    public function get()
    {
        if (!$this->action) return null;

        return $this->{'exec' . ucfirst($this->action)}();
    }

    protected function execPrepare()
    {
        $data = [
            'headers' => $this->headers,
            'display' => $this->display
        ];
        if ($this->root && $this->display !== 'simple') {
            $data['fields'] = array_merge($this->getFields($this->relations), $this->getConditions(true));
        } else {
            $data['conditions'] = $this->getConditions();
        }
        return $data;
    }

    protected function execQuery()
    {
        if (!$this->query) {
            $this->query = $this->query();
        }
        $this->addJoins($this->query);
        return $this->input('size') ? $this->query->paginate($this->input('size')) : $this->query->get();
    }

    protected function execExport()
    {
        $data['uri'] = $_SERVER['DOCUMENT_URI'];
        $data['controller'] = \Route::current()->getAction('controller');
        $data['params'] = $this->input('params', []);
        $data['fields'] = $this->input('fields', []);
        $data['extras'] = $this->input('extras', []);
        if ($groupBy = $this->input('groupBy')) {
            $data['groupBy'] = $groupBy;
        }
        if ($orderBy = $this->input('orderBy')) {
            $data['orderBy'] = $orderBy;
        }
        $data = json_encode($data);
        $params = [
            'code' => strtoupper(md5($data)),
            'expires_in' => time() + $this->exportLinkTime * 60,
            'nonce_str' => str_random(16),
        ];
        $params['sign'] = md5(http_build_query($params) . '&key=' . env('APP_KEY'));
        \Cache::put('EXPORT:' . $params['code'], $data, $this->exportLinkTime);
        return http_build_query($params);
    }

    public function query()
    {
        if (!$this->query) {
            $this->query = $this->root->query();
        }

        if (!count($this->input('fields', []))) {
            throw new \Exception('query fields null');
        }

        if ($this->root) {
            $this->addSelect($this->query, $this->getQueryFields());
        }

        $this->addWheres($this->query);

        return $this->addOrderBy($this->query)->addGroupBy($this->query)->query;
    }

    public function where($column, $operator = null, $value = null)
    {
        if (!$this->query) return $this;

        if ($this->root) {
            $column = $this->field($column);
        }

        $this->query->where($column, $operator, $value);

        return $this;
    }

    public function getQueryCondition($name, $matchOr = false)
    {
        foreach ($this->input('params', []) as $item) {

            if ($matchOr && is_array($item[0])) {
                foreach ($item as $subItem) {
                    if ($this->matchField($name, $subItem[0])) return $item;
                }
            }

            if ($this->matchField($name, $item[0])) return $item;
        }
        return null;
    }

    /**
     * @param $name
     * @param int $type 0 任意匹配 1 单条件单元匹配 2 多条件单元匹配
     * @return bool
     */
    public function hasQueryCondition($name, $type = 0)
    {
        $multiple = false;
        $single = false;
        foreach ($this->input('params', []) as $condition) {
            if (is_array($condition[0])) {
                if ($type !== 1 && !$multiple) {
                    foreach ($condition as $key => $item) {
                        if ($this->matchField($name, $item[0])) $multiple = true;
                    }
                }
            } elseif ($this->matchField($name, $condition[0])) {
                $single = true;
            }

            if ($type === 1 && $single) return true;
            if ($type === 2 && $multiple) return true;
            if ($type === 0 && ($multiple || $single)) return true;
        }
        return false;
    }

    public function getConditions($custom = false)
    {
        $conditions = array();
        foreach ($this->conditions as $key => $condition) {
            $condition['name'] = $key;
            if ($custom) {
                if (isset($condition['custom'])) {
                    $conditions[] = $condition;
                }
            } else {
                $conditions[] = $condition;
            }
        }
        return $conditions;
    }

    /**
     * @param $node RelationNode
     * @return array
     */
    public function getFields($node)
    {
        $fields = array();
        foreach ($node->fields as $key => $value) {
            if ($this->validateField($value['name'], $node)) {
                if (empty($value['label'])) {
                    $value['label'] = trans($this->lang . '.' . $node->table . '.' . $key);
                }
                unset($value['_value']);
                unset($value['custom']);
                $fields[] = $value;
            }
        }
        foreach ($node->childNodes as $key => $value) {
            $fields[] = [
                'label' => trans($this->lang . '.' . $node->table . '.' . $key),
                'name' => $key . '.*',
                'children' => $this->getFields($value)
            ];
        }
        return $fields;
    }

    private function validateField($field, $node)
    {

//        if ($node->primaryKey === $field) {
//            return false;
//        }
        $field = $node->joinName . '.' . $field;
        foreach ($this->filterPreg as $value) {
            if (preg_match("/{$value}/", $field)) {
                return false;
            }
        }
        return true;
    }

    public function field($field, $type = 0)
    {
        $field = $this->find($field, $type);

        if ($field['custom']) {
            $field['_value'] = $this->parseToMultiTree($field)->toString();
        }

        return $this->makRaw($field, false);
    }

    public function find($str, $type = 0)
    {
        $field = $this->parseField($str);
        $nodes = [$this->relations];
        while (count($nodes)) {
            $node = array_shift($nodes);
            if ($this->hasField($field, $node, $match, $type)) {
                if ($match) {
                    $field['table'] = $match->parentNode->table;
                    $field['name'] = $match->otherKey;
                    $field['node'] = $match->parentNode;
                    if (!$field['rename']) $field['rename'] = $field['name'];
                } else {
                    $field['table'] = $node->table;
                    $field['node'] = $node;
                }
                break;
            }
            foreach ($node->childNodes as $node) array_push($nodes, $node);
        }
        if (!isset($field['node'])) {
            throw new \Exception("field \"$str\" not exist");
        }
        $field = array_merge($field, $field['node']->fields[$field['name']]);
        $this->setJoins($field['node']->joins())->setGroupBy($field['node']);
        return $field;
    }

    public function input($key, $default = null)
    {
        if (array_key_exists($key, $this->request)) {
            return $this->request[$key];
        }

        if (!empty($this->{$key})) {
            return $this->{$key};
        }

        return $default;
    }

    public function addWheres($query, $conditions = null, $bool = 'and')
    {
        if (!$conditions) {
            $conditions = $this->input('params', []);
        }
        foreach ($conditions as $index => $condition) {
            if (is_array($condition[0])) {
                $query->{$bool === 'or' ? 'orWhere' : 'where'}(function ($query) use ($condition) {
                    $this->addWheres($query, $condition, 'or');
                });
            } else {
                $condition = $this->formatWhere($condition, ($index && $bool === 'or') ? 'or' : 'and');

                if (isset($condition['name'])) {
                    $this->addWhere($query, $condition);
                } else {
                    $query->{$bool === 'or' ? 'orWhere' : 'where'}(function ($query) use ($condition) {
                        $this->addWhere($query, $condition[0]);
                        $this->addWhere($query, $condition[1]);
                    });
                }
            }
        }
        return $query;
    }

    private function addWhere($query, $condition)
    {
        if (isset($this->whereDef[$condition['name']])) {
            $this->whereDef[$condition['name']]($query, $condition);
        } else {
            $query->{$condition['fun']}(...$condition['argv']);
        }
    }

    public function getQueryFields()
    {
        $fields = $this->input('fields', []);
        if ($orderBy = $this->getOrderBy()) {
            $fields = array_merge($fields, array_map(function ($item) {
                return $item['field'];
            }, $orderBy));
        }
        $fields = array_unique(array_merge($fields, $this->hidden));
        foreach ($fields as $field) {
            $node = $this->parseToMultiTree($this->find($field));
            if (is_array($node)) {
                $this->loop[0][$node[0]] = $node[1];
            } elseif ($node instanceof OperationNode) {
                $this->cutMultiTree($node);
            }
        }
        $fields = array();
        foreach ($this->loop[0] as $key => $value) {
            if ($value instanceof OperationNode) {
                $fields[] = $value->toString() . " as {$key}";
            } else {
                $fields[] = strpos($value, '.' . $key) ? $value : "{$value} as {$key}";
            }
        }
        return $fields;
    }

    protected function addSelect($query, $fields)
    {
        foreach ($fields as $field) {
            $query->addSelect(\DB::raw($field));
        }

        return $this;
    }

    public function getGroupBy($default = null)
    {
        $value = $this->input('groupBy', $default);
        if (!$value) return $value;
        if (is_string($value)) {
            $value = [$value];
        }
        return array_map(function ($field) {
            return $this->field($field);
        }, $value);
    }

    protected function addGroupBy($query, $groups = null)
    {
        $groups = $groups ?: $this->getGroupBy();

        if ($groups) {
            $query->groupBy(...$groups);
        }

        return $this;
    }

    /**
     * @param $node
     * @return $this
     */
    private function setGroupBy($node)
    {
        if (!count($this->groupDef)) return $this;
        $groups = array();
        foreach ($this->groupDef as $name => $value) {
            if (in_array(is_int($name) ? $value : $name, $node->path())) {
                $groups[] = is_int($name) ? implode('.', array_merge($node->path(), [$node->otherKey])) : $value;
            }
        }
        $this->groupBy = array_unique(array_merge($this->groupBy, $groups));
        return $this;
    }

    public function getOrderBy($default = null)
    {
        $value = $this->input('orderBy', $default);
        if (!$value) {
            return $value;
        }
        return array_map(function ($item) {
            $argv = explode(' ', $item);
            return [
                'field' => $argv[0],
                'direction' => isset($argv[1]) ? $argv[1] : 'asc'
            ];
        }, explode(';', trim($value, '; ')));
    }

    protected function addOrderBy($query, $orderBy = null)
    {
        $items = $orderBy ?: $this->getOrderBy();

        if ($items) {
            foreach ($items as $item) {
                $query->orderBy($item['field'], $item['direction']);
            }
        }

        return $this;
    }

    protected function addJoins($query)
    {
        foreach ($this->joinsRelation as $name => $join) {
            if (isset($this->joinDef[$name])) {
                $query->leftJoin($join[0], $this->joinDef[$name]);
            } else {
                $query->leftJoin($join[0], $join[1], '=', $join[2]);
            }
        }
        $this->joinsRelation = [];

        return $this;
    }

    private function bindCondition()
    {
        foreach ($this->conditions as $key => $condition) {
            if (isset($condition['custom'])) continue;

            $field = $this->find($key);

            $field['node']->fields[$field['name']] = array_merge($field['node']->fields[$field['name']], $condition);
        }
    }

    /**
     * @param $params
     * @param string $bool
     * @return array
     * @throws \Exception
     */
    private function formatWhere($params, $bool = 'and')
    {
        $name = $params[0];

        if ($this->root && empty($this->conditions[$name]['custom'])) {
            $field = $this->find($name);
            if (!in_array($params[1], $this->sqlOperators[$field['itype']], true)) {
                throw new \Exception("where \"{$params[0]}\" operator \"{$params[1]}\" not exist");
            }

            if (is_string($params[2]) && strlen($params[2]) > 64) {
                throw new \Exception("\"{$params[0]}\" value length more than 64");
            }

            $params[0] = $field['custom'] ? $field['rename'] : $this->makRaw($field, false);
        }

        if ($params[1] === 'like' || $params[1] === 'not like') {
            if ($params[2]) {
                $params[2] = '%' . $params[2] . '%';
            } else {
                $params[1] = $params[1] === 'like' ? '=' : '<>';
            }
        }

        if ($params[1] === '=' && $params[2] === null) {
            $where = ['name' => $name, 'fun' => 'whereNull', 'argv' => [$params[0], $bool]];
        } elseif ($params[1] === '<>' && $params[2] === null) {
            $where = ['name' => $name, 'fun' => 'whereNotNull', 'argv' => [$params[0], $bool]];
        } elseif ($params[1] === 'in') {
            $where = [
                ['name' => $name, 'fun' => 'where', 'argv' => [$params[0], '>=', $params[2][0]]],
                ['name' => $name, 'fun' => 'where', 'argv' => [$params[0], '<=', $params[2][1]]]
            ];
        } elseif (is_array($params[2])) {
            $where = ['name' => $name, 'fun' => $params[1] === '=' ? 'whereIn' : 'whereNotIn', 'argv' => [$params[0], $params[2], $bool]];
        } else {
            $where = ['name' => $name, 'fun' => 'where', 'argv' => [$params[0], $params[1], $params[2], $bool]];
        }
        return $where;
    }

    private function makRaw($field, $rename = true)
    {
        if (!$field['custom']) {
            $field['_value'] = $field['table'] . '.' . $field['_value'];
        }

        if ($rename && $field['_value'] !== '*' && $field['_value'] !== $field['rename']) {
            return \DB::raw($field['_value'] . ' as ' . $field['rename']);
        }

        return $field['_value'];
    }

    function parseField($str)
    {
        $argv = explode('|', $str);

        $path = explode('.', $argv[0]);

        $field['name'] = array_pop($path);

        $field['path'] = $path;

        $field['rename'] = isset($argv[1]) ? $argv[1] : $field['name'];

        return $field;
    }

    /**
     * @param $field
     * @param $node RelationNode
     * @param $match RelationNode
     * @param $type int
     * @return bool
     */
    function hasField($field, $node, &$match = null, $type = 0)
    {
        if (!$node->hasPath($field['path'])) return false;

        if ($type !== 1 && $match = $node->hasJoinField($field['name'])) {
            return true;
        }

        return $field['name'] === '*' || $node->hasField($field['name']);
    }

    private function matchField($name, $subject)
    {
        $index = strpos($subject, $name);
        if ($index > 0 && $subject{$index - 1} !== '.') {
            return false;
        }
        return (strlen($subject) - strlen($name)) === $index;
    }

    /*********************************************************/

    /**
     * @param $filed
     * @param $root OperationNode
     * @param array $renames
     * @return array | OperationNode
     * @throws \Exception
     */
    private function parseToMultiTree($filed, $root = null, $renames = [])
    {
        if (!$filed['custom']) {
            return [$filed['rename'], $filed['table'] . '.' . $filed['_value']];
        }
        if (in_array($filed['rename'], $renames)) {
            throw new \Exception("relationship \"{$filed['rename']}\" call error");
        }
        array_push($renames, $filed['rename']);
        $parts = explode('|', $filed['_value']);
        $currentNode = $childNode = null;
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $item = explode(':', $parts[$i]);
            if (!in_array($item[0], $this->fun)) {
                throw new \Exception(" \"{$filed['rename']}\" function \"{$item[0]}\" not exist ");
            }
            $currentNode = new OperationNode($item[0], $filed['rename']);
            if ($childNode) {
                $childNode->parentNode = $currentNode;
            }
            $params = isset($item[1]) ? $this->parseOperationParams($item[1], $renames, $currentNode, $childNode) : [$childNode];
            $currentNode->createMultiTree(...$params);
            $childNode = $currentNode;
        }
        $currentNode->parentNode = $root;
        return $currentNode;
    }

    /**
     * @param $params
     * @param $renames
     * @param $currentNode
     * @param $childNode
     * @return array
     * @throws \Exception
     */
    private function parseOperationParams($params, $renames, $currentNode, $childNode)
    {
        $params = explode(',', $params);
        foreach ($params as &$value) {
            if ($value{0} === '@') {
                $field = $this->find(substr($value, 1));
                $value = $this->parseToMultiTree($field, $currentNode, $renames);
            } elseif ($value === '$') {
                $value = $childNode;
            } else {
                $value = preg_replace_callback('/{.+?}/', function ($match) {
                    $field = $this->find(substr($match[0], 1, -1));
                    return $field['table'] . '.' . $field['_value'];
                }, $value);
            }
        }
        return $params;
    }

    /**
     * @param $node OperationNode
     * @param $root
     */
    private function cutMultiTree($node, $root = null)
    {
        $root = $root ?: $node;
        if ($node->floor < 2) {
            if ($node->type === 2 && $node->floor === 1) {
                $this->loop[1][$node->belongTo] = $node;
                foreach ($node->values as &$value) {
                    if ($value instanceof OperationNode) {
                        $this->loop[0][$value->belongTo] = $value;
                        $value = '@' . $value->belongTo;
                    }
                }
            } else {
                $this->loop[0][$node->belongTo] = $node;
            }
        } else {
            foreach ($node->values as &$value) {
                if ($value instanceof OperationNode) {
                    if ($node->hasJump) {
                        $this->loop[$node->floor - 1][$value->belongTo] = $root;
                        $this->cutMultiTree($value, $value);
                        $value = '@' . $value->belongTo;
                    } else {
                        $this->cutMultiTree($value, $root);
                    }
                } elseif (is_array($value)) {
                    $this->loop[0][$value[0]] = $value[1];
                    $value = '@' . $value[0];
                }
            }
        }
    }

    /*********************************************************/

    private function setJoins($joins)
    {
        foreach ($joins as $name => $join) {
            if (isset($this->joinsRelation[$name])) continue;

            $this->joinsRelation[$name] = $join;
        }
        return $this;
    }

    public function makeRelation($model, $range)
    {
        $models = [[[$model, null, &$this->relations]]];
        $tables = array();
        while (count($models)) {
            $items = array_shift($models);
            $children = [];
            foreach ($items as $model) {
                if (in_array($model[0]->getTable(), $tables)) continue;

                $parent = $this->makeNode($model[0], $model[1]);
                $tables[] = $parent->table;
                $model[2] ? $model[2]->addChild($parent) : $model[2] = $parent;

                foreach ($this->getJoinsByModel($model[0]) as $name => $joins) {
                    foreach ($joins as $key => $join) {
                        if ($key < count($joins) - 1) {
                            if (in_array($join[0]->getTable(), $tables)) continue;
                            $node = $this->makeNode($join[0], [$join[2], $join[1], $join[0]->getTable()]);
                            $node->appendTo($parent);
                            $parent = $node;
                            $tables[] = $parent->table;
                        } else {
                            $children[] = [$join[0], [$join[2], $join[1], $name], $parent];
                        }
                    }
                }
            }
            if (--$range >= 0) $models[] = $children;
        }
    }

    private function makeNode($model, $join)
    {
        $node = new RelationNode($model);

        $this->setFields($model, $node);

        if ($join) $node->setJoin(...$join);

        return $node;
    }

    /**
     * @param $model \Illuminate\Database\Eloquent\Model;
     * @param $node RelationNode
     * @return array
     */
    private function setFields($model, $node)
    {
        $fills = $model->getFillable();
        $primaryKey = $model->getKeyName();

        if (!in_array($primaryKey, $fills)) $fills[] = $primaryKey;
        $casts[$primaryKey] = 'only';
        if ($model->timestamps) {
            $casts['created_at'] = $casts['updated_at'] = 'date';
            if (!in_array('created_at', $fills)) $fills[] = 'created_at';
            if (!in_array('updated_at', $fills)) $fills[] = 'updated_at';
        }

        if (in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses($model))) {
            $casts['deleted_at'] = 'date';
            if (!in_array('deleted_at', $fills)) $fills[] = 'deleted_at';
        }
        $casts = array_merge($casts, $model->getCasts(), $model->fieldTypes ?: []);

        foreach ($fills as $field) {
            $node->fields[$field] = $this->makeField($field, isset($casts[$field]) ? $casts[$field] : 'numeric', $field);
        }

        foreach ($this->fieldDef as $key => $value) {
            $field = $this->parseField($key);
            if ($node->hasPath($field['path'])) {
                $node->fields[$field['name']] = $this->makeField($field['name'], 'numeric', $value, true);
            }
        }
    }

    private function makeField($name, $type, $value, $custom = false)
    {
        return [
            'name' => $name,
            '_value' => $value,
            'itype' => $type,
            'custom' => $custom,
        ];
    }

    /**
     * @param $model \Illuminate\Database\Eloquent\Model;
     * @return array [Model | string, string, string]
     */
    private function getJoinsByModel($model)
    {
        $joins = $model->joins ?: [];
        if (count($this->joins)) {
            $joins = array_filter($joins, function ($item) {
                return in_array($item, $this->joins);
            });
        }
        $relations = [];
        foreach ($joins as $key => $value) {
            $fn_name = is_int($key) ? $value : $key;
            $relation = $model->$fn_name();
            if ($relation instanceof BelongsTo) {
                if (app()->version() < '5.6') {
                    $relations[$fn_name] = [[$relation->getRelated(), $relation->getQualifiedOtherKeyName(), $relation->getQualifiedForeignKey()]];
                } else {
                    $relations[$fn_name] = [[$relation->getRelated(), $relation->getQualifiedOwnerKeyName(), $relation->getQualifiedForeignKey()]];
                }

            } elseif ($relation instanceof HasOne || $relation instanceof HasMany) {

                if (app()->version() < '5.6') {
                    $relations[$fn_name] = [[$relation->getRelated(), $relation->getForeignKey(), $relation->getQualifiedParentKeyName()]];
                } else {
                    $relations[$fn_name] = [[$relation->getRelated(), $relation->getQualifiedForeignKeyName(), $relation->getQualifiedParentKeyName()]];
                }

            } elseif ($relation instanceof BelongsToMany) {

                if (app()->version() < '5.6') {
                    $relations[$fn_name] = [[$relation->getTable(), $relation->getForeignKey(), $relation->getQualifiedParentKeyName()],
                        [$relation->getRelated(), $relation->getRelated()->getQualifiedKeyName(), $relation->getOtherKey()]];
                } else {
                    $relations[$fn_name] = [[$relation->getTable(), $relation->getForeignPivotKeyName(), $relation->getQualifiedParentKeyName()],
                        [$relation->getRelated(), $relation->getRelated()->getQualifiedKeyName(), $relation->getQualifiedRelatedPivotKeyName()]];
                }

            } elseif ($relation instanceof HasManyThrough) {

                if (app()->version() < '5.6') {
                    $localKey = is_int($key) ? $relation->getHasCompareKey() : $model->getTable() . '.' . $value;
                    $relations[$fn_name] = [[$relation->getParent(), $localKey, $relation->getThroughKey()],
                        [$relation->getRelated(), $relation->getQualifiedParentKeyName(), $relation->getForeignKey()]];
                } else {
//                    $localKey = is_int($key) ? $relation->getHasCompareKey() : $model->getTable() . '.' . $value;
                    $relations[$fn_name] = [[$relation->getParent(), $relation->getQualifiedFirstKeyName(), $relation->getQualifiedLocalKeyName()],
                        [$relation->getRelated(), $relation->getQualifiedParentKeyName(), $relation->getQualifiedForeignKeyName()]];
                }
            }
        }
        return $relations;
    }
}
